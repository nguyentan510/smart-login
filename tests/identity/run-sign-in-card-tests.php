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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Settings;

Settings::update(
	array(
		'identity.mode' => 'both',
		'otp.length'    => 6,
	)
);

$GLOBALS['ow_logged_in']       = true;
$GLOBALS['ow_current_user_id'] = 7;

$ow_root = dirname( __DIR__, 2 ) . '/';

/**
 * Render one template and return its markup, failing loudly on a throw.
 *
 * The template suite renders every template with one fixture each. These rules
 * need the *same* templates under fixtures chosen to be wrong in a particular
 * way, which is a different job and belongs to the phase that cares.
 */
$ow_render = static function ( string $template, array $args ) use ( $ow_root ): string {
	$captured = ow_capture(
		static function () use ( $ow_root, $template, $args ): void {
			( static function ( string $ow_file, array $ow_args ): void {
				extract( $ow_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
				include $ow_file;
			} )( $ow_root . 'templates/' . $template . '.php', $args );
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
$ow_identity = static function ( string $channel, string $subject, string $masked, string $label, bool $federated, bool $primary = false ): array {
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
$ow_email  = 'user@example.test';
$ow_domain = '@example.test';

// ---------------------------------------------------------------------
ow_section( 'Rule 1 — one value, one place (16.1)' );

$ow_contact_args = array(
	'ow_user'       => new WP_User( 7, 'Nguyễn Như' ),
	'ow_phone'      => '',
	'ow_synthetic'  => false,
	'ow_pending'    => array(),
	'ow_otp_length' => 6,
	'ow_providers'  => array(
		'ow_identities'     => array(
			$ow_identity( 'email', $ow_email, 'us•••' . $ow_domain, 'Email', false, true ),
			$ow_identity( 'google', '117100000000000000000', '1171••••••', 'Google', true ),
		),
		'ow_can_unlink'     => true,
		'ow_redirect'       => 'https://example.test/my-account/',
		'ow_link_providers' => array(),
	),
);

$ow_card = $ow_render( 'partials/account/contact', $ow_contact_args );

ow_assert(
	'the account address appears once in the card',
	1 === substr_count( $ow_card, $ow_domain ),
	sprintf(
		'Counted %d. IdentityLinkService::linked() returns every identity record and has never filtered by channel, so the contact row prints the address whole and the list below prints it masked — two rows a member reads as two addresses.',
		substr_count( $ow_card, $ow_domain )
	)
);

// The first half of this rule can be satisfied by rendering nothing at all. The
// federated row is what the card is for, and it has to survive.
ow_assert(
	'the federated row survives the filter',
	false !== strpos( $ow_card, 'Google' ),
	'A card that answers by rendering nothing has not answered.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 2 — Đổi for what you own, Bỏ liên kết for what you borrowed (16.1)' );

$ow_list = static function ( array $identities ) use ( $ow_render ): string {
	/*
	 * Rendered the way a page renders it: the unlink form is deferred out of the
	 * partial since P9, because a <form> inside the account form ends that form.
	 * A test that reads only the partial reads half the markup.
	 */
	// Reset first: the buffer is static and a previous render in this process
	// would otherwise leak its form into this one.
	\OmniWP\Frontend\DeferredForms::reset();

	$ow_markup = $ow_render(
		'partials/linked-identities',
		array(
			'ow_identities' => $identities,
			'ow_can_unlink' => true,
			'ow_redirect'   => 'https://example.test/my-account/',
		)
	);

	ob_start();
	\OmniWP\Frontend\DeferredForms::flush();

	return $ow_markup . (string) ob_get_clean();
};

$ow_self_only = $ow_list( array( $ow_identity( 'email', $ow_email, 'us•••' . $ow_domain, 'Email', false, true ) ) );
$ow_federated = $ow_list( array( $ow_identity( 'google', '117100000000000000000', '1171••••••', 'Google', true ) ) );

ow_assert(
	'an address the account owns offers no unlink control',
	false === strpos( $ow_self_only, 'unlink_identity' ),
	'The operation a member wants on their own address is Đổi, which the contact row already offers and replace_in_channel() already implements. "Bỏ liên kết" beside a badge reading "Chính" is the wrong verb for the wrong operation.'
);

ow_assert(
	'a federated identity still offers one',
	false !== strpos( $ow_federated, 'unlink_identity' ),
	'The half that stops the rule above from passing because the partial renders nothing at all.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 6 — a provider row names the account, not the number (16.2)' );

/*
 * Through linked() and the stub $wpdb rather than around them: Phase 6 added
 * that stub precisely so a repository test exercises the real path instead of a
 * mock of it, and the resolution order is the thing under test.
 *
 * Each level gets its own record. 11.1 shipped a fallback chain as a no-op with
 * its tests passing, because one assertion that "something resolves" was taken
 * for three that resolve differently.
 */
$ow_row = static function ( string $subject, ?array $meta ): array {
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

$GLOBALS['ow_wpdb_results'] = array(
	$ow_row( '117100000000000000001', array( 'name' => 'Cai Hoa', 'email' => 'hoa@example.test' ) ),
	$ow_row( '117100000000000000002', array( 'email' => 'hoa@example.test' ) ),
	$ow_row( '117100000000000000003', null ),
);

$ow_linked = ( new \OmniWP\Auth\IdentityLinkService() )->linked( 7 );

ow_check( 'a stored display name wins', 'Cai Hoa', $ow_linked[0]['display'] ?? null );

$ow_by_email = (string) ( $ow_linked[1]['display'] ?? '' );

ow_assert(
	'no name falls to the provider address, masked',
	false !== strpos( $ow_by_email, '@example.test' ) && false === strpos( $ow_by_email, 'hoa@' ),
	'A provider address is a real identifier, so the screen-sharing rule that applies to subjects applies to it. Got: ' . $ow_by_email
);

ow_assert(
	'no meta at all falls to the linked date',
	false !== strpos( (string) ( $ow_linked[2]['display'] ?? '' ), '30/07/2026' ),
	'Every identity linked before the claims were stored has empty meta, and a row with no value reads as a rendering fault. Got: ' . ( $ow_linked[2]['display'] ?? '' )
);

$GLOBALS['ow_wpdb_results'] = array();

// Asserted on the markup, not on the payload: `masked` stays in the array for
// the REST route, and it is the row that must stop rendering it.
$ow_sub_row = $ow_list(
	array( $ow_identity( 'google', '117100000000000000000', '1171••••••', 'Google', true ) + array( 'display' => 'Cai Hoa' ) )
);

ow_assert(
	'the rendered row carries no masked subject',
	false === strpos( $ow_sub_row, '1171••••••' ) && false !== strpos( $ow_sub_row, 'Cai Hoa' ),
	'`1171••••••` is the OIDC subject masked. Its owner has never seen that number here or at Google.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 3 — every input the plugin renders is styled by the plugin (16.3)' );

$ow_css = ow_source( 'assets/css/omniwp.css' );

// Comments carry example selectors and declarations. A rule that reads prose is
// a rule that changes colour when somebody rewords a comment — the lesson Phase
// 4 recorded when two fitness rules fired on docblocks.
$ow_css_code = (string) preg_replace( '#/\*.*?\*/#s', '', $ow_css );

// Every class the stylesheet declares a rule for, so a made-up class name cannot
// satisfy the rule below.
preg_match_all( '/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $ow_css_code, $ow_class_matches );
$ow_styled = array_flip( $ow_class_matches[1] );

/*
 * `[^>]` stops at the closing angle of a PHP close tag, and half these
 * attributes are PHP. The alternation consumes a close tag as one unit so the
 * match does not end in the middle of an attribute value.
 *
 * Written as a block comment on purpose: a PHP close tag inside a `//` comment
 * ends PHP mode, and the first draft of this file did exactly that — everything
 * below it was echoed as HTML and two rules silently never ran.
 */
$ow_input_tag = '/<input\b((?:\?>|[^>?]|\?(?!>))*?)>/s';

// Types that are never visible and have no styling to miss.
$ow_invisible = array( 'hidden', 'checkbox', 'radio', 'submit', 'button', 'file', 'image' );

$ow_unstyled = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( 0 !== strpos( $ow_relative, 'templates/' ) ) {
		continue;
	}

	if ( ! preg_match_all( $ow_input_tag, $ow_contents, $ow_tags, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $ow_tags[0] as $ow_index => $ow_tag ) {
		$ow_attrs = $ow_tags[1][ $ow_index ][0];

		// A type built from PHP is in scope: the stricter direction, and the one
		// dynamic type in the tree (contact.php) satisfies the rule anyway.
		if ( preg_match( '/type=["\']([a-z]+)["\']/', $ow_attrs, $ow_type )
			&& in_array( $ow_type[1], $ow_invisible, true ) ) {
			continue;
		}

		$ow_classes = array();

		if ( preg_match( '/class=["\']([^"\']*)["\']/', $ow_attrs, $ow_class_attr ) ) {
			$ow_classes = preg_split( '/\s+/', trim( $ow_class_attr[1] ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		}

		$ow_known = array_filter( $ow_classes, static fn( string $c ): bool => isset( $ow_styled[ $c ] ) );

		if ( $ow_known ) {
			continue;
		}

		$ow_line       = substr_count( substr( $ow_contents, 0, (int) $ow_tag[1] ), "\n" ) + 1;
		$ow_unstyled[] = $ow_relative . ':' . $ow_line;
	}
}

ow_assert(
	'no visible input renders without a class the stylesheet targets',
	array() === $ow_unstyled,
	'A class rule, not an instance: an input with no class inherits whatever the theme gives it, which is how the unlink confirmation ended up as a browser-default box inline with its label. → ' . implode( ', ', $ow_unstyled )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 4 — a full-width component declares its own box model (16.3)' );

/*
 * `.omniwp *` sets box-sizing by `inherit` at one class of specificity, so
 * a single theme rule outranks it and every `width: 100%` component then adds
 * its padding on the outside. omniwp.css:251-267 already argues this at
 * length and fixes it for `.sl-input` alone.
 *
 * The subject of a selector is its last class: in `.sl-savebar .sl-btn` it is
 * the button that is being sized, not the bar.
 */
$ow_subject_classes = static function ( string $selector ): array {
	$out = array();

	foreach ( explode( ',', $selector ) as $part ) {
		if ( preg_match_all( '/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $part, $found ) ) {
			$out[] = end( $found[1] );
		}
	}

	return $out;
};

$ow_full_width = array();
$ow_guarded    = array();

// @media wrappers are skipped rather than parsed: their inner rules match this
// pattern on their own, which is all the rule needs.
if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $ow_css_code, $ow_blocks, PREG_SET_ORDER ) ) {
	foreach ( $ow_blocks as $ow_block ) {
		$ow_selector = trim( $ow_block[1] );
		$ow_body     = $ow_block[2];

		if ( preg_match( '/(?<![-\w])width\s*:\s*100%/', $ow_body ) ) {
			foreach ( $ow_subject_classes( $ow_selector ) as $ow_class ) {
				if ( 0 === strpos( $ow_class, 'sl-' ) ) {
					$ow_full_width[ $ow_class ] = true;
				}
			}
		}

		if ( preg_match( '/box-sizing\s*:\s*border-box/', $ow_body )
			&& preg_match( '/max-width\s*:\s*100%/', $ow_body ) ) {
			foreach ( $ow_subject_classes( $ow_selector ) as $ow_class ) {
				$ow_guarded[ $ow_class ] = true;
			}
		}
	}
}

$ow_unguarded = array();

foreach ( array_keys( $ow_full_width ) as $ow_class ) {
	// A modifier counts as covered by its base: an element carrying
	// `sl-btn--block` carries `sl-btn` too.
	$ow_base = (string) strstr( $ow_class, '--', true );

	if ( isset( $ow_guarded[ $ow_class ] ) || ( '' !== $ow_base && isset( $ow_guarded[ $ow_base ] ) ) ) {
		continue;
	}

	$ow_unguarded[] = '.' . $ow_class;
}

sort( $ow_unguarded );

ow_assert(
	'every full-width component is covered by the border-box guard',
	array() === $ow_unguarded,
	'width:100% plus padding equals overflow the moment a theme resets box-sizing, and .omniwp * loses that argument on specificity. → ' . implode( ', ', $ow_unguarded )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 5 — the shared fixture holds the shape the rules are about (16.1)' );

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
$ow_fixtures = ow_source( 'tests/template-fixtures.php' );

foreach ( array( 'partials/account/contact', 'partials/linked-identities' ) as $ow_name ) {
	$ow_found = preg_match(
		'/\'' . preg_quote( $ow_name, '/' ) . '\'\s*=>\s*array\((.*?)\n\t\),\n/s',
		$ow_fixtures,
		$ow_block
	);

	ow_assert(
		sprintf( 'the %s fixture carries a non-federated identity', $ow_name ),
		1 === $ow_found && (bool) preg_match( "/'federated'\s*=>\s*false/", $ow_block[1] ),
		'The smoke test renders what the fixture describes. A fixture holding only federated identities cannot execute the branch the rules above are about.'
	);
}

// ---------------------------------------------------------------------
ow_section( 'Rule 6 — a lone provider button is a whole row, not half of one' );

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
$ow_grid_css = (string) preg_replace( '#/\*.*?\*/#s', '', ow_source( 'assets/css/omniwp.css' ) );

preg_match_all( '/([^{}]*\.sl-provider-buttons[^{}]*)\{([^{}]*)\}/s', $ow_grid_css, $ow_grid_rules, PREG_SET_ORDER );

$ow_spanning = '';

foreach ( $ow_grid_rules as $ow_rule ) {
	if ( false !== strpos( $ow_rule[2], 'grid-column' ) ) {
		$ow_spanning = trim( preg_replace( '/\s+/', ' ', $ow_rule[1] ) );
	}
}

ow_assert(
	'the odd button out is told to span the whole row',
	'' !== $ow_spanning && false !== strpos( $ow_spanning, ':last-child' ) && false !== strpos( $ow_spanning, ':nth-child(odd)' ),
	'Nothing in .sl-provider-buttons sets grid-column for the unpaired button, so one provider draws in a two-column track and occupies half the width. Found: ' . ( '' === $ow_spanning ? 'no such rule' : $ow_spanning )
);

/*
 * And the split survives. A rule written as "always one column" would satisfy
 * the assertion above by accident while quietly undoing the pair layout, which
 * is the change this repo asked for to be reversible.
 */
preg_match( '/\.omniwp--identify \.sl-provider-buttons\s*\{([^{}]*)\}/s', $ow_grid_css, $ow_track_rule );

ow_assert(
	'two providers still share the row',
	false !== strpos( $ow_track_rule[1] ?? '', 'repeat(2' ),
	'The two-column track is what a second provider comes back to. Removing it makes the pair layout a rewrite rather than a return. Found: ' . trim( (string) ( $ow_track_rule[1] ?? 'no rule' ) )
);

/*
 * The markup half. `:last-child:nth-child(odd)` only means "the unpaired one"
 * if the buttons are direct children of the grid — a wrapper around each would
 * make every button both last and odd within its own parent, and the rule above
 * would go on passing while every layout it describes was wrong.
 */
$ow_auth_markup = ow_source( 'templates/form-auth.php' );

preg_match( '/<div class="sl-provider-buttons">(.*?)<\/div>/s', $ow_auth_markup, $ow_grid_markup );

ow_assert(
	'the buttons are direct children of the grid',
	'' !== ( $ow_grid_markup[1] ?? '' ) && false === strpos( (string) ( $ow_grid_markup[1] ?? '' ), '<div' ),
	'A wrapper element per button makes every one of them :last-child of its own parent, so the spanning rule would apply to all of them and to none of the right ones.'
);

// ---------------------------------------------------------------------
ow_summary( 'Sign-in card' );
