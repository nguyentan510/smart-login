<?php
/**
 * Fail-closed preflight for real browser OAuth qualification.
 *
 * Credentials are resolved from encrypted Settings or deployment overrides
 * and are never printed.
 *
 * @package SmartLogin
 */

declare( strict_types=1 );

$wp_root     = rtrim( (string) getenv( 'SMART_LOGIN_WP_ROOT' ), "\\/" );
$plugin_root = rtrim( (string) getenv( 'SMART_LOGIN_PLUGIN_ROOT' ), "\\/" );
$db_host     = (string) getenv( 'SMART_LOGIN_DB_HOST' );
$db_name     = (string) getenv( 'SMART_LOGIN_DB_NAME' );
$db_user     = (string) getenv( 'SMART_LOGIN_DB_USER' );
$db_pass     = (string) getenv( 'SMART_LOGIN_DB_PASSWORD' );
$prefix      = (string) ( getenv( 'SMART_LOGIN_DB_PREFIX' ) ?: 'wp_' );
$site_url    = rtrim( (string) getenv( 'SMART_LOGIN_E2E_SITE_URL' ), '/' );
$selection   = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) ( getenv( 'SMART_LOGIN_E2E_PROVIDER' ) ?: 'both' ) ) );

$blocked = static function ( string $reason ): never {
	echo "SMART_LOGIN_PROVIDER_E2E_BLOCKED\n";
	echo 'reason=' . $reason . "\n";
	exit( 2 );
};

if ( ! in_array( $selection, array( 'google', 'zalo', 'both' ), true ) ) {
	$blocked( 'SMART_LOGIN_E2E_PROVIDER must be google, zalo, or both' );
}
if ( ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'WordPress runtime is missing' );
}
if ( ! is_file( $plugin_root . DIRECTORY_SEPARATOR . 'smart-login.php' ) ) {
	$blocked( 'current Smart Login source is missing' );
}
if ( '' === $site_url || 'https' !== strtolower( (string) parse_url( $site_url, PHP_URL_SCHEME ) ) ) {
	$blocked( 'browser OAuth qualification requires an HTTPS site URL' );
}
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'database prefix is invalid' );
}

$constant_map = array(
	'SMART_LOGIN_GOOGLE_CLIENT_ID',
	'SMART_LOGIN_GOOGLE_CLIENT_SECRET',
	'SMART_LOGIN_GOOGLE_REDIRECT_URI',
	'SMART_LOGIN_ZALO_APP_ID',
	'SMART_LOGIN_ZALO_APP_SECRET',
	'SMART_LOGIN_ZALO_REDIRECT_URI',
);
foreach ( $constant_map as $constant_name ) {
	$value = (string) getenv( $constant_name );
	if ( '' !== $value && ! defined( $constant_name ) ) {
		define( $constant_name, $value );
	}
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
define( 'WP_PLUGIN_URL', $site_url . '/wp-content/plugins' );
$table_prefix = $prefix;

try {
	require $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php';
} catch ( Throwable $exception ) {
	$blocked( 'WordPress/MySQL bootstrap failed' );
}

if (
	'https' !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) )
	|| 'https' !== strtolower( (string) wp_parse_url( site_url( '/' ), PHP_URL_SCHEME ) )
) {
	$blocked( 'WordPress home and site URLs must both use HTTPS' );
}

$providers = array(
	'google' => new \SmartLogin\Auth\Providers\GoogleProvider(),
	'zalo'   => new \SmartLogin\Auth\Providers\ZaloProvider(),
);
$selected = 'both' === $selection ? array( 'google', 'zalo' ) : array( $selection );

foreach ( $selected as $provider_id ) {
	$provider = $providers[ $provider_id ];
	if ( ! $provider->is_available() ) {
		$blocked( $provider_id . ' credential or feature flag is not configured' );
	}
	$callback = $provider->callback_url();
	$query = array();
	parse_str( (string) wp_parse_url( $callback, PHP_URL_QUERY ), $query );
	if (
		'https' !== strtolower( (string) wp_parse_url( $callback, PHP_URL_SCHEME ) )
		|| strtolower( (string) wp_parse_url( $callback, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) )
		|| '/wp-admin/admin-post.php' !== (string) wp_parse_url( $callback, PHP_URL_PATH )
		|| 'smart_login_provider_callback' !== (string) ( $query['action'] ?? '' )
		|| $provider_id !== (string) ( $query['provider'] ?? '' )
	) {
		$blocked( $provider_id . ' callback URI does not match the HTTPS site callback contract' );
	}
	echo 'provider.' . $provider_id . ".available=yes\n";
	echo 'provider.' . $provider_id . '.callback=' . $callback . "\n";
}

echo "SMART_LOGIN_PROVIDER_E2E_PREFLIGHT_OK\n";
