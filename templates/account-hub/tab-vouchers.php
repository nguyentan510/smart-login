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

<!-- Unified Voucher Module Container for Account Hub -->
<div class="sl-voucher-grid" data-sl-voucher-list>
	<?php
	$available_coupons = $vouchers;
	$ow_mode           = 'account';
	require dirname( __DIR__ ) . '/ecommerce/voucher-module.php';
	?>
</div>
