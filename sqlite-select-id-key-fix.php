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
 * A diagnostics screen is registered under Tools -> "SQLite id key fix" that
 * runs a live probe and reports whether the fix is currently effective.
 *
 * @package sqlite-select-id-key-fix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Two integration paths are supported because the SQLite driver's internals
 * changed across plugin versions:
 *
 *  - Older releases (<= 3.0.0-rc.7) route every query through
 *    WP_SQLite_Translator and expose the `pre_query_sqlite_db` filter, which
 *    lets us intercept and rewrite the *result set* directly.
 *
 *  - 3.0.0-rc.8+ replaced that with a wpdb-style WP_SQLite_DB whose result
 *    fetching is not filterable; the only query-time hook left is the standard
 *    `query` filter, which can only rewrite the *SQL statement*. There we add
 *    an explicit quoted alias (e.g. `P.id` -> `P.id AS "id"`) so SQLite echoes
 *    the written casing back as the column key.
 *
 * Registering both is safe: rc.8 never fires `pre_query_sqlite_db`, and on the
 * older path the `query` rewrite is idempotent (once aliased, the result-set
 * rename becomes a no-op because the keys already match).
 */
add_filter( 'pre_query_sqlite_db', 'sqlite_select_id_key_fix', 10, 5 );
add_filter( 'query', 'sqlite_select_id_key_fix_rewrite_query', 10, 1 );

/*
 * Admin diagnostics page. Registers a "SQLite id fix" screen under Tools that
 * runs the same probe used from the CLI (a bare "SELECT P.id" fetched as both
 * ARRAY_A and OBJECT) and reports whether the fix is currently effective.
 */
add_action( 'admin_menu', 'sqlite_select_id_key_fix_register_admin_page' );

/**
 * Registers the diagnostics page under the Tools menu.
 *
 * @return void
 */
function sqlite_select_id_key_fix_register_admin_page() {
	add_management_page(
		__( 'SQLite id key fix', 'sqlite-select-id-key-fix' ),
		__( 'SQLite id key fix', 'sqlite-select-id-key-fix' ),
		'manage_options',
		'sqlite-select-id-key-fix',
		'sqlite_select_id_key_fix_render_admin_page'
	);
}

/**
 * Runs the diagnostic probe against the live database.
 *
 * Mirrors the CLI check: a single-table, un-aliased "SELECT P.id" fetched as
 * both ARRAY_A and OBJECT. When the plugin is effective the written casing
 * "id" survives; without it SQLite would echo back the declared column "ID".
 *
 * @return array{
 *     filtered_sql:string,
 *     has_alias:bool,
 *     array_ok:bool,
 *     object_ok:bool,
 *     effective:bool,
 *     table:string
 * }
 */
function sqlite_select_id_key_fix_run_probe() {
	global $wpdb;

	$sql          = 'SELECT P.id FROM ' . $wpdb->posts . ' AS P ORDER BY P.ID LIMIT 1';
	$filtered_sql = (string) apply_filters( 'query', $sql );

	$array_row  = $wpdb->get_row( $sql, ARRAY_A );
	$object_row = $wpdb->get_row( $sql, OBJECT );

	$array_ok  = is_array( $array_row ) && array_key_exists( 'id', $array_row );
	$object_ok = is_object( $object_row ) && property_exists( $object_row, 'id' );

	// The rc.8 path proves itself through the rewritten SQL alias; the older
	// result-set path proves itself through the ARRAY_A / OBJECT keys.
	$has_alias = (bool) preg_match( '/P\.id\s+AS\s+"id"/i', $filtered_sql );

	return array(
		'filtered_sql' => $filtered_sql,
		'has_alias'    => $has_alias,
		'array_ok'     => $array_ok,
		'object_ok'    => $object_ok,
		'effective'    => ( $has_alias || ( $array_ok && $object_ok ) ),
		'table'        => $wpdb->posts,
	);
}

/**
 * Renders the diagnostics admin page.
 *
 * @return void
 */
function sqlite_select_id_key_fix_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'sqlite-select-id-key-fix' ) );
	}

	$probe = sqlite_select_id_key_fix_run_probe();

	$yes = '<span style="color:#008a00;font-weight:600;">' . esc_html__( 'yes', 'sqlite-select-id-key-fix' ) . '</span>';
	$no  = '<span style="color:#d63638;font-weight:600;">' . esc_html__( 'no', 'sqlite-select-id-key-fix' ) . '</span>';

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'SQLite id key fix', 'sqlite-select-id-key-fix' ) . '</h1>';

	if ( $probe['effective'] ) {
		echo '<div class="notice notice-success"><p><strong>'
			. esc_html__( 'The fix is effective.', 'sqlite-select-id-key-fix' )
			. '</strong> '
			. esc_html__( 'The written column casing "id" is preserved.', 'sqlite-select-id-key-fix' )
			. '</p></div>';
	} else {
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'The fix is NOT effective.', 'sqlite-select-id-key-fix' )
			. '</strong> '
			. esc_html__( 'The written column casing "id" was not preserved.', 'sqlite-select-id-key-fix' )
			. '</p></div>';
	}

	echo '<p>' . esc_html__( 'This page runs the same probe used from the command line: an un-aliased single-table "SELECT P.id" fetched as ARRAY_A and OBJECT.', 'sqlite-select-id-key-fix' ) . '</p>';

	echo '<h2>' . esc_html__( 'Probe', 'sqlite-select-id-key-fix' ) . '</h2>';
	echo '<p><strong>' . esc_html__( 'Original SQL', 'sqlite-select-id-key-fix' ) . ':</strong></p>';
	echo '<pre style="background:#f6f7f7;padding:10px;overflow:auto;">'
		. esc_html( 'SELECT P.id FROM ' . $probe['table'] . ' AS P ORDER BY P.ID LIMIT 1' )
		. '</pre>';
	echo '<p><strong>' . esc_html__( 'Filtered SQL', 'sqlite-select-id-key-fix' ) . ':</strong></p>';
	echo '<pre style="background:#f6f7f7;padding:10px;overflow:auto;">' . esc_html( $probe['filtered_sql'] ) . '</pre>';

	echo '<table class="widefat striped" style="max-width:640px;">';
	echo '<thead><tr><th>' . esc_html__( 'Check', 'sqlite-select-id-key-fix' ) . '</th><th>' . esc_html__( 'Result', 'sqlite-select-id-key-fix' ) . '</th></tr></thead>';
	echo '<tbody>';
	echo '<tr><td>' . esc_html__( 'Filtered SQL contains P.id AS "id"', 'sqlite-select-id-key-fix' ) . '</td><td>' . ( $probe['has_alias'] ? $yes : $no ) . '</td></tr>';
	echo '<tr><td>' . esc_html__( 'ARRAY_A row has key "id"', 'sqlite-select-id-key-fix' ) . '</td><td>' . ( $probe['array_ok'] ? $yes : $no ) . '</td></tr>';
	echo '<tr><td>' . esc_html__( 'OBJECT row has property "id"', 'sqlite-select-id-key-fix' ) . '</td><td>' . ( $probe['object_ok'] ? $yes : $no ) . '</td></tr>';
	echo '</tbody></table>';

	echo '</div>';
}

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
	$columns = sqlite_select_id_key_fix_parse_select( $statement );
	if ( null === $columns ) {
		return null;
	}

	$rename_map = array();
	foreach ( $columns as $column ) {
		$written = sqlite_select_id_key_fix_column_written_name( $column );
		if ( false === $written ) {
			// An alias-bearing column: SQLite already honors the casing.
			continue;
		}
		if ( null === $written ) {
			// Expression / wildcard we refused to reason about.
			return null;
		}
		$rename_map[ strtolower( $written ) ] = $written;
	}

	return $rename_map;
}

/**
 * Parses a statement and returns its SELECT column list when the query is a
 * safe single-table SELECT we are willing to touch, or null otherwise.
 *
 * Shared by both integration paths so their safety judgement stays identical:
 * the older `pre_query_sqlite_db` result rewriter and the rc.8 `query` SQL
 * rewriter must agree on exactly which statements are in scope.
 *
 * @param string $statement The original MySQL statement.
 * @return null|string[] The individual column expressions, or null.
 */
function sqlite_select_id_key_fix_parse_select( $statement ) {
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

	return sqlite_select_id_key_fix_split_columns( $columns_part );
}

/**
 * Classifies a single SELECT column expression.
 *
 * @param string $column One column expression from the SELECT list.
 * @return string|false|null The written column name to preserve; false when the
 *                           column already carries an explicit alias (nothing to
 *                           do); null when the expression is unsafe to touch.
 */
function sqlite_select_id_key_fix_column_written_name( $column ) {
	$column = trim( $column );
	if ( '' === $column || '*' === $column ) {
		// A wildcard makes the result shape unknown; bail out entirely.
		return null;
	}

	// Explicit alias: "expr AS alias" or "expr alias". SQLite honors the
	// alias verbatim, so no renaming is needed for these.
	if ( preg_match( '/\s+AS\s+[`"\']?[A-Za-z0-9_]+[`"\']?$/i', $column ) ) {
		return false;
	}

	// A bare (optionally table-qualified) column reference, e.g. "P.id".
	if ( preg_match( '/^[`"\']?([A-Za-z_][A-Za-z0-9_]*)[`"\']?\.[`"\']?([A-Za-z_][A-Za-z0-9_]*)[`"\']?$/', $column, $cm ) ) {
		return $cm[2];
	}
	if ( preg_match( '/^[`"\']?([A-Za-z_][A-Za-z0-9_]*)[`"\']?$/', $column, $cm ) ) {
		return $cm[1];
	}

	// Expression / function / implicit-alias form: do not guess.
	return null;
}

/**
 * rc.8 `query` filter: rewrites a safe single-table SELECT so each bare column
 * reference gains an explicit quoted alias matching the written casing (e.g.
 * `SELECT P.id FROM ...` -> `SELECT P.id AS "id" FROM ...`). SQLite echoes an
 * aliased column back verbatim, so the result key keeps the written casing
 * without needing a (non-existent in rc.8) result-set filter.
 *
 * The statement is returned unchanged whenever it is not a query we handle.
 *
 * @param string $query The SQL statement about to run.
 * @return string The (possibly) rewritten statement.
 */
function sqlite_select_id_key_fix_rewrite_query( $query ) {
	if ( ! is_string( $query ) ) {
		return $query;
	}

	// Reuse the shared SELECT parser so both paths agree on scope. We only need
	// the column-list slice to rewrite; the parser already vetted everything.
	if ( ! preg_match( '/^(\s*SELECT\s+)(.*?)(\s+FROM\s+.*)$/is', $query, $m ) ) {
		return $query;
	}

	$columns = sqlite_select_id_key_fix_parse_select( $query );
	if ( null === $columns ) {
		return $query;
	}

	$rewritten = array();
	$changed   = false;

	foreach ( $columns as $column ) {
		$original = $column;
		$trimmed  = trim( $column );
		$written  = sqlite_select_id_key_fix_column_written_name( $trimmed );

		if ( false === $written || null === $written ) {
			// Already aliased (false) — parse_select guarantees no null here,
			// but stay defensive and leave the column verbatim either way.
			$rewritten[] = $original;
			continue;
		}

		// Always alias a bare column to the written name. SQLite otherwise
		// returns the *declared* column name for an un-aliased reference (e.g.
		// "ID" for wp_posts.ID even when the query wrote "id"); the explicit
		// quoted alias forces the written casing into the result key. We cannot
		// know the declared name here, so aliasing unconditionally is the only
		// reliable fix.
		$rewritten[] = $trimmed . ' AS "' . $written . '"';
		$changed     = true;
	}

	if ( ! $changed ) {
		return $query;
	}

	return $m[1] . implode( ', ', $rewritten ) . $m[3];
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
