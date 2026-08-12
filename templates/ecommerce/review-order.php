<?php
/**
 * Template for OmniWP Shopee-Style Order Review Table.
 *
 * Displays Shopee 4-column product layout:
 * - Sản phẩm (Thumbnail + Title + Variation)
 * - Đơn giá (Unit Price)
 * - Số lượng (Quantity)
 * - Thành tiền (Subtotal)
 * Followed by Shopee-style Order Totals (Subtotal, Shipping, Tax, Total).
 *
 * @package OmniWP
 */

defined( 'ABSPATH' ) || exit;

$blog_name = get_option( 'blogname' ) ?: __( 'Cửa hàng', 'omniwp' );
?>

<table class="shop_table woocommerce-checkout-review-order-table sl-co-product-table">
	<thead>
		<tr>
			<th class="product-name-col"><?php esc_html_e( 'Sản phẩm', 'omniwp' ); ?></th>
			<th class="product-total-col"><?php esc_html_e( 'Thành tiền', 'omniwp' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		if ( function_exists( 'WC' ) && WC() && WC()->cart ) :
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
				$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

				if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) :
					// Thumbnail image.
					$thumbnail = $_product->get_image(
						'woocommerce_thumbnail',
						array(
							'class' => 'sl-co-item-img',
							'alt'   => esc_attr( $_product->get_name() ),
						)
					);

					// Current effective unit price formatted (clean display without strikethrough noise in calc line).
					$unit_price_html = wc_price( $_product->get_price() );

					// Subtotal.
					$subtotal_html = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );

					// Check sale status for price-anchoring strikethrough display.
					$regular_price         = (float) $_product->get_regular_price();
					$current_price         = (float) $_product->get_price();
					$is_on_sale            = $_product->is_on_sale() && $regular_price > $current_price;
					$regular_subtotal_html = $is_on_sale ? '<del class="sl-co-regular-subtotal">' . wc_price( $regular_price * $cart_item['quantity'] ) . '</del>' : '';
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item sl-co-item-row', $cart_item, $cart_item_key ) ); ?>">
						<!-- Col 1: Product Thumbnail + Title + Variation + Calc (Unit Price x Qty) -->
						<td class="product-name-col" data-title="<?php esc_attr_e( 'Sản phẩm', 'omniwp' ); ?>">
							<div class="sl-co-item-detail">
								<div class="sl-co-item-thumb">
									<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
								<div class="sl-co-item-info">
									<span class="sl-co-item-title">
										<?php echo wp_kses_post( $_product->get_name() ); ?>
									</span>
									<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div class="sl-co-item-calc">
										<span class="sl-co-calc-unit"><?php echo $unit_price_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<span class="sl-co-calc-times">&times;</span>
										<span class="sl-co-calc-qty"><?php echo esc_html( (string) $cart_item['quantity'] ); ?></span>
									</div>
								</div>
							</div>
						</td>

						<!-- Col 2: Subtotal (Thành tiền) with optional Side-by-side Strikethrough Regular Subtotal -->
						<td class="product-total-col" data-title="<?php esc_attr_e( 'Thành tiền', 'omniwp' ); ?>">
							<div class="sl-co-item-prices-wrap">
								<?php if ( $is_on_sale && ! empty( $regular_subtotal_html ) ) : ?>
									<?php echo $regular_subtotal_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endif; ?>
								<strong class="sl-co-item-subtotal"><?php echo $subtotal_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
							</div>
						</td>
					</tr>
					<?php
				endif;
			endforeach;
		endif;

		do_action( 'woocommerce_review_order_after_cart_contents' );
		?>
	</tbody>

	<tfoot>

		<tr class="cart-subtotal">
			<th colspan="1"><?php esc_html_e( 'Tạm tính', 'omniwp' ); ?></th>
			<td><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<?php $discount_amount = WC()->cart->get_coupon_discount_amount( $code ); ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<?php /* translators: %s: coupon code */ ?>
				<th colspan="1"><?php printf( esc_html__( 'Giảm giá Voucher (%s)', 'omniwp' ), esc_html( strtoupper( $code ) ) ); ?></th>
				<td class="sl-co-coupon-discount-val"><?php echo '-' . esc_html( wp_strip_all_tags( wc_price( $discount_amount ) ) ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>

			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

			<?php wc_cart_totals_shipping_html(); ?>

			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

		<?php endif; ?>

		<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
			<tr class="fee">
				<th colspan="1"><?php echo esc_html( $fee->name ); ?></th>
				<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) : ?>
			<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
				<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax_item ) : ?>
					<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
						<th colspan="1"><?php echo esc_html( $tax_item->label ); ?></th>
						<td><?php echo wp_kses_post( $tax_item->formatted_amount ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr class="tax-total">
					<th colspan="1"><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
					<td><?php wc_cart_totals_taxes_total_html(); ?></td>
				</tr>
			<?php endif; ?>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

		<tr class="order-total">
			<th colspan="1"><?php esc_html_e( 'Tổng thanh toán', 'omniwp' ); ?></th>
			<td><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>

	</tfoot>
</table>
