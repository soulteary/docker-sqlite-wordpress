<?php
/**
 * Regression tests for the optional SMTP MU Plugin.
 */

define( 'ABSPATH', __DIR__ . '/' );

$smtp_test_option          = array();
$smtp_test_actions         = array();
$smtp_test_filters         = array();
$smtp_test_settings_errors = array();
$smtp_test_fired_actions   = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $smtp_test_actions;
	$smtp_test_actions[ $hook ] = array( $callback, $priority, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $smtp_test_filters;
	$smtp_test_filters[ $hook ] = array( $callback, $priority, $accepted_args );
}

function get_option( $name, $default = false ) {
	global $smtp_test_option;
	if ( 'sqlite_wordpress_smtp' === $name ) {
		return $smtp_test_option;
	}
	return $default;
}

function __( $text ) {
	return $text;
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return is_email( $email ) ? $email : '';
}

function add_settings_error( $setting, $code, $message ) {
	global $smtp_test_settings_errors;
	$smtp_test_settings_errors[] = array( $setting, $code, $message );
}

function do_action( $hook, ...$arguments ) {
	global $smtp_test_fired_actions;
	$smtp_test_fired_actions[] = array( $hook, $arguments );
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

class SMTP_Test_Mailer {
	public $smtp = false;
	public $Host;
	public $Port;
	public $Timeout;
	public $SMTPAuth;
	public $SMTPAutoTLS;
	public $SMTPSecure;
	public $Username;
	public $Password;

	public function isSMTP() {
		$this->smtp = true;
	}
}

require dirname( __DIR__ ) . '/plugins/sqlite-wordpress-smtp.php';

function smtp_test_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true ) . "\nActual:   " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function smtp_test_assert_true( $actual, $label ) {
	smtp_test_assert_same( true, $actual, $label );
}

function smtp_test_environment_names() {
	return array_merge(
		array_values( sqlite_wordpress_smtp_environment_map() ),
		array( 'WORDPRESS_SMTP_PASSWORD_FILE' )
	);
}

function smtp_test_reset() {
	global $smtp_test_option, $smtp_test_settings_errors, $smtp_test_fired_actions;
	$smtp_test_option          = array();
	$smtp_test_settings_errors = array();
	$smtp_test_fired_actions   = array();
	foreach ( smtp_test_environment_names() as $name ) {
		putenv( $name );
	}
}

smtp_test_assert_same( array( 'sqlite_wordpress_smtp_configure_mailer', 10, 1 ), $smtp_test_actions['phpmailer_init'], 'PHPMailer hook is registered' );
smtp_test_assert_same( array( 'sqlite_wordpress_smtp_block_invalid_mail', PHP_INT_MAX, 2 ), $smtp_test_filters['pre_wp_mail'], 'invalid configuration blocker is registered last' );

smtp_test_reset();
$configuration = sqlite_wordpress_smtp_configuration();
smtp_test_assert_same( false, $configuration['values']['enabled'], 'SMTP defaults to disabled' );
smtp_test_assert_same( 'owlmail', $configuration['values']['host'], 'OwlMail service name is the default host' );
smtp_test_assert_same( 1025, $configuration['values']['port'], 'OwlMail SMTP port is the default port' );
smtp_test_assert_same( false, $configuration['block_mail'], 'disabled default never blocks normal WordPress mail' );

smtp_test_reset();
$smtp_test_option = array(
	'enabled'        => true,
	'host'           => 'smtp.internal',
	'port'           => 2525,
	'encryption'     => 'tls',
	'auto_tls'       => true,
	'authentication' => true,
	'username'       => 'mailer@example.com',
	'password'       => 'stored-secret',
	'from_email'     => 'wordpress@example.com',
	'from_name'      => 'SQLite WordPress',
	'force_from'     => true,
	'timeout'        => 25,
);
$mailer           = new SMTP_Test_Mailer();
sqlite_wordpress_smtp_configure_mailer( $mailer );
smtp_test_assert_true( $mailer->smtp, 'stored valid settings enable SMTP mode' );
smtp_test_assert_same( 'smtp.internal', $mailer->Host, 'stored SMTP host is applied' );
smtp_test_assert_same( 2525, $mailer->Port, 'stored SMTP port is applied' );
smtp_test_assert_same( 'tls', $mailer->SMTPSecure, 'stored encryption is applied' );
smtp_test_assert_same( true, $mailer->SMTPAutoTLS, 'stored automatic TLS is applied' );
smtp_test_assert_same( true, $mailer->SMTPAuth, 'stored authentication is applied' );
smtp_test_assert_same( 'mailer@example.com', $mailer->Username, 'stored username is applied' );
smtp_test_assert_same( 'stored-secret', $mailer->Password, 'stored password is applied' );
smtp_test_assert_same( 25, $mailer->Timeout, 'stored timeout is applied' );
smtp_test_assert_same( 'wordpress@example.com', sqlite_wordpress_smtp_filter_from_email( 'original@example.com' ), 'Force From email is applied' );
smtp_test_assert_same( 'SQLite WordPress', sqlite_wordpress_smtp_filter_from_name( 'WordPress' ), 'Force From name is applied' );

smtp_test_reset();
$smtp_test_option = array(
	'enabled' => false,
	'host'    => 'stored.example.com',
	'port'    => 25,
);
putenv( 'WORDPRESS_SMTP_ENABLED=true' );
putenv( 'WORDPRESS_SMTP_HOST=owlmail' );
putenv( 'WORDPRESS_SMTP_PORT=1025' );
putenv( 'WORDPRESS_SMTP_ENCRYPTION=none' );
putenv( 'WORDPRESS_SMTP_AUTO_TLS=false' );
putenv( 'WORDPRESS_SMTP_AUTH=false' );
$configuration = sqlite_wordpress_smtp_configuration();
smtp_test_assert_same( true, $configuration['values']['enabled'], 'environment enables SMTP' );
smtp_test_assert_same( 'owlmail', $configuration['values']['host'], 'environment overrides stored host' );
smtp_test_assert_same( 1025, $configuration['values']['port'], 'environment overrides stored port' );
smtp_test_assert_same( 'environment:WORDPRESS_SMTP_HOST', $configuration['sources']['host'], 'environment source is reported' );
smtp_test_assert_same( array(), $configuration['errors'], 'OwlMail environment preset is valid' );

putenv( 'WORDPRESS_SMTP_ENABLED=false' );
$configuration = sqlite_wordpress_smtp_configuration();
smtp_test_assert_same( false, $configuration['values']['enabled'], 'exact false environment value disables stored SMTP' );
smtp_test_assert_same( false, $configuration['block_mail'], 'environment-disabled SMTP does not block normal mail' );

smtp_test_reset();
$smtp_test_option = array( 'enabled' => true );
putenv( 'WORDPRESS_SMTP_PORT=not-a-port' );
$configuration = sqlite_wordpress_smtp_configuration();
smtp_test_assert_true( ! empty( $configuration['errors'] ), 'invalid environment port is reported' );
smtp_test_assert_same( true, $configuration['block_mail'], 'invalid enabled SMTP fails closed' );
$mail_result = sqlite_wordpress_smtp_block_invalid_mail( null, array( 'to' => array( 'test@example.com' ) ) );
smtp_test_assert_same( false, $mail_result, 'invalid enabled SMTP short-circuits wp_mail' );
smtp_test_assert_same( 'wp_mail_failed', $smtp_test_fired_actions[0][0], 'invalid enabled SMTP emits wp_mail_failed' );
smtp_test_assert_same( 'sqlite_wordpress_smtp_invalid_configuration', $smtp_test_fired_actions[0][1][0]->code, 'wp_mail_failed has a stable error code' );
$mail_result = sqlite_wordpress_smtp_block_invalid_mail( true, array( 'to' => array( 'test@example.com' ) ) );
smtp_test_assert_same( false, $mail_result, 'invalid enabled SMTP overrides an earlier successful short-circuit' );

smtp_test_reset();
$password_file = tempnam( sys_get_temp_dir(), 'smtp-password-' );
if ( false === $password_file ) {
	fwrite( STDERR, "FAIL: could not create password file fixture\n" );
	exit( 1 );
}
file_put_contents( $password_file, "file-secret with spaces\n" );
$smtp_test_option = array(
	'enabled'        => true,
	'authentication' => true,
	'username'       => 'owlmail-user',
);
putenv( 'WORDPRESS_SMTP_PASSWORD_FILE=' . $password_file );
$configuration = sqlite_wordpress_smtp_configuration();
smtp_test_assert_same( 'file-secret with spaces', $configuration['values']['password'], 'password file strips only its trailing line ending' );
smtp_test_assert_same( 'environment:WORDPRESS_SMTP_PASSWORD_FILE', $configuration['sources']['password'], 'password file source is reported' );
smtp_test_assert_same( array(), $configuration['errors'], 'valid password file configuration passes validation' );
unlink( $password_file );

smtp_test_reset();
$smtp_test_option = array(
	'enabled'  => true,
	'host'     => 'stored.example.com',
	'port'     => 2525,
	'password' => 'keep-me',
);
$sanitized        = sqlite_wordpress_smtp_sanitize_settings(
	array(
		'enabled'    => '1',
		'host'       => 'new.example.com',
		'port'       => '2526',
		'encryption' => 'none',
		'username'   => '',
		'password'   => '',
		'from_email' => '',
		'from_name'  => '',
		'timeout'    => '12',
	)
);
smtp_test_assert_same( 'keep-me', $sanitized['password'], 'empty password submission preserves the stored secret' );
smtp_test_assert_same( 'new.example.com', $sanitized['host'], 'unlocked host can be updated' );
smtp_test_assert_same( 2526, $sanitized['port'], 'unlocked port can be updated' );

$sanitized = sqlite_wordpress_smtp_sanitize_settings(
	array(
		'enabled'       => '1',
		'host'          => 'new.example.com',
		'port'          => '2526',
		'encryption'    => 'none',
		'username'      => '',
		'clear_password' => '1',
		'from_email'    => '',
		'from_name'     => '',
		'timeout'       => '12',
	)
);
smtp_test_assert_same( '', $sanitized['password'], 'explicit clear removes the stored secret' );

putenv( 'WORDPRESS_SMTP_HOST=environment.example.com' );
$sanitized = sqlite_wordpress_smtp_sanitize_settings(
	array(
		'enabled'    => '1',
		'host'       => 'ignored.example.com',
		'port'       => '2526',
		'encryption' => 'none',
		'username'   => '',
		'from_email' => '',
		'from_name'  => '',
		'timeout'    => '12',
	)
);
smtp_test_assert_same( 'stored.example.com', $sanitized['host'], 'saving preserves the stored value behind an environment override' );

smtp_test_reset();
fwrite( STDOUT, "sqlite-wordpress-smtp tests passed\n" );
