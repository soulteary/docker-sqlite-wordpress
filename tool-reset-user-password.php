<?php
/**
 * Emergency, credential-protected WordPress user password reset tool.
 *
 * The endpoint is disabled unless WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED
 * is exactly `true` and one strong recovery credential is configured. It uses
 * the same persistent throttle and one-shot authorization implementation as
 * the site URL recovery endpoint, but keeps an independent state file.
 *
 * @package docker-sqlite-wordpress
 */

// Reuse the hardened recovery authorization implementation without running the
// site URL endpoint. Each HTTP request has an isolated PHP execution context.
if ( ! defined( 'SQLITE_WORDPRESS_SITE_URL_TOOL_TESTING' ) ) {
	define( 'SQLITE_WORDPRESS_SITE_URL_TOOL_TESTING', true );
}
if ( ! defined( 'SQLITE_WORDPRESS_SITE_URL_TOOL_STATE_FILE' ) ) {
	define( 'SQLITE_WORDPRESS_SITE_URL_TOOL_STATE_FILE', __DIR__ . '/wp-content/database/.ht.user-password-reset-tool-state' );
}
require_once __DIR__ . '/tool-update-site-url.php';

/**
 * Checks the explicit, fail-closed enable switch.
 *
 * @return bool Whether the password-reset endpoint is explicitly enabled.
 */
function sqlite_wordpress_user_password_tool_is_enabled() {
	$enabled = getenv( 'WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED' );
	return false !== $enabled && hash_equals( 'true', $enabled );
}

/**
 * Reads and validates the configured recovery credential.
 *
 * @return string|null Configured credential, or null when none is configured.
 * @throws RuntimeException When the credential configuration is invalid.
 */
function sqlite_wordpress_user_password_tool_configured_credential() {
	$token_file   = getenv( 'WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE' );
	$password     = getenv( 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD' );
	$resolved     = getenv( 'SQLITE_WORDPRESS_USER_PASSWORD_RESET_TOKEN_RESOLVED' );
	$file_set     = false !== $token_file && '' !== trim( $token_file );
	$password_set = false !== $password && '' !== trim( $password );
	$resolved_set = false !== $resolved && '' !== trim( $resolved );

	if ( $file_set && $password_set ) {
		throw new RuntimeException( 'Set only one user password reset credential source.' );
	}
	if ( $resolved_set && ! $file_set ) {
		throw new RuntimeException( 'The internally resolved token requires a configured token file.' );
	}
	if ( ! $file_set && ! $password_set ) {
		return null;
	}

	$minimum_length  = 32;
	$credential_name = 'token';
	if ( $resolved_set ) {
		$credential = $resolved;
	} elseif ( $file_set ) {
		$token_file = trim( $token_file );
		if ( ! is_file( $token_file ) || ! is_readable( $token_file ) ) {
			throw new RuntimeException( 'The configured user password reset token file is not readable.' );
		}
		$credential = file_get_contents( $token_file );
		if ( false === $credential ) {
			throw new RuntimeException( 'The configured user password reset token file could not be read.' );
		}
	} else {
		$credential      = $password;
		$minimum_length  = 24;
		$credential_name = 'password';
	}

	$credential = trim( (string) $credential );
	if ( strlen( $credential ) < $minimum_length || strlen( $credential ) > 1024 ) {
		throw new RuntimeException( sprintf( 'The user password reset %s must contain between %d and 1024 characters.', $credential_name, $minimum_length ) );
	}
	if ( preg_match( '/[\x00-\x1F\x7F]/', $credential ) ) {
		throw new RuntimeException( 'The user password reset credential must not contain control characters.' );
	}

	return $credential;
}

/**
 * Removes password-reset configuration from the current PHP worker.
 *
 * @return void
 */
function sqlite_wordpress_user_password_tool_disable_runtime_environment() {
	putenv( 'WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED=false' );
	$_ENV['WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED']    = 'false';
	$_SERVER['WORDPRESS_USER_PASSWORD_RESET_TOOL_ENABLED'] = 'false';

	foreach ( array( 'WORDPRESS_USER_PASSWORD_RESET_TOKEN_FILE', 'WORDPRESS_USER_PASSWORD_RESET_PASSWORD', 'SQLITE_WORDPRESS_USER_PASSWORD_RESET_TOKEN_RESOLVED' ) as $credential_name ) {
		putenv( $credential_name );
		unset( $_ENV[ $credential_name ], $_SERVER[ $credential_name ] );
	}
}

/**
 * Consumes the shared one-shot authorization and disables this endpoint.
 *
 * @param string   $operation_id Reserved operation id.
 * @param int|null $now          Optional Unix timestamp for tests.
 * @return void
 */
function sqlite_wordpress_user_password_tool_consume_operation( $operation_id, $now = null ) {
	sqlite_wordpress_site_url_tool_consume_operation( $operation_id, $now );
	sqlite_wordpress_user_password_tool_disable_runtime_environment();
}

/**
 * Validates a submitted WordPress user id.
 *
 * @param mixed $value Submitted value.
 * @return int Validated user id.
 * @throws InvalidArgumentException When the id is invalid.
 */
function sqlite_wordpress_user_password_tool_validate_user_id( $value ) {
	if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 20 || ! ctype_digit( $value ) ) {
		throw new InvalidArgumentException( 'Select a valid WordPress user.' );
	}
	$user_id = (int) $value;
	if ( $user_id < 1 || (string) $user_id !== ltrim( $value, '0' ) ) {
		throw new InvalidArgumentException( 'Select a valid WordPress user.' );
	}

	return $user_id;
}

/**
 * Validates a new account password and its confirmation.
 *
 * @param mixed $password     New password.
 * @param mixed $confirmation Confirmation value.
 * @return string Validated password.
 * @throws InvalidArgumentException When the password is invalid.
 */
function sqlite_wordpress_user_password_tool_validate_password( $password, $confirmation ) {
	if ( ! is_string( $password ) || ! is_string( $confirmation ) ) {
		throw new InvalidArgumentException( 'Enter and confirm the new password.' );
	}
	if ( strlen( $password ) < 12 || strlen( $password ) > 4096 ) {
		throw new InvalidArgumentException( 'The new password must contain between 12 and 4096 characters.' );
	}
	if ( 1 !== preg_match( '//u', $password ) || false !== strpos( $password, "\0" ) ) {
		throw new InvalidArgumentException( 'The new password must be valid UTF-8 and must not contain a null byte.' );
	}
	if ( ! hash_equals( $password, $confirmation ) ) {
		throw new InvalidArgumentException( 'The new password and confirmation do not match.' );
	}

	return $password;
}

/**
 * Returns the selectable users without exposing email addresses or hashes.
 *
 * @return array<int,object> User records.
 */
function sqlite_wordpress_user_password_tool_users() {
	$users = get_users(
		array(
			'orderby' => 'user_login',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'user_login', 'display_name' ),
		)
	);

	return is_array( $users ) ? $users : array();
}

/**
 * Renders the standalone password reset page.
 *
 * @param string            $title            Page heading.
 * @param string            $message          Status text.
 * @param string            $status           info, error, or success.
 * @param array<int,object> $users            Selectable users.
 * @param int               $selected_user_id Selected user id.
 * @param bool              $show_form        Whether to render the form.
 * @param string            $reset_user_login Successfully reset login name.
 * @return void
 */
function sqlite_wordpress_user_password_tool_render( $title, $message, $status = 'info', $users = array(), $selected_user_id = 0, $show_form = true, $reset_user_login = '' ) {
	$status_class = in_array( $status, array( 'info', 'error', 'success' ), true ) ? $status : 'info';
	?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo sqlite_wordpress_site_url_tool_escape( $title ); ?></title>
	<style>
		:root { color-scheme: light dark; font-family: system-ui, sans-serif; }
		body { margin: 0; background: Canvas; color: CanvasText; }
		main { max-width: 680px; margin: 8vh auto; padding: 0 24px; }
		section { border: 1px solid color-mix(in srgb, CanvasText 22%, transparent); border-radius: 12px; padding: 24px; }
		h1 { margin-top: 0; font-size: 1.6rem; }
		.notice { border-left: 4px solid #3858e9; padding: 10px 12px; margin: 18px 0; background: color-mix(in srgb, #3858e9 10%, Canvas); }
		.notice.error { border-left-color: #d63638; background: color-mix(in srgb, #d63638 10%, Canvas); }
		.notice.success { border-left-color: #008a20; background: color-mix(in srgb, #008a20 10%, Canvas); }
		label { display: block; font-weight: 650; margin-top: 18px; }
		input, select { box-sizing: border-box; display: block; width: 100%; margin-top: 6px; padding: 10px; border: 1px solid color-mix(in srgb, CanvasText 35%, transparent); border-radius: 5px; font: inherit; background: Canvas; color: CanvasText; }
		small { display: block; margin-top: 5px; opacity: .75; }
		button { margin-top: 22px; padding: 10px 16px; border: 0; border-radius: 5px; background: #3858e9; color: white; font: inherit; font-weight: 650; cursor: pointer; }
		code { overflow-wrap: anywhere; }
	</style>
</head>
<body>
	<main>
		<section>
			<h1><?php echo sqlite_wordpress_site_url_tool_escape( $title ); ?></h1>
			<div class="notice <?php echo sqlite_wordpress_site_url_tool_escape( $status_class ); ?>"><?php echo sqlite_wordpress_site_url_tool_escape( $message ); ?></div>
			<?php if ( $show_form ) : ?>
				<form method="post" autocomplete="off">
					<label for="recovery-token">Recovery Token or Password</label>
					<input id="recovery-token" name="recovery_token" type="password" minlength="24" maxlength="1024" required autocomplete="off">
					<small>The recovery credential is sent only in the POST body.</small>

					<label for="user-id">WordPress User</label>
					<select id="user-id" name="user_id" required>
						<option value="">Select a user</option>
						<?php foreach ( $users as $user ) : ?>
							<?php $label = (string) $user->user_login . ( '' !== (string) $user->display_name ? ' — ' . (string) $user->display_name : '' ); ?>
							<option value="<?php echo (int) $user->ID; ?>"<?php echo (int) $user->ID === $selected_user_id ? ' selected' : ''; ?>><?php echo sqlite_wordpress_site_url_tool_escape( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<small>The list shows every user in this single-site WordPress installation.</small>

					<label for="new-password">New Password</label>
					<input id="new-password" name="new_password" type="password" minlength="12" maxlength="4096" required autocomplete="new-password">
					<small>Use at least 12 characters. A longer generated password is recommended.</small>

					<label for="confirm-password">Confirm New Password</label>
					<input id="confirm-password" name="confirm_password" type="password" minlength="12" maxlength="4096" required autocomplete="new-password">

					<button type="submit">Reset user password</button>
				</form>
			<?php elseif ( 'success' === $status_class ) : ?>
				<p><strong>User:</strong> <code><?php echo sqlite_wordpress_site_url_tool_escape( $reset_user_login ); ?></code></p>
				<p>The one-time authorization is now locked. Remove the enable switch and recovery credential, then recreate the container.</p>
			<?php endif; ?>
		</section>
	</main>
</body>
</html>
	<?php
}

/**
 * Updates and verifies a password in one SQLite transaction.
 *
 * @param int    $user_id  WordPress user id.
 * @param string $password New password.
 * @return void
 * @throws RuntimeException When the password cannot be updated and verified.
 */
function sqlite_wordpress_user_password_tool_update_password( $user_id, $password ) {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_driver' ) ) {
		throw new RuntimeException( 'The active WordPress database is not the supported SQLite driver.' );
	}
	$driver = $wpdb->get_driver();
	if ( ! is_object( $driver ) || ! method_exists( $driver, 'beginTransaction' ) || ! method_exists( $driver, 'commit' ) || ! method_exists( $driver, 'rollBack' ) ) {
		throw new RuntimeException( 'The active SQLite driver does not expose transaction support.' );
	}

	$in_transaction = false;
	try {
		$driver->beginTransaction();
		$in_transaction = true;
		wp_set_password( $password, $user_id );

		$stored_hash = $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $user_id ) );
		if ( ! is_string( $stored_hash ) || '' === $stored_hash || ! wp_check_password( $password, $stored_hash, $user_id ) ) {
			throw new RuntimeException( 'The updated password could not be verified.' );
		}

		$driver->commit();
		$in_transaction = false;
	} catch ( Throwable $error ) {
		if ( $in_transaction ) {
			try {
				$driver->rollBack();
			} catch ( Throwable $rollback_error ) {
				error_log( 'user password reset rollback failed: ' . $rollback_error->getMessage() );
			}
		}
		clean_user_cache( $user_id );
		throw new RuntimeException( 'The WordPress user password was not reset.', 0, $error );
	}
}

/**
 * Loads WordPress for user enumeration and password updates.
 *
 * @return bool Whether WordPress was loaded successfully.
 */
function sqlite_wordpress_user_password_tool_load_wordpress() {
	$wp_load = __DIR__ . '/wp-load.php';
	if ( ! is_file( $wp_load ) ) {
		return false;
	}
	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}
	require_once $wp_load;
	return true;
}

/**
 * Handles the standalone password-reset request.
 *
 * @return void
 */
function sqlite_wordpress_user_password_tool_main() {
	sqlite_wordpress_site_url_tool_send_headers();
	if ( ! sqlite_wordpress_user_password_tool_is_enabled() ) {
		http_response_code( 404 );
		echo 'Not Found';
		return;
	}

	try {
		$configured_credential = sqlite_wordpress_user_password_tool_configured_credential();
	} catch ( RuntimeException $error ) {
		error_log( 'user password reset configuration error: ' . $error->getMessage() );
		http_response_code( 503 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset Unavailable', 'The recovery credential configuration is invalid. Check the container logs.', 'error', array(), 0, false );
		return;
	}
	if ( null === $configured_credential ) {
		http_response_code( 404 );
		echo 'Not Found';
		return;
	}

	try {
		$availability = sqlite_wordpress_site_url_tool_availability();
	} catch ( RuntimeException $error ) {
		error_log( 'user password reset state error: ' . $error->getMessage() );
		http_response_code( 503 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset Unavailable', 'The recovery authorization state is unavailable. Check the container logs.', 'error', array(), 0, false );
		return;
	}
	if ( 'used' === $availability['status'] ) {
		http_response_code( 404 );
		echo 'Not Found';
		return;
	}
	if ( 'locked' === $availability['status'] ) {
		header( 'Retry-After: ' . max( 1, (int) $availability['retry_after'] ) );
		http_response_code( 429 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset Temporarily Locked', 'Too many invalid recovery credentials were submitted. Wait 15 minutes before trying again.', 'error', array(), 0, false );
		return;
	}
	if ( 'busy' === $availability['status'] ) {
		header( 'Retry-After: ' . max( 1, (int) $availability['retry_after'] ) );
		http_response_code( 409 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset In Progress', 'Another authenticated password reset request is already in progress.', 'error', array(), 0, false );
		return;
	}

	if ( ! sqlite_wordpress_user_password_tool_load_wordpress() ) {
		http_response_code( 503 );
		sqlite_wordpress_user_password_tool_render( 'WordPress Unavailable', 'wp-load.php was not found in the document root.', 'error', array(), 0, false );
		return;
	}
	if ( is_multisite() ) {
		http_response_code( 409 );
		sqlite_wordpress_user_password_tool_render( 'Multisite Not Supported', 'This recovery tool only resets users in single-site installations.', 'error', array(), 0, false );
		return;
	}

	$users = sqlite_wordpress_user_password_tool_users();
	if ( empty( $users ) ) {
		http_response_code( 409 );
		sqlite_wordpress_user_password_tool_render( 'No Users Available', 'No WordPress users are available to reset.', 'error', array(), 0, false );
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'GET' === $method ) {
		sqlite_wordpress_user_password_tool_render( 'WordPress User Password Reset', 'Select a user, enter a new password, and provide the configured recovery credential.', 'info', $users );
		return;
	}
	if ( 'POST' !== $method ) {
		header( 'Allow: GET, POST' );
		http_response_code( 405 );
		sqlite_wordpress_user_password_tool_render( 'Method Not Allowed', 'Use GET to open this page and POST to submit it.', 'error', array(), 0, false );
		return;
	}

	$provided_credential = isset( $_POST['recovery_token'] ) && is_string( $_POST['recovery_token'] ) ? $_POST['recovery_token'] : '';
	try {
		$authorization = sqlite_wordpress_site_url_tool_begin_operation( $configured_credential, $provided_credential );
	} catch ( RuntimeException $error ) {
		error_log( 'user password reset state error: ' . $error->getMessage() );
		http_response_code( 503 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset Unavailable', 'The recovery authorization state could not be updated. Check the container logs.', 'error', array(), 0, false );
		return;
	}
	if ( 'used' === $authorization['status'] ) {
		http_response_code( 404 );
		echo 'Not Found';
		return;
	}
	if ( 'locked' === $authorization['status'] ) {
		error_log( 'user password reset authentication locked after repeated failures.' );
		header( 'Retry-After: ' . max( 1, (int) $authorization['retry_after'] ) );
		http_response_code( 429 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset Temporarily Locked', 'Too many invalid recovery credentials were submitted. Wait 15 minutes before trying again.', 'error', array(), 0, false );
		return;
	}
	if ( 'busy' === $authorization['status'] ) {
		header( 'Retry-After: ' . max( 1, (int) $authorization['retry_after'] ) );
		http_response_code( 409 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset In Progress', 'Another authenticated password reset request is already in progress.', 'error', array(), 0, false );
		return;
	}
	$selected_user_id = isset( $_POST['user_id'] ) && is_string( $_POST['user_id'] ) && ctype_digit( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	if ( 'accepted' !== $authorization['status'] ) {
		error_log( 'user password reset authentication failed.' );
		http_response_code( 403 );
		sqlite_wordpress_user_password_tool_render( 'Access Denied', 'The recovery credential is invalid.', 'error', $users, $selected_user_id );
		return;
	}
	$operation_id = (string) $authorization['operation_id'];

	try {
		$user_id  = sqlite_wordpress_user_password_tool_validate_user_id( isset( $_POST['user_id'] ) ? $_POST['user_id'] : '' );
		$password = sqlite_wordpress_user_password_tool_validate_password(
			isset( $_POST['new_password'] ) ? $_POST['new_password'] : '',
			isset( $_POST['confirm_password'] ) ? $_POST['confirm_password'] : ''
		);
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			throw new InvalidArgumentException( 'The selected WordPress user no longer exists.' );
		}
	} catch ( InvalidArgumentException $error ) {
		sqlite_wordpress_site_url_tool_cancel_operation_safely( $operation_id );
		http_response_code( 422 );
		sqlite_wordpress_user_password_tool_render( 'Invalid Password Reset', $error->getMessage(), 'error', $users, $selected_user_id );
		return;
	}

	try {
		sqlite_wordpress_user_password_tool_consume_operation( $operation_id );
		$operation_id = '';
		sqlite_wordpress_user_password_tool_update_password( $user_id, $password );
	} catch ( Throwable $error ) {
		sqlite_wordpress_site_url_tool_cancel_operation_safely( $operation_id );
		error_log( 'user password reset failed: ' . $error->getMessage() );
		http_response_code( 500 );
		sqlite_wordpress_user_password_tool_render( 'Password Reset Failed', 'The password could not be reset. The one-time authorization is locked; check the container logs before deliberately re-enabling the tool.', 'error', array(), 0, false );
		return;
	}

	sqlite_wordpress_user_password_tool_render( 'User Password Reset', 'The WordPress user password was reset successfully and existing login sessions were invalidated. The recovery endpoint has automatically locked.', 'success', array(), 0, false, (string) $user->user_login );
}

if ( ! defined( 'SQLITE_WORDPRESS_USER_PASSWORD_TOOL_TESTING' ) ) {
	sqlite_wordpress_user_password_tool_main();
}
