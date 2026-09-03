<?php
/**
 * Plugin Name: SQLite WordPress Page Performance
 * Plugin URI: https://github.com/soulteary/docker-sqlite-wordpress
 * Description: Optionally displays server-side page generation time and PHP memory usage in the WordPress toolbar and public page footer.
 * Version: 1.0.0
 * Author: soulteary
 * Author URI: https://soulteary.com
 * License: Apache-2.0
 *
 * @package sqlite-wordpress-performance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SQLITE_WORDPRESS_PERFORMANCE_OPTION', 'sqlite_wordpress_performance' );
define( 'SQLITE_WORDPRESS_PERFORMANCE_ENV', 'WORDPRESS_PAGE_PERFORMANCE_ENABLED' );

add_action( 'admin_init', 'sqlite_wordpress_performance_register_setting' );
add_action( 'admin_menu', 'sqlite_wordpress_performance_register_page' );
add_action( 'admin_notices', 'sqlite_wordpress_performance_render_admin_notice' );
add_action( 'admin_bar_menu', 'sqlite_wordpress_performance_add_toolbar_node', PHP_INT_MAX );
add_action( 'wp_footer', 'sqlite_wordpress_performance_render_frontend_footer', PHP_INT_MAX );
add_action( 'admin_footer', 'sqlite_wordpress_performance_update_toolbar', PHP_INT_MAX );

/**
 * Reads the stored and environment-controlled state.
 *
 * An environment override locks only the effective switch. The stored setting
 * remains intact and becomes active again after the variable is removed.
 *
 * @return array{enabled:bool,stored:bool,source:string,error:string}
 */
function sqlite_wordpress_performance_configuration() {
	$stored = (bool) get_option( SQLITE_WORDPRESS_PERFORMANCE_OPTION, false );
	$value  = getenv( SQLITE_WORDPRESS_PERFORMANCE_ENV );

	if ( false === $value ) {
		return array(
			'enabled' => $stored,
			'stored'  => $stored,
			'source'  => 'database',
			'error'   => '',
		);
	}

	if ( 'true' === $value || 'false' === $value ) {
		return array(
			'enabled' => 'true' === $value,
			'stored'  => $stored,
			'source'  => 'environment',
			'error'   => '',
		);
	}

	return array(
		'enabled' => false,
		'stored'  => $stored,
		'source'  => 'environment',
		'error'   => sprintf(
			/* translators: %s: environment variable name. */
			__( '%s must be the exact lowercase value true or false. Performance information remains disabled.', 'sqlite-wordpress-performance' ),
			SQLITE_WORDPRESS_PERFORMANCE_ENV
		),
	);
}

/**
 * Returns whether performance information is currently enabled.
 *
 * @return bool
 */
function sqlite_wordpress_performance_is_enabled() {
	$configuration = sqlite_wordpress_performance_configuration();
	return $configuration['enabled'];
}

/**
 * Sanitizes the administrator-controlled switch.
 *
 * Environment control is intentionally read-only and must not destroy the
 * stored administrator preference hidden behind it.
 *
 * @param mixed $value Submitted option value.
 * @return bool
 */
function sqlite_wordpress_performance_sanitize_setting( $value ) {
	$configuration = sqlite_wordpress_performance_configuration();
	if ( 'environment' === $configuration['source'] ) {
		return $configuration['stored'];
	}

	return ! empty( $value );
}

/**
 * Registers the performance display switch.
 *
 * @return void
 */
function sqlite_wordpress_performance_register_setting() {
	register_setting(
		'sqlite_wordpress_performance',
		SQLITE_WORDPRESS_PERFORMANCE_OPTION,
		array(
			'type'              => 'boolean',
			'sanitize_callback' => 'sqlite_wordpress_performance_sanitize_setting',
			'default'           => false,
			'show_in_rest'      => false,
		)
	);
}

/**
 * Registers the settings page.
 *
 * @return void
 */
function sqlite_wordpress_performance_register_page() {
	add_options_page(
		__( 'Page Performance', 'sqlite-wordpress-performance' ),
		__( 'Page Performance', 'sqlite-wordpress-performance' ),
		'manage_options',
		'sqlite-wordpress-performance',
		'sqlite_wordpress_performance_render_page'
	);
}

/**
 * Captures the current server-side page metrics.
 *
 * The values are sampled at the output location. On normal pages this is near
 * the end of the response; the browser therefore sees a useful approximation
 * of the complete PHP generation cost without response buffering.
 *
 * @return array{seconds:string,memory:int,peak_memory:int}
 */
function sqlite_wordpress_performance_metrics() {
	$seconds = function_exists( 'timer_stop' ) ? timer_stop( 0, 3 ) : '0.000';

	return array(
		'seconds'     => (string) $seconds,
		'memory'      => memory_get_usage(),
		'peak_memory' => memory_get_peak_usage(),
	);
}

/**
 * Formats metrics for the toolbar and footer.
 *
 * @param array{seconds:string,memory:int,peak_memory:int} $metrics Page metrics.
 * @return string
 */
function sqlite_wordpress_performance_format_metrics( $metrics ) {
	return sprintf(
		/* translators: 1: generation time in seconds, 2: current PHP memory, 3: peak PHP memory. */
		__( '%1$s s · Memory %2$s · Peak %3$s', 'sqlite-wordpress-performance' ),
		$metrics['seconds'],
		size_format( $metrics['memory'], 2 ),
		size_format( $metrics['peak_memory'], 2 )
	);
}

/**
 * Adds a performance node to the toolbar on both front-end and admin pages.
 *
 * @param WP_Admin_Bar $admin_bar WordPress toolbar instance.
 * @return void
 */
function sqlite_wordpress_performance_add_toolbar_node( $admin_bar ) {
	if ( ! sqlite_wordpress_performance_is_enabled() ) {
		return;
	}

	$label = sqlite_wordpress_performance_format_metrics( sqlite_wordpress_performance_metrics() );
	$admin_bar->add_node(
		array(
			'id'    => 'sqlite-wordpress-performance',
			'title' => '<span id="sqlite-wordpress-performance-toolbar-value">' . esc_html( $label ) . '</span>',
			'href'  => current_user_can( 'manage_options' ) ? admin_url( 'options-general.php?page=sqlite-wordpress-performance' ) : false,
			'meta'  => array(
				'title' => __( 'Server-side page generation time and PHP memory usage', 'sqlite-wordpress-performance' ),
			),
		)
	);
}

/**
 * Updates the already-rendered toolbar with a later metric sample.
 *
 * @return void
 */
function sqlite_wordpress_performance_update_toolbar() {
	if ( ! sqlite_wordpress_performance_is_enabled() ) {
		return;
	}

	$label = sqlite_wordpress_performance_format_metrics( sqlite_wordpress_performance_metrics() );
	echo '<script id="sqlite-wordpress-performance-toolbar-update">';
	echo '(function(){var node=document.getElementById("sqlite-wordpress-performance-toolbar-value");if(node){node.textContent=' . wp_json_encode( $label, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';}}());';
	echo '</script>';
}

/**
 * Renders the public page footer and refreshes the toolbar value.
 *
 * @return void
 */
function sqlite_wordpress_performance_render_frontend_footer() {
	if ( ! sqlite_wordpress_performance_is_enabled() ) {
		return;
	}

	$label = sqlite_wordpress_performance_format_metrics( sqlite_wordpress_performance_metrics() );
	echo '<div id="sqlite-wordpress-performance-footer" style="box-sizing:border-box;width:100%;padding:8px 12px;border-top:1px solid #dcdcde;background:#f6f7f7;color:#3c434a;font:12px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;text-align:center;">';
	echo esc_html__( 'Server performance:', 'sqlite-wordpress-performance' ) . ' ' . esc_html( $label );
	echo '</div>';

	sqlite_wordpress_performance_update_toolbar();
}

/**
 * Renders the administrator settings page.
 *
 * @return void
 */
function sqlite_wordpress_performance_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage page performance settings.', 'sqlite-wordpress-performance' ) );
	}

	$configuration = sqlite_wordpress_performance_configuration();
	$locked        = 'environment' === $configuration['source'];

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Page Performance', 'sqlite-wordpress-performance' ) . '</h1>';
	echo '<p>' . esc_html__( 'Display server-side page generation time, current PHP memory, and peak PHP memory in the front-end/admin toolbar and at the bottom of public pages.', 'sqlite-wordpress-performance' ) . '</p>';
	settings_errors( SQLITE_WORDPRESS_PERFORMANCE_OPTION );

	if ( '' !== $configuration['error'] ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $configuration['error'] ) . '</p></div>';
	}

	echo '<form method="post" action="options.php">';
	settings_fields( 'sqlite_wordpress_performance' );
	echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Performance information', 'sqlite-wordpress-performance' ) . '</th><td><label>';
	echo '<input type="hidden" name="' . esc_attr( SQLITE_WORDPRESS_PERFORMANCE_OPTION ) . '" value="0">';
	echo '<input type="checkbox" name="' . esc_attr( SQLITE_WORDPRESS_PERFORMANCE_OPTION ) . '" value="1" ' . checked( $configuration['enabled'], true, false ) . disabled( $locked, true, false ) . '> ';
	echo esc_html__( 'Show page generation time and PHP memory usage.', 'sqlite-wordpress-performance' ) . '</label>';
	if ( $locked ) {
		echo '<p class="description"><strong>' . esc_html( sprintf( __( 'Controlled by %s; remove the variable and recreate the container to edit this setting here.', 'sqlite-wordpress-performance' ), SQLITE_WORDPRESS_PERFORMANCE_ENV ) ) . '</strong></p>';
	}
	echo '<p class="description">' . esc_html__( 'The footer is visible to public visitors while this option is enabled. Toolbar information appears only when the WordPress toolbar itself is visible.', 'sqlite-wordpress-performance' ) . '</p>';
	echo '</td></tr></tbody></table>';
	submit_button();
	echo '</form></div>';
}

/**
 * Warns administrators when an invalid environment override disables output.
 *
 * @return void
 */
function sqlite_wordpress_performance_render_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$configuration = sqlite_wordpress_performance_configuration();
	if ( '' === $configuration['error'] ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && 'settings_page_sqlite-wordpress-performance' === $screen->id ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html( $configuration['error'] ) . '</p></div>';
}
