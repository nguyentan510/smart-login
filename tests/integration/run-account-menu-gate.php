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

$checks   = array();
$failures = array();

$check = static function ( string $label, bool $ok, string $detail = '' ) use ( &$checks, &$failures ): void {
	$checks[] = $label;

	if ( ! $ok ) {
		$failures[] = $label . ( '' !== $detail ? ' (' . $detail . ')' : '' );
	}

	printf( "  %-5s %s\n", $ok ? 'OK' : 'FAIL', $label );

	if ( ! $ok && '' !== $detail ) {
		printf( "        %s\n", $detail );
	}
};

/*
 * A real page carrying only the button shortcode.
 *
 * `Assets::maybe_enqueue()` gates on `is_singular()` and a shortcode in
 * post_content, so nothing short of a real query proves it fires. Removed again
 * at the end — this gate is not the destructive one.
 */
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Smart Login button gate',
		'post_content' => '[smart_login_button]',
	)
);

if ( ! $page_id || is_wp_error( $page_id ) ) {
	$blocked( 'could not create the fixture page' );
}

$cleanup = static function () use ( $page_id ): void {
	wp_delete_post( $page_id, true );
};

$assets = new \SmartLogin\Frontend\Assets();

$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page_id ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->the_post();

$assets->register_assets();
$assets->maybe_enqueue();

$enqueued = wp_styles()->queue;

$check(
	'a page with only [smart_login_button] enqueues the button stylesheet',
	in_array( \SmartLogin\Frontend\Assets::BUTTON_HANDLE, $enqueued, true ),
	'queue: ' . implode( ', ', $enqueued )
);

/*
 * Finding 1 in one line. The button is not the form, and a page carrying it must
 * not pay for 1,500 lines of stylesheet it does not use.
 */
$check(
	'and does not drag in the sign-in form stylesheet',
	! in_array( \SmartLogin\Frontend\Assets::HANDLE, $enqueued, true ),
	'queue: ' . implode( ', ', $enqueued )
);

/*
 * Registration order is not enough — WordPress resolves the dependency at print
 * time, so the question is what the printed document contains and in which
 * order.
 */
ob_start();
wp_print_styles( array( \SmartLogin\Frontend\Assets::BUTTON_HANDLE ) );
$printed = (string) ob_get_clean();

$token_at  = strpos( $printed, 'smart-login-tokens.css' );
$button_at = strpos( $printed, 'smart-login-button.css' );

$check(
	'the token stylesheet is printed, and before the button',
	false !== $token_at && false !== $button_at && $token_at < $button_at,
	'tokens at ' . var_export( $token_at, true ) . ', button at ' . var_export( $button_at, true )
);

/*
 * Inherited from 21.3, which could not assert it: the pure suite stubs
 * `wp_logout_url()` to a fixed string, so the property "this URL is generated
 * per session, not stored" is only visible against a real WordPress.
 */
$logout_one = wp_logout_url( home_url( '/' ) );

wp_set_current_user( 1 );
$logout_admin = wp_logout_url( home_url( '/' ) );

$check(
	'the logout URL carries a nonce',
	false !== strpos( $logout_one, '_wpnonce=' ),
	$logout_one
);

$check(
	'the logout URL differs once the visitor is somebody',
	$logout_one !== $logout_admin,
	'a stored string could not do this'
);

$menu = \SmartLogin\Frontend\AccountMenu::items( get_current_user_id() );
$tail = end( $menu ) ?: array();

$check(
	'AccountMenu hands out that generated URL, not a stored one',
	'logout' === ( $tail['key'] ?? '' ) && ( $tail['url'] ?? '' ) === wp_logout_url( home_url( '/' ) ),
	'tail: ' . wp_json_encode( $tail )
);

$cleanup();

printf( "\nAccount menu gate: %d checks, %d failed\n", count( $checks ), count( $failures ) );

if ( $failures ) {
	$failed( implode( '; ', $failures ) );
}

echo "SMART_LOGIN_ACCOUNT_MENU_INTEGRATION_OK\n";
exit( 0 );
