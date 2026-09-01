<?php
/**
 * Plugin Name: SQLite WordPress SMTP
 * Plugin URI: https://github.com/soulteary/docker-sqlite-wordpress
 * Description: Configures wp_mail() through an optional SMTP transport with administrator settings and per-field environment overrides.
 * Version: 1.0.0
 * Author: soulteary
 * Author URI: https://soulteary.com
 *
 * @package sqlite-wordpress-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SQLITE_WORDPRESS_SMTP_OPTION', 'sqlite_wordpress_smtp' );

add_action( 'phpmailer_init', 'sqlite_wordpress_smtp_configure_mailer' );
add_filter( 'pre_wp_mail', 'sqlite_wordpress_smtp_block_invalid_mail', PHP_INT_MAX, 2 );
add_filter( 'wp_mail_from', 'sqlite_wordpress_smtp_filter_from_email' );
add_filter( 'wp_mail_from_name', 'sqlite_wordpress_smtp_filter_from_name' );
add_action( 'admin_init', 'sqlite_wordpress_smtp_register_setting' );
add_action( 'admin_menu', 'sqlite_wordpress_smtp_register_page' );
add_action( 'admin_notices', 'sqlite_wordpress_smtp_render_admin_notice' );

/**
 * Returns safe defaults. SMTP remains disabled until explicitly enabled.
 *
 * The host and port defaults match the optional OwlMail Compose service. They
 * have no effect while the integration is disabled.
 *
 * @return array<string,mixed> Default settings.
 */
function sqlite_wordpress_smtp_defaults() {
	return array(
		'enabled'      => false,
		'host'         => 'owlmail',
		'port'         => 1025,
		'encryption'   => 'none',
		'auto_tls'     => false,
		'authentication' => false,
		'username'     => '',
		'password'     => '',
		'from_email'   => '',
		'from_name'    => '',
		'force_from'   => false,
		'timeout'      => 10,
	);
}

/**
 * Maps stored setting names to their environment overrides.
 *
 * @return array<string,string> Setting => environment variable.
 */
function sqlite_wordpress_smtp_environment_map() {
	return array(
		'enabled'        => 'WORDPRESS_SMTP_ENABLED',
		'host'           => 'WORDPRESS_SMTP_HOST',
		'port'           => 'WORDPRESS_SMTP_PORT',
		'encryption'     => 'WORDPRESS_SMTP_ENCRYPTION',
		'auto_tls'       => 'WORDPRESS_SMTP_AUTO_TLS',
		'authentication' => 'WORDPRESS_SMTP_AUTH',
		'username'       => 'WORDPRESS_SMTP_USERNAME',
		'password'       => 'WORDPRESS_SMTP_PASSWORD',
		'from_email'     => 'WORDPRESS_SMTP_FROM_EMAIL',
		'from_name'      => 'WORDPRESS_SMTP_FROM_NAME',
		'force_from'     => 'WORDPRESS_SMTP_FORCE_FROM',
		'timeout'        => 'WORDPRESS_SMTP_TIMEOUT',
	);
}

/**
 * Reads one environment variable while preserving the distinction between an
 * unset variable and an explicitly configured empty value.
 *
 * @param string $name Environment variable name.
 * @return array{set:bool,value:string} Environment state.
 */
function sqlite_wordpress_smtp_environment_value( $name ) {
	$value = getenv( $name );

	return array(
		'set'   => false !== $value,
		'value' => false === $value ? '' : (string) $value,
	);
}

/**
 * Returns normalized stored settings without applying environment overrides.
 *
 * @return array<string,mixed> Stored settings merged with defaults.
 */
function sqlite_wordpress_smtp_stored_settings() {
	$defaults = sqlite_wordpress_smtp_defaults();
	$stored   = get_option( SQLITE_WORDPRESS_SMTP_OPTION, array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$settings = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	foreach ( array( 'enabled', 'auto_tls', 'authentication', 'force_from' ) as $field ) {
		$settings[ $field ] = ! empty( $settings[ $field ] );
	}
	$settings['port']    = (int) $settings['port'];
	$settings['timeout'] = (int) $settings['timeout'];

	return $settings;
}

/**
 * Parses an exact lowercase boolean environment value.
 *
 * @param string               $value Raw value.
 * @param string               $name  Environment variable name.
 * @param array<string,string> $errors Existing errors.
 * @return array{value:bool,errors:array<string,string>} Parsed value and errors.
 */
function sqlite_wordpress_smtp_parse_environment_boolean( $value, $name, $errors ) {
	if ( 'true' === $value ) {
		return array( 'value' => true, 'errors' => $errors );
	}
	if ( 'false' === $value ) {
		return array( 'value' => false, 'errors' => $errors );
	}

	$errors[ 'environment-' . strtolower( $name ) ] = sprintf(
		/* translators: %s: environment variable name. */
		__( '%s must be the exact lowercase value true or false.', 'sqlite-wordpress-smtp' ),
		$name
	);

	return array( 'value' => false, 'errors' => $errors );
}

/**
 * Parses a bounded positive integer environment value.
 *
 * @param string               $value Raw value.
 * @param string               $name  Environment variable name.
 * @param int                  $min   Minimum value.
 * @param int                  $max   Maximum value.
 * @param int                  $fallback Safe fallback value.
 * @param array<string,string> $errors Existing errors.
 * @return array{value:int,errors:array<string,string>} Parsed value and errors.
 */
function sqlite_wordpress_smtp_parse_environment_integer( $value, $name, $min, $max, $fallback, $errors ) {
	if ( preg_match( '/^[0-9]+$/', $value ) ) {
		$integer = (int) $value;
		if ( $integer >= $min && $integer <= $max ) {
			return array( 'value' => $integer, 'errors' => $errors );
		}
	}

	$errors[ 'environment-' . strtolower( $name ) ] = sprintf(
		/* translators: 1: environment variable name, 2: minimum, 3: maximum. */
		__( '%1$s must be an integer between %2$d and %3$d.', 'sqlite-wordpress-smtp' ),
		$name,
		$min,
		$max
	);

	return array( 'value' => $fallback, 'errors' => $errors );
}

/**
 * Checks whether a value is safe for an SMTP single-line field.
 *
 * @param string $value Value to validate.
 * @param int    $maximum Maximum byte length.
 * @return bool Whether the value is safe.
 */
function sqlite_wordpress_smtp_is_safe_line( $value, $maximum ) {
	return strlen( $value ) <= $maximum && ! preg_match( '/[\x00-\x1F\x7F]/', $value );
}

/**
 * Reads an SMTP password from a bounded file.
 *
 * A single trailing line ending is stripped so Docker secret files created by
 * common shell commands work as expected. Other whitespace remains part of the
 * password.
 *
 * @param string               $path File path.
 * @param array<string,string> $errors Existing errors.
 * @return array{value:string,errors:array<string,string>} Password and errors.
 */
function sqlite_wordpress_smtp_read_password_file( $path, $errors ) {
	if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
		$errors['password-file-path'] = __( 'WORDPRESS_SMTP_PASSWORD_FILE must contain an absolute path.', 'sqlite-wordpress-smtp' );
		return array( 'value' => '', 'errors' => $errors );
	}
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		$errors['password-file-unreadable'] = __( 'WORDPRESS_SMTP_PASSWORD_FILE is not a readable regular file.', 'sqlite-wordpress-smtp' );
		return array( 'value' => '', 'errors' => $errors );
	}

	$contents = file_get_contents( $path, false, null, 0, 4097 );
	if ( false === $contents || strlen( $contents ) > 4096 ) {
		$errors['password-file-size'] = __( 'WORDPRESS_SMTP_PASSWORD_FILE must be no larger than 4096 bytes.', 'sqlite-wordpress-smtp' );
		return array( 'value' => '', 'errors' => $errors );
	}

	return array( 'value' => rtrim( $contents, "\r\n" ), 'errors' => $errors );
}

/**
 * Validates the effective SMTP values.
 *
 * @param array<string,mixed> $values Effective values.
 * @return array<string,string> Validation errors.
 */
function sqlite_wordpress_smtp_validate_values( $values ) {
	$errors = array();
	$host   = (string) $values['host'];

	if ( '' === $host || strlen( $host ) > 255 || preg_match( '/[\s\x00-\x1F\x7F]/', $host ) ) {
		$errors['host'] = __( 'SMTP host must be a non-empty hostname or address without whitespace.', 'sqlite-wordpress-smtp' );
	}
	if ( (int) $values['port'] < 1 || (int) $values['port'] > 65535 ) {
		$errors['port'] = __( 'SMTP port must be between 1 and 65535.', 'sqlite-wordpress-smtp' );
	}
	if ( ! in_array( $values['encryption'], array( 'none', 'tls', 'ssl' ), true ) ) {
		$errors['encryption'] = __( 'SMTP encryption must be none, tls, or ssl.', 'sqlite-wordpress-smtp' );
	}
	if ( (int) $values['timeout'] < 1 || (int) $values['timeout'] > 300 ) {
		$errors['timeout'] = __( 'SMTP timeout must be between 1 and 300 seconds.', 'sqlite-wordpress-smtp' );
	}
	if ( ! sqlite_wordpress_smtp_is_safe_line( (string) $values['username'], 320 ) ) {
		$errors['username'] = __( 'SMTP username contains unsupported control characters or is too long.', 'sqlite-wordpress-smtp' );
	}
	if ( strlen( (string) $values['password'] ) > 4096 ) {
		$errors['password'] = __( 'SMTP password must be no larger than 4096 bytes.', 'sqlite-wordpress-smtp' );
	}
	if ( ! sqlite_wordpress_smtp_is_safe_line( (string) $values['from_name'], 255 ) ) {
		$errors['from-name'] = __( 'From name contains unsupported control characters or is too long.', 'sqlite-wordpress-smtp' );
	}
	if ( $values['authentication'] && '' === (string) $values['username'] ) {
		$errors['authentication-username'] = __( 'SMTP authentication requires a username.', 'sqlite-wordpress-smtp' );
	}
	if ( $values['authentication'] && '' === (string) $values['password'] ) {
		$errors['authentication-password'] = __( 'SMTP authentication requires a password.', 'sqlite-wordpress-smtp' );
	}
	if ( $values['force_from'] ) {
		if ( '' === (string) $values['from_email'] || ! is_email( $values['from_email'] ) ) {
			$errors['from-email'] = __( 'A valid From email address is required when Force From is enabled.', 'sqlite-wordpress-smtp' );
		}
	} elseif ( '' !== (string) $values['from_email'] && ! is_email( $values['from_email'] ) ) {
		$errors['from-email'] = __( 'From email address is invalid.', 'sqlite-wordpress-smtp' );
	}

	return $errors;
}

/**
 * Resolves the effective configuration and its source for every field.
 *
 * Environment variables override only their corresponding stored fields. An
 * invalid override never falls back to the stored value silently; enabled SMTP
 * mail is blocked until the configuration is corrected.
 *
 * @return array{values:array<string,mixed>,sources:array<string,string>,errors:array<string,string>,block_mail:bool} Effective configuration.
 */
function sqlite_wordpress_smtp_configuration() {
	$defaults = sqlite_wordpress_smtp_defaults();
	$stored   = get_option( SQLITE_WORDPRESS_SMTP_OPTION, array() );
	$values   = sqlite_wordpress_smtp_stored_settings();
	$sources  = array();
	$errors   = array();

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	foreach ( $defaults as $field => $unused ) {
		$sources[ $field ] = array_key_exists( $field, $stored ) ? 'settings' : 'default';
	}

	$boolean_fields = array( 'enabled', 'auto_tls', 'authentication', 'force_from' );
	$integer_fields = array(
		'port'    => array( 1, 65535 ),
		'timeout' => array( 1, 300 ),
	);

	foreach ( sqlite_wordpress_smtp_environment_map() as $field => $name ) {
		$environment = sqlite_wordpress_smtp_environment_value( $name );
		if ( ! $environment['set'] ) {
			continue;
		}

		$sources[ $field ] = 'environment:' . $name;
		if ( in_array( $field, $boolean_fields, true ) ) {
			$parsed          = sqlite_wordpress_smtp_parse_environment_boolean( $environment['value'], $name, $errors );
			$values[ $field ] = $parsed['value'];
			$errors           = $parsed['errors'];
		} elseif ( isset( $integer_fields[ $field ] ) ) {
			$parsed          = sqlite_wordpress_smtp_parse_environment_integer(
				$environment['value'],
				$name,
				$integer_fields[ $field ][0],
				$integer_fields[ $field ][1],
				$defaults[ $field ],
				$errors
			);
			$values[ $field ] = $parsed['value'];
			$errors           = $parsed['errors'];
		} elseif ( 'encryption' === $field ) {
			$value = strtolower( trim( $environment['value'] ) );
			if ( in_array( $value, array( 'none', 'tls', 'ssl' ), true ) ) {
				$values[ $field ] = $value;
			} else {
				$values[ $field ] = $defaults[ $field ];
				$errors['environment-encryption'] = __( 'WORDPRESS_SMTP_ENCRYPTION must be none, tls, or ssl.', 'sqlite-wordpress-smtp' );
			}
		} elseif ( 'password' === $field ) {
			$values[ $field ] = $environment['value'];
		} else {
			$values[ $field ] = trim( $environment['value'] );
		}
	}

	$password_environment = sqlite_wordpress_smtp_environment_value( 'WORDPRESS_SMTP_PASSWORD' );
	$password_file        = sqlite_wordpress_smtp_environment_value( 'WORDPRESS_SMTP_PASSWORD_FILE' );
	if ( $password_file['set'] ) {
		$sources['password'] = 'environment:WORDPRESS_SMTP_PASSWORD_FILE';
		if ( $password_environment['set'] ) {
			$values['password'] = '';
			$errors['password-source'] = __( 'Configure only one of WORDPRESS_SMTP_PASSWORD and WORDPRESS_SMTP_PASSWORD_FILE.', 'sqlite-wordpress-smtp' );
		} else {
			$password_result   = sqlite_wordpress_smtp_read_password_file( trim( $password_file['value'] ), $errors );
			$values['password'] = $password_result['value'];
			$errors             = $password_result['errors'];
		}
	}

	$errors     = array_merge( $errors, sqlite_wordpress_smtp_validate_values( $values ) );
	$block_mail = $values['enabled'] && ! empty( $errors );

	return array(
		'values'     => $values,
		'sources'    => $sources,
		'errors'     => $errors,
		'block_mail' => $block_mail,
	);
}

/**
 * Configures WordPress's PHPMailer instance.
 *
 * @param object $mailer PHPMailer-compatible object.
 * @return void
 */
function sqlite_wordpress_smtp_configure_mailer( $mailer ) {
	$configuration = sqlite_wordpress_smtp_configuration();
	$settings      = $configuration['values'];

	if ( ! $settings['enabled'] || ! empty( $configuration['errors'] ) ) {
		return;
	}

	$mailer->isSMTP();
	$mailer->Host        = $settings['host'];
	$mailer->Port        = $settings['port'];
	$mailer->Timeout     = $settings['timeout'];
	$mailer->SMTPAuth    = $settings['authentication'];
	$mailer->SMTPAutoTLS = $settings['auto_tls'];
	$mailer->SMTPSecure  = 'none' === $settings['encryption'] ? '' : $settings['encryption'];

	if ( $settings['authentication'] ) {
		$mailer->Username = $settings['username'];
		$mailer->Password = $settings['password'];
	}
}

/**
 * Prevents invalid enabled SMTP settings from falling back to PHP mail().
 *
 * @param null|bool $return Short-circuit value from an earlier filter.
 * @param array     $attributes wp_mail() attributes.
 * @return null|bool Whether to short-circuit wp_mail().
 */
function sqlite_wordpress_smtp_block_invalid_mail( $return, $attributes ) {
	$configuration = sqlite_wordpress_smtp_configuration();
	if ( ! $configuration['block_mail'] ) {
		return $return;
	}

	$error = new WP_Error(
		'sqlite_wordpress_smtp_invalid_configuration',
		implode( ' ', array_values( $configuration['errors'] ) ),
		$attributes
	);
	do_action( 'wp_mail_failed', $error );

	return false;
}

/**
 * Applies the configured From email when Force From is enabled.
 *
 * @param string $email Existing From email.
 * @return string Filtered From email.
 */
function sqlite_wordpress_smtp_filter_from_email( $email ) {
	$configuration = sqlite_wordpress_smtp_configuration();
	$settings      = $configuration['values'];

	if ( $settings['enabled'] && empty( $configuration['errors'] ) && $settings['force_from'] ) {
		return $settings['from_email'];
	}

	return $email;
}

/**
 * Applies the configured From name when Force From is enabled.
 *
 * @param string $name Existing From name.
 * @return string Filtered From name.
 */
function sqlite_wordpress_smtp_filter_from_name( $name ) {
	$configuration = sqlite_wordpress_smtp_configuration();
	$settings      = $configuration['values'];

	if ( $settings['enabled'] && empty( $configuration['errors'] ) && $settings['force_from'] && '' !== $settings['from_name'] ) {
		return $settings['from_name'];
	}

	return $name;
}

/**
 * Returns fields currently controlled by environment variables.
 *
 * @return array<string,string> Setting => environment source.
 */
function sqlite_wordpress_smtp_environment_locks() {
	$locks = array();
	foreach ( sqlite_wordpress_smtp_environment_map() as $field => $name ) {
		if ( sqlite_wordpress_smtp_environment_value( $name )['set'] ) {
			$locks[ $field ] = $name;
		}
	}
	if ( sqlite_wordpress_smtp_environment_value( 'WORDPRESS_SMTP_PASSWORD_FILE' )['set'] ) {
		$locks['password'] = 'WORDPRESS_SMTP_PASSWORD_FILE';
	}

	return $locks;
}

/**
 * Sanitizes administrator-managed settings while preserving environment-locked
 * stored values for use after the override is removed.
 *
 * @param mixed $input Submitted option value.
 * @return array<string,mixed> Sanitized settings.
 */
function sqlite_wordpress_smtp_sanitize_settings( $input ) {
	$previous = sqlite_wordpress_smtp_stored_settings();
	$locks    = sqlite_wordpress_smtp_environment_locks();
	$output   = $previous;
	$input    = is_array( $input ) ? $input : array();

	foreach ( array( 'enabled', 'auto_tls', 'authentication', 'force_from' ) as $field ) {
		if ( ! isset( $locks[ $field ] ) ) {
			$output[ $field ] = ! empty( $input[ $field ] );
		}
	}

	if ( ! isset( $locks['host'] ) ) {
		$host = isset( $input['host'] ) ? trim( (string) wp_unslash( $input['host'] ) ) : '';
		if ( '' !== $host && strlen( $host ) <= 255 && ! preg_match( '/[\s\x00-\x1F\x7F]/', $host ) ) {
			$output['host'] = $host;
		} else {
			add_settings_error( SQLITE_WORDPRESS_SMTP_OPTION, 'invalid-host', __( 'SMTP host was not saved because it is invalid.', 'sqlite-wordpress-smtp' ) );
		}
	}

	foreach ( array( 'port' => array( 1, 65535 ), 'timeout' => array( 1, 300 ) ) as $field => $bounds ) {
		if ( isset( $locks[ $field ] ) ) {
			continue;
		}
		$value = isset( $input[ $field ] ) ? (string) wp_unslash( $input[ $field ] ) : '';
		if ( preg_match( '/^[0-9]+$/', $value ) && (int) $value >= $bounds[0] && (int) $value <= $bounds[1] ) {
			$output[ $field ] = (int) $value;
		} else {
			add_settings_error( SQLITE_WORDPRESS_SMTP_OPTION, 'invalid-' . $field, sprintf( __( '%s was not saved because it is outside the allowed range.', 'sqlite-wordpress-smtp' ), ucfirst( $field ) ) );
		}
	}

	if ( ! isset( $locks['encryption'] ) ) {
		$encryption = isset( $input['encryption'] ) ? strtolower( trim( (string) wp_unslash( $input['encryption'] ) ) ) : '';
		if ( in_array( $encryption, array( 'none', 'tls', 'ssl' ), true ) ) {
			$output['encryption'] = $encryption;
		} else {
			add_settings_error( SQLITE_WORDPRESS_SMTP_OPTION, 'invalid-encryption', __( 'SMTP encryption was not saved because it is invalid.', 'sqlite-wordpress-smtp' ) );
		}
	}

	foreach ( array( 'username' => 320, 'from_name' => 255 ) as $field => $maximum ) {
		if ( isset( $locks[ $field ] ) ) {
			continue;
		}
		$value = isset( $input[ $field ] ) ? trim( (string) wp_unslash( $input[ $field ] ) ) : '';
		if ( sqlite_wordpress_smtp_is_safe_line( $value, $maximum ) ) {
			$output[ $field ] = $value;
		} else {
			add_settings_error( SQLITE_WORDPRESS_SMTP_OPTION, 'invalid-' . $field, sprintf( __( '%s was not saved because it contains unsupported characters or is too long.', 'sqlite-wordpress-smtp' ), ucfirst( str_replace( '_', ' ', $field ) ) ) );
		}
	}

	if ( ! isset( $locks['from_email'] ) ) {
		$raw_from_email = isset( $input['from_email'] ) ? trim( (string) wp_unslash( $input['from_email'] ) ) : '';
		$from_email     = sanitize_email( $raw_from_email );
		if ( '' === $raw_from_email || ( '' !== $from_email && is_email( $from_email ) ) ) {
			$output['from_email'] = $from_email;
		} else {
			add_settings_error( SQLITE_WORDPRESS_SMTP_OPTION, 'invalid-from-email', __( 'From email was not saved because it is invalid.', 'sqlite-wordpress-smtp' ) );
		}
	}

	if ( ! isset( $locks['password'] ) ) {
		if ( ! empty( $input['clear_password'] ) ) {
			$output['password'] = '';
		} elseif ( isset( $input['password'] ) && '' !== (string) $input['password'] ) {
			$password = (string) wp_unslash( $input['password'] );
			if ( strlen( $password ) <= 4096 ) {
				$output['password'] = $password;
			} else {
				add_settings_error( SQLITE_WORDPRESS_SMTP_OPTION, 'invalid-password', __( 'SMTP password was not saved because it is too long.', 'sqlite-wordpress-smtp' ) );
			}
		}
	}

	foreach ( sqlite_wordpress_smtp_validate_values( $output ) as $code => $message ) {
		if ( $output['enabled'] ) {
			add_settings_error( SQLITE_WORDPRESS_SMTP_OPTION, 'configuration-' . $code, $message );
		}
	}

	return $output;
}

/**
 * Registers the SMTP option with WordPress's Settings API.
 *
 * @return void
 */
function sqlite_wordpress_smtp_register_setting() {
	register_setting(
		'sqlite_wordpress_smtp',
		SQLITE_WORDPRESS_SMTP_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'sqlite_wordpress_smtp_sanitize_settings',
			'default'           => sqlite_wordpress_smtp_defaults(),
			'show_in_rest'      => false,
		)
	);
}

/**
 * Registers the SMTP page under Settings.
 *
 * @return void
 */
function sqlite_wordpress_smtp_register_page() {
	add_options_page(
		__( 'SMTP Settings', 'sqlite-wordpress-smtp' ),
		__( 'SMTP', 'sqlite-wordpress-smtp' ),
		'manage_options',
		'sqlite-wordpress-smtp',
		'sqlite_wordpress_smtp_render_page'
	);
}

/**
 * Checks whether an effective field is environment-controlled.
 *
 * @param array<string,mixed> $configuration Effective configuration.
 * @param string              $field Field name.
 * @return bool Whether the field is locked.
 */
function sqlite_wordpress_smtp_field_is_locked( $configuration, $field ) {
	return 0 === strpos( $configuration['sources'][ $field ], 'environment:' );
}

/**
 * Renders an environment override note for one field.
 *
 * @param array<string,mixed> $configuration Effective configuration.
 * @param string              $field Field name.
 * @return void
 */
function sqlite_wordpress_smtp_render_source_note( $configuration, $field ) {
	if ( sqlite_wordpress_smtp_field_is_locked( $configuration, $field ) ) {
		$source = substr( $configuration['sources'][ $field ], strlen( 'environment:' ) );
		echo '<p class="description"><strong>' . esc_html( sprintf( __( 'Controlled by %s; remove the variable and recreate the container to edit this field here.', 'sqlite-wordpress-smtp' ), $source ) ) . '</strong></p>';
	}
}

/**
 * Renders one checkbox setting.
 *
 * @param array<string,mixed> $configuration Effective configuration.
 * @param string              $field Field name.
 * @param string              $label Label.
 * @param string              $description Description.
 * @return void
 */
function sqlite_wordpress_smtp_render_checkbox( $configuration, $field, $label, $description ) {
	$locked  = sqlite_wordpress_smtp_field_is_locked( $configuration, $field );
	$checked = ! empty( $configuration['values'][ $field ] );

	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label>';
	echo '<input type="checkbox" name="' . esc_attr( SQLITE_WORDPRESS_SMTP_OPTION . '[' . $field . ']' ) . '" value="1" ' . checked( $checked, true, false ) . disabled( $locked, true, false ) . '> ';
	echo esc_html( $description ) . '</label>';
	sqlite_wordpress_smtp_render_source_note( $configuration, $field );
	echo '</td></tr>';
}

/**
 * Renders one text or number setting.
 *
 * @param array<string,mixed> $configuration Effective configuration.
 * @param string              $field Field name.
 * @param string              $label Label.
 * @param string              $type Input type.
 * @param string              $description Description.
 * @param string              $attributes Additional safe attributes.
 * @return void
 */
function sqlite_wordpress_smtp_render_input( $configuration, $field, $label, $type, $description, $attributes = '' ) {
	$locked = sqlite_wordpress_smtp_field_is_locked( $configuration, $field );
	$value  = $configuration['values'][ $field ];

	echo '<tr><th scope="row"><label for="sqlite-wordpress-smtp-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
	echo '<input class="regular-text" id="sqlite-wordpress-smtp-' . esc_attr( $field ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( SQLITE_WORDPRESS_SMTP_OPTION . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '" ' . $attributes . disabled( $locked, true, false ) . '>';
	echo '<p class="description">' . esc_html( $description ) . '</p>';
	sqlite_wordpress_smtp_render_source_note( $configuration, $field );
	echo '</td></tr>';
}

/**
 * Renders the administrator SMTP settings page.
 *
 * @return void
 */
function sqlite_wordpress_smtp_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage SMTP settings.', 'sqlite-wordpress-smtp' ) );
	}

	$configuration = sqlite_wordpress_smtp_configuration();
	$settings      = $configuration['values'];
	$password_lock = sqlite_wordpress_smtp_field_is_locked( $configuration, 'password' );

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'SMTP Settings', 'sqlite-wordpress-smtp' ) . '</h1>';
	echo '<p>' . esc_html__( 'Configure wp_mail() to use an SMTP server. Environment variables override individual fields and are intentionally read-only in this page.', 'sqlite-wordpress-smtp' ) . '</p>';
	settings_errors( SQLITE_WORDPRESS_SMTP_OPTION );

	echo '<h2>' . esc_html__( 'Effective status', 'sqlite-wordpress-smtp' ) . '</h2>';
	echo '<table class="widefat striped" style="max-width:760px;"><tbody>';
	echo '<tr><th scope="row" style="width:32%;">' . esc_html__( 'SMTP transport', 'sqlite-wordpress-smtp' ) . '</th><td>' . esc_html( $settings['enabled'] ? __( 'Enabled', 'sqlite-wordpress-smtp' ) : __( 'Disabled', 'sqlite-wordpress-smtp' ) ) . '</td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Configuration', 'sqlite-wordpress-smtp' ) . '</th><td>' . esc_html( empty( $configuration['errors'] ) ? __( 'Valid', 'sqlite-wordpress-smtp' ) : __( 'Invalid — mail is blocked while SMTP is enabled', 'sqlite-wordpress-smtp' ) ) . '</td></tr>';
	echo '</tbody></table>';

	if ( ! empty( $configuration['errors'] ) ) {
		echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Effective configuration errors:', 'sqlite-wordpress-smtp' ) . '</strong></p><ul style="list-style:disc;padding-left:2em;">';
		foreach ( $configuration['errors'] as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	echo '<form method="post" action="options.php">';
	settings_fields( 'sqlite_wordpress_smtp' );
	echo '<table class="form-table" role="presentation"><tbody>';
	sqlite_wordpress_smtp_render_checkbox( $configuration, 'enabled', __( 'Enable SMTP', 'sqlite-wordpress-smtp' ), __( 'Route wp_mail() through the configured SMTP server.', 'sqlite-wordpress-smtp' ) );
	sqlite_wordpress_smtp_render_input( $configuration, 'host', __( 'SMTP host', 'sqlite-wordpress-smtp' ), 'text', __( 'Docker Compose service names such as owlmail are accepted.', 'sqlite-wordpress-smtp' ), 'maxlength="255" autocomplete="off" ' );
	sqlite_wordpress_smtp_render_input( $configuration, 'port', __( 'SMTP port', 'sqlite-wordpress-smtp' ), 'number', __( 'OwlMail uses port 1025 by default.', 'sqlite-wordpress-smtp' ), 'min="1" max="65535" step="1" ' );

	$encryption_locked = sqlite_wordpress_smtp_field_is_locked( $configuration, 'encryption' );
	echo '<tr><th scope="row"><label for="sqlite-wordpress-smtp-encryption">' . esc_html__( 'Encryption', 'sqlite-wordpress-smtp' ) . '</label></th><td>';
	echo '<select id="sqlite-wordpress-smtp-encryption" name="' . esc_attr( SQLITE_WORDPRESS_SMTP_OPTION . '[encryption]' ) . '" ' . disabled( $encryption_locked, true, false ) . '>';
	foreach ( array( 'none' => __( 'None', 'sqlite-wordpress-smtp' ), 'tls' => __( 'STARTTLS', 'sqlite-wordpress-smtp' ), 'ssl' => __( 'Implicit TLS (SMTPS)', 'sqlite-wordpress-smtp' ) ) as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '" ' . selected( $settings['encryption'], $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select><p class="description">' . esc_html__( 'Use None for OwlMail on a private Docker network.', 'sqlite-wordpress-smtp' ) . '</p>';
	sqlite_wordpress_smtp_render_source_note( $configuration, 'encryption' );
	echo '</td></tr>';

	sqlite_wordpress_smtp_render_checkbox( $configuration, 'auto_tls', __( 'Automatic TLS', 'sqlite-wordpress-smtp' ), __( 'Allow PHPMailer to upgrade a plain connection when the server advertises STARTTLS.', 'sqlite-wordpress-smtp' ) );
	sqlite_wordpress_smtp_render_checkbox( $configuration, 'authentication', __( 'Authentication', 'sqlite-wordpress-smtp' ), __( 'Authenticate with the SMTP username and password.', 'sqlite-wordpress-smtp' ) );
	sqlite_wordpress_smtp_render_input( $configuration, 'username', __( 'Username', 'sqlite-wordpress-smtp' ), 'text', __( 'Required when authentication is enabled.', 'sqlite-wordpress-smtp' ), 'maxlength="320" autocomplete="username" ' );

	echo '<tr><th scope="row"><label for="sqlite-wordpress-smtp-password">' . esc_html__( 'Password', 'sqlite-wordpress-smtp' ) . '</label></th><td>';
	echo '<input class="regular-text" id="sqlite-wordpress-smtp-password" type="password" name="' . esc_attr( SQLITE_WORDPRESS_SMTP_OPTION . '[password]' ) . '" value="" maxlength="4096" autocomplete="new-password" ' . disabled( $password_lock, true, false ) . '>';
	if ( $password_lock ) {
		echo '<p class="description"><strong>' . esc_html__( 'Controlled by an environment variable; the effective secret is never displayed.', 'sqlite-wordpress-smtp' ) . '</strong></p>';
	} else {
		echo '<p class="description">' . esc_html( '' !== $settings['password'] ? __( 'A stored password is configured. Leave this field empty to keep it.', 'sqlite-wordpress-smtp' ) : __( 'No stored password is configured.', 'sqlite-wordpress-smtp' ) ) . '</p>';
		echo '<label><input type="checkbox" name="' . esc_attr( SQLITE_WORDPRESS_SMTP_OPTION . '[clear_password]' ) . '" value="1"> ' . esc_html__( 'Clear the stored password', 'sqlite-wordpress-smtp' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Stored passwords are kept in the WordPress database. For deployments, prefer WORDPRESS_SMTP_PASSWORD_FILE.', 'sqlite-wordpress-smtp' ) . '</p>';
	}
	echo '</td></tr>';

	sqlite_wordpress_smtp_render_input( $configuration, 'from_email', __( 'From email', 'sqlite-wordpress-smtp' ), 'email', __( 'Used only when Force From is enabled.', 'sqlite-wordpress-smtp' ), 'maxlength="320" autocomplete="email" ' );
	sqlite_wordpress_smtp_render_input( $configuration, 'from_name', __( 'From name', 'sqlite-wordpress-smtp' ), 'text', __( 'Used only when Force From is enabled; leave empty to preserve WordPress’s name.', 'sqlite-wordpress-smtp' ), 'maxlength="255" autocomplete="off" ' );
	sqlite_wordpress_smtp_render_checkbox( $configuration, 'force_from', __( 'Force From', 'sqlite-wordpress-smtp' ), __( 'Override From values supplied by themes and plugins.', 'sqlite-wordpress-smtp' ) );
	sqlite_wordpress_smtp_render_input( $configuration, 'timeout', __( 'Connection timeout', 'sqlite-wordpress-smtp' ), 'number', __( 'Seconds to wait for the SMTP connection, from 1 to 300.', 'sqlite-wordpress-smtp' ), 'min="1" max="300" step="1" ' );
	echo '</tbody></table>';
	submit_button();
	echo '</form>';

	echo '<p><strong>' . esc_html__( 'OwlMail preset:', 'sqlite-wordpress-smtp' ) . '</strong> <code>host=owlmail</code>, <code>port=1025</code>, <code>encryption=none</code>, <code>auto_tls=false</code>, <code>authentication=false</code>.</p>';
	echo '</div>';
}

/**
 * Shows configuration failures on other administrator pages when SMTP is
 * enabled, so a broken environment override is not hidden outside this page.
 *
 * @return void
 */
function sqlite_wordpress_smtp_render_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$configuration = sqlite_wordpress_smtp_configuration();
	if ( ! $configuration['block_mail'] ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && 'settings_page_sqlite-wordpress-smtp' === $screen->id ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'SQLite WordPress SMTP is enabled but invalid, so wp_mail() is blocked. Review Settings → SMTP.', 'sqlite-wordpress-smtp' );
	echo '</p></div>';
}
