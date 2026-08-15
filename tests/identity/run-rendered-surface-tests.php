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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Auth\Providers\ProviderRegistry;
use OmniWP\Settings;

Settings::update(
	array(
		'identity.mode'   => 'both',
		'otp.length'      => 6,
		'address.enabled' => true,
		'profile.dob'     => true,
		'profile.gender'  => true,
	)
);

$GLOBALS['ow_logged_in']       = true;
$GLOBALS['ow_current_user_id'] = 7;

$ow_root = dirname( __DIR__, 2 ) . '/';

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

$ow_identity = static function ( string $channel, string $label, bool $federated, bool $primary = false ): array {
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

$ow_offerable = ( new ProviderRegistry() )->get( 'google' );

$ow_providers = array(
	'ow_identities'     => array(
		$ow_identity( 'email', 'Email', false, true ),
		$ow_identity( 'google', 'Google', true ),
	),
	'ow_can_unlink'     => true,
	'ow_redirect'       => 'https://example.test/my-account/',
	'ow_link_providers' => null === $ow_offerable ? array() : array( $ow_offerable ),
);

/*
 * The whole editing surface in one document, because three of the rules below
 * are about relationships *between* cards — an id drawn twice, an aria-controls
 * resolving — and a partial rendered alone cannot show either.
 */
$ow_surfaces = array(
	'status'   => array(
		'ow_status'   => array(
			'complete'            => false,
			'required_missing'    => array( array( 'key' => 'address', 'label' => 'Địa chỉ nhận hàng' ) ),
			'recommended_missing' => array(),
			'total'               => 6,
			'done'                => 4,
		),
		'ow_pending'  => array(),
		'ow_welcome'  => false,
		'ow_edit_url' => 'https://example.test/my-account/edit-account/',
	),
	'profile'  => array(
		'ow_user'   => new WP_User( 7, 'Nguyễn Như' ),
		'ow_gender' => 'female',
		'ow_dob'    => '05/10/1994',
	),
	'contact'  => array(
		'ow_user'       => new WP_User( 7, 'Nguyễn Như' ),
		'ow_phone'      => '84969789475',
		'ow_synthetic'  => false,
		'ow_pending'    => array(),
		'ow_otp_length' => 6,
		'ow_providers'  => $ow_providers,
	),
	'address'  => array(
		'ow_values'   => array(
			'province_code' => '01',
			'province_name' => 'Thành phố Hà Nội',
			'ward_code'     => '00076',
			'ward_name'     => 'Phường Cầu Giấy',
			'street'        => '12 Trần Duy Hưng',
		),
		'ow_required' => false,
	),
	'password' => array(
		'ow_user'        => new WP_User( 7, 'Nguyễn Như' ),
		'ow_has_contact' => true,
	),
);

$ow_html = '';

foreach ( $ow_surfaces as $ow_name => $ow_args ) {
	$ow_html .= $ow_render( 'partials/account/' . $ow_name, $ow_args );
}

/*
 * What account.php and form-edit-account.php do after their form closes. The
 * unlink form is deferred out of the card that uses it — HTML forbids a form
 * inside a form — so a composite that never flushes is a page missing markup
 * the real one has.
 */
ob_start();
\OmniWP\Frontend\DeferredForms::flush();
$ow_html .= (string) ob_get_clean();

/*
 * DOMDocument, not a regex. Every rule below asks a question about structure —
 * "does this id exist", "is this control named", "is there a real form in
 * here" — and a regex answering those is a second, worse HTML parser.
 */
$ow_doc = new DOMDocument();
libxml_use_internal_errors( true );
$ow_doc->loadHTML( '<?xml encoding="UTF-8"><div id="sl-rendered-root">' . $ow_html . '</div>' );
libxml_clear_errors();

$ow_xpath = new DOMXPath( $ow_doc );

ow_assert(
	'the account surface renders as a parseable document',
	$ow_xpath->query( '//section[contains(@class,"sl-card")]' )->length >= 4,
	'Every rule in this suite reads the rendered DOM. If the render is empty they all pass on nothing, which is the failure mode this assertion exists to make impossible.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 1 — the renderer exists, and it knows every account surface (18.1)' );

$ow_renderer = ow_source( 'tests/visual/render.php' );

ow_assert(
	'tests/visual/render.php exists',
	'' !== $ow_renderer,
	'Phase 17 built this in a scratch directory, found two defects no suite could, and deleted it with the session. A tool that has to be rebuilt every time is a tool nobody uses.'
);

$ow_unknown = array();

foreach ( glob( $ow_root . 'templates/partials/account/*.php' ) ?: array() as $ow_file ) {
	$ow_name = basename( $ow_file, '.php' );

	if ( false === strpos( $ow_renderer, "'" . $ow_name . "'" ) ) {
		$ow_unknown[] = $ow_name;
	}
}

ow_assert(
	'every account partial is a surface the renderer can build',
	array() === $ow_unknown,
	'The mechanism 8.2 put in the template suite: a new partial fails the moment it lands and passes once it has arguments. That is what caught card-head in 17.8 before anything else did. → ' . implode( ', ', $ow_unknown )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 2 — every focusable control has an accessible name (18.2)' );

/*
 * Five ways a control can be named, and the wrapping <label> is in the list
 * because leaving it out reports the three gender radios as failures. A rule
 * with three false positives is a rule people learn to ignore.
 */
$ow_nameless = array();

foreach ( $ow_xpath->query( '//button | //a[@href] | //summary | //select | //textarea | //input' ) as $ow_el ) {
	$ow_type = strtolower( $ow_el->getAttribute( 'type' ) );

	if ( 'hidden' === $ow_type ) {
		continue;
	}

	if ( '' !== trim( (string) preg_replace( '/\s+/u', ' ', $ow_el->textContent ) )
		|| '' !== $ow_el->getAttribute( 'aria-label' )
		|| '' !== $ow_el->getAttribute( 'aria-labelledby' )
		|| '' !== $ow_el->getAttribute( 'title' ) ) {
		continue;
	}

	$ow_id = $ow_el->getAttribute( 'id' );

	if ( '' !== $ow_id && $ow_xpath->query( '//label[@for="' . $ow_id . '"]' )->length > 0 ) {
		continue;
	}

	$ow_wrapped = false;

	for ( $ow_parent = $ow_el->parentNode; $ow_parent instanceof DOMElement; $ow_parent = $ow_parent->parentNode ) {
		if ( 'label' === strtolower( $ow_parent->nodeName ) ) {
			$ow_wrapped = true;
			break;
		}
	}

	if ( $ow_wrapped ) {
		continue;
	}

	$ow_nameless[] = $ow_el->nodeName
		. ( '' !== $ow_type ? '[type=' . $ow_type . ']' : '' )
		. ' class="' . $ow_el->getAttribute( 'class' ) . '"';
}

ow_assert(
	'no focusable control is left without a name',
	array() === $ow_nameless,
	'A placeholder is not a name. It is the last resort in the accessible-name computation, and it disappears the moment somebody types into the box it was explaining. → ' . implode( ', ', $ow_nameless )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 3 — a row-level action is at least 24 × 24 (18.3)' );

$ow_css      = ow_source( 'assets/css/omniwp.css' );
$ow_css_code = (string) preg_replace( '#/\*.*?\*/#s', '', $ow_css );

$ow_action_rule = preg_match( '/(?<![-\w.])\.sl-action\s*\{([^{}]*)\}/s', $ow_css_code, $ow_match )
	? $ow_match[1]
	: '';

foreach ( array( 'min-height', 'min-width' ) as $ow_property ) {
	$ow_declared = preg_match( '/(?<![-\w])' . $ow_property . '\s*:\s*(\d+)px/', $ow_action_rule, $ow_value )
		? (int) $ow_value[1]
		: 0;

	ow_assert(
		sprintf( '.sl-action declares a %s of at least 24px', $ow_property ),
		$ow_declared >= 24,
		sprintf(
			'WCAG 2.2 AA (2.5.8) puts the floor at 24×24. "Đổi" is two short characters and measures 20×32 today — on the row a member touches most often. Declared: %s. A floor on the class, not padding on the instance: padding makes the number depend on the word.',
			0 === $ow_declared ? 'nothing' : $ow_declared . 'px'
		)
	);
}

// ---------------------------------------------------------------------
ow_section( 'Rule 4 — nothing points at an id that is not there (18.0, green on arrival)' );

/*
 * Green the day it landed, deliberately. It is the half that stops rule 2 being
 * satisfied by deleting the markup that fails it — and an IDREF is the property
 * most likely to break silently the next time a partial is split, because
 * nothing errors when it stops resolving.
 */
$ow_ids       = array();
$ow_duplicate = array();

foreach ( $ow_xpath->query( '//*[@id]' ) as $ow_el ) {
	$ow_id = $ow_el->getAttribute( 'id' );

	if ( isset( $ow_ids[ $ow_id ] ) ) {
		$ow_duplicate[] = $ow_id;
	}

	$ow_ids[ $ow_id ] = true;
}

ow_assert(
	'no id is drawn twice in one account surface',
	array() === $ow_duplicate,
	'Two elements sharing an id makes every label, every aria-controls and every fragment link ambiguous. → ' . implode( ', ', array_unique( $ow_duplicate ) )
);

$ow_dangling = array();

foreach ( array( 'aria-controls', 'aria-describedby', 'aria-labelledby', 'for' ) as $ow_attr ) {
	foreach ( $ow_xpath->query( '//*[@' . $ow_attr . ']' ) as $ow_el ) {
		foreach ( preg_split( '/\s+/', trim( $ow_el->getAttribute( $ow_attr ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $ow_ref ) {
			if ( ! isset( $ow_ids[ $ow_ref ] ) ) {
				$ow_dangling[] = $ow_el->nodeName . '[' . $ow_attr . '="' . $ow_ref . '"]';
			}
		}
	}
}

ow_assert(
	'every IDREF resolves inside the surface that carries it',
	array() === $ow_dangling,
	'An aria-controls pointing at nothing is worse than no aria-controls: it announces a relationship that does not exist. → ' . implode( ', ', $ow_dangling )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 5 — the destructive control works without JavaScript (18.0, green on arrival)' );

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
$ow_unlink_forms = $ow_xpath->query( '//form[@method="post"][contains(@class,"sl-identity-unlink-form")]' );

ow_assert(
	'the unlink confirmation is a real POST form',
	$ow_unlink_forms->length >= 1,
	'A control that needs a listener to work is a control that does nothing on a page whose JavaScript failed to load.'
);

$ow_form_id = $ow_unlink_forms->length ? $ow_unlink_forms->item( 0 )->getAttribute( 'id' ) : '';

ow_assert(
	'and the controls inside the disclosure point at it',
	'' !== $ow_form_id
		&& $ow_xpath->query( '//details[contains(@class,"sl-identity-unlink")]//input[@type="password"][@form="' . $ow_form_id . '"]' )->length >= 1
		&& $ow_xpath->query( '//details[contains(@class,"sl-identity-unlink")]//button[@type="submit"][@form="' . $ow_form_id . '"]' )->length >= 1,
	'The `form` attribute is what keeps the password box and the confirm button where they belong while the form element itself lives outside the account form.'
);

$ow_summary = $ow_xpath->query( '//details[contains(@class,"sl-identity-unlink")]/summary' );

ow_assert(
	'and it is opened by a <summary>, not by a listener',
	$ow_summary->length >= 1,
	'Styling the summary is 17.3; replacing it with a button would be the regression this rule exists to catch.'
);

foreach ( array( '_wpnonce', 'channel', 'subject' ) as $ow_field ) {
	ow_assert(
		sprintf( 'the form carries its %s', $ow_field ),
		$ow_xpath->query( '//form[@id="' . $ow_form_id . '"]//input[@name="' . $ow_field . '"]' )->length >= 1,
		'A form that submits without JavaScript still has to carry everything the handler needs, and each of these has been added by a different phase.'
	);
}

// ---------------------------------------------------------------------
ow_section( 'Rule 6 — the plugin draws its own focus ring (P2)' );

/*
 * Found by 18.4's keyboard pass. The stylesheet declares `:focus` for
 * `.sl-input`, `.sl-otp-digit` and `.sl-action--danger` and for nothing else, so
 * every button and every row action falls back to the browser's ring — which a
 * theme carrying `*:focus { outline: none }` removes.
 *
 * Same shape as 17.3's `.screen-reader-text`: the plugin already declined to
 * trust themes for one property and did not for its neighbour.
 */
$ow_unfocused = array();

foreach ( array( 'sl-btn', 'sl-action', 'sl-link' ) as $ow_class ) {
	if ( ! preg_match( '/\.' . preg_quote( $ow_class, '/' ) . '(?![a-zA-Z0-9_-])[^{}]*:focus(-visible)?[^{}]*\{[^{}]*outline/s', $ow_css_code ) ) {
		$ow_unfocused[] = '.' . $ow_class;
	}
}

ow_assert(
	'every interactive class declares its own focus outline',
	array() === $ow_unfocused,
	'A keyboard user on a theme that zeroes outlines has no idea where they are. The plugin does not get to rely on somebody else for that. → ' . implode( ', ', $ow_unfocused )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 7 — a control that needs JavaScript says so (P2)' );

/*
 * Also 18.4. With JavaScript off, "Đổi" is a button that does nothing and
 * explains nothing. `partials/address-fields.php` has carried a <noscript> doing
 * exactly this job for the ward select since 8.5; the contact card never got one.
 */
ow_assert(
	'the contact card explains itself with JavaScript off',
	$ow_xpath->query( '//section[@id="sl-section-contact"]//noscript' )->length >= 1,
	'The editor is hidden and opened by a listener. Without one, the row offers a control that cannot work and says nothing about why.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 8 — the off-scale remainder shrinks, and never grows (P5)' );

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
$ow_scaled_props = 'margin|margin-top|margin-right|margin-bottom|margin-left|padding|padding-top|padding-right|padding-bottom|padding-left|gap|row-gap|column-gap|font-size';

preg_match_all(
	'/(?<![-\w])(' . $ow_scaled_props . ')\s*:\s*([^;{}]*\d+px[^;{}]*)/',
	$ow_css_code,
	$ow_remaining,
	PREG_SET_ORDER
);

$ow_off_scale = array();

foreach ( $ow_remaining as $ow_decl ) {
	$ow_off_scale[] = trim( $ow_decl[1] ) . ': ' . trim( $ow_decl[2] );
}

/*
 * The baseline, as of P5. Lower it whenever a conversion lands; the assertion
 * below fails loudly if somebody forgets, which is the point of pinning a
 * number rather than a direction.
 */
$ow_off_scale_baseline = 68;

ow_assert(
	sprintf( 'at most %d off-scale spacing or type literals remain', $ow_off_scale_baseline ),
	count( $ow_off_scale ) <= $ow_off_scale_baseline,
	sprintf(
		'Counted %d. A new literal is a new value nobody chose from a set. → %s',
		count( $ow_off_scale ),
		implode( ' | ', array_slice( array_unique( $ow_off_scale ), 0, 8 ) )
	)
);

ow_assert(
	'and the baseline is not stale',
	count( $ow_off_scale ) === $ow_off_scale_baseline,
	sprintf(
		'Counted %d against a baseline of %d. Lower the baseline in this file — a ratchet nobody tightens is a ratchet that has stopped working.',
		count( $ow_off_scale ),
		$ow_off_scale_baseline
	)
);

// ---------------------------------------------------------------------
ow_section( 'Rule 9 — a placeholder is not a second copy of the label (P6)' );

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
$ow_echoing = array();

foreach ( $ow_xpath->query( '//input[@placeholder]' ) as $ow_input ) {
	$ow_placeholder = trim( $ow_input->getAttribute( 'placeholder' ) );
	$ow_id          = $ow_input->getAttribute( 'id' );

	if ( '' === $ow_id || '' === $ow_placeholder ) {
		continue;
	}

	foreach ( $ow_xpath->query( '//label[@for="' . $ow_id . '"]' ) as $ow_label ) {
		$ow_text = trim( (string) preg_replace( '/\s+/u', ' ', $ow_label->textContent ) );

		// Compared case-insensitively and without the required marker, so
		// "Họ và tên *" and "họ và tên" are still the same sentence.
		if ( '' !== $ow_text
			&& 0 === strcasecmp( rtrim( $ow_text, " *\t\n" ), $ow_placeholder ) ) {
			$ow_echoing[] = $ow_id . ': "' . $ow_placeholder . '"';
		}
	}
}

ow_assert(
	'no placeholder repeats the label above it',
	array() === $ow_echoing,
	'A placeholder is the format or an example. A restated label is text that vanishes exactly when somebody most needs it. → ' . implode( ', ', $ow_echoing )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 10 — the save bar is a bar, not a button (P8)' );

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
$ow_page_html = $ow_render(
	'account',
	array(
		'ow_form'  => new \OmniWP\Frontend\AccountForm( 7, \OmniWP\Frontend\AccountForm::CONTEXT_STANDALONE ),
		'notices'  => array(),
	)
);

$ow_page_doc = new DOMDocument();
libxml_use_internal_errors( true );
$ow_page_doc->loadHTML( '<?xml encoding="UTF-8">' . $ow_page_html );
libxml_clear_errors();

$ow_page_xpath = new DOMXPath( $ow_page_doc );

ow_assert(
	'the standalone account page renders a save bar',
	$ow_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]' )->length === 1,
	'Every rule above reads a composite of partials, and the bar belongs to the page. A rule that cannot see it is how 17.3 went unnoticed.'
);

$ow_wide = array();

foreach ( $ow_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]//*[contains(concat(" ", normalize-space(@class), " "), " sl-btn ")]' ) as $ow_el ) {
	if ( false === strpos( $ow_el->getAttribute( 'class' ), 'sl-btn--inline' ) ) {
		$ow_wide[] = trim( (string) preg_replace( '/\s+/u', ' ', $ow_el->textContent ) );
	}
}

ow_assert(
	'every button in the bar declares itself inline',
	array() === $ow_wide,
	'`.sl-btn` is `width: 100%` by default and the bar has no ancestor rule taking it back — that is 17.3, deliberately. A button in the bar without the modifier fills the bar and pushes the warning to nothing. → ' . implode( ', ', $ow_wide )
);

ow_assert(
	'the bar offers a way out as well as a way forward',
	$ow_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]//button[@type="reset"]' )->length >= 1
		&& $ow_page_xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sl-savebar ")]//button[@type="submit"]' )->length >= 1,
	'Huỷ is a native <button type="reset">, so cancelling an edit works with JavaScript off — the browser puts every field back to what the server rendered.'
);

ow_assert(
	'the unsaved warning exists, and starts hidden',
	$ow_page_xpath->query( '//*[@data-sl-savebar-state][@hidden]' )->length >= 1
		&& $ow_page_xpath->query( '//*[@data-sl-savebar-text]' )->length >= 1,
	'An aria-live region that is present and empty has already been announced; one that appears is an announcement. And the text node is separate so repainting it does not eat the warning mark beside it.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 11 — nothing rendered inside the account form is a form (P9)' );

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
 * fixture anywhere had one. The gate's own account holds a federated identity that
 * is not removable, so even the integration gate rendered a single form.
 *
 * A string rule rather than a DOM one, deliberately: DOMDocument accepts nested
 * forms and reports two, which is exactly the tolerance that let this through a
 * suite full of DOM assertions.
 */
$ow_nested = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( 0 !== strpos( $ow_relative, 'templates/partials/' ) ) {
		continue;
	}

	if ( preg_match( '/<form\b/i', $ow_contents ) && false === strpos( $ow_contents, 'DeferredForms' ) ) {
		$ow_nested[] = $ow_relative;
	}
}

ow_assert(
	'no partial emits a form where it stands',
	array() === $ow_nested,
	'A partial is rendered inside the account form. A <form> it emits there ends that form, and everything below — the address card, the password card, the save bar and its submit button — stops being part of anything. → ' . implode( ', ', $ow_nested )
);

/*
 * And the pages that hold the account form flush what the partials deferred.
 * A registered form nobody emits is a `form="…"` attribute pointing at nothing,
 * which is the same dead button by a different route.
 */
$ow_unflushed = array();

foreach ( array( 'templates/account.php', 'templates/woocommerce/form-edit-account.php' ) as $ow_page ) {
	$ow_body  = ow_source( $ow_page );
	$ow_close = strrpos( $ow_body, '</form>' );
	$ow_call  = strpos( $ow_body, 'DeferredForms::flush()' );

	if ( false === $ow_call || false === $ow_close || $ow_call < $ow_close ) {
		$ow_unflushed[] = $ow_page;
	}
}

ow_assert(
	'and every page holding the account form flushes them after it closes',
	array() === $ow_unflushed,
	'Flushed before the closing tag and the deferred form is nested again, which is the defect this rule exists for. → ' . implode( ', ', $ow_unflushed )
);

// ---------------------------------------------------------------------
ow_summary( 'Rendered surface' );
