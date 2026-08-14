<?php
/**
 * Voucher / Coupon management service for OmniWP Account Hub.
 *
 * Interacts with WooCommerce's shop_coupon post type & WC_Coupon API to retrieve,
 * evaluate, and format available/used/expired discount vouchers for the customer.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use WC_Coupon;
use WC_DateTime;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class VoucherService {

	/**
	 * Retrieve all relevant vouchers for a given customer.
	 *
	 * Scans public coupons and coupons specifically assigned to the customer,
	 * calculates user-specific usage and expiry, and sorts them logically.
	 *
	 * @param int $user_id Customer WP_User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_customer_vouchers( int $user_id ): array {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Coupon' ) ) {
			return array();
		}

		$user       = get_userdata( $user_id );
		$user_email = ( $user instanceof WP_User ) ? strtolower( trim( $user->user_email ) ) : '';

		// Query published coupons.
		$coupon_posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $coupon_posts ) ) {
			return array();
		}

		$active_vouchers   = array();
		$inactive_vouchers = array();
		$now_ts            = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		foreach ( $coupon_posts as $post ) {
			$coupon = new WC_Coupon( $post->ID );
			if ( ! $coupon->get_id() ) {
				continue;
			}

			$code = $coupon->get_code();
			if ( empty( $code ) ) {
				continue;
			}

			// Email restrictions check.
			$email_restrictions = (array) $coupon->get_email_restrictions();
			$email_restrictions = array_map( 'strtolower', array_map( 'trim', $email_restrictions ) );

			if ( ! empty( $email_restrictions ) ) {
				if ( empty( $user_email ) || ! in_array( $user_email, $email_restrictions, true ) ) {
					// This coupon is strictly restricted to other emails, skip.
					continue;
				}
			}

			$discount_type        = $coupon->get_discount_type();
			$amount               = (float) $coupon->get_amount();
			$date_expires         = $coupon->get_date_expires();
			$minimum_amount       = (float) $coupon->get_minimum_amount();
			$maximum_amount       = (float) $coupon->get_maximum_amount();
			$usage_limit          = (int) $coupon->get_usage_limit();
			$usage_limit_per_user = (int) $coupon->get_usage_limit_per_user();
			$usage_count          = (int) $coupon->get_usage_count();
			$free_shipping        = (bool) $coupon->get_free_shipping();
			$exclude_sale_items   = (bool) $coupon->get_exclude_sale_items();
			$description          = trim( (string) $coupon->get_description() );

			// Check user usage count.
			$used_by   = (array) $coupon->get_used_by();
			$user_uses = 0;
			if ( $user_id > 0 ) {
				foreach ( $used_by as $used_entry ) {
					if ( (string) $used_entry === (string) $user_id || ( $user_email && strtolower( (string) $used_entry ) === $user_email ) ) {
						++$user_uses;
					}
				}
			}

			// Check expiry.
			$is_expired  = false;
			$expires_ts  = null;
			$expiry_text = __( 'Không giới hạn', 'omniwp' );

			if ( $date_expires instanceof WC_DateTime ) {
				$expires_ts  = $date_expires->getTimestamp();
				$expiry_text = $date_expires->date_i18n( 'd/m/Y' );
				if ( $expires_ts < $now_ts ) {
					$is_expired = true;
				}
			}

			// Check system limit.
			$is_limit_reached = ( $usage_limit > 0 && $usage_count >= $usage_limit );

			// Check user limit.
			$is_user_used_up = ( $usage_limit_per_user > 0 && $user_uses >= $usage_limit_per_user );

			// Determine status.
			if ( $is_user_used_up ) {
				$status       = 'used';
				$status_label = __( 'Đã sử dụng', 'omniwp' );
			} elseif ( $is_expired ) {
				$status       = 'expired';
				$status_label = __( 'Hết hạn', 'omniwp' );
			} elseif ( $is_limit_reached ) {
				$status       = 'expired';
				$status_label = __( 'Hết lượt', 'omniwp' );
			} else {
				$status       = 'active';
				$status_label = __( 'Khả dụng', 'omniwp' );
			}

			$is_expiring_soon = ( 'active' === $status && $expires_ts && ( $expires_ts - $now_ts <= 3 * DAY_IN_SECONDS ) );

			// Format discount values.
			$amount_formatted = '';
			$badge_type       = 'money'; // 'money', 'percent', 'shipping'

			if ( $free_shipping && $amount <= 0 ) {
				$amount_formatted = __( 'Miễn phí vận chuyển', 'omniwp' );
				$badge_type       = 'shipping';
			} elseif ( 'percent' === $discount_type ) {
				$amount_formatted = $amount . '%';
				$badge_type       = 'percent';
			} else {
				$amount_formatted = wp_strip_all_tags( wc_price( $amount ) );
				$badge_type       = 'money';
			}

			// Format Headline (Short and concise).
			if ( ! empty( $description ) && mb_strlen( $description ) <= 50 && ! preg_match( '/^-\s*coupon/i', $description ) ) {
				$headline = $description;
			} else {
				$headline = sprintf(
					/* translators: %s: Discount Amount */
					__( 'Giảm %s', 'omniwp' ),
					$amount_formatted
				);
			}

			// Value display (Trị giá: ...).
			$value_display = '';
			if ( 'percent' === $discount_type ) {
				$value_display = sprintf( __( 'Giảm %s', 'omniwp' ), $amount . '%' );
				if ( $maximum_amount > 0 ) {
					$value_display .= ' ' . sprintf( __( '(tối đa %s)', 'omniwp' ), wp_strip_all_tags( wc_price( $maximum_amount ) ) );
				}
			} elseif ( $free_shipping ) {
				$value_display = __( 'Freeship đơn hàng', 'omniwp' );
			} else {
				$value_display = sprintf( __( 'Trị giá: %s', 'omniwp' ), $amount_formatted );
			}

			// Detailed terms list for Modal.
			$terms   = array();
			$terms[] = array(
				'label' => __( 'Mã ưu đãi', 'omniwp' ),
				'value' => strtoupper( $code ),
			);
			$terms[] = array(
				'label' => __( 'Hạn sử dụng', 'omniwp' ),
				'value' => $expiry_text,
			);
			$terms[] = array(
				'label' => __( 'Mức giảm', 'omniwp' ),
				'value' => $amount_formatted,
			);

			if ( $minimum_amount > 0 ) {
				$terms[] = array(
					'label' => __( 'Đơn tối thiểu', 'omniwp' ),
					'value' => wp_strip_all_tags( wc_price( $minimum_amount ) ),
				);
			}

			if ( $maximum_amount > 0 ) {
				$terms[] = array(
					'label' => __( 'Giảm tối đa', 'omniwp' ),
					'value' => wp_strip_all_tags( wc_price( $maximum_amount ) ),
				);
			}

			if ( $exclude_sale_items ) {
				$terms[] = array(
					'label' => __( 'Sản phẩm khuyến mãi', 'omniwp' ),
					'value' => __( 'Không áp dụng chung với sản phẩm đang sale', 'omniwp' ),
				);
			}

			if ( ! empty( $description ) ) {
				$terms[] = array(
					'label' => __( 'Chi tiết thể lệ', 'omniwp' ),
					'value' => $description,
				);
			}

			$voucher_data = array(
				'id'               => $coupon->get_id(),
				'code'             => strtoupper( $code ),
				'headline'         => $headline,
				'value_display'    => $value_display,
				'amount_formatted' => $amount_formatted,
				'badge_type'       => $badge_type,
				'expiry_text'      => $expiry_text,
				'expires_ts'       => $expires_ts,
				'is_expiring_soon' => $is_expiring_soon,
				'min_spend'        => $minimum_amount,
				'min_spend_text'   => $minimum_amount > 0 ? wp_strip_all_tags( wc_price( $minimum_amount ) ) : '',
				'description'      => $description,
				'status'           => $status,
				'status_label'     => $status_label,
				'can_apply'        => ( 'active' === $status ),
				'terms'            => $terms,
			);

			if ( 'active' === $status ) {
				$active_vouchers[] = $voucher_data;
			} else {
				$inactive_vouchers[] = $voucher_data;
			}
		}

		// Sort active vouchers: expiring soonest first, then by highest value.
		usort(
			$active_vouchers,
			static function ( array $a, array $b ): int {
				if ( $a['expires_ts'] && $b['expires_ts'] ) {
					if ( $a['expires_ts'] !== $b['expires_ts'] ) {
						return $a['expires_ts'] <=> $b['expires_ts'];
					}
				} elseif ( $a['expires_ts'] && ! $b['expires_ts'] ) {
					return -1;
				} elseif ( ! $a['expires_ts'] && $b['expires_ts'] ) {
					return 1;
				}
				return 0;
			}
		);

		// Merge active first, inactive/used/expired at the end.
		return array_merge( $active_vouchers, $inactive_vouchers );
	}

	/**
	 * Evaluate all system vouchers specifically against the current cart subtotal and items.
	 *
	 * Groups vouchers into 'shipping' (Freeship) and 'discount' (Order Discounts).
	 * Computes eligibility, savings estimate, and human-readable ineligible reasons.
	 *
	 * @param int $user_id Customer WP_User ID.
	 * @return array{shipping: array, discount: array, applied: array}
	 */
	public static function evaluate_cart_vouchers( int $user_id ): array {
		self::init_cart_session();

		$raw_vouchers    = self::get_customer_vouchers( $user_id );
		$cart_subtotal   = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_subtotal() : 0.0;
		$applied_coupons = ( function_exists( 'WC' ) && WC()->cart ) ? array_map( 'strtolower', WC()->cart->get_applied_coupons() ) : array();

		$shipping_list = array();
		$discount_list = array();
		$applied_list  = array();

		foreach ( $raw_vouchers as $item ) {
			$code       = strtolower( $item['code'] );
			$coupon     = new WC_Coupon( $item['code'] );
			$is_applied = in_array( $code, $applied_coupons, true );

			$is_eligible       = true;
			$ineligible_reason = '';
			$savings_estimate  = 0.0;

			if ( ! $item['can_apply'] ) {
				$is_eligible       = false;
				$ineligible_reason = $item['status_label'];
			} else {
				$min_spend = (float) $coupon->get_minimum_amount();
				$max_spend = (float) $coupon->get_maximum_amount();

				if ( $min_spend > 0 && $cart_subtotal < $min_spend ) {
					$is_eligible       = false;
					$shortfall         = $min_spend - $cart_subtotal;
					$ineligible_reason = sprintf(
						/* translators: 1: Minimum spend, 2: Shortfall amount */
						__( 'Đơn tối thiểu %1$s (còn thiếu %2$s)', 'omniwp' ),
						wp_strip_all_tags( wc_price( $min_spend ) ),
						wp_strip_all_tags( wc_price( $shortfall ) )
					);
				} elseif ( $max_spend > 0 && $cart_subtotal > $max_spend ) {
					$is_eligible       = false;
					$ineligible_reason = sprintf(
						/* translators: %s: Maximum spend */
						__( 'Giá trị đơn vượt quá %s', 'omniwp' ),
						wp_strip_all_tags( wc_price( $max_spend ) )
					);
				}
			}

			// Estimate savings
			$amount        = (float) $coupon->get_amount();
			$discount_type = $coupon->get_discount_type();
			if ( $item['badge_type'] === 'shipping' || $coupon->get_free_shipping() ) {
				$savings_estimate = 30000.0; // Estimated default shipping fee
			} elseif ( 'percent' === $discount_type ) {
				$calc = $cart_subtotal * ( $amount / 100.0 );
				$max  = (float) $coupon->get_maximum_amount();
				if ( $max > 0 && $calc > $max ) {
					$calc = $max;
				}
				$savings_estimate = $calc;
			} else {
				$savings_estimate = $amount;
			}

			// Progress calculation for min_spend Quick-Win challenge
			$min_spend          = (float) $coupon->get_minimum_amount();
			$progress_percent   = 100;
			$amount_needed      = 0.0;
			$amount_needed_html = '';

			if ( $min_spend > 0 ) {
				if ( $cart_subtotal < $min_spend ) {
					$amount_needed      = $min_spend - $cart_subtotal;
					$amount_needed_html = function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount_needed ) ) : number_format( $amount_needed, 0, ',', '.' ) . '₫';
					$progress_percent   = (int) round( ( $cart_subtotal / $min_spend ) * 100 );
					$progress_percent   = max( 0, min( 99, $progress_percent ) );
				} else {
					$progress_percent = 100;
				}
			}

			$entry = array_merge(
				$item,
				array(
					'is_applied'         => $is_applied,
					'is_eligible'        => $is_eligible,
					'ineligible_reason'  => $ineligible_reason,
					'savings_estimate'   => $savings_estimate,
					'savings_text'       => $savings_estimate > 0 ? wp_strip_all_tags( wc_price( $savings_estimate ) ) : '',
					'progress_percent'   => $progress_percent,
					'amount_needed'      => $amount_needed,
					'amount_needed_html' => $amount_needed_html,
				)
			);

			if ( $is_applied ) {
				$applied_list[] = $entry;
			}

			if ( $item['badge_type'] === 'shipping' || $coupon->get_free_shipping() ) {
				$shipping_list[] = $entry;
			} else {
				$discount_list[] = $entry;
			}
		}

		return array(
			'shipping' => $shipping_list,
			'discount' => $discount_list,
			'applied'  => $applied_list,
		);
	}

	/**
	 * Initialize WooCommerce Cart and Session for REST API / AJAX context.
	 */
	public static function init_cart_session(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( null === WC()->session && class_exists( '\WC_Session_Handler' ) ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}

		$user_id = get_current_user_id();
		if ( null === WC()->customer && $user_id > 0 && class_exists( '\WC_Customer' ) ) {
			WC()->customer = new \WC_Customer( $user_id, true );
		}

		if ( null === WC()->cart ) {
			if ( method_exists( WC(), 'initialize_cart' ) ) {
				WC()->initialize_cart();
			} elseif ( class_exists( '\WC_Cart' ) ) {
				WC()->cart = new \WC_Cart();
			}
		}
	}

	/**
	 * Apply a coupon code to the active WooCommerce cart.
	 *
	 * @param string $code Voucher code to apply.
	 * @return array{success:bool, message:string, redirect_url:string}
	 */
	public static function apply_to_cart( string $code ): array {
		$code = sanitize_text_field( trim( $code ) );

		if ( empty( $code ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'Mã giảm giá không hợp lệ.', 'omniwp' ),
				'redirect_url' => '',
			);
		}

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'Hệ thống bán hàng WooCommerce chưa được kích hoạt.', 'omniwp' ),
				'redirect_url' => '',
			);
		}

		// Ensure WooCommerce Cart and Session are initialized in REST context.
		self::init_cart_session();

		if ( ! WC()->cart ) {
			return array(
				'success'      => false,
				'message'      => __( 'Không thể khởi tạo giỏ hàng WooCommerce.', 'omniwp' ),
				'redirect_url' => '',
			);
		}

		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' );
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		if ( ! $shop_url || $shop_url === home_url( '/' ) ) {
			$shop_url = $cart_url;
		}

		// Check if already applied.
		if ( WC()->cart->has_discount( $code ) ) {
			return array(
				'success'      => true,
				'message'      => sprintf( __( 'Mã "%s" đã có sẵn trong giỏ hàng.', 'omniwp' ), strtoupper( $code ) ),
				'redirect_url' => $cart_url,
			);
		}

		// If cart is empty, pre-apply the coupon to session so when customer adds items, it is ready.
		if ( WC()->cart->is_empty() ) {
			// Save coupon to session
			if ( WC()->session ) {
				$applied_coupons = (array) WC()->session->get( 'applied_coupons', array() );
				if ( ! in_array( strtolower( $code ), array_map( 'strtolower', $applied_coupons ), true ) ) {
					$applied_coupons[] = strtolower( $code );
					WC()->session->set( 'applied_coupons', $applied_coupons );
				}
			}

			return array(
				'success'      => true,
				'message'      => sprintf( __( 'Đã lưu mã "%s" vào giỏ hàng! Đang chuyển đến cửa hàng để chọn sản phẩm...', 'omniwp' ), strtoupper( $code ) ),
				'redirect_url' => $shop_url,
			);
		}

		// Clear previous notices so we can catch WC errors.
		wc_clear_notices();

		$applied = WC()->cart->apply_coupon( $code );

		if ( $applied ) {
			wc_clear_notices();
			return array(
				'success'      => true,
				'message'      => sprintf( __( 'Áp dụng mã "%s" thành công!', 'omniwp' ), strtoupper( $code ) ),
				'redirect_url' => $cart_url,
			);
		}

		// Retrieve error notice from WC.
		$notices = wc_get_notices( 'error' );
		$message = __( 'Không thể áp dụng mã giảm giá này cho giỏ hàng hiện tại.', 'omniwp' );

		if ( ! empty( $notices ) && is_array( $notices ) ) {
			$first_notice = reset( $notices );
			if ( is_array( $first_notice ) && ! empty( $first_notice['notice'] ) ) {
				$message = wp_strip_all_tags( $first_notice['notice'] );
			} elseif ( is_string( $first_notice ) ) {
				$message = wp_strip_all_tags( $first_notice );
			}
			wc_clear_notices();
		}

		return array(
			'success'      => false,
			'message'      => $message,
			'redirect_url' => '',
		);
	}
}
