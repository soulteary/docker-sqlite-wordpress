<?php
/**
 * Plugin Name: SQLite Diagnostics
 * Description: Read-only diagnostics page under the Tools menu that surfaces the native wp_mysql_parser extension state, SQLite version/source id, PHP/architecture environment details, and the sqlite-database-integration plugin version. Performs no writes and never touches the live site database.
 * Version: 1.0.0
 * Author: soulteary
 *
 * Background:
 * The SQLite WordPress image stitches together several moving parts: an optional
 * native Rust parser extension, the pure-PHP SQLite driver, and the
 * sqlite-database-integration plugin. This drop-in gathers their runtime state
 * into a single, admin-only page so operators can confirm the accelerated path
 * is active without shelling into the container. Every probe is read-only and
 * the SQLite version check uses an in-memory database, so the real site data is
 * never read or modified.
 *
 * @package sqlite-diagnostics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'sqlite_diagnostics_register_page' );

/**
 * Registers the read-only diagnostics page under the Tools menu.
 *
 * @return void
 */
function sqlite_diagnostics_register_page() {
	add_management_page(
		__( 'SQLite Diagnostics', 'sqlite-diagnostics' ),
		__( 'SQLite Diagnostics', 'sqlite-diagnostics' ),
		'manage_options',
		'sqlite-diagnostics',
		'sqlite_diagnostics_render_page'
	);
}

/**
 * Renders the diagnostics page as a set of read-only grouped tables.
 *
 * @return void
 */
function sqlite_diagnostics_render_page() {
	$groups = array(
		__( 'Native Extension', 'sqlite-diagnostics' )    => sqlite_diagnostics_native_extension(),
		__( 'SQLite', 'sqlite-diagnostics' )              => sqlite_diagnostics_sqlite(),
		__( 'Environment', 'sqlite-diagnostics' )         => sqlite_diagnostics_environment(),
		__( 'Integration Plugin', 'sqlite-diagnostics' )  => sqlite_diagnostics_integration_plugin(),
	);

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'SQLite Diagnostics', 'sqlite-diagnostics' ) . '</h1>';
	echo '<p>' . esc_html__( 'Read-only overview of the SQLite runtime. No settings are written and the live site database is never accessed.', 'sqlite-diagnostics' ) . '</p>';

	foreach ( $groups as $title => $rows ) {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px;">';
		echo '<tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr>';
			echo '<th scope="row" style="width:40%;">' . esc_html( $label ) . '</th>';
			echo '<td>' . esc_html( $value ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
	}

	echo '</div>';
}

/**
 * Collects native wp_mysql_parser extension state and the resulting parse path.
 *
 * @return array<string,string> Label => value pairs (raw, unescaped).
 */
function sqlite_diagnostics_native_extension() {
	$loaded   = extension_loaded( 'wp_mysql_parser' );
	$ini_path = '/usr/local/etc/php/conf.d/wp_mysql_parser.ini';
	$ini_set  = file_exists( $ini_path );

	$path = $loaded
		? __( 'native accelerated path', 'sqlite-diagnostics' )
		: __( 'PHP fallback path', 'sqlite-diagnostics' );

	return array(
		__( 'Extension loaded', 'sqlite-diagnostics' )        => sqlite_diagnostics_bool( $loaded ),
		__( 'Build-time registration (.ini)', 'sqlite-diagnostics' ) => sqlite_diagnostics_bool( $ini_set ) . ' (' . $ini_path . ')',
		__( 'Active parse path', 'sqlite-diagnostics' )       => $path,
	);
}

/**
 * Probes the bundled SQLite library using an in-memory database, so the live
 * site database is never touched. Failures are reported gracefully.
 *
 * @return array<string,string> Label => value pairs (raw, unescaped).
 */
function sqlite_diagnostics_sqlite() {
	$version   = __( 'unavailable', 'sqlite-diagnostics' );
	$source_id = __( 'unavailable', 'sqlite-diagnostics' );

	if ( class_exists( 'PDO' ) ) {
		try {
			$pdo       = new PDO( 'sqlite::memory:' );
			$version   = (string) $pdo->query( 'SELECT sqlite_version()' )->fetchColumn();
			$source_id = (string) $pdo->query( 'SELECT sqlite_source_id()' )->fetchColumn();
		} catch ( Exception $e ) {
			$version   = __( 'error', 'sqlite-diagnostics' );
			$source_id = $e->getMessage();
		}
	} else {
		$version = __( 'PDO unavailable', 'sqlite-diagnostics' );
	}

	return array(
		__( 'SQLite version', 'sqlite-diagnostics' )   => $version,
		__( 'SQLite source id', 'sqlite-diagnostics' ) => $source_id,
	);
}

/**
 * Collects PHP/architecture environment details and SQLite drop-in metadata.
 *
 * @return array<string,string> Label => value pairs (raw, unescaped).
 */
function sqlite_diagnostics_environment() {
	$rows = array(
		__( 'PHP version', 'sqlite-diagnostics' )      => PHP_VERSION,
		__( 'Architecture', 'sqlite-diagnostics' )     => function_exists( 'php_uname' ) ? php_uname( 'm' ) : __( 'unknown', 'sqlite-diagnostics' ),
		__( 'pdo_sqlite driver', 'sqlite-diagnostics' ) => sqlite_diagnostics_bool( extension_loaded( 'pdo_sqlite' ) ),
	);

	$rows[ __( 'Drop-in version', 'sqlite-diagnostics' ) ] = defined( 'SQLITE_DB_DROPIN_VERSION' )
		? (string) SQLITE_DB_DROPIN_VERSION
		: __( 'not defined', 'sqlite-diagnostics' );

	if ( defined( 'FQDB' ) ) {
		$db_path = (string) FQDB;
		$rows[ __( 'Database file', 'sqlite-diagnostics' ) ] = $db_path;

		if ( file_exists( $db_path ) ) {
			$size = filesize( $db_path );
			$rows[ __( 'Database file size', 'sqlite-diagnostics' ) ] = false === $size
				? __( 'unknown', 'sqlite-diagnostics' )
				: sqlite_diagnostics_format_bytes( $size );
		} else {
			$rows[ __( 'Database file size', 'sqlite-diagnostics' ) ] = __( 'file not found', 'sqlite-diagnostics' );
		}
	} else {
		$rows[ __( 'Database file', 'sqlite-diagnostics' ) ] = __( 'FQDB not defined', 'sqlite-diagnostics' );
	}

	return $rows;
}

/**
 * Resolves the sqlite-database-integration plugin version, preferring a defined
 * constant, then the plugin's load.php header, then the build-time environment
 * variable baked into the image.
 *
 * @return array<string,string> Label => value pairs (raw, unescaped).
 */
function sqlite_diagnostics_integration_plugin() {
	$version = sqlite_diagnostics_integration_version();

	return array(
		__( 'sqlite-database-integration version', 'sqlite-diagnostics' ) => $version,
	);
}

/**
 * Determines the sqlite-database-integration plugin version from the most
 * authoritative source available.
 *
 * @return string The resolved version, or a human-readable fallback string.
 */
function sqlite_diagnostics_integration_version() {
	if ( defined( 'SQLITE_DATABASE_INTEGRATION_VERSION' ) ) {
		return (string) constant( 'SQLITE_DATABASE_INTEGRATION_VERSION' );
	}

	$load_php = '';
	if ( defined( 'WP_CONTENT_DIR' ) ) {
		$load_php = WP_CONTENT_DIR . '/mu-plugins/sqlite-database-integration/load.php';
	}

	if ( '' !== $load_php && file_exists( $load_php ) && function_exists( 'get_file_data' ) ) {
		$data = get_file_data( $load_php, array( 'version' => 'Version' ) );
		if ( ! empty( $data['version'] ) ) {
			return (string) $data['version'];
		}
	}

	$env = getenv( 'SQLITE_DATABASE_INTEGRATION_VERSION' );
	if ( false !== $env && '' !== $env ) {
		return (string) $env;
	}

	return __( 'unknown', 'sqlite-diagnostics' );
}

/**
 * Formats a boolean as a localized yes/no string.
 *
 * @param bool $value The value to format.
 * @return string Localized "Yes" or "No".
 */
function sqlite_diagnostics_bool( $value ) {
	return $value
		? __( 'Yes', 'sqlite-diagnostics' )
		: __( 'No', 'sqlite-diagnostics' );
}

/**
 * Formats a byte count into a human-readable string.
 *
 * @param int $bytes The size in bytes.
 * @return string Human-readable size (e.g. "1.25 MB").
 */
function sqlite_diagnostics_format_bytes( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$bytes = max( (int) $bytes, 0 );
	$power = $bytes > 0 ? (int) floor( log( $bytes, 1024 ) ) : 0;
	$power = min( $power, count( $units ) - 1 );
	$value = $bytes / pow( 1024, $power );

	return sprintf( '%s %s', 0 === $power ? (string) $value : number_format( $value, 2 ), $units[ $power ] );
}
