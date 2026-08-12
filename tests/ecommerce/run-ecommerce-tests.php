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

ow_summary( 'E-Commerce Suite' );
