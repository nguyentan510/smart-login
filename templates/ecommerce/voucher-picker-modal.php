<?php
/**
 * Template for OmniWP Shopee-Style Voucher Picker Modal ("Chọn Voucher Ưu Đãi").
 *
 * Cleaned UI using OmniWP design system tokens & authentic ticket notch styling.
 *
 * @package OmniWP
 * @var array $vouchers Evaluated voucher list from VoucherService::evaluate_cart_vouchers().
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$shipping_vouchers = $vouchers['shipping'] ?? array();
$discount_vouchers = $vouchers['discount'] ?? array();
?>

<!-- Shopee-Style Voucher Picker Modal -->
<div class="sl-voucher-picker-backdrop" id="sl-voucher-picker-modal" aria-hidden="true" style="display:none;">
	<div class="sl-voucher-picker-overlay" id="sl-voucher-picker-overlay"></div>
	<div class="sl-voucher-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="sl-voucher-picker-title">

		<!-- Modal Header (Clean without unnavigated links) -->
		<div class="sl-voucher-picker-header">
			<h3 class="sl-voucher-picker-title" id="sl-voucher-picker-title"><?php esc_html_e( 'Chọn Voucher Ưu Đãi', 'omniwp' ); ?></h3>
			<button type="button" class="sl-voucher-picker-close-btn" id="sl-voucher-picker-close" aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">✕</button>
		</div>

		<!-- Top Manual Code Input Section (Clean input + button row) -->
		<div class="sl-voucher-picker-manual-box">
			<div class="sl-picker-manual-input-wrap">
				<input type="text" id="sl-picker-manual-code-input" class="sl-input sl-picker-manual-input" placeholder="<?php esc_attr_e( 'Nhập mã voucher giảm giá...', 'omniwp' ); ?>" />
				<button type="button" class="sl-btn sl-btn--primary sl-picker-manual-apply-btn" id="sl-picker-manual-apply-btn">
					<?php esc_html_e( 'ÁP DỤNG', 'omniwp' ); ?>
				</button>
			</div>
		</div>

		<!-- Scrollable Voucher Cards Area -->
		<div class="sl-voucher-picker-body">

			<!-- Group 1: Mã Miễn Phí Vận Chuyển -->
			<div class="sl-voucher-group sl-voucher-group--freeship">
				<div class="sl-voucher-group__header">
					<h4 class="sl-voucher-group__title"><?php esc_html_e( 'Mã Miễn Phí Vận Chuyển', 'omniwp' ); ?></h4>
					<span class="sl-voucher-group__subtitle"><?php esc_html_e( 'Chọn 1 mã', 'omniwp' ); ?></span>
				</div>

				<div class="sl-voucher-picker-list" id="sl-picker-freeship-list">
					<?php if ( ! empty( $shipping_vouchers ) ) : ?>
						<?php foreach ( $shipping_vouchers as $v ) : ?>
							<?php
							$is_eligible = ! empty( $v['is_eligible'] );
							$is_applied  = ! empty( $v['is_applied'] );
							?>
							<div class="sl-picker-vcard <?php echo $is_eligible ? 'sl-picker-vcard--active' : 'sl-picker-vcard--disabled'; ?> <?php echo $is_applied ? 'sl-picker-vcard--selected' : ''; ?>" data-code="<?php echo esc_attr( $v['code'] ); ?>" data-type="shipping" data-savings="<?php echo esc_attr( (string) $v['savings_estimate'] ); ?>">
								<div class="sl-picker-vcard__badge sl-picker-vcard__badge--shipping">
									<span class="sl-vcard-badge-icon"><?php echo IconSet::get( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="sl-vcard-badge-text">FREESHIP</span>
								</div>
								<div class="sl-picker-vcard__content">
									<strong class="sl-vcard-title"><?php echo esc_html( $v['value_display'] ); ?></strong>
									<?php if ( ! empty( $v['min_spend_text'] ) ) : ?>
										<?php /* translators: %s: minimum spend text */ ?>
										<span class="sl-vcard-minspend"><?php printf( esc_html__( 'Đơn Tối Thiểu %s', 'omniwp' ), esc_html( $v['min_spend_text'] ) ); ?></span>
									<?php endif; ?>
									<?php /* translators: %s: expiration date */ ?>
									<span class="sl-vcard-expiry"><?php printf( esc_html__( 'HSD: %s', 'omniwp' ), esc_html( $v['expiry_text'] ) ); ?></span>
									<?php if ( ! $is_eligible && ! empty( $v['ineligible_reason'] ) ) : ?>
										<div class="sl-vcard-ineligible-notice">
											⚠️ <?php echo esc_html( $v['ineligible_reason'] ); ?>
										</div>
									<?php endif; ?>
								</div>
								<div class="sl-picker-vcard__radio-wrap">
									<input type="radio" name="sl_freeship_voucher_radio" class="sl-picker-vcard-radio" value="<?php echo esc_attr( $v['code'] ); ?>" <?php checked( $is_applied ); ?> <?php disabled( ! $is_eligible ); ?> />
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="sl-voucher-empty-notice">
							<?php esc_html_e( 'Chưa có mã khả dụng', 'omniwp' ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Group 2: Mã Giảm Giá Đơn Hàng -->
			<div class="sl-voucher-group sl-voucher-group--discount">
				<div class="sl-voucher-group__header">
					<h4 class="sl-voucher-group__title"><?php esc_html_e( 'Mã Giảm Giá Đơn Hàng', 'omniwp' ); ?></h4>
					<span class="sl-voucher-group__subtitle"><?php esc_html_e( 'Chọn 1 mã', 'omniwp' ); ?></span>
				</div>

				<div class="sl-voucher-picker-list" id="sl-picker-discount-list">
					<?php if ( ! empty( $discount_vouchers ) ) : ?>
						<?php foreach ( $discount_vouchers as $v ) : ?>
							<?php
							$is_eligible = ! empty( $v['is_eligible'] );
							$is_applied  = ! empty( $v['is_applied'] );
							?>
							<div class="sl-picker-vcard <?php echo $is_eligible ? 'sl-picker-vcard--active' : 'sl-picker-vcard--disabled'; ?> <?php echo $is_applied ? 'sl-picker-vcard--selected' : ''; ?>" data-code="<?php echo esc_attr( $v['code'] ); ?>" data-type="discount" data-savings="<?php echo esc_attr( (string) $v['savings_estimate'] ); ?>">
								<div class="sl-picker-vcard__badge sl-picker-vcard__badge--discount">
									<span class="sl-vcard-badge-icon"><?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="sl-vcard-badge-text"><?php echo esc_html( $v['amount_formatted'] ); ?></span>
								</div>
								<div class="sl-picker-vcard__content">
									<strong class="sl-vcard-title"><?php echo esc_html( $v['headline'] ); ?></strong>
									<?php /* translators: %s: expiration date */ ?>
									<span class="sl-vcard-expiry"><?php printf( esc_html__( 'HSD: %s', 'omniwp' ), esc_html( $v['expiry_text'] ) ); ?></span>
									<?php if ( ! $is_eligible && ! empty( $v['ineligible_reason'] ) ) : ?>
										<div class="sl-vcard-ineligible-notice">
											⚠️ <?php echo esc_html( $v['ineligible_reason'] ); ?>
										</div>
									<?php endif; ?>
								</div>
								<div class="sl-picker-vcard__radio-wrap">
									<input type="radio" name="sl_discount_voucher_radio" class="sl-picker-vcard-radio" value="<?php echo esc_attr( $v['code'] ); ?>" <?php checked( $is_applied ); ?> <?php disabled( ! $is_eligible ); ?> />
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="sl-voucher-empty-notice">
							<?php esc_html_e( 'Chưa có mã khả dụng', 'omniwp' ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

		</div>

		<!-- Modal Footer Bar -->
		<div class="sl-voucher-picker-footer">
			<div class="sl-voucher-picker-footer__actions">
				<button type="button" class="sl-btn sl-btn--outline sl-picker-btn-back" id="sl-picker-btn-cancel">
					<?php esc_html_e( 'TRỞ LẠI', 'omniwp' ); ?>
				</button>
				<button type="button" class="sl-btn sl-btn--primary sl-picker-btn-submit" id="sl-picker-btn-apply">
					<?php esc_html_e( 'ĐỒNG Ý', 'omniwp' ); ?>
				</button>
			</div>
		</div>

	</div>
</div>
