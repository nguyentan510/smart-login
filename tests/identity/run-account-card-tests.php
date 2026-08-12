<?php
/**
 * Account card fitness — one scale, one control vocabulary, one owner per fact.
 *
 * Normative spec: docs/account-card.md. Progress: docs/refactor-plan.md
 * Phase 17.
 *
 * Landed `spec` in 17.0, which is what that kind is for. All eight rules below
 * were red the day they landed, deliberately: the account surface suite has been
 * `required` since 8.3 and rules that are meant to fail cannot live in a suite
 * that blocks.
 *
 * One rule per sub-phase, so "17.4 is done" and "rule 4 is green" are the same
 * sentence.
 *
 * Run with:  php tests/identity/run-account-card-tests.php
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Address\AddressFields;
use OmniWP\Auth\ProfileCompletionService;
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

/**
 * Render one template and return its markup, failing loudly on a throw.
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
$ow_identity = static function ( string $channel, string $label, bool $federated, bool $primary = false ): array {
	return array(
		'channel'     => $channel,
		'subject'     => 'federated' === $channel ? 'sub-1' : 'sub-1',
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

$ow_css      = ow_source( 'assets/css/omniwp.css' );
$ow_css_code = (string) preg_replace( '#/\*.*?\*/#s', '', $ow_css );

/*
 * The scale moved to its own file in 21.1, and this suite caught it — five
 * assertions went red the moment the tokens left `.omniwp`, which is the
 * boundary CLAUDE.md warns a rename crosses.
 *
 * The property being asserted has not changed: a token used but never declared
 * resolves to nothing and the declaration silently drops. Only where the
 * declaration lives has. So this reads the token file, and the rest of the
 * suite keeps reading the stylesheet it was written against.
 */
$ow_tokens_code = (string) preg_replace( '#/\*.*?\*/#s', '', ow_source( 'assets/css/omniwp-tokens.css' ) );

// ---------------------------------------------------------------------
ow_section( 'Rule 1 — the provider\'s own mark, wherever the provider is named (17.1)' );

/*
 * LoginProviderInterface::icon_svg() has been on the interface since Phase 12,
 * Every shipped provider implements it, and templates/form-auth.php renders it on
 * the sign-in screen. The account card names a provider in two places and draws
 * a mark in neither.
 */
$ow_linked_row = $ow_render(
	'partials/linked-identities',
	array(
		'ow_identities' => array( $ow_identity( 'google', 'Google', true ) ),
		'ow_can_unlink' => true,
		'ow_redirect'   => 'https://example.test/my-account/',
	)
);

ow_assert(
	'a linked provider row draws the provider\'s mark',
	false !== strpos( $ow_linked_row, '<svg' ),
	'The asset exists and has since Phase 12. A row reading "Google" in the site\'s body font is the plugin declining to use what it already ships.'
);

$ow_google = ( new ProviderRegistry() )->get( 'google' );

$ow_invitation = $ow_render(
	'partials/account/providers',
	array(
		'ow_identities'     => array(),
		'ow_can_unlink'     => false,
		'ow_redirect'       => 'https://example.test/my-account/',
		'ow_link_providers' => null === $ow_google ? array() : array( $ow_google ),
	)
);

ow_assert(
	'the invitation to link draws it too',
	false !== strpos( $ow_invitation, '<svg' ),
	'Same brand, same screen, two treatments — the sign-in card wears the mark and the account card does not.'
);

/*
 * The link has to say where it came from.
 *
 * It was built with an empty return url, so the transaction carried nowhere to
 * go back to, and a refused link ended on the sign-in step of My Account —
 * which a signed-in visitor never sees, along with the sentence explaining the
 * refusal. The control that starts on this page has to be able to end on it.
 */
ow_assert(
	'the invitation carries a return url back to the account page',
	false !== strpos( $ow_invitation, 'redirect_to=https%3A%2F%2Fexample.test%2Fmy-account%2F' )
		|| false !== strpos( $ow_invitation, 'redirect_to=https%3A//example.test/my-account/' ),
	'start_url() was called with an empty return url, so a failure has nowhere to return to and lands on a screen the visitor cannot be on.'
);

/*
 * linked() returns email and phone rows as well, so the helper is going to be
 * handed channels no provider claims. Asserted here rather than left to a
 * fatal on a live page.
 */
ow_assert(
	'a channel no provider claims renders no mark and no error',
	false === strpos(
		$ow_render(
			'partials/linked-identities',
			array(
				'ow_identities' => array( $ow_identity( 'google', 'Google', true ), $ow_identity( 'phone', 'Số điện thoại', false, true ) ),
				'ow_can_unlink' => true,
				'ow_redirect'   => 'https://example.test/my-account/',
			)
		),
		'render failed'
	),
	'The list is filtered to federated rows for display, but the helper is a public seam and will be called with whatever a caller holds.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 2 — the account surface reads the scale (17.2)' );

/*
 * The region is located by its section comments. The marker assertion comes
 * first on purpose: renaming a comment would otherwise narrow this rule to an
 * empty string and turn it green while measuring nothing — 16.0 shipped a
 * variant of that mistake and it is the reason this paragraph exists.
 */
$ow_region_start = strpos( $ow_css, '/* ---------- Account surface ----------' );
$ow_region_end   = strpos( $ow_css, '/* ---------- Small screens ---------- */' );

ow_assert(
	'the account-surface region is findable',
	false !== $ow_region_start && false !== $ow_region_end && $ow_region_end > $ow_region_start,
	'This rule reads a named span of the stylesheet. If the section comments move, the rule must fail rather than quietly measure nothing.'
);

$ow_region = ( false !== $ow_region_start && false !== $ow_region_end && $ow_region_end > $ow_region_start )
	? substr( $ow_css, $ow_region_start, $ow_region_end - $ow_region_start )
	: '';

// Comments carry prose full of measurements — "a 460px strip", "34px". A rule
// that reads prose changes colour when somebody rewords a comment.
$ow_region_code = (string) preg_replace( '#/\*.*?\*/#s', '', $ow_region );

/*
 * Spacing and type only. `min-width`, `max-width`, `flex-basis`, `min-height`
 * and `border-*` are sizes, not rhythm: a 108px label column and a 180px
 * minimum button are decisions about one component, and forcing them onto a
 * six-step scale would be the rule inventing a requirement nobody made.
 */
$ow_scaled_props = 'margin|margin-top|margin-right|margin-bottom|margin-left|padding|padding-top|padding-right|padding-bottom|padding-left|gap|row-gap|column-gap|font-size';

preg_match_all( '/(?<![-\w])(' . $ow_scaled_props . ')\s*:\s*([^;{}]*\d+px[^;{}]*)/', $ow_region_code, $ow_literals, PREG_SET_ORDER );

$ow_offenders = array();

foreach ( $ow_literals as $ow_literal ) {
	$ow_offenders[] = trim( $ow_literal[1] ) . ': ' . trim( $ow_literal[2] );
}

ow_assert(
	'no spacing or type literal survives in the account surface',
	array() === $ow_offenders,
	sprintf(
		'%d literal(s). Six colour tokens and nothing else is why this card carries nine font sizes, ten spacing values and two negative margins that exist to cancel a distance the component above them emitted. → %s',
		count( $ow_offenders ),
		implode( ' | ', array_slice( $ow_offenders, 0, 8 ) )
	)
);

foreach ( array( '--sl-space-1', '--sl-space-6', '--sl-fs-xs', '--sl-fs-xl', '--sl-radius-card' ) as $ow_token ) {
	ow_assert(
		sprintf( 'the scale declares %s', $ow_token ),
		(bool) preg_match( '/' . preg_quote( $ow_token, '/' ) . '\s*:/', $ow_tokens_code ),
		'A token used but never declared resolves to nothing, and the property silently drops.'
	);
}

/*
 * The measurement behind finding 2, expressed as the property rather than as
 * the number: .sl-input declares a line-height and .sl-btn does not, so the
 * button beside it inherits 1.5 from .omniwp and the two controls in one
 * grid row are different heights. align-items:center hides that; it does not
 * remove it.
 */
$ow_btn_block = '';

if ( preg_match( '/(?<![-\w.])\.sl-btn\s*\{([^{}]*)\}/s', $ow_css_code, $ow_btn_match ) ) {
	$ow_btn_block = $ow_btn_match[1];
}

ow_assert(
	'the button declares the line-height its neighbour does',
	'' !== $ow_btn_block && (bool) preg_match( '/line-height\s*:/', $ow_btn_block ),
	'.sl-input is padding 12 + line-height 1.4; .sl-btn is padding 13 and whatever .omniwp happens to set. They share a grid row in the contact editor.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 3 — width intent is carried by the element (17.3)' );

/*
 * The subject of a selector is its last class. A block whose subject is
 * .sl-btn, whose selector names something else as well, and which sets
 * width: auto, is a button whose width depends on where it was put.
 */
$ow_ancestor_width = array();

if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $ow_css_code, $ow_blocks, PREG_SET_ORDER ) ) {
	foreach ( $ow_blocks as $ow_block ) {
		$ow_selector = trim( $ow_block[1] );

		if ( ! preg_match( '/(?<![-\w])width\s*:\s*auto/', $ow_block[2] ) ) {
			continue;
		}

		foreach ( explode( ',', $ow_selector ) as $ow_part ) {
			$ow_part = trim( $ow_part );

			if ( ! preg_match( '/\.sl-btn[a-zA-Z0-9_-]*\s*$/', $ow_part ) ) {
				continue;
			}

			if ( preg_match( '/^\.sl-btn[a-zA-Z0-9_-]*$/', $ow_part ) ) {
				continue;
			}

			$ow_ancestor_width[] = $ow_part;
		}
	}
}

sort( $ow_ancestor_width );

ow_assert(
	'no ancestor decides how wide a button is',
	array() === $ow_ancestor_width,
	'.sl-btn declares width:100% as its base, so every inline use takes it back — keyed on where the button sits rather than on what it is. A modifier on the element is the same fix with the knowledge in the markup. → ' . implode( ', ', $ow_ancestor_width )
);

/*
 * The account card's rows carry three actions in three elements: a
 * link-styled button, a <summary>, and a full-width outline anchor. Named by
 * file rather than by rendered ancestry because these three partials are the
 * ones that draw rows; sl-link--button stays legitimate on the OTP screen,
 * where it is a standalone control and not a row action.
 *
 * The second half of this rule was written in 17.0 as "no sl-btn--outline in
 * any of the three" and that was too blunt — it flagged two controls that are
 * not row actions at all: contact.php's "Gửi mã", which is the primary action
 * of the editor it sits in, and linked-identities.php's "Xác nhận bỏ liên
 * kết", which is the submit of a confirmation form. Both are buttons and
 * should look like buttons. Narrowed here, before the code was contorted to
 * satisfy a rule that was measuring the wrong thing — and it stays red on all
 * three halves for the defect it is actually about.
 */
$ow_row_partials = array(
	'templates/partials/account/contact.php',
	'templates/partials/account/providers.php',
	'templates/partials/linked-identities.php',
);

$ow_without_action = array();
$ow_other_vocab    = array();

foreach ( $ow_row_partials as $ow_partial ) {
	$ow_body = ow_source( $ow_partial );

	if ( false === strpos( $ow_body, 'sl-action' ) ) {
		$ow_without_action[] = $ow_partial;
	}

	if ( false !== strpos( $ow_body, 'sl-link--button' ) ) {
		$ow_other_vocab[] = $ow_partial;
	}
}

ow_assert(
	'every partial that draws a row draws its actions with one class',
	array() === $ow_without_action,
	'Đổi, Bỏ liên kết and Liên kết are one class of action — a small control acting on the row it sits in. → ' . implode( ', ', $ow_without_action )
);

ow_assert(
	'and no second control vocabulary survives beside it',
	array() === $ow_other_vocab,
	'One shape or three; there is no version of this where both are true. → ' . implode( ', ', $ow_other_vocab )
);

// The invitation is the third weight, and the loudest: a full-width outline
// button under a list of rows, left over from the two-list geometry 16.3
// removed. A provider that is not linked yet is a row like the ones above it.
ow_assert(
	'the invitation to link is a row, not a block',
	false === strpos( ow_source( 'templates/partials/account/providers.php' ), 'sl-btn' ),
	'"Google · chưa liên kết · Liên kết" is the same shape as every other way in listed above it.'
);

/*
 * Written after the defect was seen on screen rather than before, and said so
 * in 17.3's commit message with the red output that preceded the fix.
 *
 * `.screen-reader-text` is a *theme* convention. WordPress declares it in
 * wp-admin and in the block library; nothing guarantees it on the front end of
 * an arbitrary theme. Three templates have used it since 8.4 to carry text that
 * must not be visible, and on a theme that does not declare it the profile card
 * reads "Họ tên * (bắt buộc)" out loud, in the label.
 *
 * The class of defect is the plugin depending on somebody else's stylesheet to
 * hide its own text — which is 16.0's rule 3 ("every input the plugin renders is
 * styled by the plugin") pointed at a different property.
 */
$ow_hiding_classes = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( 0 !== strpos( $ow_relative, 'templates/' ) ) {
		continue;
	}

	if ( preg_match_all( '/\b(screen-reader-text|sr-only|visually-hidden)\b/', $ow_contents, $ow_found ) ) {
		foreach ( $ow_found[1] as $ow_class ) {
			$ow_hiding_classes[ $ow_class ] = true;
		}
	}
}

$ow_undeclared_hiding = array();

foreach ( array_keys( $ow_hiding_classes ) as $ow_class ) {
	if ( ! preg_match( '/\.' . preg_quote( $ow_class, '/' ) . '(?![a-zA-Z0-9_-])/', $ow_css_code ) ) {
		$ow_undeclared_hiding[] = '.' . $ow_class;
	}
}

ow_assert(
	'the plugin does not rely on the theme to hide its own text',
	array() === $ow_undeclared_hiding,
	'Used in three templates since 8.4, declared in none of them. A theme that does not carry the class renders "(bắt buộc)" beside the label it was meant to explain. → ' . implode( ', ', $ow_undeclared_hiding )
);

// ---------------------------------------------------------------------
ow_section( 'Rule 4 — the address the card names is the address it writes (17.4)' );

$GLOBALS['ow_user_meta'] = array();

AddressFields::save_for_user(
	7,
	array(
		'province_code' => '01',
		'province_name' => 'Thành phố Hà Nội',
		'ward_code'     => '00076',
		'ward_name'     => 'Phường Cầu Giấy',
		'street'        => '12 Trần Duy Hưng',
	)
);

$ow_written = $GLOBALS['ow_user_meta'][7] ?? array();

foreach ( array(
	'shipping_state'                => '01',
	'shipping_city'                 => 'Phường Cầu Giấy',
	'shipping_country'              => 'VN',
	'shipping_address_1'            => '12 Trần Duy Hưng',
	'OmniWP_shipping_ward_code' => '00076',
) as $ow_key => $ow_expected ) {
	ow_check( sprintf( 'save_for_user writes %s', $ow_key ), $ow_expected, $ow_written[ $ow_key ] ?? null );
}

// The half that stops the rule above from being satisfied by a writer that
// forgot billing. Both address books, not one swapped for the other.
ow_check( 'and still writes billing_state', '01', $ow_written['billing_state'] ?? null );
ow_check( 'and still writes billing_address_1', '12 Trần Duy Hưng', $ow_written['billing_address_1'] ?? null );

// billing stays the single source of truth; shipping is its mirror. Two readers
// of one fact is the drift this project keeps finding.
$ow_address_source = ow_source( 'includes/Address/class-address-fields.php' );

ow_assert(
	'billing remains the only side that is read back',
	false === strpos( ow_method_body( $ow_address_source, 'get_for_user' ), 'shipping_' )
		&& false === strpos( ow_method_body( $ow_address_source, 'is_complete' ), 'shipping_' ),
	'A mirror that is also read is not a mirror, it is a second source of truth waiting to disagree.'
);

$ow_address_card = $ow_render(
	'partials/account/address',
	array(
		'ow_values'   => array(
			'province_code' => '01',
			'province_name' => 'Thành phố Hà Nội',
			'ward_code'     => '00076',
			'ward_name'     => 'Phường Cầu Giấy',
			'street'        => '12 Trần Duy Hưng',
		),
		'ow_required' => false,
	)
);

ow_assert(
	'the card stops claiming a relationship it does not implement',
	false === strpos( $ow_address_card, 'sửa cả hai' ),
	'WooCommerce\'s Addresses tab holds two addresses. "Sửa ở đây là sửa cả hai" was false for every customer who had ever saved a separate shipping address.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 5 — a password write records when it happened (17.6)' );

/*
 * A companion rule over the writers rather than an assertion about a hook.
 * UserManager::apply_password_hash() writes through $wpdb directly and fires
 * nothing, so a listener on profile_update or wp_set_password would miss the
 * one writer that has no WordPress event behind it at all.
 *
 * The brief said three writers. Landing this rule found a fourth, and the
 * fourth is the one that must *not* record: AccountProvisioner writes a
 * 64-character random string nobody has ever seen, for an account signing in
 * through a provider. "Đổi lần cuối 2 năm trước" about a password its holder
 * never chose is exactly the class of statement this phase exists to remove, so
 * the exception is written down here rather than the meta being set and the
 * sentence hedged.
 */
ow_require_companion(
	'every file that writes a chosen password records the change',
	"/wp_set_password\(|'user_pass'\s*=>/",
	'/record_password_change\(/',
	'The failure mode is not a wrong date — it is a fifth writer added later and a row that quietly goes stale.',
	array( 'includes/Auth/class-account-provisioner.php' )
);

$ow_security_meta = 'OmniWP\\Security\\SecurityMeta';

ow_assert(
	'SecurityMeta owns the key, the write and the phrasing',
	class_exists( $ow_security_meta ),
	'One class, so the meta key and the sentence that renders it cannot drift apart.'
);

if ( class_exists( $ow_security_meta ) ) {
	$GLOBALS['ow_user_meta'] = array();

	ow_check(
		'an account with no stored timestamp describes nothing',
		'',
		$ow_security_meta::describe_password_age( 7 )
	);

	$ow_security_meta::record_password_change( 7 );

	ow_assert(
		'a change recorded now reads as today',
		'' !== $ow_security_meta::describe_password_age( 7 ),
		'The row shows the age or it shows nothing. There is no third state, and "chưa rõ" is the truth for every account that exists today.'
	);

	// Buckets asserted separately, not as one "something is returned": 11.1
	// shipped a fallback chain as a no-op with its tests passing.
	$GLOBALS['ow_user_meta'][7]['_OmniWP_password_changed_at'] = gmdate( 'Y-m-d H:i:s', time() - ( 95 * DAY_IN_SECONDS ) );

	ow_assert(
		'three months ago reads in months',
		false !== strpos( $ow_security_meta::describe_password_age( 7 ), 'tháng' ),
		'Got: ' . $ow_security_meta::describe_password_age( 7 )
	);

	$GLOBALS['ow_user_meta'][7]['_OmniWP_password_changed_at'] = gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) );

	ow_assert(
		'five days ago reads in days',
		false !== strpos( $ow_security_meta::describe_password_age( 7 ), 'ngày' ),
		'Got: ' . $ow_security_meta::describe_password_age( 7 )
	);
}

$GLOBALS['ow_user_meta'] = array();

// ---------------------------------------------------------------------
ow_section( 'Rule 6 — the notice states the reason it already has (17.5)' );

$ow_reasons = ProfileCompletionService::onboarding_reasons();
$ow_reason  = (string) ( $ow_reasons['address'] ?? '' );

$ow_notice = $ow_render(
	'partials/account/status',
	array(
		'ow_status'   => array(
			'complete'            => false,
			'required_missing'    => array( array( 'key' => 'address', 'label' => 'Địa chỉ' ) ),
			'recommended_missing' => array(),
			'total'               => 6,
			'done'                => 5,
		),
		'ow_pending'  => array(),
		'ow_welcome'  => false,
		'ow_edit_url' => 'https://example.test/my-account/edit-account/',
	)
);

ow_assert(
	'the reason renders beside the thing it is a reason for',
	'' !== $ow_reason && false !== strpos( $ow_notice, esc_html( $ow_reason ) ),
	'The box currently renders implode() of the labels — on a live page, "Địa chỉ, Ngày sinh". The sentence that makes each of those worth filling in has been written and translated since Phase 8, and one screen reads it.'
);

ow_assert(
	'and it is not a second copy of that sentence',
	'' !== $ow_reason && false === strpos( ow_source( 'templates/partials/account/status.php' ), $ow_reason ),
	'8.4 removed a second source of truth from this exact block. Copying the string back in is how it returns.'
);

// An item with no reason must still render: `email` deliberately has none, and
// inventing one here would be the copy drifting from where it lives.
$ow_no_reason = $ow_render(
	'partials/account/status',
	array(
		'ow_status'   => array(
			'complete'            => false,
			'required_missing'    => array( array( 'key' => 'email', 'label' => 'Email' ) ),
			'recommended_missing' => array(),
			'total'               => 6,
			'done'                => 5,
		),
		'ow_pending'  => array(),
		'ow_welcome'  => false,
		'ow_edit_url' => '',
	)
);

ow_assert(
	'an item with no reason still renders its label',
	false !== strpos( $ow_no_reason, 'Email' ),
	'A template that renders only what it has a reason for drops the item that most needs stating.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 7 — the fraction has a denominator, and one owner (17.7)' );

$GLOBALS['ow_user_meta'] = array();

$ow_service = new ProfileCompletionService();
$ow_status  = $ow_service->status( 7 );

ow_assert(
	'status() reports how many fields were asked for',
	isset( $ow_status['total'], $ow_status['done'] ) && is_int( $ow_status['total'] ) && is_int( $ow_status['done'] ),
	'"Hoàn thiện 4/6" needs a denominator, and the denominator moves with five settings. Anywhere but here means re-deriving those five lookups in a template.'
);

Settings::update( array( 'profile.dob' => false ) );
$ow_without_dob = ( new ProfileCompletionService() )->status( 7 );
Settings::update( array( 'profile.dob' => true ) );
$ow_with_dob = ( new ProfileCompletionService() )->status( 7 );

ow_assert(
	'the denominator moves when the settings move',
	( $ow_with_dob['total'] ?? 0 ) === ( $ow_without_dob['total'] ?? 0 ) + 1,
	sprintf(
		'A denominator that is secretly a constant passes every other assertion in this section. Got %s without dob, %s with.',
		var_export( $ow_without_dob['total'] ?? null, true ),
		var_export( $ow_with_dob['total'] ?? null, true )
	)
);

ow_forbid_pattern(
	'no template counts the missing fields for itself',
	'/count\(\s*\$ow_(required|optional|status)/',
	array(),
	'The rule that decides a field applies already runs in status(). Counting anywhere else is a second implementation of five settings lookups.'
);

// ---------------------------------------------------------------------
ow_section( 'Rule 8 — each card names itself with its own mark (17.8)' );

$ow_section_args = array(
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
		'ow_providers'  => array(
			'ow_identities'     => array( $ow_identity( 'google', 'Google', true ) ),
			'ow_can_unlink'     => true,
			'ow_redirect'       => 'https://example.test/my-account/',
			'ow_link_providers' => array(),
		),
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
	'password' => array( 'ow_has_contact' => true ),
);

$ow_marks = array();

foreach ( $ow_section_args as $ow_name => $ow_args ) {
	$ow_html = $ow_render( 'partials/account/' . $ow_name, $ow_args );

	if ( preg_match( '/<span class="sl-card__icon"[^>]*>(.*?)<\/span>/s', $ow_html, $ow_mark ) ) {
		$ow_marks[ $ow_name ] = trim( $ow_mark[1] );
	}
}

ow_check( 'every card draws a mark', 4, count( $ow_marks ) );

ow_assert(
	'and no two cards draw the same one',
	4 === count( array_unique( $ow_marks ) ),
	sprintf(
		'Four identical marks distinguish nothing — they are a slot that was never filled. Distinct values: %d. → %s',
		count( array_unique( $ow_marks ) ),
		implode( ' | ', array_unique( $ow_marks ) )
	)
);

ow_require_single_template(
	'the mark is written in one template',
	'/class="sl-card__icon"/',
	'Four partials each carrying their own span is the four-way drift FieldRegistry was rewritten to make unrepresentable. One array declares the label and the mark together.'
);

// ---------------------------------------------------------------------
ow_summary( 'Account card' );
