<?php
/**
 * Phase 21 qualification gate: the account menu against a real WordPress.
 *
 * Landed in 21.0 with **no rules**, deliberately. The plumbing below — locating
 * a WordPress, booting it, refusing to pass quietly when it cannot — is the part
 * that takes a sub-phase by surprise, and 21.5 should be adding assertions
 * rather than discovering that a gate needs a database.
 *
 * What will live here, and why none of it can live in the pure suite:
 *
 *   1. (21.5) a page hosting only `[smart_login_button]` has the button
 *      stylesheet among its enqueued styles, and does not have smart-login.css.
 *      This is finding 1's rule. An enqueue is precisely the class of defect a
 *      fixture reports as fine — `Assets::maybe_enqueue()` hangs off
 *      `is_singular()` and a real query.
 *   2. (21.5) the token stylesheet is enqueued ahead of the button's.
 *   3. (21.7) with a nav location selected, the rendered menu contains exactly
 *      one `.sl-account-item`, and it is a direct child of the `<ul>`.
 *      `wp_nav_menu_items` fires inside a theme's own markup, and whether the
 *      result is a valid `<li>` is not a question a fixture can answer.
 *   4. (21.7) with no location selected, no theme menu changes at all.
 *
 * Opt-in, like every other gate here: without the environment variables it
 * reports BLOCKED rather than passing quietly. A gate that passes because it
 * could not run is the failure mode the whole `tests/integration/` directory
 * exists to avoid — four gates once missed a fatal that only a real WordPress
 * could show.
 *
 * @package SmartLogin
 */

declare( strict_types=1 );

$wp_root     = rtrim( (string) getenv( 'SMART_LOGIN_WP_ROOT' ), "\\/" );
$plugin_root = rtrim( (string) getenv( 'SMART_LOGIN_PLUGIN_ROOT' ), "\\/" );

$blocked = static function ( string $message ): never {
	echo "SMART_LOGIN_ACCOUNT_MENU_INTEGRATION_BLOCKED\n";
	echo 'reason=' . $message . "\n";
	exit( 2 );
};

$failed = static function ( string $message ): never {
	echo "SMART_LOGIN_ACCOUNT_MENU_INTEGRATION_FAILED\n";
	echo 'reason=' . $message . "\n";
	exit( 1 );
};

if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'SMART_LOGIN_WP_ROOT must point to a WordPress public root' );
}

if ( '' === $plugin_root || ! is_file( $plugin_root . DIRECTORY_SEPARATOR . 'smart-login.php' ) ) {
	$blocked( 'SMART_LOGIN_PLUGIN_ROOT must point to the current plugin source' );
}

define( 'WP_USE_THEMES', false );

require $wp_root . DIRECTORY_SEPARATOR . 'wp-load.php';

if ( ! class_exists( \SmartLogin\Frontend\Shortcodes::class ) ) {
	$blocked( 'the plugin is not active on this install' );
}

$checks = array();
$failures = array();

// Rules arrive in 21.5 and 21.7. Until then this gate proves it can boot a real
// WordPress and reach the plugin, which is the whole of its job today.

printf( "\nAccount menu gate: %d checks, %d failed\n", count( $checks ), count( $failures ) );

if ( $failures ) {
	$failed( implode( '; ', $failures ) );
}

echo "SMART_LOGIN_ACCOUNT_MENU_INTEGRATION_OK\n";
exit( 0 );
