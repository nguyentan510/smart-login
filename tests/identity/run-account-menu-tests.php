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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Frontend\AccountForm;

$ow_sources = ow_plugin_sources();

/**
 * Contents of a file that may not exist yet.
 */
$ow_file = static function ( string $relative ): string {
	return ow_source( $relative );
};

/*
 * The site has a page hosting the sign-in flow. Without this `Flow::login_url()`
 * is '' and the button falls back to '#login' — so a rule about degradation
 * would be measuring the fixture's lack of a login page rather than the button.
 */
add_filter( 'omniwp_login_url',
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
$ow_render_button = static function ( bool $logged_in ): array {
	$was                     = $GLOBALS['ow_logged_in'] ?? false;
	$GLOBALS['ow_logged_in'] = $logged_in;

	$result = array(
		'ok'     => false,
		'markup' => '',
		'error'  => '',
	);

	try {
		$shortcodes       = new \OmniWP\Frontend\Shortcodes();
		$result['markup'] = (string) $shortcodes->render_button( array() );
		$result['ok']     = '' !== $result['markup'];
	} catch ( \Throwable $e ) {
		$result['error'] = get_class( $e ) . ': ' . $e->getMessage();
	}

	$GLOBALS['ow_logged_in'] = $was;

	return $result;
};

// ---------------------------------------------------------------------------
ow_section( 'Rule 1 — the tokens have one home (decision 13)' );
// ---------------------------------------------------------------------------

$ow_token_file = $ow_file( 'assets/css/omniwp-tokens.css' );

ow_assert(
	'assets/css/omniwp-tokens.css exists',
	'' !== $ow_token_file,
	'Decision 13: the token block leaves .omniwp, which welds twenty design tokens to a page-layout rule.'
);

/*
 * The rule is about *shared* tokens, and 21.1 is where that got sharpened.
 *
 * The first phrasing was "no --sl-* declaration outside the token file", which
 * would also have swept up the eight `--sl-dlg-*` on `.sl-dialog`. Those are
 * declared and read inside `omniwp-dialog.css` and nowhere else: a variable
 * that never crosses a stylesheet boundary is component-local, and promoting it
 * to a global name is the opposite of the scoping this plugin keeps.
 *
 * So: a token a stylesheet *reads without declaring* must come from the token
 * file. That is the property decision 13 actually needs, and it still fails
 * loudly while the token file does not exist.
 */
if ( '' === $ow_token_file ) {
	ow_pending( 'every cross-file token is declared in the token file', 'the token file' );
} else {
	preg_match_all( '/^\s*(--sl-[a-z0-9-]+)\s*:/m', $ow_token_file, $ow_declared_tokens );
	$ow_known = $ow_declared_tokens[1];
	$ow_orphan = array();

	foreach ( array( 'assets/css/omniwp-base.css', 'assets/css/omniwp.css', 'assets/css/omniwp-dialog.css', 'assets/css/omniwp-button.css', 'assets/css/admin.css' ) as $ow_css ) {
		$ow_body = $ow_file( $ow_css );

		if ( '' === $ow_body ) {
			continue;
		}

		preg_match_all( '/^\s*(--sl-[a-z0-9-]+)\s*:/m', $ow_body, $ow_local );
		preg_match_all( '/var\(\s*(--sl-[a-z0-9-]+)/', $ow_body, $ow_used );

		foreach ( array_unique( $ow_used[1] ) as $ow_token ) {
			if ( ! in_array( $ow_token, $ow_local[1], true ) && ! in_array( $ow_token, $ow_known, true ) ) {
				$ow_orphan[] = $ow_css . ' reads ' . $ow_token;
			}
		}
	}

	ow_assert(
		'every cross-file token is declared in the token file',
		array() === $ow_orphan,
		implode( '; ', $ow_orphan )
	);

	/*
	 * A declaration, not a usage. The first version of this pattern stopped at
	 * `--sl-` and matched `color: var(--sl-text)` inside the very rule it was
	 * checking, so it failed against a file that was already correct. The colon
	 * is what distinguishes the two: a declaration is `--sl-x:`, a reference is
	 * `--sl-x)`.
	 */
	ow_assert(
		'.omniwp declares no design token of its own',
		! preg_match( '/\.omniwp\s*\{[^}]*--sl-[a-z0-9-]+\s*:/s', $ow_file( 'assets/css/omniwp.css' ) ),
		'decision 13: the token set and the page-layout block were one rule'
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 2 — one glyph vocabulary (finding 8, decision 6)' );
// ---------------------------------------------------------------------------

/*
 * ProviderMark and the provider classes are the named exemption, not a pattern
 * that happens not to match: `icon_svg()` returns a trademark in its owner's
 * colours, and folding that into a currentColor UI set would be unification
 * past the point where it means anything.
 */
$ow_glyph_exempt = array(
	'includes/Frontend/class-icon-set.php',
	'includes/Frontend/class-provider-mark.php',
	'includes/Auth/Providers/class-google-provider.php',
	'includes/Auth/Providers/class-login-provider-interface.php',
);

$ow_inline_svg = array();

foreach ( $ow_sources as $ow_path => $ow_body ) {
	$ow_rel = str_replace( '\\', '/', $ow_path );

	if ( in_array( $ow_rel, $ow_glyph_exempt, true ) ) {
		continue;
	}

	if ( false !== strpos( $ow_body, '<svg' ) ) {
		$ow_inline_svg[] = $ow_rel;
	}
}

ow_assert(
	'no <svg literal outside IconSet and the provider mark',
	array() === $ow_inline_svg,
	'inline SVG in: ' . implode( ', ', $ow_inline_svg )
);

// ---------------------------------------------------------------------------
ow_section( 'Rule 3 — an unknown icon is unrepresentable (decision 6)' );
// ---------------------------------------------------------------------------

$ow_has_icon_set = class_exists( '\OmniWP\Frontend\IconSet' );

ow_assert(
	'IconSet exists',
	$ow_has_icon_set,
	'Decision 6: one closed set of UI glyphs, chosen by name.'
);

if ( ! $ow_has_icon_set ) {
	ow_pending( 'an unknown icon name resolves to the fallback', 'IconSet' );
	ow_pending( 'every declared name resolves to non-empty markup', 'IconSet' );
} else {
	$ow_unknown = (string) \OmniWP\Frontend\IconSet::get( 'no-such-icon-anywhere' );

	ow_assert(
		'an unknown icon name resolves to the fallback',
		'' !== $ow_unknown && false === strpos( $ow_unknown, 'no-such-icon-anywhere' ),
		'the input must not survive into the markup'
	);

	$ow_empty = array();

	foreach ( array_keys( (array) \OmniWP\Frontend\IconSet::names() ) as $ow_name ) {
		if ( '' === trim( (string) \OmniWP\Frontend\IconSet::get( (string) $ow_name ) ) ) {
			$ow_empty[] = (string) $ow_name;
		}
	}

	ow_assert(
		'every declared name resolves to non-empty markup',
		array() === $ow_empty,
		'empty: ' . implode( ', ', $ow_empty )
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 4 — destinations are not sections (finding 4)' );
// ---------------------------------------------------------------------------

$ow_has_menu = class_exists( '\OmniWP\Frontend\AccountMenu' );

ow_assert(
	'AccountMenu exists',
	$ow_has_menu,
	'Decision 2: the single registry of account destinations.'
);

if ( ! $ow_has_menu ) {
	ow_pending( 'AccountMenu never calls sections_meta()', 'AccountMenu' );
} else {
	/*
	 * Comments stripped first. The rule forbids a *call*, and the class
	 * explains at length why it does not make one — a plain substring search
	 * failed against a file whose only mention of the method was the paragraph
	 * saying it must not be used, which is a rule punishing the documentation
	 * of its own intent.
	 */
	$ow_menu_code = (string) preg_replace(
		array( '#/\*.*?\*/#s', '#//[^\n]*#' ),
		'',
		$ow_file( 'includes/Frontend/class-account-menu.php' )
	);

	ow_assert(
		'AccountMenu never calls sections_meta()',
		false === strpos( $ow_menu_code, 'sections_meta' ),
		'a section is a card on one page; a destination is a page'
	);
}

/*
 * The other half is an invariant that already holds, asserted for the first
 * time. sections_meta() must not name a section nothing draws — the statement
 * its own doc comment refuses to make.
 */
$ow_section_keys = array_keys( AccountForm::sections_meta() );
$ow_declared     = array_keys( AccountForm::SECTIONS );
$ow_undrawn      = array_diff( $ow_section_keys, $ow_declared );

ow_assert(
	'every sections_meta() key is a declared section',
	array() === $ow_undrawn,
	'names a heading nothing draws: ' . implode( ', ', $ow_undrawn )
);

// ---------------------------------------------------------------------------
ow_section( 'Rules 5-8 — the shape of the list (decisions 3, 4, 5)' );
// ---------------------------------------------------------------------------

if ( ! $ow_has_menu ) {
	ow_pending( 'Rule 5: every key matches [a-z0-9_-]+ and the pinned keys are account/logout', 'AccountMenu' );
	ow_pending( 'Rule 5: no entry key collides with a section key', 'AccountMenu' );
	ow_pending( 'Rule 6: every entry has exactly key, label, icon, url', 'AccountMenu' );
	ow_pending( 'Rule 7: with the option cleared the list is not empty', 'AccountMenu' );
	ow_pending( 'Rule 8: the logout entry is last and its URL is generated', 'AccountMenu' );
} else {
	$ow_items = (array) \OmniWP\Frontend\AccountMenu::items();
	$ow_keys  = array_column( $ow_items, 'key' );

	$ow_bad_keys = array_values(
		array_filter(
			$ow_keys,
			static function ( $key ): bool {
				return ! preg_match( '/^[a-z0-9_-]+$/', (string) $key );
			}
		)
	);

	ow_assert(
		'Rule 5: every key matches [a-z0-9_-]+ and the pinned keys are account/logout',
		array() === $ow_bad_keys && in_array( 'account', $ow_keys, true ) && in_array( 'logout', $ow_keys, true ),
		'keys: ' . implode( ', ', array_map( 'strval', $ow_keys ) )
	);

	ow_assert(
		'Rule 5: no entry key collides with a section key',
		array() === array_intersect( $ow_keys, $ow_declared ),
		'decision 4: `profile` is a section key, so the pinned head is `account`'
	);

	$ow_wrong_shape = array();

	foreach ( $ow_items as $ow_item ) {
		$ow_shape = array_keys( (array) $ow_item );
		sort( $ow_shape );

		if ( array( 'icon', 'key', 'label', 'url' ) !== $ow_shape ) {
			$ow_wrong_shape[] = implode( '+', $ow_shape );
		}
	}

	ow_assert(
		'Rule 6: every entry has exactly key, label, icon, url',
		array() === $ow_wrong_shape,
		'decision 5 — found: ' . implode( ' / ', $ow_wrong_shape )
	);

	ow_assert(
		'Rule 7: with the option cleared the list is not empty',
		count( $ow_items ) >= 2,
		'a fresh install has a usable account menu before anybody opens Settings'
	);

	$ow_last = end( $ow_items ) ?: array();

	ow_assert(
		'Rule 8: the logout entry is last and its URL is generated',
		'logout' === ( $ow_last['key'] ?? '' )
			&& '' !== (string) ( $ow_last['url'] ?? '' )
			&& (string) ( $ow_last['url'] ?? '' ) === wp_logout_url( home_url( '/' ) ),
		'finding 7: wp_logout_url() is nonced, so it cannot be a stored string'
	);
}

// ---------------------------------------------------------------------------
ow_section( '21.3 — what the registry refuses (decision 3, step 5)' );
// ---------------------------------------------------------------------------

/**
 * Run `items()` with one temporary filter, then put the hook back as it was.
 *
 * The stub keeps filters in a global array and has no `remove_filter()`, so a
 * filter added for one assertion would still be attached for the next one and
 * the second result would be measuring the first test.
 */
$ow_with_filter = static function ( callable $callback ): array {
	$was = $GLOBALS['ow_filters']['omniwp_account_menu'] ?? array();
	$GLOBALS['ow_filters']['omniwp_account_menu'] = array();

	add_filter( 'omniwp_account_menu', $callback, 10, 2 );
	$items = class_exists( '\OmniWP\Frontend\AccountMenu' )
		? (array) \OmniWP\Frontend\AccountMenu::items()
		: array();

	$GLOBALS['ow_filters']['omniwp_account_menu'] = $was;

	return $items;
};

if ( ! $ow_has_menu ) {
	ow_pending( 'an entry with no URL is dropped rather than rendered dead', 'AccountMenu' );
	ow_pending( 'an entry with an unusable key is dropped', 'AccountMenu' );
	ow_pending( 'a filtered entry is normalised to the four-key shape', 'AccountMenu' );
	ow_pending( 'an unknown icon from a filter folds to the fallback', 'AccountMenu' );
} else {
	/*
	 * The branch that matters most: `AccountForm::edit_url()` returns '' on a
	 * site with no account page. Driven through the filter rather than by
	 * removing the stub, because the property is "an entry with no URL is
	 * dropped" and the missing account page is only one way to reach it.
	 */
	$ow_no_url = $ow_with_filter(
		static function ( array $items ): array {
			$res = array();
			foreach ( $items as $item ) {
				if ( 'logout' !== ( $item['key'] ?? '' ) ) {
					$item['url'] = '';
				}
				$res[] = $item;
			}

			return $res;
		}
	);

	ow_assert(
		'an entry with no URL is dropped rather than rendered dead',
		array( 'logout' ) === array_column( $ow_no_url, 'key' ),
		'got: ' . implode( ', ', array_column( $ow_no_url, 'key' ) )
	);

	$ow_bad_key = $ow_with_filter(
		static function ( array $items ): array {
			$items[] = array(
				'key'   => 'Bad Key!',
				'label' => 'Nowhere',
				'icon'  => 'user',
				'url'   => 'https://example.test/nowhere/',
			);

			return $items;
		}
	);

	ow_assert(
		'an entry with an unusable key is dropped',
		! in_array( 'Bad Key!', array_column( $ow_bad_key, 'key' ), true ),
		'keys are compared by later surfaces to decide which item is active'
	);

	$ow_extra = $ow_with_filter(
		static function ( array $items ): array {
			$items[] = array(
				'key'        => 'orders',
				'label'      => 'Đơn hàng',
				'icon'       => 'box',
				'url'        => 'https://example.test/orders/',
				'capability' => 'manage_options',
			);

			return $items;
		}
	);

	$ow_added = array_values(
		array_filter(
			$ow_extra,
			static function ( array $item ): bool {
				return 'orders' === $item['key'];
			}
		)
	);

	$ow_added_shape = $ow_added ? array_keys( $ow_added[0] ) : array();
	sort( $ow_added_shape );

	ow_assert(
		'a filtered entry is normalised to the four-key shape',
		array( 'icon', 'key', 'label', 'url' ) === $ow_added_shape,
		'decision 5 — got: ' . implode( '+', $ow_added_shape )
	);

	$ow_bad_icon = $ow_with_filter(
		static function ( array $items ): array {
			$items[] = array(
				'key'   => 'weird',
				'label' => 'Weird',
				'icon'  => '<script>alert(1)</script>',
				'url'   => 'https://example.test/weird/',
			);

			return $items;
		}
	);

	$ow_icons = array_column( $ow_bad_icon, 'icon' );

	ow_assert(
		'an unknown icon from a filter folds to the fallback',
		! in_array( '<script>alert(1)</script>', $ow_icons, true )
			&& in_array( \OmniWP\Frontend\IconSet::FALLBACK, $ow_icons, true ),
		'icons: ' . implode( ', ', $ow_icons )
	);
}

// ---------------------------------------------------------------------------
ow_section( '21.4 — what a settings save may write (decision 3, 6)' );
// ---------------------------------------------------------------------------

/**
 * Put rows through the real save path and read back what was stored.
 *
 * `Settings::sanitize()` rather than the private sanitiser, because the thing
 * worth asserting is what an administrator pressing Save actually produces —
 * the dot-path nesting and the tab gate included.
 *
 * @return array<int,array<string,string>>
 */
$ow_save_rows = static function ( array $rows ): array {
	$clean = \OmniWP\Settings::sanitize(
		array(
			\OmniWP\Settings::TAB_FIELD => 'menu',
			'account_menu'                  => array( 'items' => $rows ),
		)
	);

	return (array) ( $clean['account_menu']['items'] ?? array() );
};

$ow_saved = $ow_save_rows(
	array(
		// A complete row.
		array(
			'icon'  => 'box',
			'label' => 'Đơn hàng',
			'url'   => 'https://example.test/don-hang/',
		),
		// A label with no destination: the blank-row case, dropped silently.
		array(
			'icon'  => 'box',
			'label' => 'Chưa có đích',
			'url'   => '',
		),
		// The same label twice must not become the same key.
		array(
			'icon'  => 'box',
			'label' => 'Đơn hàng',
			'url'   => 'https://example.test/don-hang-2/',
		),
		// A row that tries to take the pinned tail's name.
		array(
			'icon'  => 'log-out',
			'label' => 'Đăng xuất',
			'url'   => 'https://example.test/thoat/',
		),
		// An icon that never came from the picker.
		array(
			'icon'  => '<script>alert(1)</script>',
			'label' => 'Lạ',
			'url'   => 'https://example.test/la/',
		),
	)
);

$ow_saved_keys = array_column( $ow_saved, 'key' );

ow_assert(
	'a row with a label and no URL is dropped, silently',
	4 === count( $ow_saved ),
	'stored ' . count( $ow_saved ) . ' row(s): ' . implode( ', ', $ow_saved_keys )
);

ow_assert(
	'the key is derived from the label, with Vietnamese folded',
	in_array( 'don-hang', $ow_saved_keys, true ),
	'sanitize_title( "Đơn hàng" ) is don-hang — keys: ' . implode( ', ', $ow_saved_keys )
);

ow_assert(
	'two rows with the same label get two distinct keys',
	count( $ow_saved_keys ) === count( array_unique( $ow_saved_keys ) ),
	'a duplicate key makes "which item is active" ambiguous — keys: ' . implode( ', ', $ow_saved_keys )
);

ow_assert(
	'a row cannot take a pinned end\'s key',
	! in_array( \OmniWP\Frontend\AccountMenu::KEY_LOGOUT, $ow_saved_keys, true )
		&& ! in_array( \OmniWP\Frontend\AccountMenu::KEY_ACCOUNT, $ow_saved_keys, true ),
	'keys: ' . implode( ', ', $ow_saved_keys )
);

ow_assert(
	'an icon outside the set is stored as the fallback, not as itself',
	! in_array( '<script>alert(1)</script>', array_column( $ow_saved, 'icon' ), true )
		&& in_array( \OmniWP\Frontend\IconSet::FALLBACK, array_column( $ow_saved, 'icon' ), true ),
	'icons: ' . implode( ', ', array_column( $ow_saved, 'icon' ) )
);

ow_assert(
	'a row whose label yields no slug still gets a key rather than vanishing',
	1 === count( $ow_save_rows( array( array( 'label' => '★★★', 'url' => 'https://example.test/x/', 'icon' => 'user' ) ) ) ),
	'losing a menu item because of the alphabet its label is written in is not a defensible refusal'
);

$ow_fresh_keys = array_column( (array) \OmniWP\Frontend\AccountMenu::items(), 'key' );
ow_assert(
	'clearing every row leaves the two pinned entries',
	'account' === ( $ow_fresh_keys[0] ?? '' ) && 'logout' === ( end( $ow_fresh_keys ) ?? '' ),
	'decision 3: a fresh install has a working account menu before anybody opens Settings'
);

// ---------------------------------------------------------------------------
ow_section( 'Rule 9 — one source for every surface (decision 2)' );
// ---------------------------------------------------------------------------

/*
 * Checked in this order on purpose. "No template reads the option" is true today
 * because there is no option, and a rule that passes for want of a subject
 * states the opposite of the truth.
 */
$ow_option_declared = false !== strpos( $ow_file( 'includes/class-field-registry.php' ), 'account_menu.items' );

ow_assert(
	'the account_menu.items setting is declared',
	$ow_option_declared,
	'21.4 adds it to FieldRegistry, where membership and rendering read the same row.'
);

if ( ! $ow_option_declared ) {
	ow_pending( 'only AccountMenu reads the menu option', 'the account_menu.items setting' );
} else {
	$ow_other_readers = array();

	foreach ( $ow_sources as $ow_path => $ow_body ) {
		$ow_rel = str_replace( '\\', '/', $ow_path );

		if ( 'includes/Frontend/class-account-menu.php' === $ow_rel
			|| 'includes/class-field-registry.php' === $ow_rel ) {
			continue;
		}

		if ( false !== strpos( $ow_body, 'account_menu.items' ) ) {
			$ow_other_readers[] = $ow_rel;
		}
	}

	ow_assert(
		'only AccountMenu reads the menu option',
		array() === $ow_other_readers,
		'also read in: ' . implode( ', ', $ow_other_readers )
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rules 10-12 — the button (findings 1-3, decisions 7, 8)' );
// ---------------------------------------------------------------------------

$ow_signed_out = $ow_render_button( false );
$ow_signed_in  = $ow_render_button( true );

ow_assert(
	'the shortcode renders in both states without throwing',
	$ow_signed_out['ok'] && $ow_signed_in['ok'],
	trim( 'signed out: ' . ( $ow_signed_out['error'] ?: strlen( $ow_signed_out['markup'] ) . ' bytes' )
		. ' | signed in: ' . ( $ow_signed_in['error'] ?: strlen( $ow_signed_in['markup'] ) . ' bytes' ) )
);

/*
 * Every rule below asserts an ABSENCE as well as a presence, and an absence is
 * satisfied by an empty string. So they only run against markup that actually
 * rendered; otherwise they report PENDING. This is the guard the first draft
 * lacked.
 */
if ( ! $ow_signed_out['ok'] || ! $ow_signed_in['ok'] ) {
	ow_pending( 'Rule 10: signed out renders a trigger and no menu', 'a button that renders' );
	ow_pending( 'Rule 10: signed in renders a menu and no trigger', 'a button that renders' );
	ow_pending( 'Rule 11: the header button does not carry sl-btn', 'a button that renders' );
	ow_pending( 'Rule 12: signed out degrades to a real link', 'a button that renders' );
	ow_pending( 'Rule 12: signed in degrades to a scriptless <details>', 'a button that renders' );
} else {
	$ow_out = $ow_signed_out['markup'];
	$ow_in  = $ow_signed_in['markup'];

	ow_assert(
		'Rule 10: signed out renders a trigger and no menu',
		false !== strpos( $ow_out, 'data-omniwp' ) && false === strpos( $ow_out, '<details' ),
		'finding 3 / decision 1'
	);

	ow_assert(
		'Rule 10: signed in renders a menu and no trigger',
		false !== strpos( $ow_in, '<details' ) && false === strpos( $ow_in, 'data-omniwp' ),
		'today a signed-in visitor is shown a sign-in trigger that navigates away — finding 3'
	);

	ow_assert(
		'Rule 11: the header button does not carry sl-btn',
		false === strpos( $ow_out, 'sl-btn' ) && false === strpos( $ow_in, 'sl-btn' ),
		'finding 2: .sl-btn is display:block/width:100%, settled for the form and wrong for a header'
	);

	ow_assert(
		'Rule 12: signed out degrades to a real link',
		(bool) preg_match( '/<a\b[^>]*href="[^"]+"/', $ow_out ),
		'decision 8: with the script blocked the visitor still gets a link that works'
	);

	ow_assert(
		'Rule 12: signed in degrades to a scriptless <details>',
		(bool) preg_match( '/<details\b/', $ow_in ) && (bool) preg_match( '/<summary\b/', $ow_in ),
		'decision 8: the dropdown must work before any script is added'
	);
}

/*
 * Finding 1's structural half. The enqueue itself is measured in
 * tests/integration/run-account-menu-gate.php at 21.5, because an enqueue is
 * exactly the class of defect a fixture reports as fine.
 */
ow_assert(
	'the button shortcode is known to Assets::maybe_enqueue()',
	false !== strpos( $ow_file( 'includes/Frontend/class-assets.php' ), 'omniwp_button' ),
	'finding 1: the one trigger built for people who cannot edit templates is the only one with no CSS'
);

// ---------------------------------------------------------------------------
ow_section( '21.7 — the public surface is documented (decision 11)' );
// ---------------------------------------------------------------------------

/*
 * Checked against what is actually registered, never against a list typed here.
 * A second list would go stale in the same commit as the first, and this
 * project has twice found the README asserting a control that does not exist.
 */
$ow_readme = $ow_file( 'README.md' );

$ow_undocumented = array();

// Every icon the picker offers, since the filter example tells people to name one.
foreach ( array_keys( \OmniWP\Frontend\IconSet::names() ) as $ow_icon ) {
	if ( false === strpos( $ow_readme, '`' . $ow_icon . '`' ) ) {
		$ow_undocumented[] = 'icon ' . $ow_icon;
	}
}

/*
 * Every attribute the shortcode accepts, read off the shortcode itself.
 *
 * 21.7 read them with a regex over `shortcode_atts( array( … )` in the source,
 * because there was nothing else to read: the defaults were a literal at the top
 * of `render_button()`. 22.1 moved them into `Shortcodes::CATALOG`, so the same
 * question now has an answer that does not involve parsing PHP with a pattern —
 * and this rule asks the array instead. The guard below is unchanged and is what
 * caught the move: the regex narrowed to nothing and reported an empty list.
 */
$ow_atts_found = array_keys( \OmniWP\Frontend\Shortcodes::CATALOG['omniwp_button']['atts'] );

foreach ( $ow_atts_found as $ow_att ) {
	if ( false === strpos( $ow_readme, '`' . $ow_att . '`' ) ) {
		$ow_undocumented[] = 'attribute ' . $ow_att;
	}
}

foreach ( array( 'omniwp_account_menu', 'omniwp_button', '--sl-accent', 'omniwp-tokens.css' ) as $ow_name ) {
	if ( false === strpos( $ow_readme, $ow_name ) ) {
		$ow_undocumented[] = $ow_name;
	}
}

ow_assert(
	'README documents every public name this phase added',
	array() === $ow_undocumented,
	'missing: ' . implode( ', ', $ow_undocumented )
);

ow_assert(
	'the shortcode attribute list was actually found',
	count( $ow_atts_found ) >= 4,
	'a rule that narrows to nothing passes vacuously — found: ' . implode( ', ', $ow_atts_found )
);

ow_assert(
	'nav-menu placement is off by default',
	'' === (string) \OmniWP\FieldRegistry::get( \OmniWP\Frontend\NavMenuItem::SETTING )['default'],
	'decision 11: a plugin may default to being invisible, not to editing the theme'
);

ow_summary( 'Account menu' );
