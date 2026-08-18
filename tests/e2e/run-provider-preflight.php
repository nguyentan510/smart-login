<?php
/**
 * Fail-closed preflight for real browser OAuth qualification.
 *
 * Credentials are resolved from encrypted Settings or deployment overrides
 * and are never printed.
 *
 * @package OmniWP
 */

declare( strict_types=1 );

$wp_root     = rtrim( (string) getenv( 'OMNIWP_WP_ROOT' ), "\\/" );
$plugin_root = rtrim( (string) getenv( 'OMNIWP_PLUGIN_ROOT' ), "\\/" );
$db_host     = (string) getenv( 'OMNIWP_DB_HOST' );
$db_name     = (string) getenv( 'OMNIWP_DB_NAME' );
$db_user     = (string) getenv( 'OMNIWP_DB_USER' );
$db_pass     = (string) getenv( 'OMNIWP_DB_PASSWORD' );
$prefix      = (string) ( getenv( 'OMNIWP_DB_PREFIX' ) ?: 'wp_' );
$site_url    = rtrim( (string) getenv( 'OMNIWP_E2E_SITE_URL' ), '/' );
$selection   = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) ( getenv( 'OMNIWP_E2E_PROVIDER' ) ?: 'both' ) ) );

$blocked = static function ( string $reason ): never {
	echo "OMNIWP_PROVIDER_E2E_BLOCKED\n";
	echo 'reason=' . $reason . "\n";
	exit( 2 );
};

if ( ! in_array( $selection, array( 'google', 'both' ), true ) ) {
	$blocked( 'OMNIWP_E2E_PROVIDER must be google or both' );
}
if ( ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'WordPress runtime is missing' );
}
if ( ! is_file( $plugin_root . DIRECTORY_SEPARATOR . 'omniwp.php' ) ) {
	$blocked( 'current Smart Login source is missing' );
}
if ( '' === $site_url || 'https' !== strtolower( (string) parse_url( $site_url, PHP_URL_SCHEME ) ) ) {
	$blocked( 'browser OAuth qualification requires an HTTPS site URL' );
}
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'database prefix is invalid' );
}

$constant_map = array(
	'OMNIWP_GOOGLE_CLIENT_ID',
	'OMNIWP_GOOGLE_CLIENT_SECRET',
	'OMNIWP_GOOGLE_REDIRECT_URI',
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
	'google' => new \OmniWP\Auth\Providers\GoogleProvider(),
);
$selected = 'both' === $selection ? array_keys( $providers ) : array( $selection );

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
		|| 'omniwp_provider_callback' !== (string) ( $query['action'] ?? '' )
		|| $provider_id !== (string) ( $query['provider'] ?? '' )
	) {
		$blocked( $provider_id . ' callback URI does not match the HTTPS site callback contract' );
	}
	echo 'provider.' . $provider_id . ".available=yes\n";
	echo 'provider.' . $provider_id . '.callback=' . $callback . "\n";
}

echo "OMNIWP_PROVIDER_E2E_PREFLIGHT_OK\n";
