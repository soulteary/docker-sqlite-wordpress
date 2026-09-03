<?php
/**
 * Regression tests for the bundled WordPress core update integration.
 */

$local_core_package = tempnam( sys_get_temp_dir(), 'wordpress-core-' );
if ( false === $local_core_package ) {
	fwrite( STDERR, "FAIL: could not create local core package fixture\n" );
	exit( 1 );
}
file_put_contents( $local_core_package, 'packaged WordPress core' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'SQLITE_WORDPRESS_LOCAL_CORE_VERSION', '7.1.0' );
define( 'SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE', $local_core_package );
define( 'SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE_SHA256', hash_file( 'sha256', $local_core_package ) );

$registered_filters = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $registered_filters;
	$registered_filters[ $hook ] = array( $callback, $priority, $accepted_args );
}

function wp_tempnam( $filename = '' ) {
	unset( $filename );
	return tempnam( sys_get_temp_dir(), 'wordpress-update-' );
}

class WP_Error {
	public $code;

	public function __construct( $code, $message = '' ) {
		unset( $message );
		$this->code = $code;
	}
}

class Core_Upgrader {}
class Plugin_Upgrader {}

require dirname( __DIR__ ) . '/plugins/sqlite-local-core-update.php';

function local_core_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true ) . "\nActual:   " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function local_core_assert_true( $actual, $label ) {
	local_core_assert_same( true, $actual, $label );
}

local_core_assert_same( array( 'sqlite_wordpress_local_core_update_offer', 20, 1 ), $registered_filters['site_transient_update_core'], 'core transient filter is registered' );
local_core_assert_same( array( 'sqlite_wordpress_local_core_update_pre_download', 10, 4 ), $registered_filters['upgrader_pre_download'], 'download filter is registered' );
local_core_assert_true( sqlite_wordpress_local_core_versions_match( '7.1', '7.1.0' ), 'WordPress major release version matches Docker patch-zero version' );
local_core_assert_same( false, sqlite_wordpress_local_core_versions_match( '7.1-RC1', '7.1.0' ), 'pre-release version never aliases a stable local package' );

putenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED' );
local_core_assert_true( sqlite_wordpress_local_core_update_is_enabled(), 'local core update defaults to enabled' );
putenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED=false' );
local_core_assert_same( false, sqlite_wordpress_local_core_update_is_enabled(), 'exact false disables local core update' );
putenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED=yes' );
local_core_assert_same( false, sqlite_wordpress_local_core_update_is_enabled(), 'invalid explicit value fails closed' );
putenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED=true' );

$matching_update = (object) array(
	'current'  => '7.1',
	'download' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip',
	'packages' => (object) array(
		'full'       => 'https://downloads.wordpress.org/release/wordpress-7.1.zip',
		'no_content' => 'https://downloads.wordpress.org/release/wordpress-7.1-no-content.zip',
		'rollback'   => 'https://downloads.wordpress.org/release/wordpress-7.0.2.zip',
	),
);
$other_update    = (object) array(
	'current'  => '7.2.0',
	'download' => 'https://downloads.wordpress.org/release/wordpress-7.2.0.zip',
	'packages' => (object) array( 'full' => 'https://downloads.wordpress.org/release/wordpress-7.2.0.zip' ),
);
$transient       = (object) array( 'updates' => array( $matching_update, $other_update ) );
$wp_version      = '7.0.2';

$filtered = sqlite_wordpress_local_core_update_offer( $transient );
foreach ( array( 'full', 'no_content', 'new_bundled', 'partial' ) as $package_type ) {
	local_core_assert_same( $local_core_package, $filtered->updates[0]->packages->$package_type, "matching {$package_type} package uses local archive" );
}
local_core_assert_same( $local_core_package, $filtered->updates[0]->download, 'matching WordPress API version exposes the local package address' );
local_core_assert_same( 'https://downloads.wordpress.org/release/wordpress-7.0.2.zip', $filtered->updates[0]->packages->rollback, 'rollback package remains remote' );
local_core_assert_same( 'https://downloads.wordpress.org/release/wordpress-7.2.0.zip', $filtered->updates[1]->packages->full, 'different target version remains remote' );

$temporary = sqlite_wordpress_local_core_update_pre_download( false, $local_core_package, new Core_Upgrader(), array() );
local_core_assert_true( is_string( $temporary ) && is_file( $temporary ), 'core upgrader receives a temporary package copy' );
local_core_assert_same( file_get_contents( $local_core_package ), file_get_contents( $temporary ), 'temporary package matches bundled archive' );
local_core_assert_same( 0600, fileperms( $temporary ) & 0777, 'temporary package is private' );
unlink( $temporary );

$wrong_upgrader = sqlite_wordpress_local_core_update_pre_download( false, $local_core_package, new Plugin_Upgrader(), array() );
local_core_assert_true( $wrong_upgrader instanceof WP_Error, 'non-core upgrader is rejected' );
local_core_assert_same( 'sqlite_wordpress_local_core_wrong_upgrader', $wrong_upgrader->code, 'wrong upgrader error is explicit' );

$wp_version       = '7.1';
$reinstall_update = (object) array(
	'current'  => '7.1',
	'download' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip',
	'packages' => (object) array( 'full' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip' ),
);
$reinstall_filtered = sqlite_wordpress_local_core_update_offer( (object) array( 'updates' => array( $reinstall_update ) ) );
local_core_assert_same( $local_core_package, $reinstall_filtered->updates[0]->packages->full, 'matching reinstall uses local archive' );

putenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED=false' );
$disabled_update = (object) array(
	'current'  => '7.1',
	'download' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip',
	'packages' => (object) array( 'full' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip' ),
);
$disabled_filtered = sqlite_wordpress_local_core_update_offer( (object) array( 'updates' => array( $disabled_update ) ) );
local_core_assert_same( 'https://downloads.wordpress.org/release/wordpress-7.1.zip', $disabled_filtered->updates[0]->packages->full, 'disabled integration leaves remote package untouched' );
putenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED=true' );

$wp_version                  = '7.2.0';
$matching_update->download   = 'https://downloads.wordpress.org/release/wordpress-7.1.zip';
$matching_update->packages   = (object) array( 'full' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip' );
$newer_installation_filtered = sqlite_wordpress_local_core_update_offer( (object) array( 'updates' => array( $matching_update ) ) );
local_core_assert_same( 'https://downloads.wordpress.org/release/wordpress-7.1.zip', $newer_installation_filtered->updates[0]->packages->full, 'newer WordPress is never downgraded' );

$wp_version = '7.0.2';
file_put_contents( $local_core_package, 'corrupt' );
$invalid_package_update = (object) array(
	'current'  => '7.1',
	'download' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip',
	'packages' => (object) array( 'full' => 'https://downloads.wordpress.org/release/wordpress-7.1.zip' ),
);
$invalid_filtered = sqlite_wordpress_local_core_update_offer( (object) array( 'updates' => array( $invalid_package_update ) ) );
local_core_assert_same( 'https://downloads.wordpress.org/release/wordpress-7.1.zip', $invalid_filtered->updates[0]->packages->full, 'invalid local archive falls back to remote offer' );

putenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED' );
unlink( $local_core_package );

fwrite( STDOUT, "sqlite local core update tests passed\n" );
