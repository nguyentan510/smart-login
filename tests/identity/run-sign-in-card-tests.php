<?php
/**
 * Sign-in card fitness — one question, one answer per row.
 *
 * Normative spec: docs/sign-in-card.md. Progress: docs/refactor-plan.md
 * Phase 16.
 *
 * Landed `spec` in 16.0, which is what that kind is for. Four of the five rules
 * below were red the day they landed, deliberately: the account surface suite
 * has been `required` since 8.3 and rules that are meant to fail cannot live in
 * a suite that blocks.
 *
 * `required` since 16.3, the sub-phase that turned the last two green.
 *
 * Run with:  php tests/identity/run-sign-in-card-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Settings;

Settings::update(
	array(
		'identity.mode' => 'both',
		'otp.length'    => 6,
	)
);

$GLOBALS['sl_logged_in']       = true;
$GLOBALS['sl_current_user_id'] = 7;

$sl_root = dirname( __DIR__, 2 ) . '/';

/**
 * Render one template and return its markup, failing loudly on a throw.
 *
 * The template suite renders every template with one fixture each. These rules
 * need the *same* templates under fixtures chosen to be wrong in a particular
 * way, which is a different job and belongs to the phase that cares.
 */
$sl_render = static function ( string $template, array $args ) use ( $sl_root ): string {
	$captured = sl_capture(
		static function () use ( $sl_root, $template, $args ): void {
			( static function ( string $sl_file, array $sl_args ): void {
				extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
				include $sl_file;
			} )( $sl_root . 'templates/' . $template . '.php', $args );
		}
	);

	if ( null !== $captured['error'] ) {
		printf( "           render failed: %s\n", $captured['error'] );
	}

	return $captured['html'];
};

/**
 * One identity record in the shape IdentityLinkService::linked() emits.
 */
$sl_identity = static function ( string $channel, string $subject, string $masked, string $label, bool $federated, bool $primary = false ): array {
	return array(
		'channel'     => $channel,
		'subject'     => $subject,
		'masked'      => $masked,
		'label'       => $label,
		'federated'   => $federated,
		'is_primary'  => $primary,
		'linked_by'   => $federated ? 'oauth' : 'otp',
		'verified_at' => '2026-07-30 08:00:00',
		'removable'   => true,
	);
};

// The stub user's address. Counted by domain rather than in full: the masked
// form keeps the domain and loses the local part, so a rule matching the whole
// string would pass while looking straight at the defect.
$sl_email  = 'user@example.test';
$sl_domain = '@example.test';

// ---------------------------------------------------------------------
sl_section( 'Rule 1 — one value, one place (16.1)' );

$sl_contact_args = array(
	'sl_user'       => new WP_User( 7, 'Nguyễn Như' ),
	'sl_phone'      => '',
	'sl_synthetic'  => false,
	'sl_pending'    => array(),
	'sl_otp_length' => 6,
	'sl_providers'  => array(
		'sl_identities'     => array(
			$sl_identity( 'email', $sl_email, 'us•••' . $sl_domain, 'Email', false, true ),
			$sl_identity( 'google', '117100000000000000000', '1171••••••', 'Google', true ),
		),
		'sl_can_unlink'     => true,
		'sl_redirect'       => 'https://example.test/my-account/',
		'sl_link_providers' => array(),
	),
);

$sl_card = $sl_render( 'partials/account/contact', $sl_contact_args );

sl_assert(
	'the account address appears once in the card',
	1 === substr_count( $sl_card, $sl_domain ),
	sprintf(
		'Counted %d. IdentityLinkService::linked() returns every identity record and has never filtered by channel, so the contact row prints the address whole and the list below prints it masked — two rows a member reads as two addresses.',
		substr_count( $sl_card, $sl_domain )
	)
);

// The first half of this rule can be satisfied by rendering nothing at all. The
// federated row is what the card is for, and it has to survive.
sl_assert(
	'the federated row survives the filter',
	false !== strpos( $sl_card, 'Google' ),
	'A card that answers by rendering nothing has not answered.'
);

// ---------------------------------------------------------------------
sl_section( 'Rule 2 — Đổi for what you own, Bỏ liên kết for what you borrowed (16.1)' );

$sl_list = static function ( array $identities ) use ( $sl_render ): string {
	/*
	 * Rendered the way a page renders it: the unlink form is deferred out of the
	 * partial since P9, because a <form> inside the account form ends that form.
	 * A test that reads only the partial reads half the markup.
	 */
	// Reset first: the buffer is static and a previous render in this process
	// would otherwise leak its form into this one.
	\SmartLogin\Frontend\DeferredForms::reset();

	$sl_markup = $sl_render(
		'partials/linked-identities',
		array(
			'sl_identities' => $identities,
			'sl_can_unlink' => true,
			'sl_redirect'   => 'https://example.test/my-account/',
		)
	);

	ob_start();
	\SmartLogin\Frontend\DeferredForms::flush();

	return $sl_markup . (string) ob_get_clean();
};

$sl_self_only = $sl_list( array( $sl_identity( 'email', $sl_email, 'us•••' . $sl_domain, 'Email', false, true ) ) );
$sl_federated = $sl_list( array( $sl_identity( 'google', '117100000000000000000', '1171••••••', 'Google', true ) ) );

sl_assert(
	'an address the account owns offers no unlink control',
	false === strpos( $sl_self_only, 'unlink_identity' ),
	'The operation a member wants on their own address is Đổi, which the contact row already offers and replace_in_channel() already implements. "Bỏ liên kết" beside a badge reading "Chính" is the wrong verb for the wrong operation.'
);

sl_assert(
	'a federated identity still offers one',
	false !== strpos( $sl_federated, 'unlink_identity' ),
	'The half that stops the rule above from passing because the partial renders nothing at all.'
);

// ---------------------------------------------------------------------
sl_section( 'Rule 6 — a provider row names the account, not the number (16.2)' );

/*
 * Through linked() and the stub $wpdb rather than around them: Phase 6 added
 * that stub precisely so a repository test exercises the real path instead of a
 * mock of it, and the resolution order is the thing under test.
 *
 * Each level gets its own record. 11.1 shipped a fallback chain as a no-op with
 * its tests passing, because one assertion that "something resolves" was taken
 * for three that resolve differently.
 */
$sl_row = static function ( string $subject, ?array $meta ): array {
	return array(
		'id'          => 1,
		'user_id'     => 7,
		'channel'     => 'google',
		'subject'     => $subject,
		'is_primary'  => 0,
		'verified_at' => '2026-07-30 08:00:00',
		'linked_by'   => 'oauth',
		'meta_json'   => null === $meta ? null : wp_json_encode( $meta ),
		'created_at'  => '2026-07-30 08:00:00',
	);
};

$GLOBALS['sl_wpdb_results'] = array(
	$sl_row( '117100000000000000001', array( 'name' => 'Cai Hoa', 'email' => 'hoa@example.test' ) ),
	$sl_row( '117100000000000000002', array( 'email' => 'hoa@example.test' ) ),
	$sl_row( '117100000000000000003', null ),
);

$sl_linked = ( new \SmartLogin\Auth\IdentityLinkService() )->linked( 7 );

sl_check( 'a stored display name wins', 'Cai Hoa', $sl_linked[0]['display'] ?? null );

$sl_by_email = (string) ( $sl_linked[1]['display'] ?? '' );

sl_assert(
	'no name falls to the provider address, masked',
	false !== strpos( $sl_by_email, '@example.test' ) && false === strpos( $sl_by_email, 'hoa@' ),
	'A provider address is a real identifier, so the screen-sharing rule that applies to subjects applies to it. Got: ' . $sl_by_email
);

sl_assert(
	'no meta at all falls to the linked date',
	false !== strpos( (string) ( $sl_linked[2]['display'] ?? '' ), '30/07/2026' ),
	'Every identity linked before the claims were stored has empty meta, and a row with no value reads as a rendering fault. Got: ' . ( $sl_linked[2]['display'] ?? '' )
);

$GLOBALS['sl_wpdb_results'] = array();

// Asserted on the markup, not on the payload: `masked` stays in the array for
// the REST route, and it is the row that must stop rendering it.
$sl_sub_row = $sl_list(
	array( $sl_identity( 'google', '117100000000000000000', '1171••••••', 'Google', true ) + array( 'display' => 'Cai Hoa' ) )
);

sl_assert(
	'the rendered row carries no masked subject',
	false === strpos( $sl_sub_row, '1171••••••' ) && false !== strpos( $sl_sub_row, 'Cai Hoa' ),
	'`1171••••••` is the OIDC subject masked. Its owner has never seen that number here or at Google.'
);

// ---------------------------------------------------------------------
sl_section( 'Rule 3 — every input the plugin renders is styled by the plugin (16.3)' );

$sl_css = sl_source( 'assets/css/smart-login.css' );

// Comments carry example selectors and declarations. A rule that reads prose is
// a rule that changes colour when somebody rewords a comment — the lesson Phase
// 4 recorded when two fitness rules fired on docblocks.
$sl_css_code = (string) preg_replace( '#/\*.*?\*/#s', '', $sl_css );

// Every class the stylesheet declares a rule for, so a made-up class name cannot
// satisfy the rule below.
preg_match_all( '/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $sl_css_code, $sl_class_matches );
$sl_styled = array_flip( $sl_class_matches[1] );

/*
 * `[^>]` stops at the closing angle of a PHP close tag, and half these
 * attributes are PHP. The alternation consumes a close tag as one unit so the
 * match does not end in the middle of an attribute value.
 *
 * Written as a block comment on purpose: a PHP close tag inside a `//` comment
 * ends PHP mode, and the first draft of this file did exactly that — everything
 * below it was echoed as HTML and two rules silently never ran.
 */
$sl_input_tag = '/<input\b((?:\?>|[^>?]|\?(?!>))*?)>/s';

// Types that are never visible and have no styling to miss.
$sl_invisible = array( 'hidden', 'checkbox', 'radio', 'submit', 'button', 'file', 'image' );

$sl_unstyled = array();

foreach ( sl_plugin_sources() as $sl_relative => $sl_contents ) {
	if ( 0 !== strpos( $sl_relative, 'templates/' ) ) {
		continue;
	}

	if ( ! preg_match_all( $sl_input_tag, $sl_contents, $sl_tags, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $sl_tags[0] as $sl_index => $sl_tag ) {
		$sl_attrs = $sl_tags[1][ $sl_index ][0];

		// A type built from PHP is in scope: the stricter direction, and the one
		// dynamic type in the tree (contact.php) satisfies the rule anyway.
		if ( preg_match( '/type=["\']([a-z]+)["\']/', $sl_attrs, $sl_type )
			&& in_array( $sl_type[1], $sl_invisible, true ) ) {
			continue;
		}

		$sl_classes = array();

		if ( preg_match( '/class=["\']([^"\']*)["\']/', $sl_attrs, $sl_class_attr ) ) {
			$sl_classes = preg_split( '/\s+/', trim( $sl_class_attr[1] ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		}

		$sl_known = array_filter( $sl_classes, static fn( string $c ): bool => isset( $sl_styled[ $c ] ) );

		if ( $sl_known ) {
			continue;
		}

		$sl_line       = substr_count( substr( $sl_contents, 0, (int) $sl_tag[1] ), "\n" ) + 1;
		$sl_unstyled[] = $sl_relative . ':' . $sl_line;
	}
}

sl_assert(
	'no visible input renders without a class the stylesheet targets',
	array() === $sl_unstyled,
	'A class rule, not an instance: an input with no class inherits whatever the theme gives it, which is how the unlink confirmation ended up as a browser-default box inline with its label. → ' . implode( ', ', $sl_unstyled )
);

// ---------------------------------------------------------------------
sl_section( 'Rule 4 — a full-width component declares its own box model (16.3)' );

/*
 * `.smart-login *` sets box-sizing by `inherit` at one class of specificity, so
 * a single theme rule outranks it and every `width: 100%` component then adds
 * its padding on the outside. smart-login.css:251-267 already argues this at
 * length and fixes it for `.sl-input` alone.
 *
 * The subject of a selector is its last class: in `.sl-savebar .sl-btn` it is
 * the button that is being sized, not the bar.
 */
$sl_subject_classes = static function ( string $selector ): array {
	$out = array();

	foreach ( explode( ',', $selector ) as $part ) {
		if ( preg_match_all( '/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $part, $found ) ) {
			$out[] = end( $found[1] );
		}
	}

	return $out;
};

$sl_full_width = array();
$sl_guarded    = array();

// @media wrappers are skipped rather than parsed: their inner rules match this
// pattern on their own, which is all the rule needs.
if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $sl_css_code, $sl_blocks, PREG_SET_ORDER ) ) {
	foreach ( $sl_blocks as $sl_block ) {
		$sl_selector = trim( $sl_block[1] );
		$sl_body     = $sl_block[2];

		if ( preg_match( '/(?<![-\w])width\s*:\s*100%/', $sl_body ) ) {
			foreach ( $sl_subject_classes( $sl_selector ) as $sl_class ) {
				if ( 0 === strpos( $sl_class, 'sl-' ) ) {
					$sl_full_width[ $sl_class ] = true;
				}
			}
		}

		if ( preg_match( '/box-sizing\s*:\s*border-box/', $sl_body )
			&& preg_match( '/max-width\s*:\s*100%/', $sl_body ) ) {
			foreach ( $sl_subject_classes( $sl_selector ) as $sl_class ) {
				$sl_guarded[ $sl_class ] = true;
			}
		}
	}
}

$sl_unguarded = array();

foreach ( array_keys( $sl_full_width ) as $sl_class ) {
	// A modifier counts as covered by its base: an element carrying
	// `sl-btn--block` carries `sl-btn` too.
	$sl_base = (string) strstr( $sl_class, '--', true );

	if ( isset( $sl_guarded[ $sl_class ] ) || ( '' !== $sl_base && isset( $sl_guarded[ $sl_base ] ) ) ) {
		continue;
	}

	$sl_unguarded[] = '.' . $sl_class;
}

sort( $sl_unguarded );

sl_assert(
	'every full-width component is covered by the border-box guard',
	array() === $sl_unguarded,
	'width:100% plus padding equals overflow the moment a theme resets box-sizing, and .smart-login * loses that argument on specificity. → ' . implode( ', ', $sl_unguarded )
);

// ---------------------------------------------------------------------
sl_section( 'Rule 5 — the shared fixture holds the shape the rules are about (16.1)' );

/*
 * A test of a test, and it earns the place. The template suite has rendered the
 * contact card with an empty identity list and the provider partial with one
 * google row since 8.2, so its smoke test has never executed the branch this
 * phase exists to fix. Once rule 1 is green nothing else would notice the
 * fixture quietly losing the case again — 14.4's "green by default unless it
 * names the thing it just made", one level up.
 */
/*
 * The fixtures lived inside run-template-tests.php until 18.1 extracted them so
 * the visual renderer could read the same shapes. This rule went red on the
 * commit that moved them, which is the rule working: it is about a *fixture*,
 * and a fixture that has moved house is exactly the boundary a rename crosses
 * without any test noticing. CLAUDE.md records five previous times.
 */
$sl_fixtures = sl_source( 'tests/template-fixtures.php' );

foreach ( array( 'partials/account/contact', 'partials/linked-identities' ) as $sl_name ) {
	$sl_found = preg_match(
		'/\'' . preg_quote( $sl_name, '/' ) . '\'\s*=>\s*array\((.*?)\n\t\),\n/s',
		$sl_fixtures,
		$sl_block
	);

	sl_assert(
		sprintf( 'the %s fixture carries a non-federated identity', $sl_name ),
		1 === $sl_found && (bool) preg_match( "/'federated'\s*=>\s*false/", $sl_block[1] ),
		'The smoke test renders what the fixture describes. A fixture holding only federated identities cannot execute the branch the rules above are about.'
	);
}

// ---------------------------------------------------------------------
sl_section( 'Rule 6 — a lone provider button is a whole row, not half of one' );

/*
 * The provider row was a two-column grid with the count written into it. That
 * was true for exactly as long as two providers shipped; when Zalo was removed
 * the single Google button kept the left-hand track and rendered at half width
 * beside an empty space, which reads as a control that failed to load rather
 * than the only one there is.
 *
 * Written so it stays correct in both directions. Adding a second provider must
 * restore the split without anybody remembering to come back here — so the rule
 * is about the *odd one out*, not about Google, and an even count is asserted to
 * be left alone.
 *
 * What this can and cannot check: the stylesheet is read, not executed. No suite
 * in this repo runs a layout engine, so this pins the declaration that produces
 * the behaviour and the markup it acts on, and stops short of claiming to have
 * measured a rendered width.
 */
$sl_grid_css = (string) preg_replace( '#/\*.*?\*/#s', '', sl_source( 'assets/css/smart-login.css' ) );

preg_match_all( '/([^{}]*\.sl-provider-buttons[^{}]*)\{([^{}]*)\}/s', $sl_grid_css, $sl_grid_rules, PREG_SET_ORDER );

$sl_spanning = '';

foreach ( $sl_grid_rules as $sl_rule ) {
	if ( false !== strpos( $sl_rule[2], 'grid-column' ) ) {
		$sl_spanning = trim( preg_replace( '/\s+/', ' ', $sl_rule[1] ) );
	}
}

sl_assert(
	'the odd button out is told to span the whole row',
	'' !== $sl_spanning && false !== strpos( $sl_spanning, ':last-child' ) && false !== strpos( $sl_spanning, ':nth-child(odd)' ),
	'Nothing in .sl-provider-buttons sets grid-column for the unpaired button, so one provider draws in a two-column track and occupies half the width. Found: ' . ( '' === $sl_spanning ? 'no such rule' : $sl_spanning )
);

/*
 * And the split survives. A rule written as "always one column" would satisfy
 * the assertion above by accident while quietly undoing the pair layout, which
 * is the change this repo asked for to be reversible.
 */
preg_match( '/\.smart-login--identify \.sl-provider-buttons\s*\{([^{}]*)\}/s', $sl_grid_css, $sl_track_rule );

sl_assert(
	'two providers still share the row',
	false !== strpos( $sl_track_rule[1] ?? '', 'repeat(2' ),
	'The two-column track is what a second provider comes back to. Removing it makes the pair layout a rewrite rather than a return. Found: ' . trim( (string) ( $sl_track_rule[1] ?? 'no rule' ) )
);

/*
 * The markup half. `:last-child:nth-child(odd)` only means "the unpaired one"
 * if the buttons are direct children of the grid — a wrapper around each would
 * make every button both last and odd within its own parent, and the rule above
 * would go on passing while every layout it describes was wrong.
 */
$sl_auth_markup = sl_source( 'templates/form-auth.php' );

preg_match( '/<div class="sl-provider-buttons">(.*?)<\/div>/s', $sl_auth_markup, $sl_grid_markup );

sl_assert(
	'the buttons are direct children of the grid',
	'' !== ( $sl_grid_markup[1] ?? '' ) && false === strpos( (string) ( $sl_grid_markup[1] ?? '' ), '<div' ),
	'A wrapper element per button makes every one of them :last-child of its own parent, so the spanning rule would apply to all of them and to none of the right ones.'
);

// ---------------------------------------------------------------------
sl_summary( 'Sign-in card' );
