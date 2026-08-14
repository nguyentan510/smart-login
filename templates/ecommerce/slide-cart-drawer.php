<?php
/**
 * Template for OmniWP Slide Cart (Drawer Cart).
 *
 * @package OmniWP
 * @var array $cart Cart payload data.
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$is_empty       = ! empty( $cart['is_empty'] );
$freeship       = $cart['freeship'] ?? array();
$items          = $cart['items'] ?? array();
$coupons        = $cart['coupons'] ?? array();
$savings_amount = (float) ( $cart['discount_total'] ?? 0 );
?>
<div id="sl-slide-cart-overlay" class="sl-slide-cart-overlay" aria-hidden="true"></div>

<aside id="sl-slide-cart" class="sl-slide-cart" role="dialog" aria-modal="true" aria-labelledby="sl-slide-cart-title" aria-hidden="true">
	<div class="sl-slide-cart__inner">

		<!-- Ultra Clean Header -->
		<header class="sl-slide-cart__header">
			<div class="sl-slide-cart__header-top">
				<div class="sl-slide-cart__title-wrap">
					<h2 id="sl-slide-cart-title" class="sl-slide-cart__title">
						<?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Giỏ hàng', 'omniwp' ); ?>
						<span id="sl-cart-header-count" class="sl-slide-cart__count"><?php echo ! empty( $cart['item_count'] ) ? '(' . esc_html( (string) $cart['item_count'] ) . ')' : ''; ?></span>
					</h2>
				</div>
				<button type="button" class="sl-slide-cart__close" id="sl-slide-cart-close" aria-label="<?php esc_attr_e( 'Đóng giỏ hàng', 'omniwp' ); ?>">
					<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
		</header>

		<!-- Cart Body -->
		<div class="sl-slide-cart__body" id="sl-slide-cart-body">
			<?php if ( $is_empty ) : ?>
				<div class="sl-cart-empty-state">
					<div class="sl-cart-empty-state__icon">
						<?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<h3 class="sl-cart-empty-state__title"><?php esc_html_e( 'Giỏ hàng của bạn đang trống', 'omniwp' ); ?></h3>
					<p class="sl-cart-empty-state__text"><?php esc_html_e( 'Điền giỏ hàng của bạn với những mặt hàng tuyệt vời', 'omniwp' ); ?></p>
					<button type="button" class="sl-btn sl-btn--primary sl-slide-cart-close-btn">
						<?php esc_html_e( 'Mua ngay', 'omniwp' ); ?>
					</button>
				</div>
			<?php else : ?>
				<div class="sl-cart-items-list" id="sl-cart-items-list">
					<?php foreach ( $items as $item ) : ?>
						<article class="sl-cart-item" data-key="<?php echo esc_attr( $item['key'] ); ?>">
							<!-- Thumbnail -->
							<div class="sl-cart-item__thumb">
								<?php echo wp_kses_post( $item['thumbnail'] ); ?>
							</div>

							<!-- Chi tiết sản phẩm -->
							<div class="sl-cart-item__details">
								<div class="sl-cart-item__header-row">
									<!-- Tên sản phẩm -->
									<h4 class="sl-cart-item__title">
										<?php if ( ! empty( $item['permalink'] ) ) : ?>
											<a href="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $item['name'] ); ?>
										<?php endif; ?>
									</h4>
									<!-- Nút Xóa ở góc phải tên -->
									<button type="button" class="sl-cart-item__remove-btn sl-cart-item__remove" aria-label="<?php esc_attr_e( 'Xóa sản phẩm', 'omniwp' ); ?>">
										<?php echo IconSet::get( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</button>
								</div>

								<!-- Hàng dưới Tên: Trái (Biến thể + Số lượng) | Phải (Giá + Tiết kiệm) -->
								<div class="sl-cart-item__bottom-row">
									<!-- Cột trái: Biến thể và Số lượng (nằm dưới Name) -->
									<div class="sl-cart-item__left-col">
										<?php if ( ! empty( $item['is_variable'] ) && ! empty( $item['attributes_config'] ) ) : ?>
											<div class="sl-cart-item__variations">
												<?php foreach ( $item['attributes_config'] as $attr ) : ?>
													<div class="sl-cart-attr-group">
														<select class="sl-cart-attr-select" data-attribute="<?php echo esc_attr( $attr['key'] ); ?>" data-key="<?php echo esc_attr( $item['key'] ); ?>" aria-label="<?php echo esc_attr( $attr['label'] ); ?>">
															<?php foreach ( $attr['options'] as $opt ) : ?>
																<option value="<?php echo esc_attr( $opt['slug'] ); ?>" <?php selected( $attr['current_value'], $opt['slug'] ); ?>>
																	<?php echo esc_html( $opt['name'] ); ?>
																</option>
															<?php endforeach; ?>
														</select>
													</div>
												<?php endforeach; ?>
											</div>
										<?php elseif ( ! empty( $item['meta_html'] ) ) : ?>
											<div class="sl-cart-item__meta">
												<?php echo wp_kses_post( $item['meta_html'] ); ?>
											</div>
										<?php endif; ?>

										<!-- Stepper số lượng mini nằm dưới Tên sản phẩm -->
										<div class="sl-qty-stepper">
											<button type="button" class="sl-qty-btn sl-qty-minus" aria-label="<?php esc_attr_e( 'Giảm số lượng', 'omniwp' ); ?>">-</button>
											<input type="number" class="sl-input sl-qty-input" value="<?php echo esc_attr( (string) $item['quantity'] ); ?>" min="1" max="<?php echo esc_attr( (string) ( ! empty( $item['max_quantity'] ) && $item['max_quantity'] > 0 ? $item['max_quantity'] : 999 ) ); ?>" readonly />
											<button type="button" class="sl-qty-btn sl-qty-plus" aria-label="<?php esc_attr_e( 'Tăng số lượng', 'omniwp' ); ?>">+</button>
										</div>
									</div>

									<!-- Cột phải: Giá và Tiết kiệm (ở góc dưới bên phải) -->
									<div class="sl-cart-item__right-col">
										<div class="sl-cart-item__prices-wrap">
											<?php if ( ! empty( $item['regular_price_html'] ) ) : ?>
												<span class="sl-cart-item__regular-price"><?php echo wp_kses_post( $item['regular_price_html'] ); ?></span>
											<?php endif; ?>
											<span class="sl-cart-item__price"><?php echo wp_kses_post( $item['price_html'] ); ?></span>
										</div>
										<?php if ( ! empty( $item['savings_html'] ) ) : ?>
											<span class="sl-cart-item__savings"><?php echo esc_html( $item['savings_html'] ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>

				<!-- Section Sản phẩm đề xuất (Cross-sell Carousel/Grid) -->
				<?php if ( ! empty( $cart['cross_sells'] ) ) : ?>
					<div class="sl-cart-cross-sells">
						<h3 class="sl-cross-sells__title"><?php esc_html_e( 'Có thể bạn sẽ thích', 'omniwp' ); ?></h3>
						<div class="sl-cross-sells__list">
							<?php foreach ( $cart['cross_sells'] as $cs ) : ?>
								<div class="sl-cross-card">
									<div class="sl-cross-card__thumb">
										<?php echo wp_kses_post( $cs['thumbnail'] ); ?>
									</div>
									<div class="sl-cross-card__details">
										<h5 class="sl-cross-card__title"><?php echo esc_html( $cs['name'] ); ?></h5>
										<div class="sl-cross-card__bottom">
											<span class="sl-cross-card__price"><?php echo wp_kses_post( $cs['price_html'] ); ?></span>
											<button type="button" class="sl-btn sl-btn--sm sl-cross-card__btn" data-product_id="<?php echo esc_attr( (string) $cs['id'] ); ?>">
												+ <?php esc_html_e( 'Thêm', 'omniwp' ); ?>
											</button>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Compact Slide Cart Footer -->
		<?php if ( ! $is_empty ) : ?>
			<footer class="sl-slide-cart__footer">
				<!-- Coupon Accordion Section -->
				<div class="sl-coupon-accordion">
					<button type="button" class="sl-coupon-accordion__toggle" id="sl-coupon-toggle">
						<span class="sl-coupon-accordion__label">
							<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Mã giảm giá / Voucher', 'omniwp' ); ?>
						</span>
						<?php echo IconSet::get( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
					<div class="sl-coupon-accordion__content" id="sl-coupon-collapse" style="display: none;">
						<form id="sl-cart-coupon-form" class="sl-coupon-form">
							<div class="sl-coupon-input-group">
								<input type="text" id="sl-cart-coupon-code" class="sl-coupon-input" placeholder="<?php esc_attr_e( 'Nhập mã...', 'omniwp' ); ?>" autocomplete="off" required />
								<button type="submit" class="sl-btn sl-coupon-btn"><?php esc_html_e( 'Áp dụng', 'omniwp' ); ?></button>
							</div>
						</form>

						<button type="button" id="sl-open-voucher-drawer-btn" class="sl-voucher-drawer-trigger">
							<span class="sl-voucher-trigger-left">
								<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php
								$avail_count = count( $cart['available_coupons'] ?? array() );
								/* translators: %d: count of available coupons. */
								$voucher_label_html = $avail_count > 0 ? sprintf( esc_html__( 'Kho voucher (%d mã có sẵn)', 'omniwp' ), (int) $avail_count ) : esc_html__( 'Xem kho voucher', 'omniwp' );
								?>
								<span><?php echo esc_html( $voucher_label_html ); ?></span>
							</span>
							<?php echo IconSet::get( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>

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
				</div>

				<!-- Mathematical Totals Calculation (Gốc - Giảm = Thực trả) -->
				<div class="sl-cart-totals" id="sl-cart-totals-breakdown">
					<?php
					$has_discount = ! empty( $cart['total_saved_amount'] ) && $cart['total_saved_amount'] > 0;
					$cp_codes     = array();
					if ( ! empty( $coupons ) ) {
						foreach ( $coupons as $cp ) {
							$cp_codes[] = $cp['code'];
						}
					}
					$cp_str = ! empty( $cp_codes ) ? implode( ', ', $cp_codes ) : '';
					?>
					<!-- Dòng 1: Tạm tính (Giá gốc) - Chỉ hiện khi có giảm giá -->
					<div class="sl-cart-totals__row sl-cart-totals__row--subtotal" id="sl-drawer-subtotal-row" style="<?php echo $has_discount ? '' : 'display: none;'; ?>">
						<span class="sl-cart-totals__label"><?php esc_html_e( 'Tạm tính', 'omniwp' ); ?></span>
						<span id="sl-drawer-subtotal-val" class="sl-cart-totals__sub-val"><?php echo wp_kses_post( $cart['original_subtotal_html'] ?? $cart['subtotal_html'] ); ?></span>
					</div>

					<!-- Dòng 2: Giảm giá (-X đ) -->
					<div class="sl-cart-totals__row sl-cart-totals__row--discount" id="sl-drawer-discount-row" style="<?php echo $has_discount ? '' : 'display: none;'; ?>">
						<span class="sl-cart-totals__label"><?php esc_html_e( 'Giảm giá', 'omniwp' ); ?></span>
						<strong id="sl-drawer-discount-val" class="sl-cart-totals__discount-val">-<?php echo wp_kses_post( $cart['total_saved_html'] ?? '0₫' ); ?></strong>
					</div>

					<!-- Dòng 3: Tổng thanh toán (Thực trả) -->
					<div class="sl-cart-totals__row sl-cart-totals__row--final">
						<span class="sl-cart-totals__label sl-cart-totals__label--bold"><?php esc_html_e( 'Tổng thanh toán', 'omniwp' ); ?></span>
						<strong id="sl-cart-total-val" class="sl-cart-totals__val"><?php echo wp_kses_post( $cart['total_html'] ); ?></strong>
					</div>
				</div>

				<!-- Primary Action Button (Thanh toán) -->
				<div class="sl-cart-actions">
					<a href="<?php echo esc_url( $cart['checkout_url'] ); ?>" class="sl-btn sl-btn--checkout sl-btn--block">
						<?php esc_html_e( 'Thanh toán ngay', 'omniwp' ); ?>
					</a>
					<div class="sl-secure-subtext">
						<?php echo IconSet::get( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'Thanh toán bảo mật & an toàn', 'omniwp' ); ?></span>
					</div>
				</div>
			</footer>
		<?php endif; ?>

	</div>

	<!-- Sub-Slide Voucher Drawer Panel -->
	<div id="sl-voucher-panel" class="sl-voucher-panel" aria-hidden="true">
		<header class="sl-voucher-panel__header">
			<button type="button" id="sl-voucher-panel-back" class="sl-voucher-panel__back" aria-label="<?php esc_attr_e( 'Quay lại', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<h3 class="sl-voucher-panel__title"><?php esc_html_e( 'Kho ưu đãi & Mã giảm giá', 'omniwp' ); ?></h3>
			<button type="button" class="sl-slide-cart__close" id="sl-voucher-panel-close" aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</header>

		<div class="sl-voucher-panel__body" id="sl-voucher-list">
			<?php
			$available_coupons = $cart['available_coupons'] ?? array();
			$ow_mode           = 'cart';
			require __DIR__ . '/voucher-module.php';
			?>
		</div>

		<footer class="sl-voucher-panel__footer">
			<button type="button" id="sl-voucher-panel-done" class="sl-btn sl-btn--checkout sl-btn--block">
				<?php esc_html_e( 'Quay lại giỏ hàng', 'omniwp' ); ?>
			</button>
		</footer>
	</div>
</aside>

