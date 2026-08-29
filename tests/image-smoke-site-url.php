<?php
/**
 * Built-image smoke test for throttling, atomic updates, and one-shot locking.
 */

/**
 * @param bool   $condition Condition that must be true.
 * @param string $message   Failure description.
 * @return void
 */
function sqlite_wordpress_image_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

/**
 * Runs one request against the already-loaded standalone endpoint.
 *
 * @param string $method     HTTP method.
 * @param string $credential Submitted recovery credential.
 * @param string $siteurl    WordPress Address.
 * @param string $home       Site Address.
 * @return array{status:int,body:string} Response snapshot.
 */
function sqlite_wordpress_image_smoke_request( $method, $credential = '', $siteurl = '', $home = '' ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_POST                     = array();
	if ( 'POST' === $method ) {
		$_POST = array(
			'recovery_token' => $credential,
			'wordpress_url'  => $siteurl,
			'site_url'       => $home,
		);
	}

	http_response_code( 200 );
	ob_start();
	sqlite_wordpress_site_url_tool_main();
	$body = ob_get_clean();

	return array(
		'status' => http_response_code(),
		'body'   => false === $body ? '' : $body,
	);
}

$credential = str_repeat( 'p', 24 );
$state_file = '/var/www/html/wp-content/database/.ht.site-url-update-tool-state';
$lock_file  = $state_file . '.lock';
foreach ( array( $state_file, $lock_file ) as $recovery_file ) {
	if ( is_link( $recovery_file ) || ( file_exists( $recovery_file ) && ! unlink( $recovery_file ) ) ) {
		fwrite( STDERR, "FAIL: could not reset recovery state fixture\n" );
		exit( 1 );
	}
}

putenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD=' . $credential );
define( 'SQLITE_WORDPRESS_SITE_URL_TOOL_TESTING', true );
require '/var/www/html/tool-update-site-url.php';

for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
	$response = sqlite_wordpress_image_smoke_request(
		'POST',
		'incorrect-recovery-password',
		'https://new.example/wordpress',
		'https://new.example'
	);
	$expected = $attempt < 5 ? 403 : 429;
	sqlite_wordpress_image_smoke_assert( $expected === $response['status'], "failed attempt {$attempt} returns {$expected}" );
}

sqlite_wordpress_image_smoke_assert( unlink( $state_file ), 'test fixture can reset the lockout state' );
sqlite_wordpress_image_smoke_assert( unlink( $lock_file ), 'test fixture can reset the lockout guard' );
$response = sqlite_wordpress_image_smoke_request(
	'POST',
	$credential,
	'https://new.example/wordpress',
	'https://new.example'
);
sqlite_wordpress_image_smoke_assert( 200 === $response['status'], 'valid recovery request succeeds' );
sqlite_wordpress_image_smoke_assert( false !== strpos( $response['body'], 'Site URLs Updated' ), 'success page is rendered' );
sqlite_wordpress_image_smoke_assert( 'https://new.example/wordpress' === get_option( 'siteurl' ), 'siteurl is updated in the built image' );
sqlite_wordpress_image_smoke_assert( 'https://new.example' === get_option( 'home' ), 'home is updated in the built image' );
sqlite_wordpress_image_smoke_assert( 'false' === getenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED' ), 'current PHP worker is disabled after use' );
sqlite_wordpress_image_smoke_assert( file_exists( $state_file ), 'persistent one-shot state is created' );

// Simulate a fresh worker inheriting the unchanged Docker configuration. The
// persistent used flag must still hide the endpoint.
putenv( 'WORDPRESS_SITE_URL_UPDATE_TOOL_ENABLED=true' );
putenv( 'WORDPRESS_SITE_URL_UPDATE_PASSWORD=' . $credential );
$response = sqlite_wordpress_image_smoke_request( 'GET' );
sqlite_wordpress_image_smoke_assert( 404 === $response['status'], 'fresh worker cannot reopen a used authorization' );
sqlite_wordpress_image_smoke_assert( 'Not Found' === $response['body'], 'used endpoint fails closed without disclosing its state' );

fwrite( STDOUT, "site URL recovery image smoke passed\n" );
