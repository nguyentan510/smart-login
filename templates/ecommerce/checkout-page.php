<?php
/**
 * Template for OmniWP Shopee-Style Checkout.
 *
 * Single-column stacked layout: Address → Products → Billing → Payment → Total.
 * Sticky footer bar mirrors the Place Order button.
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

// If on order-received endpoint, render Thank You page template.
if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
	global $wp;
	$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
	if ( ! $order_id && isset( $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = absint( $_GET['order_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	if ( $order_id > 0 ) {
		( new \OmniWP\Ecommerce\ThankYouService() )->render_custom_thankyou( $order_id );
		return;
	}
}

// If WooCommerce is loaded and cart is empty, show empty state.
if ( function_exists( 'WC' ) && WC() && WC()->cart && WC()->cart->is_empty() ) {
	?>
	<div class="omniwp sl-checkout-page-wrap alignwide">
		<div class="sl-cart-empty-box">
			<div class="sl-cart-empty-box__icon"><?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<h2><?php esc_html_e( 'Giỏ hàng của bạn đang trống', 'omniwp' ); ?></h2>
			<p><?php esc_html_e( 'Bạn chưa có sản phẩm nào trong giỏ hàng để tiến hành thanh toán.', 'omniwp' ); ?></p>
			<a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ); ?>" class="sl-btn sl-btn--primary sl-btn--lg">
				<?php esc_html_e( 'Khám phá sản phẩm ngay', 'omniwp' ); ?> →
			</a>
		</div>
	</div>
	<?php
	return;
}

if ( function_exists( 'WC' ) && method_exists( WC(), 'checkout' ) && WC()->checkout() ) {
	$checkout = WC()->checkout();
	if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
		echo esc_html( (string) apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'Bạn phải đăng nhập để tiến hành thanh toán.', 'omniwp' ) ) );
		return;
	}
}

$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' );
?>
<div class="omniwp sl-checkout-page-wrap alignwide sl-co-shopee">
	<div class="sl-co-container">

		<?php
		if ( \OmniWP\Settings::get( 'ecommerce.stepper_enabled', true ) ) {
			$active_step = 2;
			require __DIR__ . '/checkout-stepper.php';
		}
		?>

		<!-- Checkout Header Bar -->
		<div class="sl-co-header">
			<div class="sl-co-header__left">
				<span class="sl-co-header__icon"><?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h1 class="sl-co-header__title"><?php esc_html_e( 'Thanh toán', 'omniwp' ); ?></h1>
			</div>
			<p class="sl-co-header__secure"><?php echo IconSet::get( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Giao dịch an toàn & bảo mật', 'omniwp' ); ?></p>
		</div>

		<?php do_action( 'woocommerce_before_checkout_form', $checkout ); ?>

		<form name="checkout" method="post" class="checkout woocommerce-checkout sl-checkout-form sl-co-form <?php echo ! is_user_logged_in() ? 'is-guest-checkout-locked' : ''; ?>" action="<?php echo esc_url( $checkout_url ); ?>" enctype="multipart/form-data">

			<?php if ( ! is_user_logged_in() ) : ?>
				<!-- Full-card Auth Gate for Guest Users -->
				<div class="sl-co-auth-gate-box">
					<div class="sl-co-auth-gate-box__icon">
						<?php echo IconSet::get( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<h2 class="sl-co-auth-gate-box__title"><?php esc_html_e( 'Vui lòng đăng nhập để tiến hành Thanh toán', 'omniwp' ); ?></h2>
					<p class="sl-co-auth-gate-box__sub">
						<?php esc_html_e( 'Đăng nhập nhanh bằng Số điện thoại hoặc Email để tự động điền địa chỉ giao hàng, theo dõi đơn hàng và sử dụng kho mã giảm giá dành riêng cho bạn.', 'omniwp' ); ?>
					</p>
					<div class="sl-co-auth-gate-box__actions">
						<button type="button" class="sl-btn sl-btn--primary sl-btn--lg sl-login-trigger" data-omniwp="identify" data-redirect="<?php echo esc_url( $checkout_url ); ?>">
							<?php echo IconSet::get( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Đăng nhập OTP / Social ngay', 'omniwp' ); ?>
						</button>
					</div>
				</div>
			<?php else : ?>

				<!-- Section 1: Delivery Address (red top accent) -->
				<div class="sl-co-section sl-co-section--address">
					<div class="sl-co-section__accent-bar"></div>
					<div class="sl-co-section__inner">
						<div class="sl-co-section__header">
							<span class="sl-co-section__icon"><?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<h2 class="sl-co-section__title"><?php esc_html_e( 'Địa Chỉ Nhận Hàng', 'omniwp' ); ?></h2>
						</div>

						<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

						<div class="sl-checkout-billing-fields" id="customer_details">
							<?php do_action( 'woocommerce_checkout_billing' ); ?>
							<?php do_action( 'woocommerce_checkout_shipping' ); ?>
						</div>

						<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
					</div>
				</div>

				<!-- Section 2: Product List (Order Review Table) -->
				<div class="sl-co-section sl-co-section--products">
					<div class="sl-co-section__inner">
						<div class="sl-co-section__header sl-co-section__header--toggle">
							<div class="sl-co-section__header-left">
								<span class="sl-co-section__icon"><?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<h2 class="sl-co-section__title"><?php esc_html_e( 'Sản Phẩm Đặt Mua', 'omniwp' ); ?></h2>
								<?php if ( function_exists( 'WC' ) && WC() && WC()->cart ) : ?>
									<?php /* translators: %d: number of items in cart */ ?>
									<span class="sl-co-cart-count-badge" id="sl-co-cart-count-badge"><?php echo esc_html( sprintf( __( '(%d sản phẩm)', 'omniwp' ), (int) WC()->cart->get_cart_contents_count() ) ); ?></span>
								<?php endif; ?>
							</div>
							<button type="button" class="sl-btn-link sl-co-toggle-products-btn" id="sl-co-toggle-products">
								<span class="sl-toggle-text"><?php esc_html_e( 'Thu gọn ▲', 'omniwp' ); ?></span>
							</button>
						</div>

						<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

						<div id="order_review" class="woocommerce-checkout-review-order sl-co-review-order">
							<?php woocommerce_order_review(); ?>
						</div>

						<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
					</div>
				</div>

				<!-- Section 3: Voucher Ưu Đãi -->
				<?php if ( function_exists( 'wc_coupons_enabled' ) && wc_coupons_enabled() ) : ?>
					<?php $applied_coupons = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_coupons() : array(); ?>
					<div class="sl-co-section sl-co-section--voucher">
						<div class="sl-co-section__inner">
							<div class="sl-co-voucher-bar">
								<div class="sl-co-voucher-bar__left">
									<span class="sl-co-voucher-icon"><?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<strong class="sl-co-voucher-title"><?php esc_html_e( 'Voucher Ưu Đãi', 'omniwp' ); ?></strong>

									<!-- Active Applied Coupons Chips Inline -->
									<div class="sl-co-applied-chips-inline" id="sl-co-applied-coupons" style="<?php echo ! empty( $applied_coupons ) ? 'display:inline-flex;' : 'display:none;'; ?>">
										<div class="sl-applied-chips-list" id="sl-applied-chips-list">
											<?php foreach ( $applied_coupons as $code => $coupon ) : ?>
												<span class="sl-coupon-chip" data-code="<?php echo esc_attr( $code ); ?>">
													<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <strong><?php echo esc_html( strtoupper( $code ) ); ?></strong>
													<button type="button" class="sl-btn-remove-coupon" data-code="<?php echo esc_attr( $code ); ?>" title="<?php esc_attr_e( 'Xóa mã', 'omniwp' ); ?>">✕</button>
												</span>
											<?php endforeach; ?>
										</div>
									</div>
								</div>

								<div class="sl-co-voucher-bar__right">
									<button type="button" class="sl-btn-link sl-btn-open-voucher-picker" id="sl-btn-open-voucher-picker">
										<?php echo ! empty( $applied_coupons ) ? esc_html__( 'Thay Đổi', 'omniwp' ) . ' &gt;' : esc_html__( 'Chọn hoặc Nhập Mã', 'omniwp' ) . ' &gt;'; ?>
									</button>
								</div>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<!-- Section 4: Order Notes / Ghi chú đơn hàng -->
				<div class="sl-co-section sl-co-section--notes">
					<div class="sl-co-section__inner">
						<div class="sl-co-section__header">
							<span class="sl-co-section__icon"><?php echo IconSet::get( 'message-square' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<h2 class="sl-co-section__title"><?php esc_html_e( 'Ghi Chú Đơn Hàng', 'omniwp' ); ?></h2>
						</div>
						<div class="sl-co-notes-body">
							<?php do_action( 'woocommerce_before_order_notes', $checkout ); ?>
							<p class="sl-co-notes-hint"><?php esc_html_e( 'Ghi chú về đơn hàng, ví dụ: thời gian hay chỉ dẫn giao hàng chi tiết.', 'omniwp' ); ?></p>
							<textarea name="order_comments" id="order_comments" class="sl-textarea" placeholder="<?php esc_attr_e( 'Ví dụ: Giao hàng vào giờ hành chính, gọi trước khi giao...', 'omniwp' ); ?>" rows="3"><?php echo esc_textarea( isset( $_POST['order_comments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['order_comments'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?></textarea>
							<?php do_action( 'woocommerce_after_order_notes', $checkout ); ?>
						</div>
					</div>
				</div>

				<!-- Section 5: Payment Methods & Place Order -->
				<div class="sl-co-section sl-co-section--payment">
					<div class="sl-co-section__inner">
						<div class="sl-co-section__header">
							<span class="sl-co-section__icon"><?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<h2 class="sl-co-section__title"><?php esc_html_e( 'Phương Thức Thanh Toán', 'omniwp' ); ?></h2>
						</div>

						<div id="payment_methods_wrapper" class="sl-co-payment-wrapper">
							<?php woocommerce_checkout_payment(); ?>
						</div>
					</div>
				</div>
			<?php endif; ?>

		</form>

		<?php if ( is_user_logged_in() ) : ?>
			<!-- Sticky Footer Bar (outside <form>, mirrors Place Order click via JS) -->
			<div class="sl-co-sticky-bar" id="sl-co-sticky-bar">
				<div class="sl-co-sticky-bar__inner">
					<div class="sl-co-sticky-bar__total">
						<span class="sl-co-sticky-bar__label"><?php esc_html_e( 'Tổng thanh toán:', 'omniwp' ); ?></span>
						<span class="sl-co-sticky-bar__amount" id="sl-co-sticky-total"></span>
					</div>
					<button type="button" class="sl-co-sticky-bar__btn" id="sl-co-sticky-submit">
						<?php esc_html_e( 'ĐẶT HÀNG', 'omniwp' ); ?>
					</button>
				</div>
			</div>
		<?php endif; ?>

	</div>
</div>
