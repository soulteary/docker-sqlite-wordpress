<?php
/**
 * Plugin Name: SQLite WordPress Local Core Update
 * Description: Uses the core upgrade archive bundled with the container when WordPress offers the matching version.
 * Version: 1.0.0
 * License: Apache-2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SQLITE_WORDPRESS_LOCAL_CORE_VERSION' ) ) {
	define( 'SQLITE_WORDPRESS_LOCAL_CORE_VERSION', '{WORDPRESS_VERSION}' );
}

if ( ! defined( 'SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE' ) ) {
	define(
		'SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE',
		'/usr/src/wordpress-upgrades/wordpress-' . SQLITE_WORDPRESS_LOCAL_CORE_VERSION . '-no-content.zip'
	);
}

if ( ! defined( 'SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE_SHA256' ) ) {
	define( 'SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE_SHA256', '{WORDPRESS_CORE_PACKAGE_SHA256}' );
}

/**
 * Determines whether the bundled core package integration is enabled.
 *
 * The integration is enabled by default. An exact lowercase `false` value
 * disables it; any other explicit value fails closed so a typo cannot silently
 * alter the core update source.
 *
 * @return bool
 */
function sqlite_wordpress_local_core_update_is_enabled() {
	$configured = getenv( 'WORDPRESS_LOCAL_CORE_UPDATE_ENABLED' );
	if ( false === $configured || '' === $configured ) {
		return true;
	}

	return 'true' === $configured;
}

/**
 * Validates the immutable package before exposing it to WordPress.
 *
 * @return bool
 */
function sqlite_wordpress_local_core_package_is_valid() {
	$package = SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE;
	$digest  = SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE_SHA256;
	clearstatcache( true, $package );
	$stat    = is_file( $package ) && ! is_link( $package ) ? stat( $package ) : false;

	if ( ! preg_match( '/^[0-9a-f]{64}$/D', $digest ) || false === $stat || ! is_readable( $package ) ) {
		return false;
	}

	$actual_digest = hash_file( 'sha256', $package );
	if ( false === $actual_digest ) {
		return false;
	}

	return hash_equals( $digest, $actual_digest );
}

/**
 * Replaces only the matching WordPress.org core offer with the local archive.
 *
 * Rollback packages are deliberately left untouched. If the archive is
 * missing or fails its build-time SHA-256, the original remote offer is
 * returned unchanged.
 *
 * @param mixed $transient Core update site transient.
 * @return mixed
 */
function sqlite_wordpress_local_core_update_offer( $transient ) {
	global $wp_version;

	if ( ! sqlite_wordpress_local_core_update_is_enabled()
		|| ! sqlite_wordpress_local_core_package_is_valid()
		|| ! is_object( $transient )
		|| empty( $transient->updates )
		|| ! is_array( $transient->updates )
		|| ! is_string( $wp_version )
		|| version_compare( $wp_version, SQLITE_WORDPRESS_LOCAL_CORE_VERSION, '>' )
	) {
		return $transient;
	}

	foreach ( $transient->updates as $update ) {
		if ( ! is_object( $update )
			|| ! isset( $update->current )
			|| SQLITE_WORDPRESS_LOCAL_CORE_VERSION !== (string) $update->current
		) {
			continue;
		}

		if ( ! isset( $update->packages ) || ! is_object( $update->packages ) ) {
			$update->packages = new stdClass();
		}

		// Core_Upgrader may select any of these according to the source version
		// and checksum state. The bundled archive is a complete no-content package
		// and is valid for each forward/reinstall path.
		foreach ( array( 'full', 'no_content', 'new_bundled', 'partial' ) as $package_type ) {
			$update->packages->$package_type = SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE;
		}
		$update->download = SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE;
	}

	return $transient;
}

/**
 * Copies the root-owned package to WordPress's temporary directory.
 *
 * WP_Upgrader deletes a downloaded package after extraction. Returning a
 * disposable copy keeps the immutable archive in `/usr/src` available for
 * future reinstalls while still using WordPress's normal upgrade machinery.
 *
 * @param false|string|WP_Error $reply      Existing short-circuit result.
 * @param string                $package    Requested package URL or path.
 * @param WP_Upgrader           $upgrader   Active upgrader instance.
 * @param array                 $hook_extra Upgrader context.
 * @return false|string|WP_Error
 */
function sqlite_wordpress_local_core_update_pre_download( $reply, $package, $upgrader, $hook_extra ) {
	unset( $hook_extra );

	if ( false !== $reply || SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE !== $package ) {
		return $reply;
	}

	if ( ! class_exists( 'Core_Upgrader', false ) || ! $upgrader instanceof Core_Upgrader ) {
		return new WP_Error( 'sqlite_wordpress_local_core_wrong_upgrader', 'The bundled WordPress archive may only be used for core updates.' );
	}

	if ( ! sqlite_wordpress_local_core_package_is_valid() ) {
		return new WP_Error( 'sqlite_wordpress_local_core_invalid_package', 'The bundled WordPress archive failed its integrity check.' );
	}

	if ( ! function_exists( 'wp_tempnam' ) ) {
		return new WP_Error( 'sqlite_wordpress_local_core_no_temp_file', 'WordPress could not create a temporary core update file.' );
	}

	$temporary = wp_tempnam( basename( SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE ) );
	if ( ! is_string( $temporary ) || '' === $temporary ) {
		return new WP_Error( 'sqlite_wordpress_local_core_no_temp_file', 'WordPress could not create a temporary core update file.' );
	}

	if ( ! copy( SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE, $temporary ) ) {
		@unlink( $temporary );
		return new WP_Error( 'sqlite_wordpress_local_core_copy_failed', 'WordPress could not copy the bundled core update archive.' );
	}

	$copied_digest = hash_file( 'sha256', $temporary );
	if ( false === $copied_digest || ! hash_equals( SQLITE_WORDPRESS_LOCAL_CORE_PACKAGE_SHA256, $copied_digest ) ) {
		@unlink( $temporary );
		return new WP_Error( 'sqlite_wordpress_local_core_copy_invalid', 'The temporary WordPress core archive failed its integrity check.' );
	}

	@chmod( $temporary, 0600 );
	return $temporary;
}

add_filter( 'site_transient_update_core', 'sqlite_wordpress_local_core_update_offer', 20 );
add_filter( 'upgrader_pre_download', 'sqlite_wordpress_local_core_update_pre_download', 10, 4 );
