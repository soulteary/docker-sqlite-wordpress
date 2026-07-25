<?php
/**
 * Plugin Name: SQLite SELECT id key case fix
 * Description: Restores the column-name casing written in a query (e.g. "P.id") for ARRAY_A results, working around SQLite returning the real declared column name (e.g. "ID") for un-aliased columns.
 * Version: 1.0.0
 * Author: soulteary
 *
 * Background:
 * WordPress' wp_posts table declares its primary key column as "ID". MySQL is
 * case-insensitive and echoes back the identifier as written in the query, so
 * "SELECT P.id" yields the key "id". SQLite (via PDO) instead returns the real
 * declared column name "ID" for an un-aliased column reference, so $item['id']
 * (and $row->id) is empty. This drop-in normalizes those keys back to what the
 * query wrote, for the safe case of a single-table SELECT fetched as ARRAY_A
 * (associative array) or OBJECT (stdClass).
 *
 * It is intentionally conservative: anything it does not fully understand is
 * left untouched, so the SQLite integration plugin handles it as usual.
 *
 * @package sqlite-select-id-key-fix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'pre_query_sqlite_db', 'sqlite_select_id_key_fix', 10, 5 );

/**
 * Intercepts safe single-table SELECT queries and restores the column-name
 * casing that was written in the query for ARRAY_A (associative array) and
 * OBJECT (stdClass) results.
 *
 * @param null|array $result          Default null to let the query proceed.
 * @param object     $translator      The WP_SQLite_Translator instance.
 * @param string     $statement       The original MySQL statement.
 * @param int        $mode            The PDO fetch mode.
 * @param array      $fetch_mode_args Extra fetch-mode args (unused).
 * @return null|array Null to proceed normally, or the normalized result set.
 */
function sqlite_select_id_key_fix( $result, $translator, $statement, $mode, $fetch_mode_args ) {
	// A previous filter already produced a result; do not override it.
	if ( null !== $result ) {
		return $result;
	}

	// Prevent infinite recursion: our own re-run re-enters this filter.
	static $running = false;
	if ( $running ) {
		return null;
	}

	// Only associative-array and object results carry named keys/properties
	// that can be miscased. ARRAY_N (numeric keys) is unaffected.
	if ( PDO::FETCH_ASSOC !== $mode && PDO::FETCH_OBJ !== $mode ) {
		return null;
	}

	if ( ! is_object( $translator ) || ! method_exists( $translator, 'query' ) ) {
		return null;
	}

	$rename_map = sqlite_select_id_key_fix_build_rename_map( $statement );
	if ( null === $rename_map || array() === $rename_map ) {
		// Not a query we handle, or nothing to rename.
		return null;
	}

	$running = true;
	try {
		$rows = $translator->query( $statement, $mode, ...$fetch_mode_args );
	} finally {
		$running = false;
	}

	if ( ! is_array( $rows ) ) {
		return $rows;
	}

	return sqlite_select_id_key_fix_apply_rename( $rows, $rename_map );
}

/**
 * Builds a map of returned-key => desired-key for a safe single-table SELECT.
 *
 * Returns null when the statement is not a query we are willing to touch
 * (multi-table, JOIN, subquery, UNION, functions, wildcards, DISTINCT, etc.).
 *
 * @param string $statement The original MySQL statement.
 * @return null|array<string,string> Map keyed by lowercased returned key.
 */
function sqlite_select_id_key_fix_build_rename_map( $statement ) {
	$sql = trim( $statement );

	// Must be a plain SELECT.
	if ( ! preg_match( '/^SELECT\s+/i', $sql ) ) {
		return null;
	}

	// Skip queries whose result shape we cannot reason about safely.
	if ( preg_match( '/^SELECT\s+DISTINCT\b/i', $sql ) ) {
		return null;
	}

	// Isolate the column list between SELECT and the first top-level FROM.
	if ( ! preg_match( '/^SELECT\s+(.*?)\s+FROM\s+(.*)$/is', $sql, $matches ) ) {
		return null;
	}
	$columns_part = $matches[1];
	$after_from   = $matches[2];

	// Subqueries / parenthesized expressions in the column list are unsafe.
	if ( strpos( $columns_part, '(' ) !== false ) {
		return null;
	}

	// Require a single base table: no JOIN, comma, subquery or UNION.
	if ( preg_match( '/\b(JOIN|UNION)\b/i', $after_from ) ) {
		return null;
	}
	if ( strpos( $after_from, '(' ) !== false ) {
		return null;
	}
	// A comma before any clause keyword implies multiple tables in FROM.
	$from_head = preg_split( '/\b(WHERE|GROUP|HAVING|ORDER|LIMIT)\b/i', $after_from );
	if ( isset( $from_head[0] ) && strpos( $from_head[0], ',' ) !== false ) {
		return null;
	}

	$columns = sqlite_select_id_key_fix_split_columns( $columns_part );
	if ( null === $columns ) {
		return null;
	}

	$rename_map = array();
	foreach ( $columns as $column ) {
		$column = trim( $column );
		if ( '' === $column || '*' === $column ) {
			// A wildcard makes the result shape unknown; bail out entirely.
			return null;
		}

		// Explicit alias: "expr AS alias" or "expr alias". SQLite honors the
		// alias verbatim, so no renaming is needed for these.
		if ( preg_match( '/\s+AS\s+[`"\']?[A-Za-z0-9_]+[`"\']?$/i', $column ) ) {
			continue;
		}

		// A bare (optionally table-qualified) column reference, e.g. "P.id".
		if ( preg_match( '/^[`"\']?([A-Za-z_][A-Za-z0-9_]*)[`"\']?\.[`"\']?([A-Za-z_][A-Za-z0-9_]*)[`"\']?$/', $column, $cm ) ) {
			$written = $cm[2];
		} elseif ( preg_match( '/^[`"\']?([A-Za-z_][A-Za-z0-9_]*)[`"\']?$/', $column, $cm ) ) {
			$written = $cm[1];
		} else {
			// Expression / function / implicit-alias form: do not guess.
			return null;
		}

		$rename_map[ strtolower( $written ) ] = $written;
	}

	return $rename_map;
}

/**
 * Splits a SELECT column list on top-level commas.
 *
 * Returns null if brackets/quotes are unbalanced or nested (already screened
 * out earlier, but kept defensive).
 *
 * @param string $columns_part The raw text between SELECT and FROM.
 * @return null|string[] The individual column expressions.
 */
function sqlite_select_id_key_fix_split_columns( $columns_part ) {
	$columns = array();
	$buffer  = '';
	$depth   = 0;
	$len     = strlen( $columns_part );

	for ( $i = 0; $i < $len; $i++ ) {
		$char = $columns_part[ $i ];
		if ( '(' === $char ) {
			++$depth;
		} elseif ( ')' === $char ) {
			--$depth;
			if ( $depth < 0 ) {
				return null;
			}
		}

		if ( ',' === $char && 0 === $depth ) {
			$columns[] = $buffer;
			$buffer    = '';
			continue;
		}

		$buffer .= $char;
	}

	if ( 0 !== $depth ) {
		return null;
	}

	$columns[] = $buffer;

	return $columns;
}

/**
 * Applies the rename map to each row, restoring the written casing while
 * preserving column order. Handles both ARRAY_A (array) and OBJECT (stdClass)
 * rows.
 *
 * @param array                 $rows       The result set.
 * @param array<string,string>  $rename_map Map of lowercased key => desired key.
 * @return array The result set with normalized keys/properties.
 */
function sqlite_select_id_key_fix_apply_rename( $rows, $rename_map ) {
	foreach ( $rows as $index => $row ) {
		if ( is_array( $row ) ) {
			$normalized = array();
			foreach ( $row as $key => $value ) {
				$lower = is_string( $key ) ? strtolower( $key ) : $key;
				if ( isset( $rename_map[ $lower ] ) ) {
					$normalized[ $rename_map[ $lower ] ] = $value;
				} else {
					$normalized[ $key ] = $value;
				}
			}
			$rows[ $index ] = $normalized;
		} elseif ( $row instanceof stdClass ) {
			$normalized = new stdClass();
			foreach ( get_object_vars( $row ) as $key => $value ) {
				$lower = strtolower( (string) $key );
				if ( isset( $rename_map[ $lower ] ) ) {
					$normalized->{$rename_map[ $lower ]} = $value;
				} else {
					$normalized->{$key} = $value;
				}
			}
			$rows[ $index ] = $normalized;
		}
	}

	return $rows;
}
