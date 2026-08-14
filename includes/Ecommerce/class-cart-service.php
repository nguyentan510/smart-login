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

		$cart                = WC()->cart;
		$items               = array();
		$cart_items          = $cart->get_cart();
		$subtotal            = (float) $cart->get_subtotal();
		$item_discount_total = 0;

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

			// Check if product is a variation or variable.
			$is_variable       = false;
			$attributes_config = array();
			$parent_id         = $product_id;
			$variation_id      = 0;

			if ( $product->is_type( 'variation' ) ) {
				$parent_id      = $product->get_parent_id();
				$variation_id   = $product->get_id();
				$parent_product = wc_get_product( $parent_id );

				if ( $parent_product ) {
					// Use parent product name so the variation string is not duplicated in title.
					$product_name = $parent_product->get_name();
				}

				if ( $parent_product && $parent_product->is_type( 'variable' ) ) {
					$is_variable       = true;
					$parent_attributes = $parent_product->get_variation_attributes();

					if ( is_array( $parent_attributes ) ) {
						foreach ( $parent_attributes as $attr_name => $options ) {
							$clean_name = str_replace( array( 'attribute_pa_', 'attribute_', 'pa_' ), '', $attr_name );
							$tax_name   = 'pa_' . $clean_name;
							$attr_key   = ( 0 === strpos( $attr_name, 'attribute_' ) ) ? $attr_name : 'attribute_' . $attr_name;

							if ( taxonomy_exists( $tax_name ) ) {
								$attr_label = wc_attribute_label( $tax_name, $product );
							} else {
								$attr_label = wc_attribute_label( $clean_name, $product );
							}

							$current_val = '';
							foreach ( (array) ( $cart_item['variation'] ?? array() ) as $var_k => $var_v ) {
								if ( $var_k === $attr_name || $var_k === $attr_key || str_replace( 'attribute_', '', $var_k ) === str_replace( 'attribute_', '', $attr_name ) ) {
									$current_val = (string) $var_v;
									break;
								}
							}

							$formatted_options = array();
							if ( taxonomy_exists( $tax_name ) ) {
								$terms = wc_get_product_terms( $parent_id, $tax_name, array( 'fields' => 'all' ) );
								if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
									foreach ( $terms as $term ) {
										if ( in_array( $term->slug, (array) $options, true ) ) {
											$formatted_options[] = array(
												'slug' => $term->slug,
												'name' => $term->name,
											);
										}
									}
								}
							}

							if ( empty( $formatted_options ) && is_array( $options ) ) {
								foreach ( $options as $opt ) {
									$formatted_options[] = array(
										'slug' => (string) $opt,
										'name' => (string) $opt,
									);
								}
							}

							$attributes_config[] = array(
								'name'          => $attr_name,
								'key'           => $attr_key,
								'label'         => $attr_label,
								'current_value' => $current_val,
								'options'       => $formatted_options,
							);
						}
					}
				}
			}

			// Variation text fallback if not already formatted.
			$variation_text = '';
			if ( ! empty( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
				$formatted = array();
				foreach ( $cart_item['variation'] as $name => $value ) {
					$clean_name  = str_replace( array( 'attribute_pa_', 'attribute_', 'pa_' ), '', $name );
					$taxonomy    = 'pa_' . $clean_name;
					$label       = taxonomy_exists( $taxonomy ) ? wc_attribute_label( $taxonomy, $product ) : wc_attribute_label( $clean_name, $product );
					$val_term    = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
					$val_name    = $val_term ? $val_term->name : $value;
					$formatted[] = $label . ': ' . $val_name;
				}
				$variation_text = implode( ' | ', $formatted );
			}

			// Sale & Regular price formatting.
			$is_on_sale              = $product->is_on_sale();
			$regular_price_html      = '';
			$regular_line_total_html = '';
			$discount_badge          = '';
			$saved_amount_val        = 0;
			$saved_amount_html       = '';

			if ( $is_on_sale && $product->get_regular_price() ) {
				$regular_price           = (float) $product->get_regular_price();
				$sale_price              = (float) $product->get_price();
				$regular_price_html      = function_exists( 'wc_price' ) ? wc_price( $regular_price ) : '';
				$regular_line_total_html = function_exists( 'wc_price' ) ? wc_price( $regular_price * (int) $cart_item['quantity'] ) : '';

				if ( $regular_price > $sale_price && $regular_price > 0 ) {
					$percent = round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );
					/* translators: %d: discount percentage. */
					$discount_badge   = sprintf( __( 'Giảm %d%%', 'omniwp' ), $percent );
					$saved_amount_val = ( $regular_price - $sale_price ) * (int) $cart_item['quantity'];
					if ( function_exists( 'wc_price' ) ) {
						$saved_amount_html = wc_price( $saved_amount_val );
					}
				}
			}

			$item_discount_total += $saved_amount_val;

			$items[] = array(
				'key'                     => $cart_item_key,
				'product_id'              => $product_id,
				'parent_id'               => $parent_id,
				'variation_id'            => $variation_id,
				'is_variable'             => $is_variable,
				'attributes_config'       => $attributes_config,
				'name'                    => $product_name,
				'thumbnail'               => $thumbnail,
				'permalink'               => $permalink,
				'price_html'              => $price_html,
				'single_price_html'       => function_exists( 'wc_price' ) ? wc_price( (float) $product->get_price() ) : $price_html,
				'is_on_sale'              => $is_on_sale,
				'regular_price_html'      => $regular_price_html,
				'regular_line_total_html' => $regular_line_total_html,
				'discount_badge'          => $discount_badge,
				'saved_amount_html'       => $saved_amount_html,
				'line_total'              => $line_total,
				'quantity'                => (int) $cart_item['quantity'],
				'max_quantity'            => $product->get_max_purchase_quantity(),
				'min_quantity'            => $product->get_min_purchase_quantity(),
				'step'                    => 1,
				'variation_text'          => $variation_text,
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

		$item_count            = (int) $cart->get_cart_contents_count();
		$coupon_discount_total = (float) $cart->get_discount_total();
		$total_saved_amount    = $item_discount_total + $coupon_discount_total;
		$total_saved_html      = function_exists( 'wc_price' ) ? wc_price( $total_saved_amount ) : sprintf( '%s₫', number_format( $total_saved_amount, 0, ',', '.' ) );

		$original_subtotal      = $subtotal + $item_discount_total;
		$original_subtotal_html = function_exists( 'wc_price' ) ? wc_price( $original_subtotal ) : sprintf( '%s₫', number_format( $original_subtotal, 0, ',', '.' ) );

		$shipping_total = method_exists( $cart, 'get_shipping_total' ) ? (float) $cart->get_shipping_total() : 0.0;
		$shipping_label = $shipping_total > 0 ? ( function_exists( 'wc_price' ) ? wc_price( $shipping_total ) : number_format( $shipping_total ) . '₫' ) : __( 'Miễn phí', 'omniwp' );

		$cross_sells = self::get_cross_sells( 4 );
		$freeship    = self::calculate_freeship_progress( $subtotal );

		// Gap filler: suggest a small addon when customer is near freeship milestone.
		$gap_filler = null;
		if ( ! empty( $freeship['enabled'] ) && ! $freeship['is_reached'] && ! empty( $cross_sells ) ) {
			$gap_filler = $cross_sells[0];
		}

		return array(
			'is_empty'               => $cart->is_empty(),
			'item_count'             => $item_count,
			'items'                  => $items,
			'subtotal'               => $subtotal,
			'subtotal_html'          => function_exists( 'wc_price' ) ? wc_price( $subtotal ) : sprintf( '%s₫', number_format( $subtotal, 0, ',', '.' ) ),
			'original_subtotal'      => $original_subtotal,
			'original_subtotal_html' => $original_subtotal_html,
			'total'                  => (float) $cart->get_total( 'edit' ),
			'total_html'             => $cart->get_total(),
			'discount_total'         => $coupon_discount_total,
			'item_discount_total'    => $item_discount_total,
			'item_discount_html'     => function_exists( 'wc_price' ) ? wc_price( $item_discount_total ) : number_format( $item_discount_total ) . '₫',
			'coupon_discount_total'  => $coupon_discount_total,
			'coupon_discount_html'   => function_exists( 'wc_price' ) ? wc_price( $coupon_discount_total ) : number_format( $coupon_discount_total ) . '₫',
			'total_saved_amount'     => $total_saved_amount,
			'total_saved_html'       => $total_saved_html,
			'shipping_total'         => $shipping_total,
			'shipping_label'         => $shipping_label,
			'coupons'                => $applied_coupons,
			'available_coupons'      => self::get_available_coupons( $subtotal ),
			'freeship'               => $freeship,
			'gap_filler'             => $gap_filler,
			'cross_sells'            => $cross_sells,
			'cart_url'               => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' ),
			'checkout_url'           => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' ),
		);
	}

	/**
	 * Get list of available coupons from WooCommerce store.
	 *
	 * @param float $subtotal Current cart subtotal.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_available_coupons( float $subtotal ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		$cart          = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart : null;
		$applied_codes = $cart ? $cart->get_applied_coupons() : array();

		$coupons = array();
		foreach ( $posts as $post ) {
			$coupon = new \WC_Coupon( $post->post_title );
			if ( ! $coupon->get_id() ) {
				continue;
			}

			$code       = $coupon->get_code();
			$is_applied = in_array( strtolower( $code ), array_map( 'strtolower', $applied_codes ), true );

			// Check expiry.
			$expiry_date = $coupon->get_date_expires();
			$is_expired  = false;
			$expiry_text = '';
			if ( $expiry_date ) {
				$is_expired  = $expiry_date->getTimestamp() < time();
				$expiry_text = $expiry_date->date_i18n( 'd/m/Y' );
			}
			if ( $is_expired ) {
				continue;
			}

			$min_spend = (float) $coupon->get_minimum_amount();
			$max_spend = (float) $coupon->get_maximum_amount();

			$is_usable          = true;
			$requirement_text   = '';
			$amount_needed      = 0;
			$amount_needed_html = '';

			if ( $min_spend > 0 ) {
				$formatted_min    = function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $min_spend ) ) ) : number_format( $min_spend, 0, ',', '.' ) . '₫';
				$requirement_text = sprintf( 'Đơn tối thiểu %s', $formatted_min );
				if ( $subtotal < $min_spend ) {
					$is_usable          = false;
					$amount_needed      = $min_spend - $subtotal;
					$amount_needed_html = function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $amount_needed ) ) ) : number_format( $amount_needed, 0, ',', '.' ) . '₫';
				}
			}

			if ( $max_spend > 0 && $subtotal > $max_spend ) {
				$is_usable = false;
			}

			$discount_type  = $coupon->get_discount_type();
			$amount         = (float) $coupon->get_amount();
			$badge_text     = 'Ưu đãi';
			$discount_label = 'Giảm giá';

			if ( 'percent' === $discount_type ) {
				$badge_text     = sprintf( 'GIẢM %s%%', $amount );
				$discount_label = sprintf( 'Giảm %s%%', $amount );
			} elseif ( 'fixed_cart' === $discount_type || 'fixed_product' === $discount_type ) {
				if ( $amount >= 1000 ) {
					$badge_text = sprintf( 'GIẢM %sk', round( $amount / 1000 ) );
				} else {
					$badge_text = sprintf( 'GIẢM %s', $amount );
				}
				$formatted_amt  = function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) ) : number_format( $amount, 0, ',', '.' ) . '₫';
				$discount_label = sprintf( 'Giảm %s', $formatted_amt );
			} elseif ( $coupon->get_free_shipping() ) {
				$badge_text     = 'FREESHIP';
				$discount_label = 'Miễn phí vận chuyển';
			}

			// Details and conditions for Tooltip.
			$conditions = array();
			if ( ! empty( $coupon->get_description() ) ) {
				$conditions[] = $coupon->get_description();
			}
			if ( $requirement_text ) {
				$conditions[] = $requirement_text;
			}
			if ( $max_spend > 0 ) {
				$formatted_max = function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $max_spend ) ) ) : number_format( $max_spend, 0, ',', '.' ) . '₫';
				$conditions[]  = sprintf( 'Đơn tối đa %s', $formatted_max );
			}
			if ( $coupon->get_individual_use() ) {
				$conditions[] = 'Không áp dụng cùng mã khác';
			}
			if ( $coupon->get_usage_limit() > 0 ) {
				$conditions[] = sprintf( 'Giới hạn %d lượt dùng', $coupon->get_usage_limit() );
			}
			if ( ! $is_usable && $amount_needed_html ) {
				$conditions[] = sprintf( 'Mua thêm %s để đủ điều kiện', $amount_needed_html );
			}

			$details_text = ! empty( $conditions ) ? implode( ' • ', $conditions ) : 'Áp dụng cho đơn hàng hợp lệ.';

			$progress_percent = 100;
			if ( $min_spend > 0 ) {
				if ( $subtotal < $min_spend ) {
					$progress_percent = (int) round( ( $subtotal / $min_spend ) * 100 );
					$progress_percent = max( 0, min( 99, $progress_percent ) );
				}
			}

			$coupons[] = array(
				'id'                 => $coupon->get_id(),
				'code'               => $code,
				'badge'              => $badge_text,
				'discount_label'     => $discount_label,
				'description'        => $details_text,
				'amount'             => $amount,
				'discount_type'      => $discount_type,
				'free_shipping'      => (bool) $coupon->get_free_shipping(),
				'min_spend'          => $min_spend,
				'requirement_text'   => $requirement_text,
				'expiry_text'        => $expiry_text ?: 'Không thời hạn',
				'is_applied'         => $is_applied,
				'is_usable'          => $is_usable,
				'amount_needed'      => $amount_needed,
				'amount_needed_html' => $amount_needed_html,
				'progress_percent'   => $progress_percent,
			);
		}

		return $coupons;
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
			$message = __( '🎉 Bạn đã đủ điều kiện Miễn phí vận chuyển!', 'omniwp' );
		} else {
			$remaining_formatted = '<strong class="sl-freeship-remaining">' . $remaining_html . '</strong>';
			$message             = sprintf(
				/* translators: %s: formatted remaining amount */
				__( 'Mua thêm %s để được Freeship', 'omniwp' ),
				$remaining_formatted
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
		VoucherService::init_cart_session();
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return array(
				'success' => false,
				'message' => __( 'Không thể khởi tạo giỏ hàng.', 'omniwp' ),
			);
		}

		$cart_item    = WC()->cart->get_cart_item( $cart_item_key );
		$removed_data = null;
		if ( ! empty( $cart_item ) ) {
			/** @var \WC_Product|null $product */
			$product      = $cart_item['data'] ?? null;
			$product_name = $product ? $product->get_name() : __( 'Sản phẩm', 'omniwp' );
			$removed_data = array(
				'product_id'   => (int) ( $cart_item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $cart_item['variation_id'] ?? 0 ),
				'quantity'     => (int) ( $cart_item['quantity'] ?? 1 ),
				'variation'    => (array) ( $cart_item['variation'] ?? array() ),
				'name'         => $product_name,
			);
		}

		WC()->cart->remove_cart_item( $cart_item_key );
		WC()->cart->calculate_totals();

		return array(
			'success'      => true,
			'cart'         => self::get_cart_data(),
			'removed_item' => $removed_data,
		);
	}

	/**
	 * Switch item variation via AJAX.
	 *
	 * @param string                $cart_item_key  Cart item key.
	 * @param array<string, string> $new_attributes New variation attributes.
	 * @return array<string, mixed>
	 */
	public static function switch_variation( string $cart_item_key, array $new_attributes ): array {
		VoucherService::init_cart_session();
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return array(
				'success' => false,
				'message' => __( 'Không thể khởi tạo giỏ hàng.', 'omniwp' ),
			);
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( empty( $cart_item ) ) {
			return array(
				'success' => false,
				'message' => __( 'Không tìm thấy sản phẩm trong giỏ hàng.', 'omniwp' ),
			);
		}

		/** @var \WC_Product $product */
		$product = $cart_item['data'] ?? null;
		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Sản phẩm này không hỗ trợ đổi biến thể.', 'omniwp' ),
			);
		}

		$parent_id      = $product->get_parent_id();
		$parent_product = wc_get_product( $parent_id );
		if ( ! $parent_product || ! $parent_product->is_type( 'variable' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Sản phẩm chính không tồn tại.', 'omniwp' ),
			);
		}

		$quantity = (int) $cart_item['quantity'];

		// Merge current variation attributes with new attributes.
		$current_variation = (array) ( $cart_item['variation'] ?? array() );
		$merged_attributes = array();

		foreach ( $current_variation as $k => $v ) {
			$norm_key                       = ( 0 === strpos( $k, 'attribute_' ) ) ? $k : 'attribute_' . $k;
			$merged_attributes[ $norm_key ] = $v;
		}

		foreach ( $new_attributes as $k => $v ) {
			$norm_key                       = ( 0 === strpos( $k, 'attribute_' ) ) ? $k : 'attribute_' . $k;
			$merged_attributes[ $norm_key ] = sanitize_text_field( (string) $v );
		}

		// Find matching variation ID.
		$data_store   = \WC_Data_Store::load( 'product' );
		$variation_id = (int) $data_store->find_matching_product_variation( $parent_product, $merged_attributes );

		if ( ! $variation_id ) {
			return array(
				'success' => false,
				'message' => __( 'Biến thể được chọn hiện không tồn tại hoặc không khả dụng.', 'omniwp' ),
			);
		}

		$target_product = wc_get_product( $variation_id );
		if ( ! $target_product || ! $target_product->is_purchasable() || ! $target_product->is_in_stock() ) {
			return array(
				'success' => false,
				'message' => __( 'Biến thể này hiện đã hết hàng.', 'omniwp' ),
			);
		}

		// Remove old cart item and add new variation.
		WC()->cart->remove_cart_item( $cart_item_key );
		$added = WC()->cart->add_to_cart( $parent_id, $quantity, $variation_id, $merged_attributes );

		if ( ! $added ) {
			// Restore previous item if addition fails.
			WC()->cart->add_to_cart( $parent_id, $quantity, $product->get_id(), $current_variation );
			return array(
				'success' => false,
				'message' => __( 'Không thể chuyển sang biến thể này.', 'omniwp' ),
			);
		}

		WC()->cart->calculate_totals();

		return array(
			'success' => true,
			'cart'    => self::get_cart_data(),
		);
	}
}
