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
 * What account.php and form-edit-account.php do after their form closes. The
 * unlink form is deferred out of the card that uses it — HTML forbids a form
 * inside a form — so a composite that never flushes is a page missing markup
 * the real one has.
 */
ob_start();
\SmartLogin\Frontend\DeferredForms::flush();
$sl_html .= (string) ob_get_clean();

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
/*
 * The form is no longer *inside* the `<details>`, and that is the fix for
 * "Lưu thay đổi does nothing": a `<form>` inside the account form is invalid
 * HTML, the parser drops the inner start tag, and the inner close tag ends the
 * outer form — taking the save bar with it. The controls stay in the
 * `<details>` and reach their form through the `form` attribute.
 *
 * So the property this rule is about — it works with JavaScript off — is now
 * "there is a real POST form, and the controls point at it", not "the form is
 * nested here".
 */
$sl_unlink_forms = $sl_xpath->query( '//form[@method="post"][contains(@class,"sl-identity-unlink-form")]' );

sl_assert(
	'the unlink confirmation is a real POST form',
	$sl_unlink_forms->length >= 1,
	'A control that needs a listener to work is a control that does nothing on a page whose JavaScript failed to load.'
);

$sl_form_id = $sl_unlink_forms->length ? $sl_unlink_forms->item( 0 )->getAttribute( 'id' ) : '';

sl_assert(
	'and the controls inside the disclosure point at it',
	'' !== $sl_form_id
		&& $sl_xpath->query( '//details[contains(@class,"sl-identity-unlink")]//input[@type="password"][@form="' . $sl_form_id . '"]' )->length >= 1
		&& $sl_xpath->query( '//details[contains(@class,"sl-identity-unlink")]//button[@type="submit"][@form="' . $sl_form_id . '"]' )->length >= 1,
	'The `form` attribute is what keeps the password box and the confirm button where they belong while the form element itself lives outside the account form.'
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
		$sl_xpath->query( '//form[@id="' . $sl_form_id . '"]//input[@name="' . $sl_field . '"]' )->length >= 1,
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
sl_section( 'Rule 8 — the off-scale remainder shrinks, and never grows (P5)' );

/*
 * 17.2 tokenised the account region and left the rest of the stylesheet as a
 * written deferral. P5 converted every other declaration whose values were
 * *already* on the scale — 51 of them, no value changed, so nothing moved on
 * screen — and left the ones holding genuinely off-scale numbers alone.
 *
 * Those are judgement calls with visual consequences, and some of them are not
 * rhythm at all: `padding-right: 46px` reserves room for the password eye.
 * Converting them in bulk would be the scale inventing requirements.
 *
 * So the remainder is a baseline rather than a rule, the same way phpcs is: the
 * count may fall and must not rise. A ratchet is the only honest shape for debt
 * somebody has decided not to pay today.
 */
$sl_scaled_props = 'margin|margin-top|margin-right|margin-bottom|margin-left|padding|padding-top|padding-right|padding-bottom|padding-left|gap|row-gap|column-gap|font-size';

preg_match_all(
	'/(?<![-\w])(' . $sl_scaled_props . ')\s*:\s*([^;{}]*\d+px[^;{}]*)/',
	$sl_css_code,
	$sl_remaining,
	PREG_SET_ORDER
);

$sl_off_scale = array();

foreach ( $sl_remaining as $sl_decl ) {
	$sl_off_scale[] = trim( $sl_decl[1] ) . ': ' . trim( $sl_decl[2] );
}

/*
 * The baseline, as of P5. Lower it whenever a conversion lands; the assertion
 * below fails loudly if somebody forgets, which is the point of pinning a
 * number rather than a direction.
 */
$sl_off_scale_baseline = 40;

sl_assert(
	sprintf( 'at most %d off-scale spacing or type literals remain', $sl_off_scale_baseline ),
	count( $sl_off_scale ) <= $sl_off_scale_baseline,
	sprintf(
		'Counted %d. A new literal is a new value nobody chose from a set. → %s',
		count( $sl_off_scale ),
		implode( ' | ', array_slice( array_unique( $sl_off_scale ), 0, 8 ) )
	)
);

sl_assert(
	'and the baseline is not stale',
	count( $sl_off_scale ) === $sl_off_scale_baseline,
	sprintf(
		'Counted %d against a baseline of %d. Lower the baseline in this file — a ratchet nobody tightens is a ratchet that has stopped working.',
		count( $sl_off_scale ),
		$sl_off_scale_baseline
	)
);

// ---------------------------------------------------------------------
sl_section( 'Rule 9 — a placeholder is not a second copy of the label (P6)' );

/*
 * The rule this project settled on when the question was first asked, and never
 * wrote down: a placeholder gives the *format* ("dd/mm/yyyy") or an example
 * ("Ví dụ: 12 Trần Duy Hưng"). Anything that restates the label above it is
 * noise that disappears the moment somebody types.
 *
 * 18.2 applied it to the OTP box — `placeholder="Mã OTP"` beside a new label
 * saying the same thing — and removed the placeholder rather than keeping both.
 * This is that decision as a rule, so the next field does not have to
 * rediscover it.
 */
$sl_echoing = array();

foreach ( $sl_xpath->query( '//input[@placeholder]' ) as $sl_input ) {
	$sl_placeholder = trim( $sl_input->getAttribute( 'placeholder' ) );
	$sl_id          = $sl_input->getAttribute( 'id' );

	if ( '' === $sl_id || '' === $sl_placeholder ) {
		continue;
	}

	foreach ( $sl_xpath->query( '//label[@for="' . $sl_id . '"]' ) as $sl_label ) {
		$sl_text = trim( (string) preg_replace( '/\s+/u', ' ', $sl_label->textContent ) );

		// Compared case-insensitively and without the required marker, so
		// "Họ và tên *" and "họ và tên" are still the same sentence.
		if ( '' !== $sl_text
			&& 0 === strcasecmp( rtrim( $sl_text, " *\t\n" ), $sl_placeholder ) ) {
			$sl_echoing[] = $sl_id . ': "' . $sl_placeholder . '"';
		}
	}
}

sl_assert(
	'no placeholder repeats the label above it',
	array() === $sl_echoing,
	'A placeholder is the format or an example. A restated label is text that vanishes exactly when somebody most needs it. → ' . implode( ', ', $sl_echoing )
);

// ---------------------------------------------------------------------
sl_section( 'Rule 10 — the save bar is a bar, not a button (P8)' );

/*
 * Its own document, because `account.php` renders the same five cards the
 * composite above already holds and one page cannot carry two of each id.
 *
 * That separation is also the finding. The save bar is markup no *partial*
 * carries, so every rule in this suite — and the visual renderer's `account`
 * composite, which glues the cards together itself — was blind to it. 17.3
 * removed `width: auto` from `.sl-savebar .sl-btn` and did not add
 * `.sl-btn--inline` to the two templates that needed it; the submit went back to
 * `width: 100%`, wrapped onto its own line and squeezed the unsaved warning to
 * 0×0, and nothing noticed for two phases.
 */
$sl_page_html = $sl_render(
	'account',
	array(
		'sl_form'  => new \SmartLogin\Frontend\AccountForm( 7, \SmartLogin\Frontend\AccountForm::CONTEXT_STANDALONE ),
		'notices'  => array(),
	)
);

$sl_page_doc = new DOMDocument();
libxml_use_internal_errors( true );
$sl_page_doc->loadHTML( '<?xml encoding="UTF-8">' . $sl_page_html );
libxml_clear_errors();

$sl_page_xpath = new DOMXPath( $sl_page_doc );

sl_assert(
	'the standalone account page renders a save bar',
	$sl_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]' )->length === 1,
	'Every rule above reads a composite of partials, and the bar belongs to the page. A rule that cannot see it is how 17.3 went unnoticed.'
);

$sl_wide = array();

foreach ( $sl_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]//*[contains(concat(" ", normalize-space(@class), " "), " sl-btn ")]' ) as $sl_el ) {
	if ( false === strpos( $sl_el->getAttribute( 'class' ), 'sl-btn--inline' ) ) {
		$sl_wide[] = trim( (string) preg_replace( '/\s+/u', ' ', $sl_el->textContent ) );
	}
}

sl_assert(
	'every button in the bar declares itself inline',
	array() === $sl_wide,
	'`.sl-btn` is `width: 100%` by default and the bar has no ancestor rule taking it back — that is 17.3, deliberately. A button in the bar without the modifier fills the bar and pushes the warning to nothing. → ' . implode( ', ', $sl_wide )
);

sl_assert(
	'the bar offers a way out as well as a way forward',
	$sl_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]//button[@type="reset"]' )->length >= 1
		&& $sl_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]//button[@type="submit"]' )->length >= 1,
	'Huỷ is a native <button type="reset">, so cancelling an edit works with JavaScript off — the browser puts every field back to what the server rendered.'
);

sl_assert(
	'the unsaved warning exists, and starts hidden',
	$sl_page_xpath->query( '//*[@data-sl-savebar-state][@hidden]' )->length >= 1
		&& $sl_page_xpath->query( '//*[@data-sl-savebar-text]' )->length >= 1,
	'An aria-live region that is present and empty has already been announced; one that appears is an announcement. And the text node is separate so repainting it does not eat the warning mark beside it.'
);

// ---------------------------------------------------------------------
sl_section( 'Rule 11 — nothing rendered inside the account form is a form (P9)' );

/*
 * The rule for the defect that made "Lưu thay đổi" do nothing.
 *
 * HTML forbids a `<form>` inside a `<form>`. Browsers do not merely ignore the
 * inner one — the parser drops its start tag, so the inner close tag ends the
 * OUTER form. Measured on a real account holding a removable Google identity,
 * on the live site:
 *
 *     <form class="woocommerce-EditAccountForm …">   offset    401
 *       <form class="sl-identity-unlink-form">       offset   8171
 *       </form>                                      offset   9273  ← ends the outer
 *     save bar, nonce, submit                        offset  28359  ← outside
 *
 * Every suite passed throughout, for a reason worth naming: the unlink form
 * only renders for an identity that is *federated and removable*, and no
 * fixture anywhere had one. The gate's own account holds a Zalo identity that
 * is not removable, so even the integration gate rendered a single form.
 *
 * A string rule rather than a DOM one, deliberately: DOMDocument accepts nested
 * forms and reports two, which is exactly the tolerance that let this through a
 * suite full of DOM assertions.
 */
$sl_nested = array();

foreach ( sl_plugin_sources() as $sl_relative => $sl_contents ) {
	if ( 0 !== strpos( $sl_relative, 'templates/partials/' ) ) {
		continue;
	}

	if ( preg_match( '/<form\b/i', $sl_contents ) && false === strpos( $sl_contents, 'DeferredForms' ) ) {
		$sl_nested[] = $sl_relative;
	}
}

sl_assert(
	'no partial emits a form where it stands',
	array() === $sl_nested,
	'A partial is rendered inside the account form. A <form> it emits there ends that form, and everything below — the address card, the password card, the save bar and its submit button — stops being part of anything. → ' . implode( ', ', $sl_nested )
);

/*
 * And the pages that hold the account form flush what the partials deferred.
 * A registered form nobody emits is a `form="…"` attribute pointing at nothing,
 * which is the same dead button by a different route.
 */
$sl_unflushed = array();

foreach ( array( 'templates/account.php', 'templates/woocommerce/form-edit-account.php' ) as $sl_page ) {
	$sl_body  = sl_source( $sl_page );
	$sl_close = strrpos( $sl_body, '</form>' );
	$sl_call  = strpos( $sl_body, 'DeferredForms::flush()' );

	if ( false === $sl_call || false === $sl_close || $sl_call < $sl_close ) {
		$sl_unflushed[] = $sl_page;
	}
}

sl_assert(
	'and every page holding the account form flushes them after it closes',
	array() === $sl_unflushed,
	'Flushed before the closing tag and the deferred form is nested again, which is the defect this rule exists for. → ' . implode( ', ', $sl_unflushed )
);

// ---------------------------------------------------------------------
sl_summary( 'Rendered surface' );
