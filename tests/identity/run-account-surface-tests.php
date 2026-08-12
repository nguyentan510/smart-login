<?php
/**
 * Account surface fitness — one section, one template.
 *
 * Normative spec: docs/account-surface.md. Progress: docs/refactor-plan.md
 * Phase 8.
 *
 * Registered `spec` in run-all.php, which is what that kind is for: a
 * specification that lands ahead of its implementation. Two of the three rules
 * below are red the day they land, deliberately. A guard rail demonstrated only
 * after the defect is gone has demonstrated nothing — see the Postscript in the
 * refactor plan, where three separate gates each passed a fatal for four phases.
 *
 * Promote this suite to `required` when 8.2 turns it green.
 *
 * Run with:  php tests/identity/run-account-surface-tests.php
 *
 * @package OmniWP
 */

require __DIR__ . '/../harness.php';

// ---------------------------------------------------------------------
ow_section( 'One section, one template (Phase 8.2 turns these green)' );

// The account surface exists twice: templates/profile-summary.php and
// templates/woocommerce/form-edit-account.php. Only the first was kept current.
// The second offers "link Google" to accounts that already have Google linked,
// and renders the profile-status notice as a bare implode() of labels — on a
// live site, a blue box containing the single word "Địa chỉ".

// Anchored on the link mode, not on the shared .sl-provider-buttons class. The
// first draft of this rule used the class and named templates/form-auth.php as
// an offender, which is wrong: that block is data-sl-provider-mode="login" — a
// way into the site, not a way to attach a provider to an account you are
// already signed into. Same markup, different concern, and a rule that cannot
// tell the two apart only teaches people to add allowlist entries.
ow_require_single_template(
	'the provider link invitation lives in one template',
	'/data-sl-provider-mode="link"/',
	'partials/linked-identities.php owns what is already linked; one partial must own the invitation to link. The Woo copy calls ProviderRegistry::available() raw and never filters unlinked_providers().'
);

ow_require_single_template(
	'the profile-status notice lives in one template',
	'/recommended_missing/',
	'One copy renders a heading, a sentence and an action link; the other renders implode() of the labels and drops the reason string ProfileCompletionService::onboarding_reasons() exists to supply.'
);

// Green today. The rule is what keeps it green through the extraction: the
// contact panel is the block most likely to be copied into a second surface.
ow_require_single_template(
	'the contact verification panel lives in one template',
	'/data-sl-contact=/',
	'assets/js/omniwp.js binds every [data-sl-contact] panel it finds, and each panel holds its own OTP token. A second copy on one page would wire two panels to one flow.'
);

// ---------------------------------------------------------------------
ow_section( 'The Woo template is the only editing surface (Phase 8.3 turns this green)' );

$controller = ow_source( 'includes/Frontend/class-form-controller.php' );

ow_assert(
	'FormController can save a profile without WooCommerce',
	false !== strpos( $controller, "case 'save_profile'" ),
	'Profile editing runs entirely through WC_Form_Handler::save_account_details. Deactivate WooCommerce and there is no way to edit a profile at all.'
);

$summary = ow_source( 'templates/profile-summary.php' );

ow_assert(
	'the profile summary never sends a customer into wp-admin',
	false === strpos( $summary, "admin_url( 'profile.php' )" ),
	'The "Cập nhật ngay" link falls back to wp-admin when WooCommerce is inactive. That is a leak, not a fallback.'
);

// ---------------------------------------------------------------------
ow_section( 'The WooCommerce adapter still speaks WooCommerce (Phase 8.4)' );

$woo = ow_source( 'includes/Frontend/class-woo-integration.php' );

// WooCommerce 10.9.4 registers save_account_details with no priority, so it runs
// at 10. This ran at 10 too, and equal priorities fall back to registration
// order — which WooCommerce wins, because it registers during plugins_loaded.
// The result was "First name is a required field" on every save, from a form
// that had posted a name. An explicit lower priority makes the ordering a
// property of the code rather than of the load order.
ow_assert(
	'prepare_account_post runs before WooCommerce validates',
	(bool) preg_match( "/'template_redirect',\s*array\(\s*\\\$this,\s*'prepare_account_post'\s*\),\s*([0-9])\s*\)/", $woo, $ow_priority )
		&& (int) $ow_priority[1] < 10,
	'WooCommerce hooks the same action at the default priority 10.'
);

// Since 8.4 the email is text with a "Đổi" beside it, not an input, so nothing
// posts account_email. Backfilling only for synthetic addresses — which is what
// this did — left every real account failing WooCommerce's required-field check.
/*
 * Rewritten in P7.2, and the reason is worth keeping.
 *
 * The first version matched `isset( $_POST['account_email'] ) …
 * wp_get_current_user()->user_email` as one span of text, so it was asserting a
 * *layout* — two statements close together — rather than the property. Adding
 * `wp_unslash()` to the read pushed them apart and the rule went red against
 * behaviour that had not changed at all.
 *
 * Two assertions now, and together they still fail on the real regression. The
 * defect this guards is a backfill that only fires for a synthetic address,
 * which is what left every real account failing WooCommerce's required-field
 * check: the assignment must exist, and the method must not consult
 * `synthetic` anywhere while deciding to make it.
 */
preg_match( '/function prepare_account_post\(\).*?\n\t\}/s', $woo, $ow_prepare );

/*
 * Comments stripped, or the second assertion fails against correct code: the
 * method's own comment explains that the backfill "also covers the synthetic
 * case", and a rule forbidding a word cannot be allowed to punish the sentence
 * that documents why the word is not a condition. Phase 21 hit this three
 * times; it is the same mistake and it is cheap to keep avoiding.
 */
$ow_prepare_body = (string) preg_replace(
	array( '#/\*.*?\*/#s', '#//[^\n]*#' ),
	'',
	$ow_prepare[0] ?? ''
);

ow_assert(
	'prepare_account_post backfills account_email from the address on file',
	'' !== $ow_prepare_body
		&& (bool) preg_match( "/\\\$_POST\['account_email'\]\s*=\s*wp_get_current_user\(\)->user_email/", $ow_prepare_body ),
	'The account form cannot change an email at all, so the address on file is always what it is submitting.'
);

ow_assert(
	'and it does so whatever kind of address that is',
	'' !== $ow_prepare_body && false === stripos( $ow_prepare_body, 'synthetic' ),
	'Backfilling only for synthetic addresses left every real account failing WooCommerce\'s required-field check.'
);

// ---------------------------------------------------------------------
ow_section( 'A refused address says why (P10)' );

/*
 * Written after a false alarm, and worth keeping for that reason.
 *
 * A probe posting `OmniWP_address_1` with no `OmniWP_province_code`
 * key at all saved nothing and reported nothing, which looked like a silent
 * failure. It is not: both save paths guard with
 *
 *     if ( ! isset( $_POST[ AddressFields::FIELD_PROVINCE ] ) ) return;
 *
 * and that guard is correct — "the address card was not on this form" is a real
 * case and must not raise an error. The real form always renders the select, so
 * the key is always posted, and re-run that way the answer arrives:
 *
 *     [error] Vui lòng chọn Tỉnh/Thành phố.
 *
 * with the rest of the profile saved, which is what the comment on
 * save_account_address() promises.
 *
 * So there was nothing to fix, and the near-miss is a rule instead: whoever
 * calls validate() must report what it refuses. A caller that swallows the
 * WP_Error would produce exactly the silence this went looking for.
 */
$ow_validate_callers = array(
	'includes/Frontend/class-woo-integration.php' => array( 'save_account_address', 'wc_add_notice' ),
	// save_onboarding(), not handle_save_profile(): the standalone page's own
	// save delegates the profile fields and the address to it, so one method
	// serves the welcome screen and the account card. Named here because the
	// rule is about *this* body — a notice elsewhere in the file would not help
	// the person whose address was refused.
	//
	// Repointed in 19.1. The method moved to FlowEngine with the rest of the
	// state machine, and it now reports the refusal by putting it on the
	// decision rather than flashing it directly — because the same body serves
	// two transports, only one of which is a page that can hold a cookie. Both
	// appliers emit it, which is what the second rule below is for. The rule
	// went red the moment the body moved, which is what a body-reading rule is
	// for.
	'includes/Auth/class-flow-engine.php'         => array( 'save_onboarding', '->notice(' ),
);

foreach ( $ow_validate_callers as $ow_file => $ow_expect ) {
	$ow_body = ow_method_body( ow_source( $ow_file ), $ow_expect[0] );

	ow_assert(
		sprintf( '%s reports what the address validator refuses', basename( $ow_file ) ),
		'' !== $ow_body
			&& false !== strpos( $ow_body, 'AddressFields::validate' )
			&& false !== strpos( $ow_body, 'is_wp_error' )
			&& false !== strpos( $ow_body, $ow_expect[1] ),
		'A validator whose refusal nobody prints is a form that discards what somebody typed and says nothing. Expected ' . $ow_expect[1] . ' in ' . $ow_expect[0] . '().'
	);
}

/*
 * And no third caller may appear without one. The two above are named because
 * the notice API differs between them — Woo owns its own — and a rule that only
 * knew the two would not notice a third.
 */
ow_require_companion(
	'every file that validates an address also reports a refusal',
	'/AddressFields::validate\(/',
	// `->notice(` joined the two in 19.1: a step that serves both a page and a
	// fetched fragment cannot flash a cookie itself, so it hands the message to
	// whoever asked. The three are the same property through three transports.
	'/wc_add_notice\(|Notices::flash\(|->notice\(/',
	'The guard for "there was no address on this form" is an early return before validate(). Once validate() has run, its answer belongs to the person who typed.',
	array( 'includes/Address/class-address-fields.php' )
);

// ---------------------------------------------------------------------
ow_summary( 'Account surface' );
