<?php
/**
 * Regression tests for the standalone WordPress user password reset tool.
 */

$state_file = tempnam( sys_get_temp_dir(), 'user-password-state-' );
if ( false === $state_file ) {
	fwrite( STDERR, "FAIL: could not create state fixture\n" );
	exit( 1 );
}
unlink( $state_file );

define( 'SQLITE_WORDPRESS_SITE_URL_TOOL_STATE_FILE', $state_file );
define( 'SQLITE_WORDPRESS_USER_PASSWORD_TOOL_TESTING', true );
require dirname( __DIR__ ) . '/tool-reset-user-password.php';

/**
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Assertion label.
 * @return void
 */
function user_password_tool_assert_same( $expected, $actual, $label ) {
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
function user_password_tool_assert_throws( $callback, $exception, $label ) {
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

foreach ( array( '', '0', '1', 'yes', 'TRUE', 'true ' ) as $disabled_value ) {
	putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED=' . $disabled_value );
	user_password_tool_assert_same( false, sqlite_wordpress_user_password_tool_is_enabled(), 'non-exact enable value remains disabled' );
}
putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED=true' );
user_password_tool_assert_same( true, sqlite_wordpress_user_password_tool_is_enabled(), 'exact lowercase true enables the tool' );

putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE' );
putenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD' );
putenv( 'SQLITE_WORDPRESS_USER_PASSWORD_RESET_TOKEN_RESOLVED' );
user_password_tool_assert_same( null, sqlite_wordpress_user_password_tool_configured_credential(), 'enabled tool still requires a credential' );

$strong_password = str_repeat( 'p', 24 );
putenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD=' . $strong_password );
user_password_tool_assert_same( $strong_password, sqlite_wordpress_user_password_tool_configured_credential(), 'strong direct recovery password is accepted' );

$token_file = tempnam( sys_get_temp_dir(), 'user-password-token-' );
if ( false === $token_file ) {
	fwrite( STDERR, "FAIL: could not create token fixture\n" );
	exit( 1 );
}
file_put_contents( $token_file, str_repeat( 't', 64 ) . "\n" );
putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE=' . $token_file );
user_password_tool_assert_throws(
	function () {
		sqlite_wordpress_user_password_tool_configured_credential();
	},
	RuntimeException::class,
	'direct password and token file are mutually exclusive'
);
putenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD' );
user_password_tool_assert_same( str_repeat( 't', 64 ), sqlite_wordpress_user_password_tool_configured_credential(), 'file-backed token is accepted' );

putenv( 'SQLITE_WORDPRESS_USER_PASSWORD_RESET_TOKEN_RESOLVED=' . str_repeat( 'r', 64 ) );
user_password_tool_assert_same( str_repeat( 'r', 64 ), sqlite_wordpress_user_password_tool_configured_credential(), 'entrypoint-resolved token is accepted' );
putenv( 'SQLITE_WORDPRESS_USER_PASSWORD_RESET_TOKEN_RESOLVED' );
putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE' );

user_password_tool_assert_same( 42, sqlite_wordpress_user_password_tool_validate_user_id( '42' ), 'numeric user id is accepted' );
foreach ( array( '', '0', '-1', '1.5', 'user' ) as $invalid_user_id ) {
	user_password_tool_assert_throws(
		function () use ( $invalid_user_id ) {
			sqlite_wordpress_user_password_tool_validate_user_id( $invalid_user_id );
		},
		InvalidArgumentException::class,
		'invalid user id is rejected'
	);
}

$new_password = 'correct horse battery staple';
user_password_tool_assert_same(
	$new_password,
	sqlite_wordpress_user_password_tool_validate_password( $new_password, $new_password ),
	'matching strong password is accepted'
);
user_password_tool_assert_throws(
	function () {
		sqlite_wordpress_user_password_tool_validate_password( 'too-short', 'too-short' );
	},
	InvalidArgumentException::class,
	'short account password is rejected'
);
user_password_tool_assert_throws(
	function () use ( $new_password ) {
		sqlite_wordpress_user_password_tool_validate_password( $new_password, $new_password . 'x' );
	},
	InvalidArgumentException::class,
	'mismatched account password is rejected'
);

user_password_tool_assert_same(
	array( 'status' => 'ready', 'retry_after' => 0 ),
	sqlite_wordpress_site_url_tool_availability( 1000000 ),
	'password tool has an independent ready authorization state'
);
$authorization = sqlite_wordpress_site_url_tool_begin_operation( $strong_password, $strong_password, 1000001 );
user_password_tool_assert_same( 'accepted', $authorization['status'], 'correct credential reserves one password reset' );
putenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD=' . $strong_password );
sqlite_wordpress_user_password_tool_consume_operation( $authorization['operation_id'], 1000001 );
user_password_tool_assert_same( 'false', getenv( 'WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED' ), 'consumed authorization disables the current worker' );
user_password_tool_assert_same( false, getenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD' ), 'consumed authorization clears the current worker credential' );
user_password_tool_assert_same(
	array( 'status' => 'used', 'retry_after' => 0 ),
	sqlite_wordpress_site_url_tool_availability( 1000002 ),
	'consumed password reset remains locked'
);

foreach ( array( $state_file, $state_file . '.lock', $token_file ) as $fixture ) {
	if ( file_exists( $fixture ) || is_link( $fixture ) ) {
		unlink( $fixture );
	}
}
fwrite( STDOUT, "user password reset tool tests passed\n" );
