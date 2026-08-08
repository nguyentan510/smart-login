<?php
/**
 * Account-menu fitness — one list of destinations, one glyph set, one token home.
 *
 * Normative spec: docs/account-menu.md. Progress: docs/refactor-plan.md
 * Phase 21.
 *
 * Landed `spec` in 21.0, which is what that kind is for. Most of the rules below
 * are red or pending the day they land, deliberately: a rule written after the
 * fix cannot fail, and a rule that has never failed is a comment.
 *
 * Nine of them describe code that does not exist yet. Each asserts its subject
 * was **found** before counting anything, so narrowing a rule to nothing reports
 * PENDING rather than passing vacuously — the failure mode 10.0's PENDING rows
 * were written to avoid.
 *
 * Rule 9 is the one most at risk of that trap: "no template reads the menu
 * option" is true today for the uninteresting reason that no such option exists.
 * It therefore checks the option is declared first, and only then that nothing
 * outside AccountMenu reads it.
 *
 * Run with:  php tests/identity/run-account-menu-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Frontend\AccountForm;

$sl_sources = sl_plugin_sources();

/**
 * Contents of a file that may not exist yet.
 */
$sl_file = static function ( string $relative ): string {
	return sl_source( $relative );
};

/*
 * The site has a page hosting the sign-in flow. Without this `Flow::login_url()`
 * is '' and the button falls back to '#login' — so a rule about degradation
 * would be measuring the fixture's lack of a login page rather than the button.
 */
add_filter(
	'smart_login_login_url',
	static function (): string {
		return 'https://example.test/dang-nhap';
	}
);

/**
 * Render the button shortcode in a chosen signed-in state.
 *
 * Returns the error rather than swallowing it. The first draft of this helper
 * caught the throw and returned '', and three rules phrased as "the markup does
 * not contain X" passed against an empty string — a false green in the suite
 * written to prevent false greens. `shortcode_atts()` was simply not stubbed.
 *
 * Restores the flag afterwards: two rules render in opposite states and the
 * second must not inherit the first's world.
 *
 * @return array{ok:bool,markup:string,error:string}
 */
$sl_render_button = static function ( bool $logged_in ): array {
	$was                     = $GLOBALS['sl_logged_in'] ?? false;
	$GLOBALS['sl_logged_in'] = $logged_in;

	$result = array(
		'ok'     => false,
		'markup' => '',
		'error'  => '',
	);

	try {
		$shortcodes       = new \SmartLogin\Frontend\Shortcodes();
		$result['markup'] = (string) $shortcodes->render_button( array() );
		$result['ok']     = '' !== $result['markup'];
	} catch ( \Throwable $e ) {
		$result['error'] = get_class( $e ) . ': ' . $e->getMessage();
	}

	$GLOBALS['sl_logged_in'] = $was;

	return $result;
};

// ---------------------------------------------------------------------------
sl_section( 'Rule 1 — the tokens have one home (decision 13)' );
// ---------------------------------------------------------------------------

$sl_token_file = $sl_file( 'assets/css/smart-login-tokens.css' );

sl_assert(
	'assets/css/smart-login-tokens.css exists',
	'' !== $sl_token_file,
	'Decision 13: the token block leaves .smart-login, which welds twenty design tokens to a page-layout rule.'
);

if ( '' === $sl_token_file ) {
	sl_pending( 'no --sl-* declaration lives outside the token file', 'the token file' );
} else {
	$sl_stray = array();

	foreach ( array( 'assets/css/smart-login.css', 'assets/css/smart-login-dialog.css', 'assets/css/smart-login-button.css', 'assets/css/admin.css' ) as $sl_css ) {
		$sl_body = $sl_file( $sl_css );

		if ( '' !== $sl_body && preg_match( '/^\s*--sl-[a-z0-9-]+\s*:/m', $sl_body ) ) {
			$sl_stray[] = $sl_css;
		}
	}

	sl_assert(
		'no --sl-* declaration lives outside the token file',
		array() === $sl_stray,
		'declared in: ' . implode( ', ', $sl_stray )
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 2 — one glyph vocabulary (finding 8, decision 6)' );
// ---------------------------------------------------------------------------

/*
 * ProviderMark and the provider classes are the named exemption, not a pattern
 * that happens not to match: `icon_svg()` returns a trademark in its owner's
 * colours, and folding that into a currentColor UI set would be unification
 * past the point where it means anything.
 */
$sl_glyph_exempt = array(
	'includes/Frontend/class-icon-set.php',
	'includes/Frontend/class-provider-mark.php',
	'includes/Auth/Providers/class-google-provider.php',
	'includes/Auth/Providers/class-login-provider-interface.php',
);

$sl_inline_svg = array();

foreach ( $sl_sources as $sl_path => $sl_body ) {
	$sl_rel = str_replace( '\\', '/', $sl_path );

	if ( in_array( $sl_rel, $sl_glyph_exempt, true ) ) {
		continue;
	}

	if ( false !== strpos( $sl_body, '<svg' ) ) {
		$sl_inline_svg[] = $sl_rel;
	}
}

sl_assert(
	'no <svg literal outside IconSet and the provider mark',
	array() === $sl_inline_svg,
	'inline SVG in: ' . implode( ', ', $sl_inline_svg )
);

// ---------------------------------------------------------------------------
sl_section( 'Rule 3 — an unknown icon is unrepresentable (decision 6)' );
// ---------------------------------------------------------------------------

$sl_has_icon_set = class_exists( '\SmartLogin\Frontend\IconSet' );

sl_assert(
	'IconSet exists',
	$sl_has_icon_set,
	'Decision 6: one closed set of UI glyphs, chosen by name.'
);

if ( ! $sl_has_icon_set ) {
	sl_pending( 'an unknown icon name resolves to the fallback', 'IconSet' );
	sl_pending( 'every declared name resolves to non-empty markup', 'IconSet' );
} else {
	$sl_unknown = (string) \SmartLogin\Frontend\IconSet::get( 'no-such-icon-anywhere' );

	sl_assert(
		'an unknown icon name resolves to the fallback',
		'' !== $sl_unknown && false === strpos( $sl_unknown, 'no-such-icon-anywhere' ),
		'the input must not survive into the markup'
	);

	$sl_empty = array();

	foreach ( array_keys( (array) \SmartLogin\Frontend\IconSet::names() ) as $sl_name ) {
		if ( '' === trim( (string) \SmartLogin\Frontend\IconSet::get( (string) $sl_name ) ) ) {
			$sl_empty[] = (string) $sl_name;
		}
	}

	sl_assert(
		'every declared name resolves to non-empty markup',
		array() === $sl_empty,
		'empty: ' . implode( ', ', $sl_empty )
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 4 — destinations are not sections (finding 4)' );
// ---------------------------------------------------------------------------

$sl_has_menu = class_exists( '\SmartLogin\Frontend\AccountMenu' );

sl_assert(
	'AccountMenu exists',
	$sl_has_menu,
	'Decision 2: the single registry of account destinations.'
);

if ( ! $sl_has_menu ) {
	sl_pending( 'AccountMenu never calls sections_meta()', 'AccountMenu' );
} else {
	sl_assert(
		'AccountMenu never calls sections_meta()',
		false === strpos( $sl_file( 'includes/Frontend/class-account-menu.php' ), 'sections_meta' ),
		'a section is a card on one page; a destination is a page'
	);
}

/*
 * The other half is an invariant that already holds, asserted for the first
 * time. sections_meta() must not name a section nothing draws — the statement
 * its own doc comment refuses to make.
 */
$sl_section_keys = array_keys( AccountForm::sections_meta() );
$sl_declared     = array_keys( AccountForm::SECTIONS );
$sl_undrawn      = array_diff( $sl_section_keys, $sl_declared );

sl_assert(
	'every sections_meta() key is a declared section',
	array() === $sl_undrawn,
	'names a heading nothing draws: ' . implode( ', ', $sl_undrawn )
);

// ---------------------------------------------------------------------------
sl_section( 'Rules 5-8 — the shape of the list (decisions 3, 4, 5)' );
// ---------------------------------------------------------------------------

if ( ! $sl_has_menu ) {
	sl_pending( 'Rule 5: every key matches [a-z0-9_-]+ and the pinned keys are account/logout', 'AccountMenu' );
	sl_pending( 'Rule 5: no entry key collides with a section key', 'AccountMenu' );
	sl_pending( 'Rule 6: every entry has exactly key, label, icon, url', 'AccountMenu' );
	sl_pending( 'Rule 7: with the option cleared the list is not empty', 'AccountMenu' );
	sl_pending( 'Rule 8: the logout entry is last and its URL is generated', 'AccountMenu' );
} else {
	$sl_items = (array) \SmartLogin\Frontend\AccountMenu::items();
	$sl_keys  = array_column( $sl_items, 'key' );

	$sl_bad_keys = array_values(
		array_filter(
			$sl_keys,
			static function ( $key ): bool {
				return ! preg_match( '/^[a-z0-9_-]+$/', (string) $key );
			}
		)
	);

	sl_assert(
		'Rule 5: every key matches [a-z0-9_-]+ and the pinned keys are account/logout',
		array() === $sl_bad_keys && in_array( 'account', $sl_keys, true ) && in_array( 'logout', $sl_keys, true ),
		'keys: ' . implode( ', ', array_map( 'strval', $sl_keys ) )
	);

	sl_assert(
		'Rule 5: no entry key collides with a section key',
		array() === array_intersect( $sl_keys, $sl_declared ),
		'decision 4: `profile` is a section key, so the pinned head is `account`'
	);

	$sl_wrong_shape = array();

	foreach ( $sl_items as $sl_item ) {
		$sl_shape = array_keys( (array) $sl_item );
		sort( $sl_shape );

		if ( array( 'icon', 'key', 'label', 'url' ) !== $sl_shape ) {
			$sl_wrong_shape[] = implode( '+', $sl_shape );
		}
	}

	sl_assert(
		'Rule 6: every entry has exactly key, label, icon, url',
		array() === $sl_wrong_shape,
		'decision 5 — found: ' . implode( ' / ', $sl_wrong_shape )
	);

	sl_assert(
		'Rule 7: with the option cleared the list is not empty',
		count( $sl_items ) >= 2,
		'a fresh install has a usable account menu before anybody opens Settings'
	);

	$sl_last = end( $sl_items ) ?: array();

	sl_assert(
		'Rule 8: the logout entry is last and its URL is generated',
		'logout' === ( $sl_last['key'] ?? '' )
			&& '' !== (string) ( $sl_last['url'] ?? '' )
			&& (string) ( $sl_last['url'] ?? '' ) === wp_logout_url( home_url( '/' ) ),
		'finding 7: wp_logout_url() is nonced, so it cannot be a stored string'
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 9 — one source for every surface (decision 2)' );
// ---------------------------------------------------------------------------

/*
 * Checked in this order on purpose. "No template reads the option" is true today
 * because there is no option, and a rule that passes for want of a subject
 * states the opposite of the truth.
 */
$sl_option_declared = false !== strpos( $sl_file( 'includes/class-field-registry.php' ), 'account_menu.items' );

sl_assert(
	'the account_menu.items setting is declared',
	$sl_option_declared,
	'21.4 adds it to FieldRegistry, where membership and rendering read the same row.'
);

if ( ! $sl_option_declared ) {
	sl_pending( 'only AccountMenu reads the menu option', 'the account_menu.items setting' );
} else {
	$sl_other_readers = array();

	foreach ( $sl_sources as $sl_path => $sl_body ) {
		$sl_rel = str_replace( '\\', '/', $sl_path );

		if ( 'includes/Frontend/class-account-menu.php' === $sl_rel
			|| 'includes/class-field-registry.php' === $sl_rel ) {
			continue;
		}

		if ( false !== strpos( $sl_body, 'account_menu.items' ) ) {
			$sl_other_readers[] = $sl_rel;
		}
	}

	sl_assert(
		'only AccountMenu reads the menu option',
		array() === $sl_other_readers,
		'also read in: ' . implode( ', ', $sl_other_readers )
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rules 10-12 — the button (findings 1-3, decisions 7, 8)' );
// ---------------------------------------------------------------------------

$sl_signed_out = $sl_render_button( false );
$sl_signed_in  = $sl_render_button( true );

sl_assert(
	'the shortcode renders in both states without throwing',
	$sl_signed_out['ok'] && $sl_signed_in['ok'],
	trim( 'signed out: ' . ( $sl_signed_out['error'] ?: strlen( $sl_signed_out['markup'] ) . ' bytes' )
		. ' | signed in: ' . ( $sl_signed_in['error'] ?: strlen( $sl_signed_in['markup'] ) . ' bytes' ) )
);

/*
 * Every rule below asserts an ABSENCE as well as a presence, and an absence is
 * satisfied by an empty string. So they only run against markup that actually
 * rendered; otherwise they report PENDING. This is the guard the first draft
 * lacked.
 */
if ( ! $sl_signed_out['ok'] || ! $sl_signed_in['ok'] ) {
	sl_pending( 'Rule 10: signed out renders a trigger and no menu', 'a button that renders' );
	sl_pending( 'Rule 10: signed in renders a menu and no trigger', 'a button that renders' );
	sl_pending( 'Rule 11: the header button does not carry sl-btn', 'a button that renders' );
	sl_pending( 'Rule 12: signed out degrades to a real link', 'a button that renders' );
	sl_pending( 'Rule 12: signed in degrades to a scriptless <details>', 'a button that renders' );
} else {
	$sl_out = $sl_signed_out['markup'];
	$sl_in  = $sl_signed_in['markup'];

	sl_assert(
		'Rule 10: signed out renders a trigger and no menu',
		false !== strpos( $sl_out, 'data-smart-login' ) && false === strpos( $sl_out, '<details' ),
		'finding 3 / decision 1'
	);

	sl_assert(
		'Rule 10: signed in renders a menu and no trigger',
		false !== strpos( $sl_in, '<details' ) && false === strpos( $sl_in, 'data-smart-login' ),
		'today a signed-in visitor is shown a sign-in trigger that navigates away — finding 3'
	);

	sl_assert(
		'Rule 11: the header button does not carry sl-btn',
		false === strpos( $sl_out, 'sl-btn' ) && false === strpos( $sl_in, 'sl-btn' ),
		'finding 2: .sl-btn is display:block/width:100%, settled for the form and wrong for a header'
	);

	sl_assert(
		'Rule 12: signed out degrades to a real link',
		(bool) preg_match( '/<a\b[^>]*href="https?:\/\/[^"]+"/', $sl_out ),
		'decision 8: with the script blocked the visitor still gets a link that works'
	);

	sl_assert(
		'Rule 12: signed in degrades to a scriptless <details>',
		(bool) preg_match( '/<details\b/', $sl_in ) && (bool) preg_match( '/<summary\b/', $sl_in ),
		'decision 8: the dropdown must work before any script is added'
	);
}

/*
 * Finding 1's structural half. The enqueue itself is measured in
 * tests/integration/run-account-menu-gate.php at 21.5, because an enqueue is
 * exactly the class of defect a fixture reports as fine.
 */
sl_assert(
	'the button shortcode is known to Assets::maybe_enqueue()',
	false !== strpos( $sl_file( 'includes/Frontend/class-assets.php' ), 'smart_login_button' ),
	'finding 1: the one trigger built for people who cannot edit templates is the only one with no CSS'
);

sl_summary( 'Account menu' );
