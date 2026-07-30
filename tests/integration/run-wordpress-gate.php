<?php
/**
 * WordPress/MySQL qualification gate for the authentication refactor.
 *
 * This is intentionally an opt-in local integration check. It uses a real
 * WordPress bootstrap and a real MySQL connection, then exercises the
 * migration, external identity repository, and profile completeness boundary.
 *
 * @package SmartLogin
 */

declare( strict_types=1 );

$wp_root  = rtrim( (string) getenv( 'SMART_LOGIN_WP_ROOT' ), "\\/" );
$db_host  = (string) getenv( 'SMART_LOGIN_DB_HOST' );
$db_name  = (string) getenv( 'SMART_LOGIN_DB_NAME' );
$db_user  = (string) getenv( 'SMART_LOGIN_DB_USER' );
$db_pass  = (string) getenv( 'SMART_LOGIN_DB_PASSWORD' );
$prefix   = (string) getenv( 'SMART_LOGIN_DB_PREFIX' );
$plugin_root = rtrim( (string) getenv( 'SMART_LOGIN_PLUGIN_ROOT' ), "\\/" );
$plugin      = $plugin_root . DIRECTORY_SEPARATOR . 'smart-login.php';

$blocked = static function ( string $message ): never {
	echo "SMART_LOGIN_AUTH_INTEGRATION_BLOCKED\n";
	echo 'reason=' . $message . "\n";
	exit( 2 );
};

$failed = static function ( string $message ): never {
	echo "SMART_LOGIN_AUTH_INTEGRATION_FAILED\n";
	echo 'reason=' . $message . "\n";
	exit( 1 );
};

if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'SMART_LOGIN_WP_ROOT must point to a WordPress public root' );
}
if ( '' === $plugin_root || ! is_file( $plugin ) ) {
	$blocked( 'SMART_LOGIN_PLUGIN_ROOT must point to the current plugin source' );
}

if ( '' === $db_host || '' === $db_name || '' === $db_user ) {
	$blocked( 'SMART_LOGIN_DB_HOST, SMART_LOGIN_DB_NAME and SMART_LOGIN_DB_USER are required' );
}

if ( '' === $prefix ) {
	$prefix = 'wp_';
}
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'SMART_LOGIN_DB_PREFIX contains unsupported characters' );
}

if ( ! extension_loaded( 'mysqli' ) && ! extension_loaded( 'pdo_mysql' ) ) {
	$blocked( 'PHP MySQL extension is not loaded' );
}

define( 'ABSPATH', $wp_root . DIRECTORY_SEPARATOR );
define( 'DB_NAME', $db_name );
define( 'DB_USER', $db_user );
define( 'DB_PASSWORD', $db_pass );
define( 'DB_HOST', $db_host );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', false );
define( 'WP_PLUGIN_DIR', dirname( $plugin_root ) );
define( 'WP_PLUGIN_URL', 'https://example.test/wp-content/plugins' );
$table_prefix = $prefix;

try {
	require $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php';
} catch ( Throwable $exception ) {
	$blocked( 'WordPress bootstrap failed: ' . $exception->getMessage() );
}

if ( ! class_exists( 'SmartLogin\\Installer' ) ) {
	if ( ! is_file( $plugin ) ) {
		$blocked( 'Smart Login plugin is not present in the WordPress installation' );
	}
	require_once $plugin;
}

global $wpdb;
if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb ) {
	$blocked( 'WordPress did not initialise wpdb' );
}

if ( '' !== (string) $wpdb->last_error ) {
	$blocked( 'Database bootstrap reported an error: ' . $wpdb->last_error );
}

try {
	\SmartLogin\Installer::maybe_upgrade();
	$table = \SmartLogin\Installer::external_identities_table();
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! is_array( $columns ) || ! in_array( 'provider', $columns, true ) || ! in_array( 'provider_subject', $columns, true ) ) {
		$failed( 'external identity table is missing required columns' );
	}

	// A second call proves the upgrade path is idempotent for the installed schema.
	\SmartLogin\Installer::maybe_upgrade();
	if ( (string) get_option( \SmartLogin\Installer::DB_VERSION_OPTION, '' ) !== (string) SMART_LOGIN_DB_VERSION ) {
		$failed( 'database version option does not match SMART_LOGIN_DB_VERSION' );
	}

	$login = 'sl_gate_' . strtolower( wp_generate_password( 10, false, false ) );
	$email = $login . '@example.test';
	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => $email,
			'display_name' => $login,
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		$failed( 'temporary WordPress user could not be created: ' . $user_id->get_error_message() );
	}

	$repository = new \SmartLogin\Auth\ExternalIdentityRepository();
	$subject = 'gate-' . wp_generate_uuid4();
	if ( ! $repository->insert( 'google', $subject, (int) $user_id, $email, true, array( 'source' => 'integration-gate' ), 'integration_gate' ) ) {
		$failed( 'external identity insert failed' );
	}
	$identity = $repository->find( 'google', $subject );
	if ( ! is_array( $identity ) || (int) $identity['user_id'] !== (int) $user_id ) {
		$failed( 'external identity lookup returned the wrong user' );
	}
	if ( 1 !== count( $repository->find_by_verified_email( $email ) ) ) {
		$failed( 'verified email lookup did not return the inserted identity' );
	}

	$status = ( new \SmartLogin\Auth\ProfileCompletionService() )->status( (int) $user_id );
	if ( ! isset( $status['complete'], $status['required_missing'], $status['recommended_missing'] ) || ! is_array( $status['required_missing'] ) ) {
		$failed( 'profile completion service returned an invalid status contract' );
	}

	$repository->delete( 'google', $subject, (int) $user_id );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $user_id );
} catch ( Throwable $exception ) {
	if ( isset( $user_id ) && function_exists( 'wp_delete_user' ) ) {
		@wp_delete_user( (int) $user_id );
	}
	$failed( 'integration assertion raised an exception: ' . $exception->getMessage() );
}

echo "SMART_LOGIN_AUTH_INTEGRATION_OK\n";
echo 'wordpress=' . get_bloginfo( 'version' ) . "\n";
echo 'external_identities_table=' . \SmartLogin\Installer::external_identities_table() . "\n";
echo 'db_version=' . get_option( \SmartLogin\Installer::DB_VERSION_OPTION ) . "\n";
