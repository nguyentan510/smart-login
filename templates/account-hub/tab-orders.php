<?php
/**
 * Orders History Tab (Lịch sử đơn hàng với Pipeline & Live Search).
 *
 * @var \WP_User $user
 * @var array    $tab
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;
use OmniWP\Frontend\RestController;

defined( 'ABSPATH' ) || exit;

$pipeline_tabs = array(
	'all'           => __( 'Tất cả', 'omniwp' ),
	'wc-pending'    => __( 'Chờ thanh toán', 'omniwp' ),
	'wc-processing' => __( 'Đang chuẩn bị hàng', 'omniwp' ),
	'wc-shipping'   => __( 'Đang giao hàng', 'omniwp' ),
	'wc-completed'  => __( 'Hoàn thành', 'omniwp' ),
	'wc-cancelled'  => __( 'Đã hủy', 'omniwp' ),
);

/**
 * Filter order pipeline tabs.
 *
 * @param array $pipeline_tabs Key => Label map.
 */
$pipeline_tabs = (array) apply_filters( 'omniwp_order_pipeline_statuses', $pipeline_tabs );
?>

<div class="sl-hub-header sl-hub-header--orders">
	<div class="sl-hub-header__meta">
		<h2 class="sl-hub-title">
			<span class="sl-hub-title__icon"><?php echo IconSet::get( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span><?php esc_html_e( 'Lịch sử đơn hàng', 'omniwp' ); ?></span>
		</h2>
		<p class="sl-hub-subtitle"><?php esc_html_e( 'Theo dõi trạng thái và tra cứu các đơn hàng đã đặt.', 'omniwp' ); ?></p>
	</div>

	<!-- Live Search Input -->
	<div class="sl-orders-search-wrap">
		<span class="sl-orders-search-icon">
			<?php echo IconSet::get( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</span>
		<input
			type="text"
			class="sl-input sl-orders-search-input"
			data-sl-orders-search
			placeholder="<?php esc_attr_e( 'Tìm kiếm mã đơn hàng hoặc tên sản phẩm', 'omniwp' ); ?>"
		/>
	</div>
</div>

<!-- Order Pipeline Status Tabs Bar -->
<div class="sl-orders-pipeline" data-sl-orders-pipeline>
	<?php $is_first = true; ?>
	<?php foreach ( $pipeline_tabs as $status_key => $status_label ) : ?>
		<button
			type="button"
			class="sl-orders-pipeline__item<?php echo $is_first ? ' is-active' : ''; ?>"
			data-sl-order-status="<?php echo esc_attr( $status_key ); ?>"
		>
			<span><?php echo esc_html( $status_label ); ?></span>
			<span class="sl-order-badge" data-sl-order-badge="<?php echo esc_attr( $status_key ); ?>" style="display:none;">0</span>
		</button>
		<?php $is_first = false; ?>
	<?php endforeach; ?>
</div>

<!-- AJAX Orders Container -->
<div class="sl-hub-orders-wrapper" data-sl-orders-container>
	<?php if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) : ?>
		<?php
		$customer_orders = wc_get_orders(
			array(
				'customer_id' => $user->ID,
				'limit'       => 20,
			)
		);
		?>

		<?php if ( ! empty( $customer_orders ) && is_array( $customer_orders ) ) : ?>
			<div class="sl-hub-orders-list">
				<?php foreach ( $customer_orders as $wc_order ) : ?>
					<?php
					if ( ! $wc_order instanceof \WC_Order ) {
						continue;
					}
					echo RestController::render_order_card( $wc_order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<div class="ow-empty-state">
				<div class="ow-empty-state__icon-wrap">
					<?php echo IconSet::get( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<h4 class="ow-empty-state__title"><?php esc_html_e( 'Bạn chưa có đơn hàng nào', 'omniwp' ); ?></h4>
				<p class="ow-empty-state__desc"><?php esc_html_e( 'Các đơn hàng bạn đã mua sẽ xuất hiện tại đây để bạn tiện theo dõi trạng thái giao hàng.', 'omniwp' ); ?></p>
				<?php if ( function_exists( 'wc_get_page_permalink' ) ) : ?>
					<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="sl-btn sl-btn--primary sl-btn--inline ow-empty-state__btn">
						<?php esc_html_e( 'Khám phá sản phẩm ngay', 'omniwp' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<div class="ow-empty-state">
			<div class="ow-empty-state__icon-wrap">
				<?php echo IconSet::get( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<h4 class="ow-empty-state__title"><?php esc_html_e( 'Chưa có đơn hàng nào', 'omniwp' ); ?></h4>
			<p class="ow-empty-state__desc"><?php esc_html_e( 'Hệ thống chưa ghi nhận đơn hàng nào cho tài khoản này.', 'omniwp' ); ?></p>
		</div>
	<?php endif; ?>
</div>
