<?php
/**
 * Rendered-surface fitness — what the page says once it is a page.
 *
 * Normative spec: docs/rendered-surface.md. Progress: docs/refactor-plan.md
 * Phase 18.
 *
 * Landed `spec` in 18.0, which is what that kind is for. Three of the five
 * rules below were red the day they landed, deliberately.
 *
 * **Two were green on arrival, and that is the 16.0 precedent rather than an
 * oversight.** Rule 4 is what stops rule 2 being satisfied by deleting the
 * markup that fails it, and rule 5 turns 17.3's unrun acceptance item into
 * something that runs. A property nothing has ever checked is worth a rule the
 * day somebody notices it holds.
 *
 * Every rule here works on a *rendered* surface parsed as a DOM, not on the
 * template source. That is the whole point of the phase: the two defects Phase
 * 17 found by looking at a page were both invisible to every string-matching
 * rule in this repository.
 *
 * Run with:  php tests/identity/run-rendered-surface-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Settings;

Settings::update(
	array(
		'identity.mode'   => 'both',
		'otp.length'      => 6,
		'address.enabled' => true,
		'profile.dob'     => true,
		'profile.gender'  => true,
	)
);

$GLOBALS['sl_logged_in']       = true;
$GLOBALS['sl_current_user_id'] = 7;

$sl_root = dirname( __DIR__, 2 ) . '/';

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

$sl_identity = static function ( string $channel, string $label, bool $federated, bool $primary = false ): array {
	return array(
		'channel'     => $channel,
		'subject'     => 'sub-1',
		'masked'      => 'sub-••••••',
		'display'     => 'Cai Hoa',
		'label'       => $label,
		'federated'   => $federated,
		'is_primary'  => $primary,
		'linked_by'   => $federated ? 'oauth' : 'otp',
		'verified_at' => '2026-07-30 08:00:00',
		'removable'   => true,
	);
};

$sl_zalo = ( new ProviderRegistry() )->get( 'zalo' );

$sl_providers = array(
	'sl_identities'     => array(
		$sl_identity( 'email', 'Email', false, true ),
		$sl_identity( 'google', 'Google', true ),
	),
	'sl_can_unlink'     => true,
	'sl_redirect'       => 'https://example.test/my-account/',
	'sl_link_providers' => null === $sl_zalo ? array() : array( $sl_zalo ),
);

/*
 * The whole editing surface in one document, because three of the rules below
 * are about relationships *between* cards — an id drawn twice, an aria-controls
 * resolving — and a partial rendered alone cannot show either.
 */
$sl_surfaces = array(
	'status'   => array(
		'sl_status'   => array(
			'complete'            => false,
			'required_missing'    => array( array( 'key' => 'address', 'label' => 'Địa chỉ nhận hàng' ) ),
			'recommended_missing' => array(),
			'total'               => 6,
			'done'                => 4,
		),
		'sl_pending'  => array(),
		'sl_welcome'  => false,
		'sl_edit_url' => 'https://example.test/my-account/edit-account/',
	),
	'profile'  => array(
		'sl_user'   => new WP_User( 7, 'Nguyễn Như' ),
		'sl_gender' => 'female',
		'sl_dob'    => '05/10/1994',
	),
	'contact'  => array(
		'sl_user'       => new WP_User( 7, 'Nguyễn Như' ),
		'sl_phone'      => '84969789475',
		'sl_synthetic'  => false,
		'sl_pending'    => array(),
		'sl_otp_length' => 6,
		'sl_providers'  => $sl_providers,
	),
	'address'  => array(
		'sl_values'   => array(
			'province_code' => '01',
			'province_name' => 'Thành phố Hà Nội',
			'ward_code'     => '00076',
			'ward_name'     => 'Phường Cầu Giấy',
			'street'        => '12 Trần Duy Hưng',
		),
		'sl_required' => false,
	),
	'password' => array(
		'sl_user'        => new WP_User( 7, 'Nguyễn Như' ),
		'sl_has_contact' => true,
	),
);

$sl_html = '';

foreach ( $sl_surfaces as $sl_name => $sl_args ) {
	$sl_html .= $sl_render( 'partials/account/' . $sl_name, $sl_args );
}

/*
 * DOMDocument, not a regex. Every rule below asks a question about structure —
 * "does this id exist", "is this control named", "is there a real form in
 * here" — and a regex answering those is a second, worse HTML parser.
 */
$sl_doc = new DOMDocument();
libxml_use_internal_errors( true );
$sl_doc->loadHTML( '<?xml encoding="UTF-8"><div id="sl-rendered-root">' . $sl_html . '</div>' );
libxml_clear_errors();

$sl_xpath = new DOMXPath( $sl_doc );

sl_assert(
	'the account surface renders as a parseable document',
	$sl_xpath->query( '//section[contains(@class,"sl-card")]' )->length >= 4,
	'Every rule in this suite reads the rendered DOM. If the render is empty they all pass on nothing, which is the failure mode this assertion exists to make impossible.'
);

// ---------------------------------------------------------------------
sl_section( 'Rule 1 — the renderer exists, and it knows every account surface (18.1)' );

$sl_renderer = sl_source( 'tests/visual/render.php' );

sl_assert(
	'tests/visual/render.php exists',
	'' !== $sl_renderer,
	'Phase 17 built this in a scratch directory, found two defects no suite could, and deleted it with the session. A tool that has to be rebuilt every time is a tool nobody uses.'
);

$sl_unknown = array();

foreach ( glob( $sl_root . 'templates/partials/account/*.php' ) ?: array() as $sl_file ) {
	$sl_name = basename( $sl_file, '.php' );

	if ( false === strpos( $sl_renderer, "'" . $sl_name . "'" ) ) {
		$sl_unknown[] = $sl_name;
	}
}

sl_assert(
	'every account partial is a surface the renderer can build',
	array() === $sl_unknown,
	'The mechanism 8.2 put in the template suite: a new partial fails the moment it lands and passes once it has arguments. That is what caught card-head in 17.8 before anything else did. → ' . implode( ', ', $sl_unknown )
);

// ---------------------------------------------------------------------
sl_section( 'Rule 2 — every focusable control has an accessible name (18.2)' );

/*
 * Five ways a control can be named, and the wrapping <label> is in the list
 * because leaving it out reports the three gender radios as failures. A rule
 * with three false positives is a rule people learn to ignore.
 */
$sl_nameless = array();

foreach ( $sl_xpath->query( '//button | //a[@href] | //summary | //select | //textarea | //input' ) as $sl_el ) {
	$sl_type = strtolower( $sl_el->getAttribute( 'type' ) );

	if ( 'hidden' === $sl_type ) {
		continue;
	}

	if ( '' !== trim( (string) preg_replace( '/\s+/u', ' ', $sl_el->textContent ) )
		|| '' !== $sl_el->getAttribute( 'aria-label' )
		|| '' !== $sl_el->getAttribute( 'aria-labelledby' )
		|| '' !== $sl_el->getAttribute( 'title' ) ) {
		continue;
	}

	$sl_id = $sl_el->getAttribute( 'id' );

	if ( '' !== $sl_id && $sl_xpath->query( '//label[@for="' . $sl_id . '"]' )->length > 0 ) {
		continue;
	}

	$sl_wrapped = false;

	for ( $sl_parent = $sl_el->parentNode; $sl_parent instanceof DOMElement; $sl_parent = $sl_parent->parentNode ) {
		if ( 'label' === strtolower( $sl_parent->nodeName ) ) {
			$sl_wrapped = true;
			break;
		}
	}

	if ( $sl_wrapped ) {
		continue;
	}

	$sl_nameless[] = $sl_el->nodeName
		. ( '' !== $sl_type ? '[type=' . $sl_type . ']' : '' )
		. ' class="' . $sl_el->getAttribute( 'class' ) . '"';
}

sl_assert(
	'no focusable control is left without a name',
	array() === $sl_nameless,
	'A placeholder is not a name. It is the last resort in the accessible-name computation, and it disappears the moment somebody types into the box it was explaining. → ' . implode( ', ', $sl_nameless )
);

// ---------------------------------------------------------------------
sl_section( 'Rule 3 — a row-level action is at least 24 × 24 (18.3)' );

$sl_css      = sl_source( 'assets/css/smart-login.css' );
$sl_css_code = (string) preg_replace( '#/\*.*?\*/#s', '', $sl_css );

$sl_action_rule = preg_match( '/(?<![-\w.])\.sl-action\s*\{([^{}]*)\}/s', $sl_css_code, $sl_match )
	? $sl_match[1]
	: '';

foreach ( array( 'min-height', 'min-width' ) as $sl_property ) {
	$sl_declared = preg_match( '/(?<![-\w])' . $sl_property . '\s*:\s*(\d+)px/', $sl_action_rule, $sl_value )
		? (int) $sl_value[1]
		: 0;

	sl_assert(
		sprintf( '.sl-action declares a %s of at least 24px', $sl_property ),
		$sl_declared >= 24,
		sprintf(
			'WCAG 2.2 AA (2.5.8) puts the floor at 24×24. "Đổi" is two short characters and measures 20×32 today — on the row a member touches most often. Declared: %s. A floor on the class, not padding on the instance: padding makes the number depend on the word.',
			0 === $sl_declared ? 'nothing' : $sl_declared . 'px'
		)
	);
}

// ---------------------------------------------------------------------
sl_section( 'Rule 4 — nothing points at an id that is not there (18.0, green on arrival)' );

/*
 * Green the day it landed, deliberately. It is the half that stops rule 2 being
 * satisfied by deleting the markup that fails it — and an IDREF is the property
 * most likely to break silently the next time a partial is split, because
 * nothing errors when it stops resolving.
 */
$sl_ids       = array();
$sl_duplicate = array();

foreach ( $sl_xpath->query( '//*[@id]' ) as $sl_el ) {
	$sl_id = $sl_el->getAttribute( 'id' );

	if ( isset( $sl_ids[ $sl_id ] ) ) {
		$sl_duplicate[] = $sl_id;
	}

	$sl_ids[ $sl_id ] = true;
}

sl_assert(
	'no id is drawn twice in one account surface',
	array() === $sl_duplicate,
	'Two elements sharing an id makes every label, every aria-controls and every fragment link ambiguous. → ' . implode( ', ', array_unique( $sl_duplicate ) )
);

$sl_dangling = array();

foreach ( array( 'aria-controls', 'aria-describedby', 'aria-labelledby', 'for' ) as $sl_attr ) {
	foreach ( $sl_xpath->query( '//*[@' . $sl_attr . ']' ) as $sl_el ) {
		foreach ( preg_split( '/\s+/', trim( $sl_el->getAttribute( $sl_attr ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $sl_ref ) {
			if ( ! isset( $sl_ids[ $sl_ref ] ) ) {
				$sl_dangling[] = $sl_el->nodeName . '[' . $sl_attr . '="' . $sl_ref . '"]';
			}
		}
	}
}

sl_assert(
	'every IDREF resolves inside the surface that carries it',
	array() === $sl_dangling,
	'An aria-controls pointing at nothing is worse than no aria-controls: it announces a relationship that does not exist. → ' . implode( ', ', $sl_dangling )
);

// ---------------------------------------------------------------------
sl_section( 'Rule 5 — the destructive control works without JavaScript (18.0, green on arrival)' );

/*
 * 17.3's unrun acceptance item, turned into something that runs. `<details>`
 * was chosen as the mechanism for exactly this property and nothing has ever
 * checked it — which is the shape of every defect this project keeps finding.
 */
$sl_unlink_forms = $sl_xpath->query( '//details[contains(@class,"sl-identity-unlink")]//form[@method="post"]' );

sl_assert(
	'the unlink confirmation is a real POST form inside a <details>',
	$sl_unlink_forms->length >= 1,
	'A control that needs a listener to work is a control that does nothing on a page whose JavaScript failed to load. The <details> element was chosen so this holds.'
);

$sl_summary = $sl_xpath->query( '//details[contains(@class,"sl-identity-unlink")]/summary' );

sl_assert(
	'and it is opened by a <summary>, not by a listener',
	$sl_summary->length >= 1,
	'Styling the summary is 17.3; replacing it with a button would be the regression this rule exists to catch.'
);

foreach ( array( '_wpnonce', 'channel', 'subject' ) as $sl_field ) {
	sl_assert(
		sprintf( 'the form carries its %s', $sl_field ),
		$sl_xpath->query( '//details[contains(@class,"sl-identity-unlink")]//input[@name="' . $sl_field . '"]' )->length >= 1,
		'A form that submits without JavaScript still has to carry everything the handler needs, and each of these has been added by a different phase.'
	);
}

// ---------------------------------------------------------------------
sl_section( 'Rule 6 — the plugin draws its own focus ring (P2)' );

/*
 * Found by 18.4's keyboard pass. The stylesheet declares `:focus` for
 * `.sl-input`, `.sl-otp-digit` and `.sl-action--danger` and for nothing else, so
 * every button and every row action falls back to the browser's ring — which a
 * theme carrying `*:focus { outline: none }` removes.
 *
 * Same shape as 17.3's `.screen-reader-text`: the plugin already declined to
 * trust themes for one property and did not for its neighbour.
 */
$sl_unfocused = array();

foreach ( array( 'sl-btn', 'sl-action', 'sl-link' ) as $sl_class ) {
	if ( ! preg_match( '/\.' . preg_quote( $sl_class, '/' ) . '(?![a-zA-Z0-9_-])[^{}]*:focus(-visible)?[^{}]*\{[^{}]*outline/s', $sl_css_code ) ) {
		$sl_unfocused[] = '.' . $sl_class;
	}
}

sl_assert(
	'every interactive class declares its own focus outline',
	array() === $sl_unfocused,
	'A keyboard user on a theme that zeroes outlines has no idea where they are. The plugin does not get to rely on somebody else for that. → ' . implode( ', ', $sl_unfocused )
);

// ---------------------------------------------------------------------
sl_section( 'Rule 7 — a control that needs JavaScript says so (P2)' );

/*
 * Also 18.4. With JavaScript off, "Đổi" is a button that does nothing and
 * explains nothing. `partials/address-fields.php` has carried a <noscript> doing
 * exactly this job for the ward select since 8.5; the contact card never got one.
 */
sl_assert(
	'the contact card explains itself with JavaScript off',
	$sl_xpath->query( '//section[@id="sl-section-contact"]//noscript' )->length >= 1,
	'The editor is hidden and opened by a listener. Without one, the row offers a control that cannot work and says nothing about why.'
);

// ---------------------------------------------------------------------
sl_summary( 'Rendered surface' );
