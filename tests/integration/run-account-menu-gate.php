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

/*
 * 21.7 — placement into a theme's own menu.
 *
 * `wp_nav_menu_items` fires inside markup the theme owns, and whether the
 * result is a valid `<li>` in the right `<ul>` is not a question a fixture can
 * answer. The location is registered here rather than assumed, because the
 * active theme may register none and the gate would then be measuring nothing.
 */
register_nav_menu( 'sl-gate-location', 'Smart Login gate location' );

/*
 * The filter is already attached — `Plugin::boot()` registered NavMenuItem when
 * wp-load ran. Attaching a second instance here made the gate report two
 * `.sl-account-item` on its first run: `add_filter()` de-duplicates a callback
 * by object hash, so two instances of the same class are two subscribers, not
 * one. That was the fixture's defect, not the code's, and it is left written
 * down because "call register() to be sure" is the obvious thing to do next
 * time somebody extends this file.
 */
$theme_items = '<li class="menu-item"><a href="/">Trang chủ</a></li>';
$args_wanted = (object) array( 'theme_location' => 'sl-gate-location' );
$args_other  = (object) array( 'theme_location' => 'some-other-location' );

// Off by default: nothing selected, nothing touched.
$check(
	'with no location selected, the theme menu is returned unchanged',
	apply_filters( 'wp_nav_menu_items', $theme_items, $args_wanted ) === $theme_items,
	'decision 11: a plugin may default to being invisible, not to editing the theme'
);

\SmartLogin\Settings::update( array( 'account_menu.nav_location' => 'sl-gate-location' ) );

$injected = (string) apply_filters( 'wp_nav_menu_items', $theme_items, $args_wanted );
$elsewhere = (string) apply_filters( 'wp_nav_menu_items', $theme_items, $args_other );

$check(
	'the chosen location gains exactly one .sl-account-item',
	1 === substr_count( $injected, 'sl-account-item' ),
	'found ' . substr_count( $injected, 'sl-account-item' )
);

$check(
	'and it is an <li>, which is the filter\'s whole contract',
	(bool) preg_match( '#<li class="menu-item sl-account-item">.*</li>\s*$#s', $injected ),
	'anything else puts a stray node inside the theme\'s <ul>'
);

$check(
	'every other location is left alone',
	$elsewhere === $theme_items,
	'a second menu on the page must not sprout a button'
);

/*
 * The one-renderer rule, compared rather than inspected. Two entry points
 * producing two markups is the drift this phase is organised against, and
 * placement is where it would reappear.
 */
$from_shortcode = ( new \SmartLogin\Frontend\Shortcodes() )->render_button( array() );
$inner          = (string) preg_replace( '#^.*<li class="menu-item sl-account-item">(.*)</li>\s*$#s', '$1', $injected );

$check(
	'the injected markup is the shortcode\'s markup, not a second copy',
	trim( $inner ) === trim( $from_shortcode ),
	'lengths: ' . strlen( trim( $inner ) ) . ' vs ' . strlen( trim( $from_shortcode ) )
);

/*
 * A theme switch leaves the option pointing at a location that no longer
 * exists. Simulated by asking for a location the theme never registered, which
 * is the same state from the filter's point of view.
 */
\SmartLogin\Settings::update( array( 'account_menu.nav_location' => 'gone-with-the-old-theme' ) );

$check(
	'a stale location injects nothing and warns about nothing',
	apply_filters( 'wp_nav_menu_items', $theme_items, $args_wanted ) === $theme_items,
	'themes get switched; a stale option is not an error condition'
);

\SmartLogin\Settings::update( array( 'account_menu.nav_location' => '' ) );

$cleanup();

printf( "\nAccount menu gate: %d checks, %d failed\n", count( $checks ), count( $failures ) );

if ( $failures ) {
	$failed( implode( '; ', $failures ) );
}

echo "SMART_LOGIN_ACCOUNT_MENU_INTEGRATION_OK\n";
exit( 0 );
