<?php
/**
 * Template for OmniWP Unified Kho Voucher Module.
 *
 * Reusable across Slide Cart Drawer, Inline Cart Page Modal, Checkout, and Account Hub.
 *
 * @package OmniWP
 * @var array  $available_coupons Array of available coupons.
 * @var string $ow_mode           Rendering mode: 'cart' or 'account'. Default 'cart'.
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$coupons = $available_coupons ?? array();
$ow_mode = isset( $ow_mode ) ? $ow_mode : ( isset( $mode ) ? $mode : 'cart' );
?>
<div class="sl-voucher-module sl-voucher-module--<?php echo esc_attr( $ow_mode ); ?>">
	
	<?php
	$freeship = $freeship ?? ( class_exists( '\OmniWP\Ecommerce\CartService' ) ? \OmniWP\Ecommerce\CartService::get_cart_data()['freeship'] ?? array() : array() );
	if ( 'cart' === $ow_mode && ! empty( $freeship['enabled'] ) ) :
		?>
		<!-- Top Freeship Progress Banner inside Kho Voucher Module -->
		<div class="sl-freeship-bar sl-freeship-bar--module <?php echo ! empty( $freeship['is_reached'] ) ? 'sl-freeship-bar--reached' : ''; ?>" id="sl-module-freeship-bar">
			<div class="sl-freeship-bar__header">
				<span class="sl-freeship-bar__icon">
					<?php echo IconSet::get( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<div class="sl-freeship-bar__text" id="sl-module-freeship-text">
					<?php echo wp_kses_post( $freeship['message'] ?? '' ); ?>
				</div>
				<span class="sl-freeship-bar__percent" id="sl-module-freeship-percent"><?php echo esc_html( (string) ( $freeship['percentage'] ?? 0 ) ); ?>%</span>
			</div>
			<div class="sl-freeship-bar__track">
				<div class="sl-freeship-bar__progress" id="sl-module-freeship-progress" style="width: <?php echo esc_attr( (string) ( $freeship['percentage'] ?? 0 ) ); ?>%;"></div>
			</div>
		</div>
	<?php endif; ?>

	<!-- 1-Touch Filter Tabs -->
	<div class="sl-voucher-filter-tabs" role="tablist">
		<button type="button" class="sl-voucher-tab is-active" data-filter="all" role="tab" aria-selected="true">
			<?php esc_html_e( 'Tất cả', 'omniwp' ); ?>
		</button>
		<?php if ( 'account' === $ow_mode ) : ?>
			<button type="button" class="sl-voucher-tab" data-filter="mine" role="tab" aria-selected="false">
				<?php esc_html_e( 'Voucher của tôi', 'omniwp' ); ?>
			</button>
			<button type="button" class="sl-voucher-tab" data-filter="expired" role="tab" aria-selected="false">
				<?php esc_html_e( 'Đã dùng / Hết hạn', 'omniwp' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="sl-voucher-tab" data-filter="freeship" role="tab" aria-selected="false">
				<?php esc_html_e( 'Freeship', 'omniwp' ); ?>
			</button>
			<button type="button" class="sl-voucher-tab" data-filter="discount" role="tab" aria-selected="false">
				<?php esc_html_e( 'Giảm giá', 'omniwp' ); ?>
			</button>
		<?php endif; ?>
	</div>

	<!-- Unified Manual Coupon Box -->
	<div class="sl-voucher-module__input-wrap">
		<div class="sl-voucher-box">
			<div class="sl-voucher-box__header">
				<span class="sl-voucher-box__icon">
					<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<div class="sl-voucher-box__text">
					<strong class="sl-voucher-box__title"><?php esc_html_e( 'Bạn có mã giảm giá riêng?', 'omniwp' ); ?></strong>
					<p class="sl-voucher-box__desc"><?php esc_html_e( 'Nhập mã voucher được gửi riêng qua SMS, Email hoặc quà tặng sự kiện để kích hoạt ưu đãi.', 'omniwp' ); ?></p>
				</div>
			</div>

			<div class="sl-voucher-box__action">
				<form class="sl-coupon-form sl-voucher-module-form" data-sl-voucher-form>
					<div class="sl-coupon-input-group">
						<input type="text" class="sl-input sl-coupon-input sl-voucher-module-code-input" placeholder="<?php esc_attr_e( 'Nhập mã ưu đãi / voucher...', 'omniwp' ); ?>" autocomplete="off" required />
						<button type="submit" class="sl-btn sl-coupon-btn"><?php esc_html_e( 'Áp dụng', 'omniwp' ); ?></button>
					</div>
				</form>
				<div class="sl-coupon-message sl-voucher-module-msg"></div>
			</div>
		</div>
	</div>

	<!-- Ticket Cards List / Grid -->
	<div class="sl-voucher-module__body">
		<?php if ( empty( $coupons ) ) : ?>
			<div class="sl-voucher-empty">
				<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p><?php esc_html_e( 'Hiện chưa có mã giảm giá công khai nào trong kho.', 'omniwp' ); ?></p>
			</div>
		<?php else : ?>
			<div class="sl-voucher-cards-list">
				<?php foreach ( $coupons as $v ) : ?>
					<?php
					$is_freeship       = ! empty( $v['free_shipping'] ) || ( isset( $v['discount_type'] ) && 'free_shipping' === $v['discount_type'] );
					$is_applied        = ! empty( $v['is_applied'] );
					$is_usable         = isset( $v['is_usable'] ) ? ! empty( $v['is_usable'] ) : ( ! empty( $v['can_apply'] ) );
					$progress_pct      = isset( $v['progress_percent'] ) ? (int) $v['progress_percent'] : ( $is_usable ? 100 : 50 );
					$amount_needed_txt = $v['amount_needed_html'] ?? '';
					$filter_type       = $is_freeship ? 'freeship' : 'discount';
					$status_class      = ! empty( $v['status'] ) && 'active' !== $v['status'] ? 'is-expired' : '';
					?>
					<div class="sl-coupon-ticket <?php echo $is_applied ? 'is-applied' : ''; ?> <?php echo ! $is_usable ? 'is-disabled' : ''; ?> <?php echo $is_freeship ? 'sl-coupon-ticket--freeship' : ''; ?> <?php echo esc_attr( $status_class ); ?>" data-code="<?php echo esc_attr( $v['code'] ); ?>" data-type="<?php echo esc_attr( $filter_type ); ?>">
						
						<!-- Cánh trái: Badge type & giá trị -->
						<div class="sl-coupon-ticket__left">
							<span class="sl-coupon-ticket__type"><?php echo $is_freeship ? esc_html__( 'FREESHIP', 'omniwp' ) : esc_html__( 'GIẢM GIÁ', 'omniwp' ); ?></span>
							<strong class="sl-coupon-ticket__val"><?php echo esc_html( $v['badge'] ?? $v['amount_formatted'] ?? '' ); ?></strong>
						</div>

						<!-- Đường răng cưa & 2 vết cắt bán nguyệt -->
						<div class="sl-coupon-ticket__divider"></div>

						<!-- Cánh phải: Chi tiết, Progress & Nút thao tác -->
						<div class="sl-coupon-ticket__right">
							<div class="sl-coupon-ticket__header">
								<span class="sl-coupon-ticket__code"><?php echo esc_html( $v['code'] ); ?></span>
								<?php if ( ! empty( $v['description'] ) ) : ?>
									<span class="sl-coupon-ticket__info-btn sl-voucher-card__tooltip-trigger" tabindex="0" aria-label="<?php esc_attr_e( 'Xem chi tiết điều kiện', 'omniwp' ); ?>">
										<?php echo IconSet::get( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<span class="sl-voucher-card__tooltip-content">
											<span class="sl-tooltip-title"><?php esc_html_e( 'Điều kiện áp dụng:', 'omniwp' ); ?></span>
											<?php echo esc_html( $v['description'] ); ?>
										</span>
									</span>
								<?php endif; ?>
							</div>

							<div class="sl-coupon-ticket__meta">
								<span class="sl-coupon-ticket__discount-text"><?php echo esc_html( $v['discount_label'] ?? $v['value_display'] ?? '' ); ?></span>
							</div>

							<!-- Quick-Win Mini Progress Bar cho mã chưa đủ điều kiện -->
							<?php if ( ! $is_usable && ! empty( $amount_needed_txt ) ) : ?>
								<div class="sl-coupon-ticket__progress-wrap">
									<div class="sl-coupon-ticket__progress-bar">
										<div class="sl-coupon-ticket__progress-fill" style="width: <?php echo esc_attr( (string) $progress_pct ); ?>%;"></div>
									</div>
									<span class="sl-progress-tip-text">
										<?php
										/* translators: %s: shortage amount needed. */
										printf( esc_html__( 'Mua thêm %s để mở khóa', 'omniwp' ), esc_html( $amount_needed_txt ) );
										?>
									</span>
								</div>
							<?php endif; ?>

							<div class="sl-coupon-ticket__footer">
								<span class="sl-coupon-ticket__expiry">
									<?php
									/* translators: %s: expiry date string. */
									printf( esc_html__( 'HSD: %s', 'omniwp' ), esc_html( $v['expiry_text'] ?: __( 'Không thời hạn', 'omniwp' ) ) );
									?>
								</span>
								<div class="sl-coupon-ticket__action">
									<?php if ( $is_applied ) : ?>
										<span class="sl-coupon-ticket__applied-badge"><?php esc_html_e( '✓ Đã dùng', 'omniwp' ); ?></span>
									<?php elseif ( 'account' === $ow_mode ) : ?>
										<button type="button" class="sl-coupon-ticket__btn sl-voucher-copy-btn" data-code="<?php echo esc_attr( $v['code'] ); ?>">
											<?php echo IconSet::get( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php esc_html_e( 'Sao chép', 'omniwp' ); ?>
										</button>
									<?php elseif ( $is_usable ) : ?>
										<button type="button" class="sl-coupon-ticket__btn sl-voucher-apply-btn" data-code="<?php echo esc_attr( $v['code'] ); ?>"><?php esc_html_e( 'Dùng mã', 'omniwp' ); ?></button>
									<?php else : ?>
										<button type="button" class="sl-coupon-ticket__btn sl-voucher-quickwin-btn is-disabled" data-code="<?php echo esc_attr( $v['code'] ); ?>" aria-label="<?php esc_attr_e( 'Gợi ý mua kèm', 'omniwp' ); ?>"><?php esc_html_e( 'Mua thêm', 'omniwp' ); ?></button>
									<?php endif; ?>
								</div>
							</div>
						</div>

					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
