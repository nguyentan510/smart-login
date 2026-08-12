<?php
/**
 * Voucher Detail Modal Template (Chi tiết thể lệ mã giảm giá).
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;
?>

<!-- Voucher Detail Modal Backdrop -->
<div class="sl-logout-modal-backdrop sl-voucher-modal-backdrop" data-sl-voucher-modal aria-hidden="true">
	<div class="sl-logout-modal sl-voucher-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sl-voucher-modal-title">
		
		<!-- Modal Header -->
		<div class="sl-voucher-modal__header">
			<div class="sl-voucher-modal__title-wrap">
				<span class="sl-voucher-modal__icon">
					<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<h3 class="sl-voucher-modal__title" id="sl-voucher-modal-title">
					<?php esc_html_e( 'Chi tiết mã giảm giá', 'omniwp' ); ?>
				</h3>
			</div>
			<button type="button" class="sl-invoice-close" data-sl-voucher-modal-close aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
				&times;
			</button>
		</div>

		<!-- Modal Body -->
		<div class="sl-voucher-modal__body">
			
			<!-- Highlight Code Banner -->
			<div class="sl-voucher-modal__code-banner">
				<div class="sl-voucher-modal__code-box">
					<span class="sl-voucher-modal__code-label"><?php esc_html_e( 'MÃ ƯU ĐÃI', 'omniwp' ); ?></span>
					<span class="sl-voucher-modal__code" data-sl-modal-code>---</span>
				</div>
				<button type="button" class="sl-btn sl-btn--ghost sl-btn-copy-code" data-sl-modal-copy>
					<?php echo IconSet::get( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'Sao chép', 'omniwp' ); ?></span>
				</button>
			</div>

			<!-- Headline & Expiry Banner -->
			<div class="sl-voucher-modal__meta">
				<h4 class="sl-voucher-modal__headline" data-sl-modal-headline></h4>
				<div class="sl-voucher-modal__expiry-row">
					<span class="sl-voucher-modal__expiry-badge" data-sl-modal-expiry></span>
					<span class="sl-voucher-badge" data-sl-modal-status-badge></span>
				</div>
			</div>

			<!-- Conditions / Terms Table -->
			<div class="sl-voucher-modal__terms">
				<h5 class="sl-voucher-modal__section-title"><?php esc_html_e( 'Điều kiện áp dụng', 'omniwp' ); ?></h5>
				<div class="sl-voucher-terms-list" data-sl-modal-terms>
					<!-- Injected dynamically via JS -->
				</div>
			</div>

		</div>

		<!-- Modal Footer Actions -->
		<div class="sl-voucher-modal__footer">
			<button type="button" class="sl-btn sl-btn--ghost" data-sl-voucher-modal-close>
				<?php esc_html_e( 'Đóng', 'omniwp' ); ?>
			</button>
			<button type="button" class="sl-btn sl-btn--primary" data-sl-modal-apply>
				<?php esc_html_e( 'Dùng mã ngay', 'omniwp' ); ?>
			</button>
		</div>

	</div>
</div>
