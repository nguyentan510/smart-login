<?php
/**
 * What is left of E-Commerce in this plugin, and what must not be.
 *
 * E-Commerce moved into CartKit, which ships as an independent standalone plugin.
 *
 * Checks: that the ecommerce module is GONE from this repository, not merely unused,
 * that this repository no longer contains ecommerce settings, and that the shortcodes
 * and guide screen are strictly focused on Identity and Account management.
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../admin-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Admin\Screens\GuideScreen;
use OmniWP\FieldRegistry;
use OmniWP\Frontend\Shortcodes;

ow_section( 'Rule 1 — the ecommerce module is gone, not just unused' );

$ow_ghosts = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( 0 === strpos( $ow_relative, 'includes/Ecommerce/' ) || 0 === strpos( $ow_relative, 'templates/ecommerce/' ) ) {
		$ow_ghosts[] = $ow_relative;
	}
}

ow_assert( 'No file of the old ecommerce module survives', empty( $ow_ghosts ) );

foreach ( array( 'assets/css/omniwp-ecommerce.css', 'assets/js/omniwp-slide-cart.js', 'assets/js/omniwp-checkout.js' ) as $ow_asset ) {
	ow_assert( 'Its assets are gone too: ' . $ow_asset, '' === ow_source( $ow_asset ) );
}

ow_assert(
	'And nothing still tries to autoload them',
	! class_exists( '\OmniWP\Ecommerce\SlideCart' ) && ! class_exists( '\OmniWP\Ecommerce\CartService' ),
	'A class that autoloads from a directory that no longer exists is a fatal.'
);

ow_section( 'Rule 2 — the settings it owned went with it' );

$ow_dead_ecom_rows = array();

foreach ( array_keys( FieldRegistry::all() ) as $ow_path ) {
	if ( 0 === strpos( $ow_path, 'ecommerce.' ) ) {
		$ow_dead_ecom_rows[] = $ow_path;
	}
}

ow_assert( 'No ecommerce setting is still declared', empty( $ow_dead_ecom_rows ) );
ow_assert( 'And the tabs that drew them are gone', ! isset( FieldRegistry::tabs()['ecommerce'] ) && ! isset( FieldRegistry::tabs()['ecommerce-checkout'] ) );

ow_section( 'Rule 3 — Shortcodes and Guide Screen are purely identity & account focused' );

$catalog = Shortcodes::CATALOG;
ow_assert( 'Cart shortcode is gone from OmniWP', ! isset( $catalog['omniwp_cart'] ) );
ow_assert( 'Checkout shortcode is gone from OmniWP', ! isset( $catalog['omniwp_checkout'] ) );
ow_assert( 'Cart button shortcode is gone from OmniWP', ! isset( $catalog['omniwp_cart_button'] ) );

$guide_codes = GuideScreen::shortcodes();
ow_check( 'Guide screen documents every catalog shortcode', array_keys( $catalog ), array_keys( $guide_codes ) );

ow_section( 'Rule 4 — Address Book is maintained as user profile capability' );
ow_assert( 'AddressBook class exists in OmniWP for account management', class_exists( '\OmniWP\Address\AddressBook' ) );
