<?php
/**
 * Built-image smoke test for user listing, password reset, and one-shot lock.
 */

/**
 * @param bool   $condition Condition that must be true.
 * @param string $message   Failure description.
 * @return void
 */
function sqlite_wordpress_user_password_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

/**
 * @param string $method       HTTP method.
 * @param string $credential   Recovery credential.
 * @param string $user_id      Selected user id.
 * @param string $password     New password.
 * @param string $confirmation Password confirmation.
 * @return array{status:int,body:string} Response snapshot.
 */
function sqlite_wordpress_user_password_smoke_request( $method, $credential = '', $user_id = '', $password = '', $confirmation = '' ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_POST                     = array();
	if ( 'POST' === $method ) {
		$_POST = array(
			'recovery_token'  => $credential,
			'user_id'         => $user_id,
			'new_password'    => $password,
			'confirm_password' => $confirmation,
		);
	}
	http_response_code( 200 );
	ob_start();
	sqlite_wordpress_user_password_tool_main();
	$body = ob_get_clean();
	return array( 'status' => http_response_code(), 'body' => false === $body ? '' : $body );
}

require '/var/www/html/wp-load.php';
$old_password = 'old generated password 123';
$new_password = 'new generated password 456';
$user_id      = wp_insert_user(
	array(
		'user_login'   => 'recovery-smoke-user',
		'display_name' => 'Recovery Smoke User',
		'user_pass'    => $old_password,
	)
);
sqlite_wordpress_user_password_smoke_assert( ! is_wp_error( $user_id ), 'password-reset fixture user is created' );

$credential = str_repeat( 'p', 24 );
$state_file = '/var/www/html/wp-content/database/.ht.user-password-reset-tool-state';
$lock_file  = $state_file . '.lock';
foreach ( array( $state_file, $lock_file ) as $recovery_file ) {
	if ( is_link( $recovery_file ) || ( file_exists( $recovery_file ) && ! unlink( $recovery_file ) ) ) {
		fwrite( STDERR, "FAIL: could not reset password recovery state fixture\n" );
		exit( 1 );
	}
}

putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED=true' );
putenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD=' . $credential );
define( 'SQLITE_WORDPRESS_USER_PASSWORD_TOOL_TESTING', true );
require '/var/www/html/tool-reset-user-password.php';

$response = sqlite_wordpress_user_password_smoke_request( 'GET' );
sqlite_wordpress_user_password_smoke_assert( 200 === $response['status'], 'enabled password reset form loads' );
sqlite_wordpress_user_password_smoke_assert( false !== strpos( $response['body'], 'recovery-smoke-user' ), 'user dropdown includes the fixture login' );
sqlite_wordpress_user_password_smoke_assert( false === strpos( $response['body'], $old_password ), 'rendered form does not expose the old password' );

$response = sqlite_wordpress_user_password_smoke_request( 'POST', $credential, (string) $user_id, $new_password, $new_password );
sqlite_wordpress_user_password_smoke_assert( 200 === $response['status'], 'valid password reset request succeeds' );
sqlite_wordpress_user_password_smoke_assert( false !== strpos( $response['body'], 'User Password Reset' ), 'password reset success page is rendered' );
$updated_user = get_userdata( $user_id );
sqlite_wordpress_user_password_smoke_assert( $updated_user instanceof WP_User, 'updated user remains available' );
sqlite_wordpress_user_password_smoke_assert( wp_check_password( $new_password, $updated_user->user_pass, $user_id ), 'new password is stored' );
sqlite_wordpress_user_password_smoke_assert( ! wp_check_password( $old_password, $updated_user->user_pass, $user_id ), 'old password no longer works' );
sqlite_wordpress_user_password_smoke_assert( file_exists( $state_file ), 'password reset one-shot state is created' );

putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED=true' );
putenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD=' . $credential );
$response = sqlite_wordpress_user_password_smoke_request( 'GET' );
sqlite_wordpress_user_password_smoke_assert( 404 === $response['status'], 'fresh worker configuration cannot reopen a used password reset authorization' );
sqlite_wordpress_user_password_smoke_assert( 'Not Found' === $response['body'], 'used password reset endpoint fails closed' );

fwrite( STDOUT, "user password reset image smoke passed\n" );
