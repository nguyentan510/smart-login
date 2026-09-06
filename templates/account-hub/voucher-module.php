<?php
/**
 * Template for OmniWP Unified Kho Voucher Module (E-Commerce Ticket Cards).
 *
 * Modern Shopee/Lazada style coupon tickets with cutout notches,
 * instant copy to clipboard, modal terms, and responsive layout.
 *
 * @package OmniWP
 * @var array  $available_coupons Array of available coupons.
 * @var string $ow_mode           Rendering mode: 'cart' or 'account'. Default 'account'.
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$coupons = $available_coupons ?? array();
$ow_mode = isset( $ow_mode ) ? $ow_mode : ( isset( $mode ) ? $mode : 'account' );
?>

<div class="sl-voucher-module sl-voucher-module--<?php echo esc_attr( $ow_mode ); ?>">
	
	<!-- 1-Touch Filter Tabs -->
	<div class="sl-voucher-filter-tabs" role="tablist">
		<button type="button" class="sl-voucher-tab is-active" data-filter="all" role="tab" aria-selected="true">
			<span><?php esc_html_e( 'Tất cả', 'omniwp' ); ?></span>
		</button>
		<?php if ( 'account' === $ow_mode ) : ?>
			<button type="button" class="sl-voucher-tab" data-filter="mine" role="tab" aria-selected="false">
				<span><?php esc_html_e( 'Khả dụng', 'omniwp' ); ?></span>
			</button>
			<button type="button" class="sl-voucher-tab" data-filter="expired" role="tab" aria-selected="false">
				<span><?php esc_html_e( 'Đã dùng / Hết hạn', 'omniwp' ); ?></span>
			</button>
		<?php else : ?>
			<button type="button" class="sl-voucher-tab" data-filter="freeship" role="tab" aria-selected="false">
				<span><?php esc_html_e( 'Freeship', 'omniwp' ); ?></span>
			</button>
			<button type="button" class="sl-voucher-tab" data-filter="discount" role="tab" aria-selected="false">
				<span><?php esc_html_e( 'Giảm giá', 'omniwp' ); ?></span>
			</button>
		<?php endif; ?>
	</div>

	<!-- Unified Manual Coupon Input Box -->
	<div class="sl-voucher-module__input-wrap">
		<div class="sl-voucher-box">
			<div class="sl-voucher-box__header">
				<span class="sl-voucher-box__icon">
					<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<div class="sl-voucher-box__text">
					<strong class="sl-voucher-box__title"><?php esc_html_e( 'Bạn có mã giảm giá riêng?', 'omniwp' ); ?></strong>
					<p class="sl-voucher-box__desc"><?php esc_html_e( 'Nhập mã voucher được gửi riêng qua SMS, Email hoặc sự kiện để lưu vào kho.', 'omniwp' ); ?></p>
				</div>
			</div>

			<div class="sl-voucher-box__action">
				<form class="sl-coupon-form sl-voucher-module-form" data-sl-voucher-form>
					<div class="sl-coupon-input-group">
						<input type="text" class="sl-input sl-coupon-input sl-voucher-module-code-input" placeholder="<?php esc_attr_e( 'Nhập mã ưu đãi / voucher...', 'omniwp' ); ?>" autocomplete="off" required />
						<button type="submit" class="sl-btn sl-btn--primary sl-coupon-btn"><?php esc_html_e( 'Áp dụng', 'omniwp' ); ?></button>
					</div>
				</form>
				<div class="sl-coupon-message sl-voucher-module-msg"></div>
			</div>
		</div>
	</div>

	<!-- Ticket Cards Grid Body -->
	<div class="sl-voucher-module__body">
		<?php if ( empty( $coupons ) ) : ?>
			<div class="sl-voucher-empty">
				<div class="sl-voucher-empty__icon">
					<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<h4 class="sl-voucher-empty__title"><?php esc_html_e( 'Hiện chưa có mã giảm giá nào', 'omniwp' ); ?></h4>
				<p class="sl-voucher-empty__desc"><?php esc_html_e( 'Các mã khuyến mãi và voucher dành riêng cho bạn sẽ hiển thị tại đây.', 'omniwp' ); ?></p>
			</div>
		<?php else : ?>
			<div class="sl-voucher-cards-list" data-sl-voucher-grid>
				<?php foreach ( $coupons as $v ) : ?>
					<?php
					$is_freeship  = ! empty( $v['free_shipping'] ) || ( isset( $v['discount_type'] ) && 'free_shipping' === $v['discount_type'] );
					$is_applied   = ! empty( $v['is_applied'] );
					$is_usable    = isset( $v['is_usable'] ) ? ! empty( $v['is_usable'] ) : ( ! empty( $v['can_apply'] ) );
					$filter_type  = $is_freeship ? 'freeship' : 'discount';
					$status_val   = $v['status'] ?? ( $is_usable ? 'active' : 'expired' );
					$status_class = 'active' !== $status_val ? 'is-expired' : '';
					$is_mine_val  = ! empty( $v['is_mine'] ) ? '1' : '0';
					$voucher_json = wp_json_encode( $v );
					$amount_str   = $v['badge'] ?? $v['amount_formatted'] ?? '';
					$headline_str = $v['headline'] ?: ( $v['description'] ?: sprintf( __( 'Mã ưu đãi %s', 'omniwp' ), $v['code'] ) );
					?>
					<div
						class="sl-coupon-ticket sl-coupon-ticket--<?php echo esc_attr( $filter_type ); ?> <?php echo $is_applied ? 'is-applied' : ''; ?> <?php echo ! $is_usable ? 'is-disabled' : ''; ?> <?php echo esc_attr( $status_class ); ?>"
						data-code="<?php echo esc_attr( $v['code'] ); ?>"
						data-type="<?php echo esc_attr( $filter_type ); ?>"
						data-status="<?php echo esc_attr( $status_val ); ?>"
						data-is-mine="<?php echo esc_attr( $is_mine_val ); ?>"
						data-voucher="<?php echo esc_attr( $voucher_json ); ?>"
					>
						<!-- Cánh Trái: Badge & Giá trị giảm -->
						<div class="sl-coupon-ticket__left">
							<span class="sl-coupon-ticket__type"><?php echo $is_freeship ? esc_html__( 'FREESHIP', 'omniwp' ) : esc_html__( 'GIẢM GIÁ', 'omniwp' ); ?></span>
							<strong class="sl-coupon-ticket__val"><?php echo esc_html( $amount_str ); ?></strong>
						</div>

						<!-- Đường Răng Cưa & Vết Cắt Bán Nguyệt (Ticket Notches) -->
						<div class="sl-coupon-ticket__divider"></div>

						<!-- Cánh Phải: Chi tiết, Điều kiện & Nút thao tác -->
						<div class="sl-coupon-ticket__right">
							<div class="sl-coupon-ticket__header">
								<span
									class="sl-coupon-ticket__code"
									data-sl-voucher-copy="<?php echo esc_attr( $v['code'] ); ?>"
									data-code="<?php echo esc_attr( $v['code'] ); ?>"
									title="<?php esc_attr_e( 'Sao chép mã', 'omniwp' ); ?>"
								>
									<?php echo esc_html( $v['code'] ); ?>
								</span>
								<button
									type="button"
									class="sl-coupon-ticket__info-btn"
									data-sl-voucher-detail
									data-voucher="<?php echo esc_attr( $voucher_json ); ?>"
									title="<?php esc_attr_e( 'Xem điều kiện', 'omniwp' ); ?>"
								>
									<?php echo IconSet::get( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</button>
							</div>

							<div class="sl-coupon-ticket__meta">
								<span class="sl-coupon-ticket__discount-text"><?php echo esc_html( $headline_str ); ?></span>
								<span class="sl-coupon-ticket__min-spend">
									<?php
									if ( ! empty( $v['min_spend_text'] ) ) {
										/* translators: %s: minimum spend amount. */
										printf( esc_html__( 'Đơn tối thiểu %s', 'omniwp' ), esc_html( $v['min_spend_text'] ) );
									} else {
										esc_html_e( 'Mọi đơn hàng', 'omniwp' );
									}
									?>
								</span>
							</div>

							<div class="sl-coupon-ticket__footer">
								<span class="sl-coupon-ticket__expiry">
									<?php
									/* translators: %s: expiry date string. */
									printf( esc_html__( 'HSD: %s', 'omniwp' ), esc_html( $v['expiry_text'] ?: __( 'Không thời hạn', 'omniwp' ) ) );
									?>
								</span>
								<div class="sl-coupon-ticket__action">
									<?php if ( 'account' === $ow_mode ) : ?>
										<button
											type="button"
											class="sl-btn sl-btn--outline sl-btn--sm sl-voucher-copy-btn"
											data-code="<?php echo esc_attr( $v['code'] ); ?>"
											data-sl-voucher-copy
											title="<?php esc_attr_e( 'Sao chép mã', 'omniwp' ); ?>"
										>
											<span><?php esc_html_e( 'Sao chép', 'omniwp' ); ?></span>
										</button>
									<?php elseif ( $is_usable ) : ?>
										<button type="button" class="sl-btn sl-btn--primary sl-btn--sm sl-voucher-apply-btn" data-code="<?php echo esc_attr( $v['code'] ); ?>" data-sl-voucher-apply>
											<span><?php esc_html_e( 'Dùng mã', 'omniwp' ); ?></span>
										</button>
									<?php else : ?>
										<button type="button" class="sl-btn sl-btn--secondary sl-btn--sm is-disabled" data-code="<?php echo esc_attr( $v['code'] ); ?>">
											<span><?php esc_html_e( 'Chưa đủ ĐK', 'omniwp' ); ?></span>
										</button>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Empty state when no vouchers match filter -->
			<div class="sl-voucher-filter-empty" data-sl-voucher-filter-empty style="display:none;">
				<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="sl-voucher-filter-empty__text"><?php esc_html_e( 'Không có mã ưu đãi nào trong mục này.', 'omniwp' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</div>
