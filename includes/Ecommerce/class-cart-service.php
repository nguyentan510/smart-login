<?php
/**
 * E-Commerce Cart Service: handles data serialization, AJAX operations,
 * free shipping progress calculations, and cross-sell recommendations.
 *
 * @package OmniWP
 */

namespace OmniWP\Ecommerce;

use OmniWP\Frontend\VoucherService;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class CartService {

	/**
	 * Get normalized cart payload for frontend rendering and JSON responses.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_cart_data(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			VoucherService::init_cart_session();
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return array(
				'is_empty'       => true,
				'item_count'     => 0,
				'items'          => array(),
				'subtotal'       => 0,
				'subtotal_html'  => function_exists( 'wc_price' ) ? wc_price( 0 ) : '0₫',
				'total'          => 0,
				'total_html'     => function_exists( 'wc_price' ) ? wc_price( 0 ) : '0₫',
				'discount_total' => 0,
				'coupons'        => array(),
				'freeship'       => self::calculate_freeship_progress( 0 ),
				'cross_sells'    => array(),
				'cart_url'       => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' ),
				'checkout_url'   => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' ),
			);
		}

		$cart       = WC()->cart;
		$items      = array();
		$cart_items = $cart->get_cart();
		$subtotal   = (float) $cart->get_subtotal();

		foreach ( $cart_items as $cart_item_key => $cart_item ) {
			/** @var \WC_Product $product */
			$product = $cart_item['data'];
			if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
				continue;
			}

			$product_id   = $product->get_id();
			$product_name = $product->get_name();
			$thumbnail    = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'sl-cart-thumb-img' ) );
			$price_html   = $cart->get_product_price( $product );
			$line_total   = $cart->get_product_subtotal( $product, $cart_item['quantity'] );
			$permalink    = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';

			// Variation details if any.
			$variation_text = '';
			if ( ! empty( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
				$formatted = array();
				foreach ( $cart_item['variation'] as $name => $value ) {
					$taxonomy    = wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', $name ) );
					$label       = wc_attribute_label( $taxonomy, $product );
					$val_term    = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
					$val_name    = $val_term ? $val_term->name : $value;
					$formatted[] = $label . ': ' . $val_name;
				}
				$variation_text = implode( ' | ', $formatted );
			}

			$items[] = array(
				'key'            => $cart_item_key,
				'product_id'     => $product_id,
				'name'           => $product_name,
				'thumbnail'      => $thumbnail,
				'permalink'      => $permalink,
				'price_html'     => $price_html,
				'line_total'     => $line_total,
				'quantity'       => (int) $cart_item['quantity'],
				'max_quantity'   => $product->get_max_purchase_quantity(),
				'min_quantity'   => $product->get_min_purchase_quantity(),
				'step'           => 1,
				'variation_text' => $variation_text,
			);
		}

		// Coupons.
		$applied_coupons = array();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon            = new \WC_Coupon( $code );
			$applied_coupons[] = array(
				'code'          => $code,
				'description'   => $coupon->get_description(),
				'amount'        => $coupon->get_amount(),
				'discount_type' => $coupon->get_discount_type(),
			);
		}

		$item_count = (int) $cart->get_cart_contents_count();

		return array(
			'is_empty'       => $cart->is_empty(),
			'item_count'     => $item_count,
			'items'          => $items,
			'subtotal'       => $subtotal,
			'subtotal_html'  => function_exists( 'wc_price' ) ? wc_price( $subtotal ) : sprintf( '%s₫', number_format( $subtotal, 0, ',', '.' ) ),
			'total'          => (float) $cart->get_total( 'edit' ),
			'total_html'     => $cart->get_total(),
			'discount_total' => (float) $cart->get_discount_total(),
			'coupons'        => $applied_coupons,
			'freeship'       => self::calculate_freeship_progress( $subtotal ),
			'cross_sells'    => self::get_cross_sells( 3 ),
			'cart_url'       => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' ),
			'checkout_url'   => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' ),
		);
	}

	/**
	 * Calculate free shipping progress bar state.
	 *
	 * @param float $subtotal Current cart subtotal.
	 * @return array<string, mixed>
	 */
	public static function calculate_freeship_progress( float $subtotal ): array {
		$threshold = (float) Settings::get( 'ecommerce.freeship_threshold', 500000 );
		if ( $threshold <= 0 ) {
			return array(
				'enabled'        => false,
				'threshold'      => 0,
				'threshold_html' => '',
				'percentage'     => 100,
				'remaining'      => 0,
				'remaining_html' => '',
				'is_reached'     => true,
				'message'        => '',
			);
		}

		$remaining  = max( 0, $threshold - $subtotal );
		$percentage = min( 100, (int) round( ( $subtotal / $threshold ) * 100 ) );
		$is_reached = $remaining <= 0;

		$threshold_html = function_exists( 'wc_price' ) ? wc_price( $threshold ) : number_format( $threshold ) . '₫';
		$remaining_html = function_exists( 'wc_price' ) ? wc_price( $remaining ) : number_format( $remaining ) . '₫';

		if ( $is_reached ) {
			$message = __( 'Chúc mừng! Bạn đã đủ điều kiện nhận Miễn phí giao hàng 🎉', 'omniwp' );
		} else {
			$message = sprintf(
				/* translators: %s: formatted remaining amount */
				__( 'Mua thêm %s để được Miễn phí giao hàng', 'omniwp' ),
				$remaining_html
			);
		}

		return array(
			'enabled'        => true,
			'threshold'      => $threshold,
			'threshold_html' => $threshold_html,
			'percentage'     => $percentage,
			'remaining'      => $remaining,
			'remaining_html' => $remaining_html,
			'is_reached'     => $is_reached,
			'message'        => $message,
		);
	}

	/**
	 * Retrieve cross-sell products for the current cart items.
	 *
	 * @param int $limit Max items to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_cross_sells( int $limit = 3 ): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}

		$cross_sell_ids = WC()->cart->get_cross_sells();
		if ( empty( $cross_sell_ids ) ) {
			return array();
		}

		$cross_sell_ids = array_slice( array_unique( $cross_sell_ids ), 0, $limit );
		$results        = array();

		foreach ( $cross_sell_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$results[] = array(
				'id'              => $product->get_id(),
				'name'            => $product->get_name(),
				'permalink'       => $product->get_permalink(),
				'thumbnail'       => $product->get_image( 'thumbnail', array( 'class' => 'sl-cross-thumb-img' ) ),
				'price_html'      => $product->get_price_html(),
				'add_to_cart_url' => $product->add_to_cart_url(),
			);
		}

		return $results;
	}

	/**
	 * Update item quantity via AJAX.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $quantity      New quantity.
	 * @return array<string, mixed>
	 */
	public static function update_quantity( string $cart_item_key, int $quantity ): array {
		VoucherService::init_cart_session();
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return array(
				'success' => false,
				'message' => __( 'Không thể khởi tạo giỏ hàng.', 'omniwp' ),
			);
		}

		if ( $quantity <= 0 ) {
			WC()->cart->remove_cart_item( $cart_item_key );
		} else {
			WC()->cart->set_quantity( $cart_item_key, $quantity, true );
		}

		WC()->cart->calculate_totals();

		return array(
			'success' => true,
			'cart'    => self::get_cart_data(),
		);
	}

	/**
	 * Remove an item from the cart.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @return array<string, mixed>
	 */
	public static function remove_item( string $cart_item_key ): array {
		return self::update_quantity( $cart_item_key, 0 );
	}
}
