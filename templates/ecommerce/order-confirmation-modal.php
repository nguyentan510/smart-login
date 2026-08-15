<?php
/**
 * Template for OmniWP Order Confirmation Modal.
 *
 * Rendered in wp_footer (OUTSIDE any <form>) to avoid nested form issues.
 * Displays a summary card of the recipient, delivery address, payment method,
 * and total amount so the buyer can double-check before placing the order.
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;
?>
<div id="sl-order-confirm-modal" class="sl-address-modal sl-order-confirm-modal" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="sl-order-confirm-title">
	<div class="sl-address-modal__overlay" id="sl-order-confirm-overlay"></div>
	<div class="sl-address-modal__dialog sl-order-confirm-dialog">
		<header class="sl-address-modal__header sl-order-confirm-header">
			<div class="sl-order-confirm-header__title-wrap">
				<span class="sl-order-confirm-header__icon">
					<?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<h3 id="sl-order-confirm-title"><?php esc_html_e( 'Xác nhận thông tin giao hàng', 'omniwp' ); ?></h3>
			</div>
			<button type="button" class="sl-address-modal__close" id="sl-order-confirm-close" aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</header>

		<div class="sl-order-confirm-body">
			<div class="sl-order-confirm-notice">
				<span class="sl-order-confirm-notice__icon">
					<?php echo IconSet::get( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<p class="sl-order-confirm-notice__text">
					<?php esc_html_e( 'Vui lòng kiểm tra kỹ người nhận và địa chỉ để đơn hàng được giao chính xác & nhanh chóng nhất:', 'omniwp' ); ?>
				</p>
			</div>

			<!-- Card 1: Recipient & Address -->
			<div class="sl-order-confirm-card">
				<div class="sl-order-confirm-card__row">
					<span class="sl-order-confirm-card__icon">
						<?php echo IconSet::get( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<div class="sl-order-confirm-card__info">
						<div class="sl-order-confirm-card__name-phone">
							<strong id="sl-confirm-recipient-name" class="sl-order-confirm-name">---</strong>
							<span id="sl-confirm-recipient-phone" class="sl-order-confirm-phone">---</span>
						</div>
					</div>
				</div>

				<div class="sl-order-confirm-card__row sl-order-confirm-card__row--address">
					<span class="sl-order-confirm-card__icon">
						<?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<div class="sl-order-confirm-card__info">
						<div id="sl-confirm-recipient-address" class="sl-order-confirm-address">---</div>
					</div>
				</div>
			</div>

			<!-- Card 2: Payment & Order Total -->
			<div class="sl-order-confirm-card sl-order-confirm-card--meta">
				<div class="sl-order-confirm-card__meta-row">
					<span class="sl-order-confirm-meta-label">
						<?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'Thanh toán:', 'omniwp' ); ?></span>
					</span>
					<strong id="sl-confirm-payment-method" class="sl-order-confirm-meta-val">---</strong>
				</div>

				<div class="sl-order-confirm-card__meta-row sl-order-confirm-card__meta-row--total">
					<span class="sl-order-confirm-meta-label">
						<?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'Tổng đơn hàng:', 'omniwp' ); ?></span>
					</span>
					<strong id="sl-confirm-order-total" class="sl-order-confirm-total-val">---</strong>
				</div>
			</div>
		</div>

		<footer class="sl-order-confirm-footer">
			<button type="button" class="sl-btn sl-btn--outline sl-order-confirm-btn-edit" id="sl-btn-confirm-edit">
				<?php echo IconSet::get( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Sửa địa chỉ', 'omniwp' ); ?></span>
			</button>
			<button type="button" class="sl-btn sl-btn--primary sl-btn--lg sl-order-confirm-btn-proceed" id="sl-btn-confirm-proceed">
				<?php echo IconSet::get( 'check-simple' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Xác nhận & Đặt hàng', 'omniwp' ); ?></span>
			</button>
		</footer>
	</div>
</div>
