<?php
/**
 * Template for OmniWP Order Received & Thank You Order Tracker & Printable Invoice.
 *
 * @package OmniWP
 * @var \WC_Order $order Order object.
 * @var int $order_id Order ID.
 * @var string $status Order status slug.
 * @var string $status_name Order status display name.
 * @var string $payment_method Order payment method.
 * @var string|null $vietqr_url Dynamic VietQR code URL.
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

// Extract order object gracefully if loaded directly or via WooCommerce template override.
$ow_order = isset( $order ) ? $order : null;
if ( ! $ow_order && function_exists( 'wc_get_order' ) ) {
	global $wp;
	$order_id_param = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
	if ( ! $order_id_param && isset( $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id_param = absint( $_GET['order_id'] );
	}
	if ( $order_id_param > 0 ) {
		$ow_order = wc_get_order( $order_id_param );
	}
}

if ( ! isset( $ow_order ) || ! $ow_order || ! is_object( $ow_order ) ) {
	return;
}

$order_id       = isset( $order_id ) ? (int) $order_id : ( method_exists( $ow_order, 'get_id' ) ? (int) $ow_order->get_id() : 1001 );
$ow_status      = isset( $status ) ? (string) $status : ( method_exists( $ow_order, 'get_status' ) ? (string) $ow_order->get_status() : 'processing' );
$status_name    = isset( $status_name ) ? (string) $status_name : ( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $ow_status ) : 'Đang xử lý' );
$payment_method = isset( $payment_method ) ? (string) $payment_method : ( method_exists( $ow_order, 'get_payment_method' ) ? (string) $ow_order->get_payment_method() : 'bacs' );
$vietqr_url     = isset( $vietqr_url ) ? $vietqr_url : ( class_exists( '\OmniWP\Ecommerce\ThankYouService' ) ? \OmniWP\Ecommerce\ThankYouService::generate_vietqr_url( $ow_order ) : '' );

// Calculate tracker active step (1 to 4).
$step_index = 1;
if ( in_array( $status, array( 'processing', 'on-hold', 'pending' ), true ) ) {
	$step_index = 1;
} elseif ( 'packed' === $status ) {
	$step_index = 2;
} elseif ( 'shipping' === $status ) {
	$step_index = 3;
} elseif ( 'completed' === $status ) {
	$step_index = 4;
}

$order_items    = $order->get_items();
$order_date     = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '';
$discount_total = (float) $order->get_total_discount();
$shipping_total = (float) $order->get_shipping_total();
?>
<div class="sl-thankyou-wrapper">

	<!-- Top Actions Toolbar (Hidden on Print) -->
	<div class="sl-thankyou-actions-bar sl-no-print">
		<button type="button" class="sl-btn-print-invoice" onclick="window.print();">
			<span class="sl-subtle-icon"><?php echo IconSet::get( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php esc_html_e( 'In hóa đơn / Xuất PDF', 'omniwp' ); ?>
		</button>
	</div>

	<!-- Success Banner (Hidden on Print) -->
	<div class="sl-thankyou-hero sl-no-print">
		<div class="sl-thankyou-icon-badge">
			<?php echo IconSet::get( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<h2 class="sl-thankyou-title"><?php esc_html_e( 'Cảm ơn bạn đã đặt hàng!', 'omniwp' ); ?></h2>
		<p class="sl-thankyou-desc">
			<?php
			printf(
				/* translators: %s: Order number */
				esc_html__( 'Mã đơn hàng: #%s. Chúng tôi đã nhận được thông tin và đang xử lý đơn hàng cho bạn.', 'omniwp' ),
				'<strong>' . esc_html( $order->get_order_number() ) . '</strong>'
			);
			?>
		</p>
	</div>

	<!-- Visual Order Status Tracker (Hidden on Print) -->
	<div class="sl-order-tracker-card sl-no-print">
		<h3 class="sl-tracker-heading"><?php esc_html_e( 'Trạng thái xử lý đơn hàng', 'omniwp' ); ?></h3>
		<div class="sl-tracker-steps">
			<div class="sl-tracker-step <?php echo $step_index >= 1 ? 'sl-tracker-step--active' : ''; ?>">
				<div class="sl-tracker-step__circle">
					<?php echo $step_index > 1 ? '✓' : '1'; ?>
				</div>
				<div class="sl-tracker-step__label"><?php esc_html_e( 'Đã tiếp nhận', 'omniwp' ); ?></div>
			</div>

			<div class="sl-tracker-line <?php echo $step_index >= 2 ? 'sl-tracker-line--active' : ''; ?>"></div>

			<div class="sl-tracker-step <?php echo $step_index >= 2 ? 'sl-tracker-step--active' : ''; ?>">
				<div class="sl-tracker-step__circle">
					<?php echo $step_index > 2 ? '✓' : '2'; ?>
				</div>
				<div class="sl-tracker-step__label"><?php esc_html_e( 'Đã đóng gói', 'omniwp' ); ?></div>
			</div>

			<div class="sl-tracker-line <?php echo $step_index >= 3 ? 'sl-tracker-line--active' : ''; ?>"></div>

			<div class="sl-tracker-step <?php echo $step_index >= 3 ? 'sl-tracker-step--active' : ''; ?>">
				<div class="sl-tracker-step__circle">
					<?php echo $step_index > 3 ? '✓' : '3'; ?>
				</div>
				<div class="sl-tracker-step__label"><?php esc_html_e( 'Đang giao hàng', 'omniwp' ); ?></div>
			</div>

			<div class="sl-tracker-line <?php echo $step_index >= 4 ? 'sl-tracker-line--active' : ''; ?>"></div>

			<div class="sl-tracker-step <?php echo $step_index >= 4 ? 'sl-tracker-step--active' : ''; ?>">
				<div class="sl-tracker-step__circle">4</div>
				<div class="sl-tracker-step__label"><?php esc_html_e( 'Hoàn tất', 'omniwp' ); ?></div>
			</div>
		</div>
	</div>

	<!-- VietQR Quick Payment Box (If Bank Transfer) (Hidden on Print) -->
	<?php if ( ! empty( $vietqr_url ) ) : ?>
		<div class="sl-vietqr-card sl-no-print">
			<div class="sl-vietqr-header">
				<span class="sl-vietqr-badge">
					<span class="sl-subtle-icon"><?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					VietQR
				</span>
				<h3><?php esc_html_e( 'Quét mã chuyển khoản nhanh', 'omniwp' ); ?></h3>
				<p><?php esc_html_e( 'Mở ứng dụng Ngân hàng bất kỳ hoặc Ví điện tử để quét mã thanh toán tự động, đơn hàng sẽ được kích hoạt xử lý ngay lập tức.', 'omniwp' ); ?></p>
			</div>
			<div class="sl-vietqr-body">
				<div class="sl-vietqr-img-wrap">
					<img src="<?php echo esc_url( $vietqr_url ); ?>" alt="<?php esc_attr_e( 'Mã QR chuyển khoản đơn hàng', 'omniwp' ); ?>" class="sl-vietqr-image" />
				</div>
				<div class="sl-vietqr-details">
					<div class="sl-vietqr-row">
						<span><?php esc_html_e( 'Số tiền cần chuyển:', 'omniwp' ); ?></span>
						<strong class="sl-vietqr-amount"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
					</div>
					<div class="sl-vietqr-row">
						<span><?php esc_html_e( 'Nội dung chuyển khoản (Memo):', 'omniwp' ); ?></span>
						<strong class="sl-vietqr-memo">DH<?php echo esc_html( $order->get_order_number() ); ?></strong>
					</div>
					<div class="sl-vietqr-tip">
						<span class="sl-subtle-icon"><?php echo IconSet::get( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<?php esc_html_e( 'Vui lòng giữ nguyên nội dung chuyển khoản để hệ thống tự động xác nhận đơn hàng.', 'omniwp' ); ?>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<!-- Formal Printable Invoice Paper Card (Tờ Hóa Đơn Chuẩn Từ Trên Xuống Dưới) -->
	<div class="sl-invoice-paper" id="sl-printable-invoice">
		
		<!-- Invoice Header -->
		<div class="sl-invoice-header">
			<div class="sl-invoice-brand">
				<h2 class="sl-invoice-store-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
				<span class="sl-invoice-tagline"><?php esc_html_e( 'HÓA ĐƠN XÁC NHẬN ĐƠN HÀNG', 'omniwp' ); ?></span>
			</div>
			<div class="sl-invoice-meta">
				<div class="sl-invoice-meta-row">
					<span><?php esc_html_e( 'Mã đơn hàng:', 'omniwp' ); ?></span>
					<strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
				</div>
				<?php if ( $order_date ) : ?>
					<div class="sl-invoice-meta-row">
						<span><?php esc_html_e( 'Ngày đặt hàng:', 'omniwp' ); ?></span>
						<span><?php echo esc_html( $order_date ); ?></span>
					</div>
				<?php endif; ?>
				<div class="sl-invoice-meta-row">
					<span><?php esc_html_e( 'Trạng thái:', 'omniwp' ); ?></span>
					<span class="sl-invoice-status-pill"><?php echo esc_html( $status_name ); ?></span>
				</div>
			</div>
		</div>

		<!-- Customer & Order Summary Info Grid -->
		<div class="sl-invoice-info-grid">
			<div class="sl-invoice-info-block">
				<h4 class="sl-invoice-block-title">
					<span class="sl-subtle-icon"><?php echo IconSet::get( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php esc_html_e( 'Thông tin khách hàng & Giao hàng', 'omniwp' ); ?></span>
				</h4>
				<div class="sl-invoice-info-content">
					<p class="sl-invoice-customer-name"><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></strong></p>
					<?php if ( $order->get_billing_phone() ) : ?>
						<p><span class="sl-subtle-icon"><?php echo IconSet::get( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php echo esc_html( $order->get_billing_phone() ); ?></p>
					<?php endif; ?>
					<?php if ( $order->get_billing_email() ) : ?>
						<p><span class="sl-subtle-icon"><?php echo IconSet::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php echo esc_html( $order->get_billing_email() ); ?></p>
					<?php endif; ?>
					<?php if ( $order->get_formatted_billing_address() ) : ?>
						<p><span class="sl-subtle-icon"><?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="sl-invoice-info-block">
				<h4 class="sl-invoice-block-title">
					<span class="sl-subtle-icon"><?php echo IconSet::get( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php esc_html_e( 'Phương thức thanh toán & Vận chuyển', 'omniwp' ); ?></span>
				</h4>
				<div class="sl-invoice-info-content">
					<p>
						<span class="sl-subtle-icon"><?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="sl-invoice-label"><?php esc_html_e( 'Thanh toán:', 'omniwp' ); ?></span>
						<strong><?php echo esc_html( $order->get_payment_method_title() ); ?></strong>
					</p>
					<p>
						<span class="sl-subtle-icon"><?php echo IconSet::get( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="sl-invoice-label"><?php esc_html_e( 'Vận chuyển:', 'omniwp' ); ?></span>
						<span><?php echo esc_html( $order->get_shipping_method() ?: __( 'Giao hàng tiêu chuẩn', 'omniwp' ) ); ?></span>
					</p>
				</div>
			</div>
		</div>

		<!-- Clean Product List (Replacing heavy <table>) -->
		<?php if ( ! empty( $order_items ) ) : ?>
			<ul class="sl-invoice-items-list">
				<?php
				foreach ( $order_items as $item_id => $item ) :
					$item_name     = $item->get_name();
					$qty           = $item->get_quantity();
					$unit_price    = function_exists( 'wc_price' ) ? wc_price( $item->get_subtotal() / max( 1, $qty ) ) : '';
					$subtotal_html = $order->get_formatted_line_subtotal( $item );
					$product       = $item->get_product();
					$image_id      = $product ? $product->get_image_id() : 0;
					$img_url       = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '' );
					?>
					<li class="sl-invoice-item">
						<div class="sl-invoice-item__thumb">
							<?php if ( ! empty( $img_url ) ) : ?>
								<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $item_name ); ?>" loading="lazy" />
							<?php else : ?>
								<div class="sl-invoice-item__placeholder"><?php echo IconSet::get( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<?php endif; ?>
						</div>

						<div class="sl-invoice-item__info">
							<h4 class="sl-invoice-item__name"><?php echo esc_html( $item_name ); ?></h4>
							<div class="sl-invoice-item__meta">
								<span><?php esc_html_e( 'Đơn giá:', 'omniwp' ); ?> <?php echo wp_kses_post( $unit_price ); ?></span>
								<span class="sl-invoice-item__dot">•</span>
								<span>
									<?php
									/* translators: %d: product quantity */
									printf( esc_html__( 'SL: %d', 'omniwp' ), (int) $qty );
									?>
								</span>
							</div>
						</div>

						<div class="sl-invoice-item__total">
							<?php echo wp_kses_post( $subtotal_html ); ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<!-- Invoice Totals Calculation Box -->
		<div class="sl-invoice-totals-box">
			<div class="sl-invoice-totals-inner">
				<div class="sl-invoice-total-row">
					<span><?php esc_html_e( 'Tạm tính:', 'omniwp' ); ?></span>
					<span><?php echo wp_kses_post( $order->get_subtotal_to_display() ); ?></span>
				</div>
				<?php if ( $discount_total > 0 ) : ?>
					<div class="sl-invoice-total-row sl-invoice-total-row--discount">
						<span><?php esc_html_e( 'Giảm giá / Voucher:', 'omniwp' ); ?></span>
						<span>-<?php echo wp_kses_post( wc_price( $discount_total ) ); ?></span>
					</div>
				<?php endif; ?>
				<div class="sl-invoice-total-row">
					<span><?php esc_html_e( 'Phí vận chuyển:', 'omniwp' ); ?></span>
					<span><?php echo wp_kses_post( $shipping_total > 0 ? wc_price( $shipping_total ) : __( 'Miễn phí', 'omniwp' ) ); ?></span>
				</div>
				<div class="sl-invoice-total-row sl-invoice-total-row--grand">
					<span><?php esc_html_e( 'Tổng số tiền thanh toán:', 'omniwp' ); ?></span>
					<strong class="sl-invoice-grand-price"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
				</div>
			</div>
		</div>

		<!-- Invoice Footer Note -->
		<div class="sl-invoice-footer">
			<p><?php esc_html_e( 'Cảm ơn quý khách đã mua sắm tại cửa hàng của chúng tôi!', 'omniwp' ); ?></p>
			<p class="sl-invoice-contact-note"><?php esc_html_e( 'Nếu bạn có bất kỳ câu hỏi nào về hóa đơn này, xin vui lòng liên hệ bộ phận chăm sóc khách hàng.', 'omniwp' ); ?></p>
		</div>

	</div>

</div>


