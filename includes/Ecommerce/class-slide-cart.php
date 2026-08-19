<?php
/**
 * Slide Cart (Drawer Cart) and Floating Cart bubble renderer & Ajax controller.
 *
 * @package OmniWP
 */

namespace OmniWP\Ecommerce;

use OmniWP\Frontend\TemplateLoader;
use OmniWP\Frontend\VoucherService;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class SlideCart {

	public function register(): void {
		if ( ! Settings::is_on( 'ecommerce.slide_cart_enabled', true ) ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'render_drawer' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 25 );

		/*
		 * We own the cart, so we own the sentence that says it changed.
		 *
		 * ShopKit's Quick View can add an item — through WooCommerce's endpoint,
		 * never through `WC()->cart`, so the boundary in docs/ecommerce.md holds —
		 * and then fires `added_to_cart`, which is what opens this drawer. It also
		 * used to raise a toast of its own, so one click produced two messages:
		 * its toast in the bottom-right corner, on top of our floating cart
		 * bubble, and this drawer sliding open behind it.
		 *
		 * `shopkit_cart_feedback` is the filter ShopKit publishes for a cart owner
		 * to take that job over. It silences the success toast only; an error
		 * inside its modal — a variation the customer never chose — stays with the
		 * modal, because no drawer knows about it. Registered here rather than at
		 * boot so the claim cannot outlive the drawer: with the slide cart off,
		 * this method has already returned and ShopKit keeps speaking.
		 */
		add_filter( 'shopkit_cart_feedback', '__return_false' );

		// AJAX endpoints.
		add_action( 'wp_ajax_omniwp_get_cart', array( $this, 'ajax_get_cart' ) );
		add_action( 'wp_ajax_nopriv_omniwp_get_cart', array( $this, 'ajax_get_cart' ) );

		add_action( 'wp_ajax_omniwp_update_cart_qty', array( $this, 'ajax_update_quantity' ) );
		add_action( 'wp_ajax_nopriv_omniwp_update_cart_qty', array( $this, 'ajax_update_quantity' ) );

		add_action( 'wp_ajax_omniwp_remove_cart_item', array( $this, 'ajax_remove_item' ) );
		add_action( 'wp_ajax_nopriv_omniwp_remove_cart_item', array( $this, 'ajax_remove_item' ) );

		add_action( 'wp_ajax_omniwp_apply_coupon', array( $this, 'ajax_apply_coupon' ) );
		add_action( 'wp_ajax_nopriv_omniwp_apply_coupon', array( $this, 'ajax_apply_coupon' ) );

		add_action( 'wp_ajax_omniwp_remove_coupon', array( $this, 'ajax_remove_coupon' ) );
		add_action( 'wp_ajax_nopriv_omniwp_remove_coupon', array( $this, 'ajax_remove_coupon' ) );

		add_action( 'wp_ajax_omniwp_change_cart_variation', array( $this, 'ajax_change_variation' ) );
		add_action( 'wp_ajax_nopriv_omniwp_change_cart_variation', array( $this, 'ajax_change_variation' ) );

		add_action( 'wp_ajax_omniwp_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_omniwp_add_to_cart', array( $this, 'ajax_add_to_cart' ) );

		add_action( 'wp_ajax_omniwp_restore_cart_item', array( $this, 'ajax_restore_cart_item' ) );
		add_action( 'wp_ajax_nopriv_omniwp_restore_cart_item', array( $this, 'ajax_restore_cart_item' ) );
	}

	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$ver = defined( 'OMNIWP_VERSION' ) ? OMNIWP_VERSION : '1.0.0';

		wp_enqueue_style(
			'omniwp-ecommerce',
			plugins_url( 'assets/css/omniwp-ecommerce.css', OMNIWP_FILE ),
			array( 'omniwp-tokens', 'omniwp-base' ),
			$ver
		);

		wp_enqueue_script(
			'omniwp-slide-cart',
			plugins_url( 'assets/js/omniwp-slide-cart.js', OMNIWP_FILE ),
			array( 'jquery' ),
			$ver,
			true
		);

		wp_localize_script(
			'omniwp-slide-cart',
			'omniwpCartConfig',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'omniwp_cart_nonce' ),
				'autoOpen'       => Settings::is_on( 'ecommerce.auto_open_slide_cart', true ),
				'isCartPage'     => function_exists( 'is_cart' ) && is_cart(),
				'isCheckoutPage' => function_exists( 'is_checkout' ) && is_checkout(),
				'i18n'           => array(
					'updateSuccess' => __( 'Đã cập nhật giỏ hàng', 'omniwp' ),
					'removeSuccess' => __( 'Đã xóa sản phẩm khỏi giỏ', 'omniwp' ),
					'error'         => __( 'Có lỗi xảy ra, vui lòng thử lại', 'omniwp' ),
					'undo'          => __( 'Hoàn tác', 'omniwp' ),
				),
			)
		);
	}

	public function render_drawer(): void {
		if ( is_admin() ) {
			return;
		}

		// Don't render slide drawer or floating bubble on checkout page to reduce friction.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}

		$cart_data = CartService::get_cart_data();

		TemplateLoader::output(
			'ecommerce/slide-cart-drawer',
			array(
				'cart' => $cart_data,
			)
		);

		if ( Settings::is_on( 'ecommerce.floating_cart_enabled', true ) && ! ( function_exists( 'is_cart' ) && is_cart() ) ) {
			TemplateLoader::output(
				'ecommerce/floating-cart',
				array(
					'cart' => $cart_data,
				)
			);
		}
	}

	public function ajax_get_cart(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );
		wp_send_json_success( CartService::get_cart_data() );
	}

	public function ajax_update_quantity(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );

		$key = '';
		if ( isset( $_POST['cart_item_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) );
		} elseif ( isset( $_POST['key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['key'] ) );
		}
		$qty = isset( $_POST['quantity'] ) ? (int) $_POST['quantity'] : 1;

		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Thiếu thông tin sản phẩm.', 'omniwp' ) ) );
		}

		$result = CartService::update_quantity( $key, $qty );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result['cart'] );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Lỗi cập nhật giỏ hàng.', 'omniwp' ) ) );
		}
	}

	public function ajax_remove_item(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );

		$key = '';
		if ( isset( $_POST['cart_item_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) );
		} elseif ( isset( $_POST['key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['key'] ) );
		}
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Thiếu thông tin sản phẩm.', 'omniwp' ) ) );
		}

		$result = CartService::remove_item( $key );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success(
				array(
					'cart'         => $result['cart'],
					'removed_item' => $result['removed_item'] ?? null,
					'message'      => __( 'Đã xóa sản phẩm khỏi giỏ.', 'omniwp' ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Lỗi gỡ sản phẩm.', 'omniwp' ) ) );
		}
	}

	public function ajax_apply_coupon(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );

		$code = isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';
		if ( empty( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Vui lòng nhập mã giảm giá.', 'omniwp' ) ) );
		}

		$applied = VoucherService::apply_to_cart( $code );
		if ( ! empty( $applied['success'] ) ) {
			wp_send_json_success(
				array(
					'message' => $applied['message'] ?? __( 'Áp dụng mã thành công!', 'omniwp' ),
					'cart'    => CartService::get_cart_data(),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => $applied['message'] ?? __( 'Mã giảm giá không hợp lệ hoặc đã hết hạn.', 'omniwp' ) ) );
		}
	}

	public function ajax_remove_coupon(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );

		$code = isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';
		if ( empty( $code ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Yêu cầu không hợp lệ.', 'omniwp' ) ) );
		}

		WC()->cart->remove_coupon( $code );
		WC()->cart->calculate_totals();

		wp_send_json_success(
			array(
				'message' => __( 'Đã gỡ mã giảm giá.', 'omniwp' ),
				'cart'    => CartService::get_cart_data(),
			)
		);
	}

	public function ajax_change_variation(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );

		$key = '';
		if ( isset( $_POST['cart_item_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) );
		} elseif ( isset( $_POST['key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['key'] ) );
		}

		$attributes = isset( $_POST['attributes'] ) && is_array( $_POST['attributes'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['attributes'] ) ) : array();

		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Thiếu thông tin sản phẩm.', 'omniwp' ) ) );
		}

		$result = CartService::switch_variation( $key, $attributes );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result['cart'] );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Lỗi đổi biến thể.', 'omniwp' ) ) );
		}
	}

	/**
	 * Add a product to cart via AJAX (used by cross-sell buttons).
	 */
	public function ajax_add_to_cart(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, (int) $_POST['quantity'] ) : 1;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Thiếu thông tin sản phẩm.', 'omniwp' ) ) );
		}

		\OmniWP\Frontend\VoucherService::init_cart_session();
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Không thể khởi tạo giỏ hàng.', 'omniwp' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'Sản phẩm không khả dụng hoặc đã hết hàng.', 'omniwp' ) ) );
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity );
		if ( $added ) {
			WC()->cart->calculate_totals();
			wp_send_json_success(
				array(
					'message' => __( 'Đã thêm sản phẩm vào giỏ hàng.', 'omniwp' ),
					'cart'    => CartService::get_cart_data(),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Không thể thêm sản phẩm vào giỏ hàng.', 'omniwp' ) ) );
		}
	}

	/**
	 * Restore a previously removed cart item (Undo action).
	 */
	public function ajax_restore_cart_item(): void {
		check_ajax_referer( 'omniwp_cart_nonce', 'nonce' );

		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? max( 1, (int) $_POST['quantity'] ) : 1;
		$variation    = isset( $_POST['variation'] ) && is_array( $_POST['variation'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['variation'] ) )
			: array();

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Thiếu thông tin sản phẩm.', 'omniwp' ) ) );
		}

		\OmniWP\Frontend\VoucherService::init_cart_session();
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Không thể khởi tạo giỏ hàng.', 'omniwp' ) ) );
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
		if ( $added ) {
			WC()->cart->calculate_totals();
			wp_send_json_success(
				array(
					'message' => __( 'Đã khôi phục sản phẩm vào giỏ hàng!', 'omniwp' ),
					'cart'    => CartService::get_cart_data(),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Không thể khôi phục sản phẩm.', 'omniwp' ) ) );
		}
	}
}
