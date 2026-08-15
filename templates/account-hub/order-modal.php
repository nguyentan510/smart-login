<?php
/**
 * Order Detail Invoice Modal Template (Chuẩn định dạng hoá đơn tinh gọn).
 *
 * @var \WP_User $user
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;
?>

<!-- Order Detail Invoice Modal Backdrop -->
<div class="sl-logout-modal-backdrop sl-order-modal-backdrop" data-sl-order-modal aria-hidden="true">
	<div class="sl-order-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sl-order-modal-title">
		
		<!-- Modal Invoice Header -->
		<div class="sl-invoice-header">
			<div class="sl-invoice-header__left">
				<div class="sl-invoice-badge-wrap">
					<span class="sl-invoice-badge"><?php esc_html_e( 'HOÁ ĐƠN ĐƠN HÀNG', 'omniwp' ); ?></span>
					<h3 class="sl-invoice-title" id="sl-order-modal-title">
						<span data-sl-modal-num>#---</span>
					</h3>
				</div>
				<p class="sl-invoice-date" data-sl-modal-date></p>
			</div>
			<div class="sl-invoice-header__right">
				<span class="sl-hub-status-badge" data-sl-modal-status-badge></span>
				<button type="button" class="sl-invoice-close" data-sl-order-modal-close aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
					&times;
				</button>
			</div>
		</div>

		<!-- Loading State -->
		<div class="sl-order-modal__loading" data-sl-order-modal-loading>
			<div class="sl-order-modal__spinner"></div>
			<p><?php esc_html_e( 'Đang tải chi tiết hoá đơn...', 'omniwp' ); ?></p>
		</div>

		<!-- Modal Content Body (Scrollable E-Invoice) -->
		<div class="sl-invoice-body" data-sl-order-modal-content style="display:none;">

			<!-- Compact Order Pipeline / Timeline Strip -->
			<div class="sl-invoice-timeline" data-sl-modal-timeline>
				<!-- Dynamically rendered -->
			</div>

			<!-- Customer & Delivery / Payment 2-Column Section -->
			<div class="sl-invoice-info-grid">
				<!-- Delivery Info -->
				<div class="sl-invoice-info-card">
					<div class="sl-invoice-info-card__head">
						<?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'Thông tin giao hàng', 'omniwp' ); ?></span>
					</div>
					<div class="sl-invoice-info-card__content">
						<div class="sl-invoice-line">
							<strong data-sl-modal-customer-name></strong>
							<span class="sl-invoice-pipe">•</span>
							<span data-sl-modal-customer-phone></span>
						</div>
						<div class="sl-invoice-address" data-sl-modal-shipping-address></div>
						<div class="sl-invoice-note" data-sl-modal-note-wrap style="display:none;">
							<em><?php esc_html_e( 'Ghi chú:', 'omniwp' ); ?> <span data-sl-modal-customer-note></span></em>
						</div>
					</div>
				</div>

				<!-- Payment Info -->
				<div class="sl-invoice-info-card">
					<div class="sl-invoice-info-card__head">
						<?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'Thông tin thanh toán', 'omniwp' ); ?></span>
					</div>
					<div class="sl-invoice-info-card__content">
						<div class="sl-invoice-keyval">
							<span class="sl-invoice-key"><?php esc_html_e( 'Hình thức:', 'omniwp' ); ?></span>
							<span class="sl-invoice-val" data-sl-modal-payment-method></span>
						</div>
						<div class="sl-invoice-keyval" data-sl-modal-email-wrap style="display:none;">
							<span class="sl-invoice-key"><?php esc_html_e( 'Email:', 'omniwp' ); ?></span>
							<span class="sl-invoice-val" data-sl-modal-customer-email></span>
						</div>
						<div class="sl-invoice-keyval">
							<span class="sl-invoice-key"><?php esc_html_e( 'Thanh toán:', 'omniwp' ); ?></span>
							<span class="sl-invoice-val" data-sl-modal-pay-status><?php esc_html_e( 'Theo đơn hàng', 'omniwp' ); ?></span>
						</div>
					</div>
				</div>
			</div>

			<!-- Product Items List Table (Bảng chi tiết hàng hóa chia rõ 3 cột) -->
			<div class="sl-invoice-items-section">
				<div class="sl-invoice-items-header">
					<span class="sl-invoice-col-product"><?php esc_html_e( 'SẢN PHẨM / ĐƠN GIÁ', 'omniwp' ); ?></span>
					<span class="sl-invoice-col-qty"><?php esc_html_e( 'SỐ LƯỢNG', 'omniwp' ); ?></span>
					<span class="sl-invoice-col-total"><?php esc_html_e( 'THÀNH TIỀN', 'omniwp' ); ?></span>
				</div>
				<div class="sl-invoice-items-list" data-sl-modal-items>
					<!-- Dynamically rendered items -->
				</div>
			</div>

			<!-- Payment & Totals Summary Table -->
			<div class="sl-invoice-summary-wrap">
				<div class="sl-invoice-summary-card">
					<div class="sl-invoice-summary-row">
						<span><?php esc_html_e( 'Tạm tính tiền hàng:', 'omniwp' ); ?></span>
						<span data-sl-modal-subtotal></span>
					</div>
					<div class="sl-invoice-summary-row">
						<span><?php esc_html_e( 'Phí vận chuyển:', 'omniwp' ); ?></span>
						<span data-sl-modal-shipping></span>
					</div>
					<div class="sl-invoice-summary-row sl-invoice-summary-row--discount" data-sl-modal-discount-row style="display:none;">
						<span><?php esc_html_e( 'Giảm giá / Voucher:', 'omniwp' ); ?></span>
						<span data-sl-modal-discount></span>
					</div>
					<div class="sl-invoice-summary-row sl-invoice-summary-row--total">
						<span><?php esc_html_e( 'TỔNG CỘNG THANH TOÁN:', 'omniwp' ); ?></span>
						<strong class="sl-invoice-total-amount" data-sl-modal-total></strong>
					</div>
				</div>
			</div>

		</div>

		<!-- Modal Footer Actions -->
		<div class="sl-invoice-footer">
			<button type="button" class="sl-btn sl-btn--ghost sl-btn--sm" data-sl-order-modal-close>
				<?php esc_html_e( 'Đóng', 'omniwp' ); ?>
			</button>
			<button type="button" class="sl-btn sl-btn--primary sl-btn--sm sl-btn-reorder" data-sl-modal-reorder>
				<?php echo IconSet::get( 'rotate-ccw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Mua lại', 'omniwp' ); ?></span>
			</button>
		</div>

	</div>
</div>
