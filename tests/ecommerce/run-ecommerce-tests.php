<?php
/**
 * OmniWP E-Commerce Suite (Slide Cart, In-page Cart, Vietnamese Checkout, VietQR) Test Suite.
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Admin\Screens\GuideScreen;
use OmniWP\Ecommerce\CartService;
use OmniWP\Ecommerce\ThankYouService;
use OmniWP\FieldRegistry;
use OmniWP\Frontend\Shortcodes;

ow_section( 'Rule 1 — E-Commerce settings tabs and schema' );
$tabs = FieldRegistry::tabs();
ow_assert( 'Top-level ecommerce tab exists', isset( $tabs['ecommerce'] ) );
ow_assert( 'Sub-tab ecommerce-checkout exists', isset( $tabs['ecommerce-checkout'] ) );

$cart_enabled = FieldRegistry::get( 'ecommerce.slide_cart_enabled' );
ow_check( 'Slide cart enabled setting default is 1', 1, $cart_enabled['default'] ?? null );

$checkout_enabled = FieldRegistry::get( 'ecommerce.clean_checkout_enabled' );
ow_check( 'Clean checkout setting default is 1', 1, $checkout_enabled['default'] ?? null );

$freeship = FieldRegistry::get( 'ecommerce.freeship_threshold' );
ow_check( 'Freeship threshold default is 500,000', 500000, $freeship['default'] ?? null );

$confirm_modal = FieldRegistry::get( 'ecommerce.order_confirmation_modal_enabled' );
ow_check( 'Order confirmation modal enabled default is 1', 1, $confirm_modal['default'] ?? null );

$confirm_threshold = FieldRegistry::get( 'ecommerce.order_confirmation_days_threshold' );
ow_check( 'Order confirmation days threshold default is 0', 0, $confirm_threshold['default'] ?? null );

ow_section( 'Rule 2 — Shortcodes catalog completeness' );
$catalog = Shortcodes::CATALOG;
ow_assert( 'omniwp_cart is registered in CATALOG', isset( $catalog['omniwp_cart'] ) );
ow_assert( 'omniwp_checkout is registered in CATALOG', isset( $catalog['omniwp_checkout'] ) );
ow_assert( 'omniwp_cart_button is registered in CATALOG', isset( $catalog['omniwp_cart_button'] ) );

$guide_codes = GuideScreen::shortcodes();
ow_check( 'Guide screen documents every catalog shortcode', array_keys( $catalog ), array_keys( $guide_codes ) );

ow_section( 'Rule 3 — Cart Free Shipping Calculation' );
$progress_partial = CartService::calculate_freeship_progress( 200000 );
ow_check( '200k on 500k is 40%', 40, $progress_partial['percentage'] );
ow_check( '200k on 500k has 300k remaining', (float) 300000, (float) $progress_partial['remaining'] );
ow_check( 'is_reached is false', false, $progress_partial['is_reached'] );

$progress_complete = CartService::calculate_freeship_progress( 550000 );
ow_check( '550k on 500k is 100%', 100, $progress_complete['percentage'] );
ow_check( '550k on 500k has 0 remaining', (float) 0, (float) $progress_complete['remaining'] );
ow_check( 'is_reached is true', true, $progress_complete['is_reached'] );

ow_section( 'Rule 4 — Thank You Service VietQR generator' );
$mock_cod_order = new class() {
	public function get_payment_method(): string {
		return 'cod';
	}
};
ow_check( 'COD order generates null VietQR', null, ThankYouService::generate_vietqr_url( $mock_cod_order ) );

ow_section( 'Rule 5 — every AJAX action resolves to a method that exists' );

/*
 * This module arrived outside the process that produced everything else: 2,445
 * lines across CartService, CheckoutService, VoucherService and SlideCart, with
 * no spec, no tracker row and eighteen assertions, twelve of which only read
 * registry defaults back. These rules are the structural floor it never got.
 *
 * The module's whole write surface is `wp_ajax_*`. Twenty-three registrations
 * across two classes, each naming a callback by string, and nothing until now
 * checked that the string names anything.
 */
$ow_ajax_files = array(
	'includes/Ecommerce/class-checkout-service.php',
	'includes/Ecommerce/class-slide-cart.php',
);

$ow_ajax = array();

foreach ( $ow_ajax_files as $ow_file ) {
	$ow_code = ow_source( $ow_file );

	if ( preg_match_all(
		"/add_action\(\s*'wp_ajax_(?:nopriv_)?([a-z0-9_]+)'\s*,\s*array\(\s*\\\$this\s*,\s*'([a-z0-9_]+)'\s*\)/i",
		$ow_code,
		$ow_hits,
		PREG_SET_ORDER
	) ) {
		foreach ( $ow_hits as $ow_hit ) {
			$ow_ajax[ $ow_file ][ $ow_hit[2] ][] = $ow_hit[1];
		}
	}
}

ow_assert(
	'the AJAX scan found the module’s callbacks',
	count( $ow_ajax ) === count( $ow_ajax_files ),
	'A rule that matches nothing passes for want of a subject, which states the opposite of the truth.'
);

$ow_missing_methods = array();

foreach ( $ow_ajax as $ow_file => $ow_methods ) {
	$ow_code = ow_source( $ow_file );

	foreach ( array_keys( $ow_methods ) as $ow_method ) {
		if ( '' === ow_method_body( $ow_code, $ow_method ) ) {
			$ow_missing_methods[] = $ow_file . '::' . $ow_method;
		}
	}
}

ow_assert(
	'every registered AJAX callback is a method on the class that registered it',
	array() === $ow_missing_methods,
	'admin-ajax.php answers 0 and the browser sees a broken cart. Missing: ' . implode( ', ', $ow_missing_methods )
);

ow_section( 'Rule 6 — every AJAX callback verifies a nonce, or says why not' );

/*
 * Four of the seven checkout callbacks call `check_ajax_referer()` and three do
 * not. All three turned out to be defensible when read — but "defensible when
 * read" is exactly the state this rule replaces, because it depends on somebody
 * reading them again after the next edit.
 *
 * The allowlist carries the reason at the call site, which is the repo's
 * standing rule for deferrals. Adding an entry means writing one.
 */
$ow_nonce_exempt = array(
	// Answers 401 and writes nothing; it exists so a guest gets a message
	// instead of admin-ajax.php's bare `0`.
	'ajax_save_address_nopriv' => 'returns a login-required error and touches no state',
	// Administrative-unit reference data, identical for every visitor and
	// already public through the address REST route.
	'ajax_get_wards'           => 'reads the shipped province/ward dataset, which is public',
	// Read-only and scoped to the current session; a cross-origin caller can
	// cause it to run but cannot read the response.
	'ajax_get_checkout_vouchers' => 'read-only, returns only the current user’s own evaluated vouchers',
);

$ow_unprotected = array();

foreach ( $ow_ajax as $ow_file => $ow_methods ) {
	$ow_code = ow_source( $ow_file );

	foreach ( array_keys( $ow_methods ) as $ow_method ) {
		if ( isset( $ow_nonce_exempt[ $ow_method ] ) ) {
			continue;
		}

		$ow_body = ow_method_body( $ow_code, $ow_method );

		if ( false === strpos( $ow_body, 'check_ajax_referer' ) && false === strpos( $ow_body, 'wp_verify_nonce' ) ) {
			$ow_unprotected[] = $ow_file . '::' . $ow_method;
		}
	}
}

ow_assert(
	'every state-changing AJAX callback checks a nonce',
	array() === $ow_unprotected,
	'Unprotected: ' . implode( ', ', $ow_unprotected )
);

// The allowlist must not outlive its entries, for the same reason
// MBSTRING_FUNCTIONS is checked in both directions.
$ow_all_methods = array();
foreach ( $ow_ajax as $ow_methods ) {
	$ow_all_methods = array_merge( $ow_all_methods, array_keys( $ow_methods ) );
}

ow_assert(
	'the nonce allowlist names only callbacks that still exist',
	array() === array_diff( array_keys( $ow_nonce_exempt ), $ow_all_methods ),
	'Stale exemptions hide the next unprotected callback: '
		. implode( ', ', array_diff( array_keys( $ow_nonce_exempt ), $ow_all_methods ) )
);

ow_section( 'Rule 7 — the freeship threshold at its edges' );

/*
 * The one piece of money arithmetic in the module that is pure enough to pin
 * down. The existing two cases were both comfortably inside the range; these are
 * the ones that decide whether a customer is told they qualify.
 */
$ow_exact = CartService::calculate_freeship_progress( 500000 );
ow_check( 'exactly at the threshold is 100%', 100, $ow_exact['percentage'] );
ow_check( 'exactly at the threshold has nothing remaining', (float) 0, (float) $ow_exact['remaining'] );
ow_check( 'exactly at the threshold qualifies', true, $ow_exact['is_reached'] );

$ow_empty = CartService::calculate_freeship_progress( 0 );
ow_check( 'an empty cart is 0%', 0, $ow_empty['percentage'] );
ow_check( 'an empty cart does not qualify', false, $ow_empty['is_reached'] );
ow_check( 'an empty cart still owes the whole threshold', (float) 500000, (float) $ow_empty['remaining'] );

/*
 * `percentage` is rounded, and rounding is where a progress bar starts lying:
 * 499,999 of 500,000 rounds to 100 while `is_reached` is still false. The bar
 * may read full; the flag is what decides shipping, and they are allowed to
 * disagree only in this direction.
 */
$ow_nearly = CartService::calculate_freeship_progress( 499999 );
ow_check( 'a hair under the threshold does not qualify', false, $ow_nearly['is_reached'] );
ow_assert(
	'and still reports something remaining',
	$ow_nearly['remaining'] > 0,
	'A zero remaining with is_reached false would print "Mua thêm 0₫ để được Freeship".'
);

ow_summary( 'E-Commerce Suite' );
