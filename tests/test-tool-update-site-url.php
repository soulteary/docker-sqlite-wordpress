<?php
/**
 * Regression tests for the standalone site URL recovery tool.
 */

define( 'SQLITE_WORDPRESS_SITE_URL_TOOL_TESTING', true );
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
putenv( 'SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED' );
site_url_tool_assert_same( null, sqlite_wordpress_site_url_tool_configured_token(), 'tool disabled without a token' );

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN=too-short' );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_configured_token();
	},
	RuntimeException::class,
	'weak direct token is rejected'
);

$strong_token = str_repeat( 'a', 64 );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN=' . $strong_token );
site_url_tool_assert_same( $strong_token, sqlite_wordpress_site_url_tool_configured_token(), 'strong direct token is accepted' );

$token_file = tempnam( sys_get_temp_dir(), 'site-url-token-' );
if ( false === $token_file ) {
	fwrite( STDERR, "FAIL: could not create token fixture\n" );
	exit( 1 );
}
file_put_contents( $token_file, str_repeat( 'b', 64 ) . "\n" );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE=' . $token_file );
site_url_tool_assert_same( str_repeat( 'b', 64 ), sqlite_wordpress_site_url_tool_configured_token(), 'Docker secret token is accepted' );

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN=' . $strong_token );
site_url_tool_assert_throws(
	function () {
		sqlite_wordpress_site_url_tool_configured_token();
	},
	RuntimeException::class,
	'ambiguous token sources are rejected'
);
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE' );
unlink( $token_file );

putenv( 'SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED=' . str_repeat( 'c', 64 ) );
site_url_tool_assert_same( str_repeat( 'c', 64 ), sqlite_wordpress_site_url_tool_configured_token(), 'entrypoint-resolved secret is accepted' );
putenv( 'SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED' );

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

fwrite( STDOUT, "tool-update-site-url tests passed\n" );
