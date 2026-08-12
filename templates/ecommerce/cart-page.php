<?php
/**
 * Template for OmniWP In-page Cart.
 *
 * @package OmniWP
 */

use OmniWP\Ecommerce\CartService;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$cart     = CartService::get_cart_data();
$is_empty = ! empty( $cart['is_empty'] );
$freeship = $cart['freeship'] ?? array();
?>
<div class="omniwp sl-cart-page-wrap alignwide">
	<div class="sl-cart-container">

		<div class="sl-cart-header">
			<h1 class="sl-cart-page-title">
				<span class="sl-cart-page-title__icon"><?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php esc_html_e( 'Giỏ hàng của bạn', 'omniwp' ); ?>
				<?php if ( ! $is_empty ) : ?>
					<span class="sl-cart-count-pill">(<?php echo esc_html( (string) $cart['item_count'] ); ?> <?php esc_html_e( 'sản phẩm', 'omniwp' ); ?>)</span>
				<?php endif; ?>
			</h1>
		</div>

		<?php if ( $is_empty ) : ?>
			<div class="sl-cart-empty-box">
				<div class="sl-cart-empty-box__icon"><?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<h2><?php esc_html_e( 'Giỏ hàng của bạn đang trống', 'omniwp' ); ?></h2>
				<p><?php esc_html_e( 'Chưa có sản phẩm nào được chọn. Hãy tiếp tục khám phá các sản phẩm nổi bật nhé!', 'omniwp' ); ?></p>
				<a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ); ?>" class="sl-btn sl-btn--primary sl-btn--lg">
					<?php esc_html_e( 'Khám phá sản phẩm ngay', 'omniwp' ); ?> →
				</a>
			</div>
		<?php else : ?>

			<!-- Free Shipping Progress -->
			<?php if ( ! empty( $freeship['enabled'] ) ) : ?>
				<div class="sl-freeship-bar sl-freeship-bar--inpage <?php echo ! empty( $freeship['is_reached'] ) ? 'sl-freeship-bar--reached' : ''; ?>">
					<div class="sl-freeship-bar__text">
						<?php echo wp_kses_post( $freeship['message'] ?? '' ); ?>
					</div>
					<div class="sl-freeship-bar__track">
						<div class="sl-freeship-bar__progress" style="width: <?php echo esc_attr( (string) ( $freeship['percentage'] ?? 0 ) ); ?>%;"></div>
					</div>
				</div>
			<?php endif; ?>

			<div class="sl-cart-grid">

				<!-- Left: Items list -->
				<div class="sl-cart-grid__items">
					<div class="sl-cart-table-card">
						<div class="sl-cart-table-head">
							<div class="sl-col-prod"><?php esc_html_e( 'Sản phẩm', 'omniwp' ); ?></div>
							<div class="sl-col-price"><?php esc_html_e( 'Đơn giá', 'omniwp' ); ?></div>
							<div class="sl-col-qty"><?php esc_html_e( 'Số lượng', 'omniwp' ); ?></div>
							<div class="sl-col-subtotal"><?php esc_html_e( 'Tạm tính', 'omniwp' ); ?></div>
							<div class="sl-col-del"></div>
						</div>

						<div class="sl-cart-table-body">
							<?php foreach ( $cart['items'] as $item ) : ?>
								<div class="sl-cart-row" data-key="<?php echo esc_attr( $item['key'] ); ?>">
									<div class="sl-col-prod">
										<div class="sl-cart-prod-cell">
											<div class="sl-cart-thumb"><?php echo wp_kses_post( $item['thumbnail'] ); ?></div>
											<div class="sl-cart-info">
												<h3 class="sl-cart-name">
													<?php if ( ! empty( $item['permalink'] ) ) : ?>
														<a href="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
													<?php else : ?>
														<?php echo esc_html( $item['name'] ); ?>
													<?php endif; ?>
												</h3>
												<?php if ( ! empty( $item['variation_text'] ) ) : ?>
													<div class="sl-cart-variation"><?php echo esc_html( $item['variation_text'] ); ?></div>
												<?php endif; ?>
											</div>
										</div>
									</div>

									<div class="sl-col-price">
										<span class="sl-mobile-label"><?php esc_html_e( 'Đơn giá:', 'omniwp' ); ?> </span>
										<span class="sl-item-unit-price"><?php echo wp_kses_post( $item['price_html'] ); ?></span>
									</div>

									<div class="sl-col-qty">
										<div class="sl-qty-stepper">
											<button type="button" class="sl-qty-btn sl-qty-minus" aria-label="<?php esc_attr_e( 'Giảm', 'omniwp' ); ?>">-</button>
											<input type="number" class="sl-input sl-qty-input" value="<?php echo esc_attr( (string) $item['quantity'] ); ?>" min="1" max="<?php echo esc_attr( (string) ( $item['max_quantity'] > 0 ? $item['max_quantity'] : 999 ) ); ?>" readonly />
											<button type="button" class="sl-qty-btn sl-qty-plus" aria-label="<?php esc_attr_e( 'Tăng', 'omniwp' ); ?>">+</button>
										</div>
									</div>

									<div class="sl-col-subtotal">
										<span class="sl-mobile-label"><?php esc_html_e( 'Tạm tính:', 'omniwp' ); ?> </span>
										<strong class="sl-item-line-total"><?php echo wp_kses_post( $item['line_total'] ); ?></strong>
									</div>

									<div class="sl-col-del">
										<button type="button" class="sl-cart-item__remove" aria-label="<?php esc_attr_e( 'Xóa', 'omniwp' ); ?>">
											<?php echo IconSet::get( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="sl-cart-bottom-actions">
						<a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ); ?>" class="sl-btn sl-btn--outline sl-continue-shopping-btn">
							← <?php esc_html_e( 'Tiếp tục xem sản phẩm khác', 'omniwp' ); ?>
						</a>
					</div>
				</div>

				<!-- Right: Sticky Order Summary -->
				<div class="sl-cart-grid__summary">
					<div class="sl-cart-summary-card">
						<h3 class="sl-summary-title"><?php esc_html_e( 'Tóm tắt đơn hàng', 'omniwp' ); ?></h3>

						<div class="sl-summary-row">
							<span><?php esc_html_e( 'Tạm tính:', 'omniwp' ); ?></span>
							<strong id="sl-inpage-subtotal"><?php echo wp_kses_post( $cart['subtotal_html'] ); ?></strong>
						</div>

						<?php if ( ! empty( $cart['discount_total'] ) && $cart['discount_total'] > 0 ) : ?>
							<div class="sl-summary-row sl-summary-discount">
								<span><?php esc_html_e( 'Giảm giá ưu đãi:', 'omniwp' ); ?></span>
								<strong>-<?php echo wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $cart['discount_total'] ) : number_format( $cart['discount_total'] ) ); ?></strong>
							</div>
						<?php endif; ?>

						<!-- Coupon box -->
						<div class="sl-summary-coupon-box">
							<label for="sl-inpage-coupon-code" class="sl-coupon-label"><?php esc_html_e( 'Mã giảm giá / Voucher', 'omniwp' ); ?></label>
							<form id="sl-inpage-coupon-form" class="sl-coupon-form">
								<input type="text" id="sl-inpage-coupon-code" class="sl-input sl-coupon-input" placeholder="<?php esc_attr_e( 'Nhập mã...', 'omniwp' ); ?>" />
								<button type="submit" class="sl-btn sl-btn--outline sl-coupon-btn"><?php esc_html_e( 'Áp dụng', 'omniwp' ); ?></button>
							</form>
						</div>

						<div class="sl-summary-divider"></div>

						<div class="sl-summary-row sl-summary-total">
							<span><?php esc_html_e( 'Tổng thanh toán:', 'omniwp' ); ?></span>
							<strong id="sl-inpage-total"><?php echo wp_kses_post( $cart['total_html'] ); ?></strong>
						</div>

						<a href="<?php echo esc_url( $cart['checkout_url'] ); ?>" class="sl-btn sl-btn--primary sl-btn--block sl-btn--lg sl-checkout-btn">
							<?php esc_html_e( 'Tiến hành đặt hàng', 'omniwp' ); ?> →
						</a>

						<div class="sl-secure-badge">
							<?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Bảo mật thanh toán & An toàn 100%', 'omniwp' ); ?></span>
						</div>
					</div>
				</div>

			</div>
		<?php endif; ?>

	</div>
</div>
