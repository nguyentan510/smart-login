<?php
/**
 * Template for OmniWP Slide Cart (Drawer Cart).
 *
 * @package OmniWP
 * @var array $cart Cart payload data.
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$is_empty = ! empty( $cart['is_empty'] );
$freeship = $cart['freeship'] ?? array();
$items    = $cart['items'] ?? array();
$coupons  = $cart['coupons'] ?? array();
?>
<div id="sl-slide-cart-overlay" class="sl-slide-cart-overlay" aria-hidden="true"></div>

<aside id="sl-slide-cart" class="sl-slide-cart" role="dialog" aria-modal="true" aria-labelledby="sl-slide-cart-title" aria-hidden="true">
	<div class="sl-slide-cart__inner">

		<!-- Header -->
		<header class="sl-slide-cart__header">
			<div class="sl-slide-cart__title-wrap">
				<span class="sl-slide-cart__icon">
					<?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<h2 id="sl-slide-cart-title" class="sl-slide-cart__title">
					<?php esc_html_e( 'Giỏ hàng của bạn', 'omniwp' ); ?>
					<span class="sl-slide-cart__count-badge" id="sl-cart-header-count">(<?php echo esc_html( (string) ( $cart['item_count'] ?? 0 ) ); ?>)</span>
				</h2>
			</div>
			<button type="button" class="sl-slide-cart__close" id="sl-slide-cart-close" aria-label="<?php esc_attr_e( 'Đóng giỏ hàng', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</header>

		<!-- Free Shipping Bar -->
		<?php if ( ! empty( $freeship['enabled'] ) ) : ?>
			<div class="sl-freeship-bar <?php echo ! empty( $freeship['is_reached'] ) ? 'sl-freeship-bar--reached' : ''; ?>" id="sl-freeship-bar">
				<div class="sl-freeship-bar__text" id="sl-freeship-text">
					<?php echo wp_kses_post( $freeship['message'] ?? '' ); ?>
				</div>
				<div class="sl-freeship-bar__track">
					<div class="sl-freeship-bar__progress" id="sl-freeship-progress" style="width: <?php echo esc_attr( (string) ( $freeship['percentage'] ?? 0 ) ); ?>%;"></div>
				</div>
			</div>
		<?php endif; ?>

		<!-- Cart Body -->
		<div class="sl-slide-cart__body" id="sl-slide-cart-body">
			<?php if ( $is_empty ) : ?>
				<div class="sl-cart-empty-state">
					<div class="sl-cart-empty-state__icon">🛍️</div>
					<h3 class="sl-cart-empty-state__title"><?php esc_html_e( 'Giỏ hàng đang trống', 'omniwp' ); ?></h3>
					<p class="sl-cart-empty-state__text"><?php esc_html_e( 'Hãy lựa chọn những sản phẩm ưng ý để thêm vào giỏ hàng nhé.', 'omniwp' ); ?></p>
					<button type="button" class="sl-btn sl-btn--primary sl-slide-cart-close-btn">
						<?php esc_html_e( 'Tiếp tục mua sắm', 'omniwp' ); ?>
					</button>
				</div>
			<?php else : ?>
				<div class="sl-cart-items-list" id="sl-cart-items-list">
					<?php foreach ( $items as $item ) : ?>
						<article class="sl-cart-item" data-key="<?php echo esc_attr( $item['key'] ); ?>">
							<div class="sl-cart-item__thumb">
								<?php echo wp_kses_post( $item['thumbnail'] ); ?>
							</div>
							<div class="sl-cart-item__details">
								<h4 class="sl-cart-item__title">
									<?php if ( ! empty( $item['permalink'] ) ) : ?>
										<a href="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $item['name'] ); ?>
									<?php endif; ?>
								</h4>
								<?php if ( ! empty( $item['variation_text'] ) ) : ?>
									<div class="sl-cart-item__meta"><?php echo esc_html( $item['variation_text'] ); ?></div>
								<?php endif; ?>
								<div class="sl-cart-item__price"><?php echo wp_kses_post( $item['price_html'] ); ?></div>
								<div class="sl-cart-item__actions">
									<div class="sl-qty-stepper">
										<button type="button" class="sl-qty-btn sl-qty-minus" aria-label="<?php esc_attr_e( 'Giảm số lượng', 'omniwp' ); ?>">-</button>
										<input type="number" class="sl-input sl-qty-input" value="<?php echo esc_attr( (string) $item['quantity'] ); ?>" min="1" max="<?php echo esc_attr( (string) ( $item['max_quantity'] > 0 ? $item['max_quantity'] : 999 ) ); ?>" readonly />
										<button type="button" class="sl-qty-btn sl-qty-plus" aria-label="<?php esc_attr_e( 'Tăng số lượng', 'omniwp' ); ?>">+</button>
									</div>
									<button type="button" class="sl-cart-item__remove" aria-label="<?php esc_attr_e( 'Xóa sản phẩm', 'omniwp' ); ?>">
										<?php echo IconSet::get( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</button>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>

				<!-- Cross Sells Section -->
				<?php if ( ! empty( $cart['cross_sells'] ) ) : ?>
					<div class="sl-cart-cross-sells">
						<h4 class="sl-cart-cross-sells__heading"><?php esc_html_e( 'Sản phẩm gợi ý mua kèm 🔥', 'omniwp' ); ?></h4>
						<div class="sl-cart-cross-sells__grid">
							<?php foreach ( $cart['cross_sells'] as $cross ) : ?>
								<div class="sl-cross-item">
									<div class="sl-cross-item__thumb"><?php echo wp_kses_post( $cross['thumbnail'] ); ?></div>
									<div class="sl-cross-item__info">
										<a href="<?php echo esc_url( $cross['permalink'] ); ?>" class="sl-cross-item__name"><?php echo esc_html( $cross['name'] ); ?></a>
										<div class="sl-cross-item__price"><?php echo wp_kses_post( $cross['price_html'] ); ?></div>
									</div>
									<a href="<?php echo esc_url( $cross['add_to_cart_url'] ); ?>" class="sl-btn sl-btn--sm sl-cross-item__btn" data-product_id="<?php echo esc_attr( (string) $cross['id'] ); ?>">
										+ <?php esc_html_e( 'Thêm', 'omniwp' ); ?>
									</a>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Footer Summary & Actions -->
		<?php if ( ! $is_empty ) : ?>
			<footer class="sl-slide-cart__footer" id="sl-slide-cart-footer">
				<!-- Coupon Input -->
				<div class="sl-cart-coupon-wrap">
					<form id="sl-cart-coupon-form" class="sl-coupon-form">
						<input type="text" id="sl-cart-coupon-code" class="sl-input sl-coupon-input" placeholder="<?php esc_attr_e( 'Mã giảm giá...', 'omniwp' ); ?>" />
						<button type="submit" class="sl-btn sl-btn--outline sl-coupon-btn"><?php esc_html_e( 'Áp dụng', 'omniwp' ); ?></button>
					</form>
					<div id="sl-coupon-message" class="sl-coupon-message"></div>

					<?php if ( ! empty( $coupons ) ) : ?>
						<div class="sl-applied-coupons">
							<?php foreach ( $coupons as $cp ) : ?>
								<span class="sl-coupon-tag">
									<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $cp['code'] ); ?>
									<button type="button" class="sl-coupon-tag__remove" data-code="<?php echo esc_attr( $cp['code'] ); ?>">&times;</button>
								</span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<!-- Totals -->
				<div class="sl-cart-totals">
					<div class="sl-cart-totals__row">
						<span><?php esc_html_e( 'Tạm tính:', 'omniwp' ); ?></span>
						<strong id="sl-cart-subtotal-val"><?php echo wp_kses_post( $cart['subtotal_html'] ); ?></strong>
					</div>
					<?php if ( ! empty( $cart['discount_total'] ) && $cart['discount_total'] > 0 ) : ?>
						<div class="sl-cart-totals__row sl-cart-totals__discount">
							<span><?php esc_html_e( 'Giảm giá:', 'omniwp' ); ?></span>
							<strong id="sl-cart-discount-val">-<?php echo wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $cart['discount_total'] ) : number_format( $cart['discount_total'] ) ); ?></strong>
						</div>
					<?php endif; ?>
					<div class="sl-cart-totals__row sl-cart-totals__final">
						<span><?php esc_html_e( 'Tổng cộng:', 'omniwp' ); ?></span>
						<strong id="sl-cart-total-val"><?php echo wp_kses_post( $cart['total_html'] ); ?></strong>
					</div>
				</div>

				<!-- Checkout Buttons -->
				<div class="sl-cart-actions">
					<a href="<?php echo esc_url( $cart['checkout_url'] ); ?>" class="sl-btn sl-btn--primary sl-btn--block sl-btn--lg">
						<?php esc_html_e( 'Tiến hành Thanh toán', 'omniwp' ); ?> →
					</a>
					<a href="<?php echo esc_url( $cart['cart_url'] ); ?>" class="sl-cart-actions__view-cart">
						<?php esc_html_e( 'Xem chi tiết giỏ hàng', 'omniwp' ); ?>
					</a>
				</div>
			</footer>
		<?php endif; ?>

	</div>
</aside>
