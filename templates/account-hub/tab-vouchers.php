<?php
/**
 * Vouchers Tab Template (Mã giảm giá phong cách e-commerce).
 *
 * @var \WP_User $user
 * @var array    $tab
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;
use OmniWP\Frontend\VoucherService;

defined( 'ABSPATH' ) || exit;

$vouchers  = VoucherService::get_customer_vouchers( $user->ID );
$site_name = get_bloginfo( 'name' ) ?: 'SHOP';
?>

<div class="sl-hub-header sl-hub-header--vouchers">
	<div class="sl-hub-header__meta">
		<h2 class="sl-hub-title">
			<span class="sl-hub-title__icon"><?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span><?php esc_html_e( 'Mã giảm giá', 'omniwp' ); ?></span>
		</h2>
		<p class="sl-hub-subtitle"><?php esc_html_e( 'Quản lý và áp dụng các mã ưu đãi dành riêng cho tài khoản của bạn.', 'omniwp' ); ?></p>
	</div>

	<?php if ( ! empty( $vouchers ) ) : ?>
		<div class="sl-vouchers-count">
			<span class="sl-vouchers-count__badge">
				<?php
				$active_count = count(
					array_filter(
						$vouchers,
						static function ( array $v ): bool {
							return 'active' === $v['status'];
						}
					)
				);
				/* translators: %d: active voucher count */
				echo esc_html( sprintf( _n( '%d mã khả dụng', '%d mã khả dụng', $active_count, 'omniwp' ), $active_count ) );
				?>
			</span>
		</div>
	<?php endif; ?>
</div>

<!-- Voucher Cards Container -->
<div class="sl-voucher-grid" data-sl-voucher-list>
	<?php if ( ! empty( $vouchers ) ) : ?>
		<?php foreach ( $vouchers as $v ) : ?>
			<?php
			$is_disabled  = ( 'active' !== $v['status'] );
			$card_classes = array( 'sl-voucher-card' );
			if ( $is_disabled ) {
				$card_classes[] = 'is-disabled';
			}
			if ( ! empty( $v['is_expiring_soon'] ) ) {
				$card_classes[] = 'is-expiring-soon';
			}

			$brand_icon = 'ticket';
			if ( 'percent' === $v['badge_type'] ) {
				$brand_icon = 'percent';
			} elseif ( 'shipping' === $v['badge_type'] ) {
				$brand_icon = 'truck';
			}
			?>
			<div class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>" data-sl-voucher-card="<?php echo esc_attr( $v['code'] ); ?>">
				
				<!-- Left Ticket Notch Brand Column -->
				<div class="sl-voucher-card__brand">
					<div class="sl-voucher-brand-inner">
						<span class="sl-voucher-brand-icon">
							<?php echo IconSet::get( $brand_icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
						<span class="sl-voucher-brand-label"><?php echo esc_html( $site_name ); ?></span>
					</div>
					<div class="sl-ticket-notch sl-ticket-notch--top" aria-hidden="true"></div>
					<div class="sl-ticket-notch sl-ticket-notch--bottom" aria-hidden="true"></div>
				</div>

				<!-- Middle Voucher Information -->
				<div class="sl-voucher-card__content">
					<div class="sl-voucher-card__header">
						<?php if ( 'used' === $v['status'] ) : ?>
							<span class="sl-voucher-badge sl-voucher-badge--used"><?php esc_html_e( 'Đã sử dụng', 'omniwp' ); ?></span>
						<?php elseif ( 'expired' === $v['status'] ) : ?>
							<span class="sl-voucher-badge sl-voucher-badge--expired"><?php esc_html_e( 'Hết hạn', 'omniwp' ); ?></span>
						<?php elseif ( ! empty( $v['is_expiring_soon'] ) ) : ?>
							<span class="sl-voucher-badge sl-voucher-badge--warning"><?php esc_html_e( 'Sắp hết hạn', 'omniwp' ); ?></span>
						<?php endif; ?>
					</div>

					<h4 class="sl-voucher-card__title">
						<?php echo esc_html( $v['headline'] ); ?>
					</h4>

					<?php if ( ! empty( $v['value_display'] ) ) : ?>
						<p class="sl-voucher-card__value"><?php echo esc_html( $v['value_display'] ); ?></p>
					<?php endif; ?>

					<div class="sl-voucher-card__expiry">
						<span class="sl-voucher-expiry-text">
							<?php
							/* translators: %s: expiry date */
							echo esc_html( sprintf( __( 'HSD: %s', 'omniwp' ), $v['expiry_text'] ) );
							?>
						</span>
					</div>
				</div>

				<!-- Right Actions Column -->
				<div class="sl-voucher-card__actions">
					<button
						type="button"
						class="sl-voucher-btn sl-voucher-btn--detail"
						data-sl-voucher-detail
						data-voucher="<?php echo esc_attr( wp_json_encode( $v ) ); ?>"
					>
						<?php esc_html_e( 'Chi tiết', 'omniwp' ); ?>
					</button>

					<?php if ( ! $is_disabled ) : ?>
						<div class="sl-voucher-btn-group">
							<button
								type="button"
								class="sl-voucher-btn sl-voucher-btn--copy"
								data-sl-voucher-copy
								data-code="<?php echo esc_attr( $v['code'] ); ?>"
								title="<?php esc_attr_e( 'Sao chép mã', 'omniwp' ); ?>"
							>
								<?php echo IconSet::get( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span><?php esc_html_e( 'Sao chép', 'omniwp' ); ?></span>
							</button>

							<button
								type="button"
								class="sl-voucher-btn sl-voucher-btn--apply"
								data-sl-voucher-apply
								data-code="<?php echo esc_attr( $v['code'] ); ?>"
							>
								<?php esc_html_e( 'Dùng ngay', 'omniwp' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</div>

			</div>
		<?php endforeach; ?>
	<?php else : ?>
		<div class="sl-voucher-empty">
			<div class="sl-voucher-empty__icon">
				<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<h4 class="sl-voucher-empty__title"><?php esc_html_e( 'Chưa có mã giảm giá nào', 'omniwp' ); ?></h4>
			<p class="sl-voucher-empty__desc"><?php esc_html_e( 'Các chương trình ưu đãi và voucher mua sắm sẽ xuất hiện tại đây khi được kích hoạt.', 'omniwp' ); ?></p>
		</div>
	<?php endif; ?>
</div>
