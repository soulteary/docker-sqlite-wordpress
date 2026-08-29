<?php
/**
 * Emergency, credential-protected updater for the WordPress site URLs.
 *
 * This file intentionally lives in the document root instead of inside
 * WordPress. It remains reachable when an incorrect `home` or `siteurl` option
 * makes the normal site and wp-admin inaccessible. The endpoint is disabled
 * unless WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED is exactly `true` and
 * WORDPRESS_SITE_URL_UPDATE_TOKEN, WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE, or
 * WORDPRESS_SITE_URL_UPDATE_PASSWORD supplies one strong credential.
 *
 * @package docker-sqlite-wordpress
 */

/**
 * Sends headers suitable for a credential-bearing recovery page.
 *
 * @return void
 */
function sqlite_wordpress_site_url_tool_send_headers() {
	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: DENY' );
}

/**
 * Escapes a value for HTML output without requiring WordPress to be loaded.
 *
 * @param string $value Raw value.
 * @return string Escaped value.
 */
function sqlite_wordpress_site_url_tool_escape( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Checks the explicit, fail-closed recovery-tool enable switch.
 *
 * Only the exact lowercase value `true` enables the endpoint. Values such as
 * `1`, `yes`, or `TRUE` remain disabled so a loosely parsed environment value
 * cannot expose the recovery form accidentally.
 *
 * @return bool Whether the recovery endpoint is explicitly enabled.
 */
function sqlite_wordpress_site_url_tool_is_enabled() {
	$enabled = getenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED' );
	return false !== $enabled && hash_equals( 'true', $enabled );
}

/**
 * Reads and validates the configured recovery credential.
 *
 * The *_FILE variant follows the Docker secrets convention and is preferred
 * because direct token/password environment variables are visible through
 * container inspection. Credential sources are mutually exclusive.
 *
 * @return string|null Configured credential, or null when none is configured.
 * @throws RuntimeException When the credential configuration is invalid.
 */
function sqlite_wordpress_site_url_tool_configured_credential() {
	$direct_token = getenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN' );
	$token_file   = getenv( 'WORDPRESS_SITE_URL_UPDATE_TOKEN_FILE' );
	$password     = getenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD' );
	$resolved     = getenv( 'SQLITE_WORDPRESS_SITE_URL_UPDATE_TOKEN_RESOLVED' );
	$direct_set   = false !== $direct_token && '' !== trim( $direct_token );
	$file_set     = false !== $token_file && '' !== trim( $token_file );
	$password_set = false !== $password && '' !== trim( $password );
	$resolved_set = false !== $resolved && '' !== trim( $resolved );

	$source_count = ( $direct_set ? 1 : 0 ) + ( $file_set ? 1 : 0 ) + ( $password_set ? 1 : 0 );
	if ( $source_count > 1 ) {
		throw new RuntimeException( 'Set only one site URL update credential source.' );
	}
	if ( $resolved_set && ( $direct_set || $password_set ) ) {
		throw new RuntimeException( 'The resolved token and direct site URL update credentials must not both be set.' );
	}

	if ( 0 === $source_count && ! $resolved_set ) {
		return null;
	}

	$minimum_length  = 32;
	$credential_name = 'token';
	if ( $resolved_set ) {
		$direct_token = $resolved;
	} elseif ( $file_set ) {
		$token_file = trim( $token_file );
		if ( ! is_file( $token_file ) || ! is_readable( $token_file ) ) {
			throw new RuntimeException( 'The configured site URL update token file is not readable.' );
		}

		$direct_token = file_get_contents( $token_file );
		if ( false === $direct_token ) {
			throw new RuntimeException( 'The configured site URL update token file could not be read.' );
		}
	} elseif ( $password_set ) {
		$direct_token    = $password;
		$minimum_length  = 16;
		$credential_name = 'password';
	}

	$credential = trim( (string) $direct_token );
	if ( strlen( $credential ) < $minimum_length || strlen( $credential ) > 1024 ) {
		throw new RuntimeException( sprintf( 'The site URL update %s must contain between %d and 1024 characters.', $credential_name, $minimum_length ) );
	}
	if ( preg_match( '/[\x00-\x1F\x7F]/', $credential ) ) {
		throw new RuntimeException( 'The site URL update credential must not contain control characters.' );
	}

	return $credential;
}

/**
 * Validates and normalizes a WordPress URL setting.
 *
 * Local hostnames and private IP addresses are deliberately accepted because
 * this image is commonly used on local networks. Credentials, query strings,
 * fragments, and non-HTTP schemes are never valid WordPress base addresses.
 *
 * @param mixed  $value Raw submitted value.
 * @param string $label Human-readable field label.
 * @return string Normalized URL without a trailing slash.
 * @throws InvalidArgumentException When the URL is unsafe or malformed.
 */
function sqlite_wordpress_site_url_tool_validate_url( $value, $label ) {
	if ( ! is_string( $value ) ) {
		throw new InvalidArgumentException( $label . ' must be a URL.' );
	}

	$value = trim( $value );
	if ( '' === $value || strlen( $value ) > 2048 ) {
		throw new InvalidArgumentException( $label . ' must contain a URL no longer than 2048 characters.' );
	}
	if ( preg_match( '/[\x00-\x20\x7F\\\\]/', $value ) ) {
		throw new InvalidArgumentException( $label . ' must not contain whitespace, control characters, or backslashes.' );
	}

	try {
		$parts = parse_url( $value );
	} catch ( ValueError $error ) {
		$parts = false;
	}

	if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
		throw new InvalidArgumentException( $label . ' must be an absolute http:// or https:// URL.' );
	}

	$scheme = strtolower( (string) $parts['scheme'] );
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		throw new InvalidArgumentException( $label . ' must use http:// or https://.' );
	}
	$host = (string) $parts['host'];
	if ( '' === $host ) {
		throw new InvalidArgumentException( $label . ' must include a hostname.' );
	}
	if ( '[' === $host[0] ) {
		$ipv6 = ']' === substr( $host, -1 ) ? substr( $host, 1, -1 ) : '';
		if ( false === filter_var( $ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			throw new InvalidArgumentException( $label . ' contains an invalid IPv6 hostname.' );
		}
	} elseif ( strlen( $host ) > 253 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $host ) || false !== strpos( $host, '..' ) ) {
		throw new InvalidArgumentException( $label . ' contains an invalid hostname. Use an ASCII or punycode hostname.' );
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		throw new InvalidArgumentException( $label . ' must not include credentials.' );
	}
	if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
		throw new InvalidArgumentException( $label . ' must not include a query string or fragment.' );
	}
	if ( isset( $parts['port'] ) && ( $parts['port'] < 1 || $parts['port'] > 65535 ) ) {
		throw new InvalidArgumentException( $label . ' contains an invalid port.' );
	}

	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	if ( preg_match( '#(?:^|/)\.\.?(/|$)#', $path ) ) {
		throw new InvalidArgumentException( $label . ' must not contain dot path segments.' );
	}

	return rtrim( $value, '/' );
}

/**
 * Renders the standalone recovery page.
 *
 * @param string $title         Page heading.
 * @param string $message       Status or guidance text.
 * @param string $status        One of info, error, or success.
 * @param string $wordpress_url WordPress Address field value.
 * @param string $site_url      Site Address field value.
 * @param bool   $show_form     Whether to render the update form.
 * @return void
 */
function sqlite_wordpress_site_url_tool_render( $title, $message, $status = 'info', $wordpress_url = '', $site_url = '', $show_form = true ) {
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
		input { box-sizing: border-box; display: block; width: 100%; margin-top: 6px; padding: 10px; border: 1px solid color-mix(in srgb, CanvasText 35%, transparent); border-radius: 5px; font: inherit; background: Canvas; color: CanvasText; }
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
					<input id="recovery-token" name="recovery_token" type="password" minlength="16" maxlength="1024" required autocomplete="off">
					<small>The credential is sent only in the POST body; do not put it in the URL.</small>

					<label for="wordpress-url">WordPress Address (URL)</label>
					<input id="wordpress-url" name="wordpress_url" type="url" maxlength="2048" required placeholder="https://example.com" value="<?php echo sqlite_wordpress_site_url_tool_escape( $wordpress_url ); ?>">
					<small>Updates the <code>siteurl</code> option, where WordPress core files are located.</small>

					<label for="site-url">Site Address (URL)</label>
					<input id="site-url" name="site_url" type="url" maxlength="2048" required placeholder="https://example.com" value="<?php echo sqlite_wordpress_site_url_tool_escape( $site_url ); ?>">
					<small>Updates the <code>home</code> option, the public address visitors use.</small>

					<button type="submit">Update both addresses</button>
				</form>
			<?php elseif ( 'success' === $status_class ) : ?>
				<p><strong>WordPress Address:</strong> <code><?php echo sqlite_wordpress_site_url_tool_escape( $wordpress_url ); ?></code></p>
				<p><strong>Site Address:</strong> <code><?php echo sqlite_wordpress_site_url_tool_escape( $site_url ); ?></code></p>
				<p>Disable the recovery tool now by removing its enable switch and credential configuration, then restart the container.</p>
			<?php endif; ?>
		</section>
	</main>
</body>
</html>
	<?php
}

/**
 * Atomically updates both WordPress URL options through the v3 SQLite driver.
 *
 * @param string $wordpress_url New siteurl option.
 * @param string $site_url      New home option.
 * @return array<string,string> Previous option values.
 * @throws RuntimeException When the transaction cannot be completed.
 */
function sqlite_wordpress_site_url_tool_update_options( $wordpress_url, $site_url ) {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_driver' ) ) {
		throw new RuntimeException( 'The active WordPress database is not the supported SQLite driver.' );
	}

	$driver = $wpdb->get_driver();
	if ( ! is_object( $driver ) || ! method_exists( $driver, 'beginTransaction' ) || ! method_exists( $driver, 'commit' ) || ! method_exists( $driver, 'rollBack' ) ) {
		throw new RuntimeException( 'The active SQLite driver does not expose transaction support.' );
	}

	$previous = array(
		'siteurl' => (string) get_option( 'siteurl' ),
		'home'    => (string) get_option( 'home' ),
	);
	$in_transaction = false;

	try {
		$driver->beginTransaction();
		$in_transaction = true;

		if ( $previous['siteurl'] !== $wordpress_url && ! update_option( 'siteurl', $wordpress_url ) ) {
			throw new RuntimeException( 'WordPress rejected the WordPress Address update.' );
		}
		if ( $previous['home'] !== $site_url && ! update_option( 'home', $site_url ) ) {
			throw new RuntimeException( 'WordPress rejected the Site Address update.' );
		}

		if ( (string) get_option( 'siteurl' ) !== $wordpress_url || (string) get_option( 'home' ) !== $site_url ) {
			throw new RuntimeException( 'The updated WordPress addresses could not be verified.' );
		}

		$driver->commit();
		$in_transaction = false;
	} catch ( Throwable $error ) {
		if ( $in_transaction ) {
			try {
				$driver->rollBack();
			} catch ( Throwable $rollback_error ) {
				error_log( 'site URL recovery rollback failed: ' . $rollback_error->getMessage() );
			}
		}

		wp_cache_delete( 'siteurl', 'options' );
		wp_cache_delete( 'home', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		throw new RuntimeException( 'The two WordPress addresses were not updated.', 0, $error );
	}

	return $previous;
}

/**
 * Handles the standalone recovery request.
 *
 * @return void
 */
function sqlite_wordpress_site_url_tool_main() {
	sqlite_wordpress_site_url_tool_send_headers();
	if ( ! sqlite_wordpress_site_url_tool_is_enabled() ) {
		http_response_code( 404 );
		echo 'Not Found';
		return;
	}

	try {
		$configured_credential = sqlite_wordpress_site_url_tool_configured_credential();
	} catch ( RuntimeException $error ) {
		error_log( 'site URL recovery configuration error: ' . $error->getMessage() );
		http_response_code( 503 );
		sqlite_wordpress_site_url_tool_render(
			'Site URL Recovery Unavailable',
			'The recovery credential configuration is invalid. Check the container logs.',
			'error',
			'',
			'',
			false
		);
		return;
	}

	if ( null === $configured_credential ) {
		http_response_code( 404 );
		echo 'Not Found';
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'GET' === $method ) {
		sqlite_wordpress_site_url_tool_render(
			'WordPress Site URL Recovery',
			'Enter the configured recovery token or password and both new addresses. The update is committed as one SQLite transaction.'
		);
		return;
	}

	if ( 'POST' !== $method ) {
		header( 'Allow: GET, POST' );
		http_response_code( 405 );
		sqlite_wordpress_site_url_tool_render( 'Method Not Allowed', 'Use GET to open this page and POST to submit it.', 'error', '', '', false );
		return;
	}

	$provided_credential = isset( $_POST['recovery_token'] ) && is_string( $_POST['recovery_token'] )
		? $_POST['recovery_token']
		: '';
	if ( strlen( $provided_credential ) > 1024 || ! hash_equals( $configured_credential, $provided_credential ) ) {
		http_response_code( 403 );
		sqlite_wordpress_site_url_tool_render( 'Access Denied', 'The recovery credential is invalid.', 'error' );
		return;
	}

	$wordpress_input = isset( $_POST['wordpress_url'] ) ? $_POST['wordpress_url'] : '';
	$site_input      = isset( $_POST['site_url'] ) ? $_POST['site_url'] : '';

	try {
		$wordpress_url = sqlite_wordpress_site_url_tool_validate_url( $wordpress_input, 'WordPress Address (URL)' );
		$site_url      = sqlite_wordpress_site_url_tool_validate_url( $site_input, 'Site Address (URL)' );
	} catch ( InvalidArgumentException $error ) {
		http_response_code( 422 );
		sqlite_wordpress_site_url_tool_render(
			'Invalid Address',
			$error->getMessage(),
			'error',
			is_string( $wordpress_input ) ? $wordpress_input : '',
			is_string( $site_input ) ? $site_input : ''
		);
		return;
	}

	$wp_load = __DIR__ . '/wp-load.php';
	if ( ! is_file( $wp_load ) ) {
		http_response_code( 503 );
		sqlite_wordpress_site_url_tool_render( 'WordPress Unavailable', 'wp-load.php was not found in the document root.', 'error', '', '', false );
		return;
	}

	define( 'WP_USE_THEMES', false );
	require_once $wp_load;

	if ( is_multisite() ) {
		http_response_code( 409 );
		sqlite_wordpress_site_url_tool_render( 'Multisite Not Supported', 'This recovery tool only updates single-site installations.', 'error', $wordpress_url, $site_url );
		return;
	}
	if ( defined( 'WP_SITEURL' ) || defined( 'WP_HOME' ) ) {
		http_response_code( 409 );
		sqlite_wordpress_site_url_tool_render(
			'Configuration Override Detected',
			'WP_SITEURL or WP_HOME is defined in wp-config.php. Update or remove those constants instead of changing the database options.',
			'error',
			$wordpress_url,
			$site_url
		);
		return;
	}

	$wordpress_url = sanitize_option( 'siteurl', $wordpress_url );
	$site_url      = sanitize_option( 'home', $site_url );
	if ( is_wp_error( $wordpress_url ) || is_wp_error( $site_url ) ) {
		http_response_code( 422 );
		sqlite_wordpress_site_url_tool_render( 'Invalid Address', 'WordPress rejected one or both addresses.', 'error' );
		return;
	}

	try {
		$wordpress_url = sqlite_wordpress_site_url_tool_validate_url( (string) $wordpress_url, 'WordPress Address (URL)' );
		$site_url      = sqlite_wordpress_site_url_tool_validate_url( (string) $site_url, 'Site Address (URL)' );
		sqlite_wordpress_site_url_tool_update_options( $wordpress_url, $site_url );
	} catch ( Throwable $error ) {
		error_log( 'site URL recovery update failed: ' . $error->getMessage() );
		http_response_code( 500 );
		sqlite_wordpress_site_url_tool_render(
			'Update Failed',
			'The atomic update could not be completed. Check the container logs and verify both settings before retrying.',
			'error',
			$wordpress_url,
			$site_url
		);
		return;
	}

	sqlite_wordpress_site_url_tool_render(
		'Site URLs Updated',
		'WordPress Address and Site Address were updated successfully.',
		'success',
		$wordpress_url,
		$site_url,
		false
	);
}

if ( ! defined( 'SQLITE_WORDPRESS_SITE_URL_TOOL_TESTING' ) ) {
	sqlite_wordpress_site_url_tool_main();
}
