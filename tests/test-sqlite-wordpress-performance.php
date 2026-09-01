<?php
/**
 * Regression tests for the optional page performance MU Plugin.
 */

define( 'ABSPATH', __DIR__ . '/' );

$performance_test_option  = false;
$performance_test_actions = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $performance_test_actions;
	$performance_test_actions[ $hook ] = array( $callback, $priority, $accepted_args );
}

function get_option( $name, $default = false ) {
	global $performance_test_option;
	if ( 'sqlite_wordpress_performance' === $name ) {
		return $performance_test_option;
	}
	return $default;
}

function __( $text ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text ) {
	return esc_html( $text );
}

function current_user_can( $capability ) {
	return 'manage_options' === $capability;
}

function admin_url( $path ) {
	return 'https://example.test/wp-admin/' . $path;
}

function timer_stop() {
	return '0.125';
}

function size_format( $bytes ) {
	return 'bytes-' . $bytes;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

class Performance_Test_Admin_Bar {
	public $node = null;

	public function add_node( $node ) {
		$this->node = $node;
	}
}

require dirname( __DIR__ ) . '/sqlite-wordpress-performance.php';

function performance_test_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true ) . "\nActual:   " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function performance_test_assert_contains( $needle, $haystack, $label ) {
	if ( false === strpos( $haystack, $needle ) ) {
		fwrite( STDERR, "FAIL: {$label}\nMissing: " . var_export( $needle, true ) . "\nActual:  " . var_export( $haystack, true ) . "\n" );
		exit( 1 );
	}
}

function performance_test_reset() {
	global $performance_test_option;
	$performance_test_option = false;
	putenv( 'WORDPRESS_PAGE_PERFORMANCE_ENABLED' );
}

performance_test_assert_same( array( 'sqlite_wordpress_performance_add_toolbar_node', PHP_INT_MAX, 1 ), $performance_test_actions['admin_bar_menu'], 'toolbar hook is registered last' );
performance_test_assert_same( array( 'sqlite_wordpress_performance_render_frontend_footer', PHP_INT_MAX, 1 ), $performance_test_actions['wp_footer'], 'front-end footer hook is registered last' );
performance_test_assert_same( array( 'sqlite_wordpress_performance_update_toolbar', PHP_INT_MAX, 1 ), $performance_test_actions['admin_footer'], 'admin toolbar refresh is registered last' );

performance_test_reset();
$configuration = sqlite_wordpress_performance_configuration();
performance_test_assert_same( false, $configuration['enabled'], 'performance output defaults to disabled' );
performance_test_assert_same( 'database', $configuration['source'], 'unset environment leaves administrator control active' );

$performance_test_option = true;
$configuration           = sqlite_wordpress_performance_configuration();
performance_test_assert_same( true, $configuration['enabled'], 'stored administrator setting enables output' );

putenv( 'WORDPRESS_PAGE_PERFORMANCE_ENABLED=false' );
$configuration = sqlite_wordpress_performance_configuration();
performance_test_assert_same( false, $configuration['enabled'], 'environment false overrides stored true' );
performance_test_assert_same( true, sqlite_wordpress_performance_sanitize_setting( false ), 'environment control preserves stored administrator value' );

putenv( 'WORDPRESS_PAGE_PERFORMANCE_ENABLED=true' );
$performance_test_option = false;
$configuration           = sqlite_wordpress_performance_configuration();
performance_test_assert_same( true, $configuration['enabled'], 'environment true overrides stored false' );
performance_test_assert_same( false, sqlite_wordpress_performance_sanitize_setting( true ), 'environment control does not overwrite stored false' );

putenv( 'WORDPRESS_PAGE_PERFORMANCE_ENABLED=TRUE' );
$configuration = sqlite_wordpress_performance_configuration();
performance_test_assert_same( false, $configuration['enabled'], 'invalid environment value fails closed' );
performance_test_assert_contains( 'exact lowercase value true or false', $configuration['error'], 'invalid environment value explains accepted syntax' );

performance_test_reset();
performance_test_assert_same( true, sqlite_wordpress_performance_sanitize_setting( '1' ), 'administrator can enable output' );
performance_test_assert_same( false, sqlite_wordpress_performance_sanitize_setting( '0' ), 'administrator can disable output' );

$performance_test_option = true;
$admin_bar               = new Performance_Test_Admin_Bar();
sqlite_wordpress_performance_add_toolbar_node( $admin_bar );
performance_test_assert_same( 'sqlite-wordpress-performance', $admin_bar->node['id'], 'enabled plugin adds the toolbar node' );
performance_test_assert_contains( '0.125 s', $admin_bar->node['title'], 'toolbar reports generation time' );
performance_test_assert_contains( 'Memory bytes-', $admin_bar->node['title'], 'toolbar reports current memory' );
performance_test_assert_contains( 'Peak bytes-', $admin_bar->node['title'], 'toolbar reports peak memory' );

ob_start();
sqlite_wordpress_performance_render_frontend_footer();
$footer = ob_get_clean();
performance_test_assert_contains( 'sqlite-wordpress-performance-footer', $footer, 'enabled plugin renders the public footer' );
performance_test_assert_contains( 'Server performance:', $footer, 'public footer labels the metrics' );
performance_test_assert_contains( 'sqlite-wordpress-performance-toolbar-update', $footer, 'front-end footer refreshes the toolbar with a late metric sample' );

performance_test_reset();
ob_start();
sqlite_wordpress_performance_render_frontend_footer();
$footer = ob_get_clean();
performance_test_assert_same( '', $footer, 'disabled plugin emits no public footer' );

fwrite( STDOUT, "sqlite-wordpress-performance tests passed\n" );
