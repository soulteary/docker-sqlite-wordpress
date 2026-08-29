<?php
/**
 * Regression tests for the standalone site URL recovery tool.
 */

$site_url_tool_state_file = tempnam( sys_get_temp_dir(), 'site-url-state-' );
if ( false === $site_url_tool_state_file ) {
	fwrite( STDERR, "FAIL: could not create state fixture\n" );
	exit( 1 );
}
unlink( $site_url_tool_state_file );

define( 'SQLITE_WORDPRESS_SITE_URL_TOOL_TESTING', true );
define( 'SQLITE_WORDPRESS_SITE_URL_TOOL_STATE_FILE', $site_url_tool_state_file );
require dirname( __DIR__ ) . '/tool-update-site-url.php';

/**
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Assertion label.
 * @return void
 */
function site_url_tool_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true ) . "\nActual:   " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

/**
 * @param callable $callback  Operation expected to throw.
 * @param string   $exception Expected exception class.
 * @param string   $label     Assertion label.
 * @return void
 */
function site_url_tool_assert_throws( $callback, $exception, $label ) {
	try {
		$callback();
	} catch ( Throwable $error ) {
		if ( $error instanceof $exception ) {
			return;
		}
		fwrite( STDERR, "FAIL: {$label}\nExpected {$exception}, got " . get_class( $error ) . "\n" );
		exit( 1 );
	}

	fwrite( STDERR, "FAIL: {$label}\nExpected {$exception}, but nothing was thrown.\n" );
	exit( 1 );
}

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD' );
putenv( 'SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED' );
site_url_tool_assert_same( false, sqlite_wordpress_site_url_tool_is_enabled(), 'tool is disabled without an enable switch' );

$disabled_values = array( '', '0', '1', 'yes', 'on', 'TRUE', 'true ' );
foreach ( $disabled_values as $disabled_value ) {
	putenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=' . $disabled_value );
	site_url_tool_assert_same( false, sqlite_wordpress_site_url_tool_is_enabled(), 'non-exact enable value remains disabled: ' . $disabled_value );
}
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true' );
site_url_tool_assert_same( true, sqlite_wordpress_site_url_tool_is_enabled(), 'exact lowercase true enables the tool' );
site_url_tool_assert_same( null, sqlite_wordpress_site_url_tool_configured_credential(), 'enabled tool still requires a credential' );

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN=too-short' );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_configured_credential();
	},
	RuntimeException::class,
	'weak direct token is rejected'
);

$strong_token = str_repeat( 'a', 64 );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN=' . $strong_token );
site_url_tool_assert_same( $strong_token, sqlite_wordpress_site_url_tool_configured_credential(), 'strong direct token is accepted' );

$token_file = tempnam( sys_get_temp_dir(), 'site-url-token-' );
if ( false === $token_file ) {
	fwrite( STDERR, "FAIL: could not create token fixture\n" );
	exit( 1 );
}
file_put_contents( $token_file, str_repeat( 'b', 64 ) . "\n" );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE=' . $token_file );
site_url_tool_assert_same( str_repeat( 'b', 64 ), sqlite_wordpress_site_url_tool_configured_credential(), 'Docker secret token is accepted' );

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN=' . $strong_token );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_configured_credential();
	},
	RuntimeException::class,
	'ambiguous token sources are rejected'
);
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE' );

putenv( 'SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED=' . str_repeat( 'c', 64 ) );
site_url_tool_assert_same( str_repeat( 'c', 64 ), sqlite_wordpress_site_url_tool_configured_credential(), 'entrypoint-resolved secret is accepted' );
putenv( 'SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED' );

putenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD=' . str_repeat( 'p', 23 ) );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_configured_credential();
	},
	RuntimeException::class,
	'weak password is rejected'
);
$strong_password = str_repeat( 'p', 24 );
putenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD=' . $strong_password );
site_url_tool_assert_same( $strong_password, sqlite_wordpress_site_url_tool_configured_credential(), 'strong direct password is accepted' );

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE=' . $token_file );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_configured_credential();
	},
	RuntimeException::class,
	'password and token file cannot be configured together'
);
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE' );

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN=' . $strong_token );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_configured_credential();
	},
	RuntimeException::class,
	'password and token cannot be configured together'
);
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED' );
unlink( $token_file );

$state_time = 1000000;
site_url_tool_assert_same(
	array( 'status' => 'ready', 'retry_after' => 0 ),
	sqlite_wordpress_site_url_tool_availability( $state_time ),
	'new authorization state is ready'
);
for ( $attempt = 1; $attempt <= 4; ++$attempt ) {
	$authorization = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, 'incorrect-password', $state_time + $attempt );
	site_url_tool_assert_same( 'denied', $authorization['status'], 'failed credential is denied before threshold ' . $attempt );
}
$authorization = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, 'incorrect-password', $state_time + 5 );
site_url_tool_assert_same( 'locked', $authorization['status'], 'fifth failed credential locks recovery' );
site_url_tool_assert_same( 900, $authorization['retry_after'], 'lockout lasts 15 minutes' );
site_url_tool_assert_same(
	array( 'status' => 'locked', 'retry_after' => 800 ),
	sqlite_wordpress_site_url_tool_availability( $state_time + 105 ),
	'lockout is shared by later requests'
);
$authorization = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, $strong_password, $state_time + 105 );
site_url_tool_assert_same( 'locked', $authorization['status'], 'correct credential cannot bypass active lockout' );
site_url_tool_assert_same(
	array( 'status' => 'ready', 'retry_after' => 0 ),
	sqlite_wordpress_site_url_tool_availability( $state_time + 906 ),
	'expired lockout resets automatically'
);

$authorization = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, $strong_password, $state_time + 907 );
site_url_tool_assert_same( 'accepted', $authorization['status'], 'correct credential reserves recovery operation' );
$operation_id = $authorization['operation_id'];
site_url_tool_assert_same( 32, strlen( $operation_id ), 'reservation uses a random 128-bit id' );
site_url_tool_assert_same(
	array( 'status' => 'busy', 'retry_after' => 300 ),
	sqlite_wordpress_site_url_tool_availability( $state_time + 907 ),
	'parallel recovery requests are blocked'
);
$parallel = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, $strong_password, $state_time + 907 );
site_url_tool_assert_same( 'busy', $parallel['status'], 'second correct request cannot reserve the same authorization' );
site_url_tool_assert_same( false, sqlite_wordpress_site_url_tool_cancel_operation( str_repeat( '0', 32 ), $state_time + 908 ), 'wrong reservation cannot be cancelled' );
site_url_tool_assert_same( true, sqlite_wordpress_site_url_tool_cancel_operation( $operation_id, $state_time + 908 ), 'validation failure releases reservation' );
site_url_tool_assert_same(
	array( 'status' => 'ready', 'retry_after' => 0 ),
	sqlite_wordpress_site_url_tool_availability( $state_time + 908 ),
	'released reservation can be retried'
);

$authorization = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, $strong_password, $state_time + 909 );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD=' . $strong_password );
sqlite_wordpress_site_url_tool_consume_operation( $authorization['operation_id'], $state_time + 909 );
site_url_tool_assert_same( 'false', getenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED' ), 'consumed authorization disables the current PHP worker' );
site_url_tool_assert_same( false, getenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD' ), 'consumed authorization removes the current worker credential' );
site_url_tool_assert_same(
	array( 'status' => 'used', 'retry_after' => 0 ),
	sqlite_wordpress_site_url_tool_availability( $state_time + 910 ),
	'consumed authorization stays locked for every worker'
);
$authorization = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, $strong_password, $state_time + 910 );
site_url_tool_assert_same( 'used', $authorization['status'], 'used authorization cannot be replayed' );
site_url_tool_assert_same( 0600, fileperms( $site_url_tool_state_file ) & 0777, 'authorization state is owner-only' );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_decode_state( '{"version":1}' );
	},
	RuntimeException::class,
	'malformed authorization state fails closed'
);
file_put_contents( $site_url_tool_state_file, '' );
site_url_tool_assert_throws(
	function () use ( $state_time ) {
		sqlite_wordpress_site_url_tool_availability( $state_time + 911 );
	},
	RuntimeException::class,
	'unexpectedly empty authorization state fails closed'
);
unlink( $site_url_tool_state_file );
site_url_tool_assert_throws(
	function () use ( $state_time ) {
		sqlite_wordpress_site_url_tool_availability( $state_time + 912 );
	},
	RuntimeException::class,
	'missing authorization state with an existing lock fails closed'
);
$state_symlink_target = tempnam( sys_get_temp_dir(), 'site-url-state-target-' );
if ( false === $state_symlink_target || ! symlink( $state_symlink_target, $site_url_tool_state_file ) ) {
	fwrite( STDERR, "FAIL: could not create state symlink fixture\n" );
	exit( 1 );
}
site_url_tool_assert_throws(
	function () use ( $state_time ) {
		sqlite_wordpress_site_url_tool_availability( $state_time + 913 );
	},
	RuntimeException::class,
	'symbolic-link authorization state fails closed'
);
unlink( $site_url_tool_state_file );
unlink( $state_symlink_target );

$valid_urls = array(
	'public URL'       => array( 'https://example.com/', 'https://example.com' ),
	'local URL'        => array( 'http://localhost:8080/wordpress/', 'http://localhost:8080/wordpress' ),
	'private IPv4 URL' => array( 'http://192.168.1.20:8080', 'http://192.168.1.20:8080' ),
	'IPv6 URL'         => array( 'https://[::1]:8443/wp', 'https://[::1]:8443/wp' ),
);
foreach ( $valid_urls as $label => $case ) {
	site_url_tool_assert_same(
		$case[1],
		sqlite_wordpress_site_url_tool_validate_url( $case[0], 'URL' ),
		$label
	);
}

$invalid_urls = array(
	'relative URL'       => '/wordpress',
	'non-HTTP scheme'    => 'javascript:alert(1)',
	'embedded username'  => 'https://user@example.com',
	'embedded password'  => 'https://user:pass@example.com',
	'query string'       => 'https://example.com/?redirect=elsewhere',
	'fragment'           => 'https://example.com/#settings',
	'invalid hostname'   => 'https://example%2ecom',
	'invalid IPv6 host'  => 'https://[not-ipv6]/',
	'whitespace'         => 'https://example.com/a b',
	'backslash'          => 'https://example.com\\admin',
	'dot path segment'   => 'https://example.com/a/../admin',
	'non-string payload' => array( 'https://example.com' ),
);
foreach ( $invalid_urls as $label => $url ) {
	site_url_tool_assert_throws(
		function () use ( $url ) {
			sqlite_wordpress_site_url_tool_validate_url( $url, 'URL' );
		},
		InvalidArgumentException::class,
		$label . ' is rejected'
	);
}

$site_url_tool_options        = array();
$site_url_tool_update_failure = null;
$site_url_tool_cache_deletes  = array();

/**
 * @param string $name Option name.
 * @return mixed Option value.
 */
function get_option( $name ) {
	global $site_url_tool_options;
	return isset( $site_url_tool_options[ $name ] ) ? $site_url_tool_options[ $name ] : false;
}

/**
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 * @return bool Whether the option changed.
 */
function update_option( $name, $value ) {
	global $site_url_tool_options, $site_url_tool_update_failure;
	if ( $site_url_tool_update_failure === $name ) {
		return false;
	}
	if ( isset( $site_url_tool_options[ $name ] ) && $site_url_tool_options[ $name ] === $value ) {
		return false;
	}
	$site_url_tool_options[ $name ] = $value;
	return true;
}

/**
 * @param string $key   Cache key.
 * @param string $group Cache group.
 * @return bool Always true for the fixture.
 */
function wp_cache_delete( $key, $group = '' ) {
	global $site_url_tool_cache_deletes;
	$site_url_tool_cache_deletes[] = $group . ':' . $key;
	return true;
}

/**
 * Minimal transactional driver fixture.
 */
class Site_URL_Tool_Fake_Driver {
	/** @var array<string,string> */
	private $snapshot = array();

	/** @var int */
	public $commits = 0;

	/** @var int */
	public $rollbacks = 0;

	/** @return void */
	public function beginTransaction() {
		global $site_url_tool_options;
		$this->snapshot = $site_url_tool_options;
	}

	/** @return void */
	public function commit() {
		++$this->commits;
		$this->snapshot = array();
	}

	/** @return void */
	public function rollBack() {
		global $site_url_tool_options;
		++$this->rollbacks;
		$site_url_tool_options = $this->snapshot;
		$this->snapshot        = array();
	}
}

/**
 * Minimal wpdb fixture exposing the SQLite v3 driver API.
 */
class Site_URL_Tool_Fake_WPDB {
	/** @var Site_URL_Tool_Fake_Driver */
	private $driver;

	/**
	 * @param Site_URL_Tool_Fake_Driver $driver Transactional driver.
	 */
	public function __construct( $driver ) {
		$this->driver = $driver;
	}

	/** @return Site_URL_Tool_Fake_Driver */
	public function get_driver() {
		return $this->driver;
	}
}

$site_url_tool_options = array(
	'siteurl' => 'https://old.example/wordpress',
	'home'    => 'https://old.example',
);
$driver                = new Site_URL_Tool_Fake_Driver();
$wpdb                  = new Site_URL_Tool_Fake_WPDB( $driver );
$previous              = sqlite_wordpress_site_url_tool_update_options( 'https://new.example/wordpress', 'https://new.example' );
site_url_tool_assert_same(
	array( 'siteurl' => 'https://old.example/wordpress', 'home' => 'https://old.example' ),
	$previous,
	'previous values are returned'
);
site_url_tool_assert_same( 'https://new.example/wordpress', $site_url_tool_options['siteurl'], 'siteurl is updated' );
site_url_tool_assert_same( 'https://new.example', $site_url_tool_options['home'], 'home is updated' );
site_url_tool_assert_same( 1, $driver->commits, 'both updates are committed once' );
site_url_tool_assert_same( 0, $driver->rollbacks, 'successful update is not rolled back' );

$site_url_tool_options = array(
	'siteurl' => 'https://old.example/wordpress',
	'home'    => 'https://old.example',
);
$site_url_tool_update_failure = 'home';
$site_url_tool_cache_deletes  = array();
$driver                       = new Site_URL_Tool_Fake_Driver();
$wpdb                         = new Site_URL_Tool_Fake_WPDB( $driver );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_update_options( 'https://new.example/wordpress', 'https://new.example' );
	},
	RuntimeException::class,
	'partial update is rejected'
);
site_url_tool_assert_same( 'https://old.example/wordpress', $site_url_tool_options['siteurl'], 'siteurl rolls back when home fails' );
site_url_tool_assert_same( 'https://old.example', $site_url_tool_options['home'], 'home remains unchanged after rollback' );
site_url_tool_assert_same( 0, $driver->commits, 'failed update is not committed' );
site_url_tool_assert_same( 1, $driver->rollbacks, 'failed update is rolled back once' );
site_url_tool_assert_same(
	array( 'options:siteurl', 'options:home', 'options:alloptions' ),
	$site_url_tool_cache_deletes,
	'option caches are cleared after rollback'
);

if ( file_exists( $site_url_tool_state_file ) || is_link( $site_url_tool_state_file ) ) {
	unlink( $site_url_tool_state_file );
}
$site_url_tool_lock_file = $site_url_tool_state_file . '.lock';
if ( file_exists( $site_url_tool_lock_file ) || is_link( $site_url_tool_lock_file ) ) {
	unlink( $site_url_tool_lock_file );
}
fwrite( STDOUT, "tool-update-site-url tests passed\n" );
