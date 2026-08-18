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

		<?php
		if ( \OmniWP\Settings::get( 'ecommerce.stepper_enabled', true ) ) {
			$active_step = 1;
			require __DIR__ . '/checkout-stepper.php';
		}
		?>

		<!-- Top Header with Trust Badge Pill -->
		<div class="sl-cart-header">
			<div class="sl-cart-header__left">
				<h1 class="sl-cart-page-title">
					<?php esc_html_e( 'Giỏ hàng', 'omniwp' ); ?>
					<?php if ( ! $is_empty ) : ?>
						<span class="sl-cart-count-pill">
							<?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo esc_html( (string) $cart['item_count'] ); ?> <?php esc_html_e( 'sản phẩm', 'omniwp' ); ?>
						</span>
					<?php endif; ?>
				</h1>
			</div>

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

			<div class="sl-cart-grid">

				<!-- Left Column: Items list, Cross-sells & Trust Badges -->
				<div class="sl-cart-grid__items">
					
					<!-- Product Items Table Card -->
					<div class="sl-cart-items-card sl-cart-items-card--table">
						<div class="sl-cart-table-wrap">
							<table class="sl-cart-table">
								<colgroup>
									<col class="sl-cart-col sl-cart-col--product" />
									<col class="sl-cart-col sl-cart-col--price" />
									<col class="sl-cart-col sl-cart-col--qty" />
									<col class="sl-cart-col sl-cart-col--subtotal" />
									<col class="sl-cart-col sl-cart-col--remove" />
								</colgroup>
								<thead>
									<tr>
										<th class="sl-cart-th sl-cart-th--product"><?php esc_html_e( 'Sản phẩm', 'omniwp' ); ?></th>
										<th class="sl-cart-th sl-cart-th--price"><?php esc_html_e( 'Đơn giá', 'omniwp' ); ?></th>
										<th class="sl-cart-th sl-cart-th--qty"><?php esc_html_e( 'Số lượng', 'omniwp' ); ?></th>
										<th class="sl-cart-th sl-cart-th--subtotal"><?php esc_html_e( 'Tạm tính', 'omniwp' ); ?></th>
										<th class="sl-cart-th sl-cart-th--remove" aria-label="<?php esc_attr_e( 'Thao tác', 'omniwp' ); ?>"></th>
									</tr>
								</thead>
								<tbody id="sl-inpage-items-list">
									<?php foreach ( $cart['items'] as $item ) : ?>
										<tr class="sl-cart-item sl-cart-item--tr" data-key="<?php echo esc_attr( $item['key'] ); ?>">
											<!-- Cột 1: Thumbnail + Tên sản phẩm + Biến thể -->
											<td class="sl-cart-td sl-cart-td--product">
												<div class="sl-cart-item__product-cell">
													<div class="sl-cart-item__thumb">
														<?php echo wp_kses_post( $item['thumbnail'] ); ?>
													</div>
													<div class="sl-cart-item__meta-wrap">
														<h4 class="sl-cart-item__title">
															<?php if ( ! empty( $item['permalink'] ) ) : ?>
																<a href="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
															<?php else : ?>
																<?php echo esc_html( $item['name'] ); ?>
															<?php endif; ?>
														</h4>
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
															<div class="sl-cart-item__meta"><?php echo wp_kses_post( $item['meta_html'] ); ?></div>
														<?php endif; ?>
													</div>
												</div>
											</td>

											<!-- Cột 2: Đơn giá -->
											<td class="sl-cart-td sl-cart-td--price" data-label="<?php esc_attr_e( 'Đơn giá', 'omniwp' ); ?>">
												<div class="sl-cart-item__unit-price">
													<?php if ( ! empty( $item['regular_price_html'] ) ) : ?>
														<span class="sl-cart-item__regular-price"><?php echo wp_kses_post( $item['regular_price_html'] ); ?></span>
													<?php endif; ?>
													<span class="sl-cart-item__single-price sl-item-unit-price"><?php echo wp_kses_post( $item['price_html'] ); ?></span>
												</div>
											</td>

											<!-- Cột 3: Số lượng -->
											<td class="sl-cart-td sl-cart-td--qty" data-label="<?php esc_attr_e( 'Số lượng', 'omniwp' ); ?>">
												<div class="sl-qty-stepper">
													<button type="button" class="sl-qty-btn sl-qty-minus" aria-label="<?php esc_attr_e( 'Giảm số lượng', 'omniwp' ); ?>">-</button>
													<input type="number" class="sl-input sl-qty-input" value="<?php echo esc_attr( (string) $item['quantity'] ); ?>" min="1" max="<?php echo esc_attr( (string) ( ! empty( $item['max_quantity'] ) && $item['max_quantity'] > 0 ? $item['max_quantity'] : 999 ) ); ?>" readonly />
													<button type="button" class="sl-qty-btn sl-qty-plus" aria-label="<?php esc_attr_e( 'Tăng số lượng', 'omniwp' ); ?>">+</button>
												</div>
											</td>

											<!-- Cột 4: Thành tiền (Tạm tính) -->
											<td class="sl-cart-td sl-cart-td--subtotal" data-label="<?php esc_attr_e( 'Tạm tính', 'omniwp' ); ?>">
												<strong class="sl-cart-item__price sl-item-line-total"><?php echo wp_kses_post( $item['line_total'] ); ?></strong>
												<?php if ( ! empty( $item['savings_html'] ) ) : ?>
													<span class="sl-cart-item__savings"><?php echo esc_html( $item['savings_html'] ); ?></span>
												<?php endif; ?>
											</td>

											<!-- Cột 5: Nút Xóa -->
											<td class="sl-cart-td sl-cart-td--remove">
												<button type="button" class="sl-cart-item__remove-btn sl-cart-item__remove" data-key="<?php echo esc_attr( $item['key'] ); ?>" aria-label="<?php esc_attr_e( 'Xóa sản phẩm', 'omniwp' ); ?>">
													<?php echo IconSet::get( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Cross Sells Section (Ưu đãi khi mua kèm) -->
					<?php if ( ! empty( $cart['cross_sells'] ) ) : ?>
						<div class="sl-cart-cross-sells-card">
							<div class="sl-cross-sells-header">
								<h3 class="sl-cross-sells-title"><?php esc_html_e( 'Ưu đãi khi mua kèm giảm giá', 'omniwp' ); ?></h3>
								<p class="sl-cross-sells-subtitle"><?php esc_html_e( 'Thêm sản phẩm vào giỏ để tiết kiệm hơn', 'omniwp' ); ?></p>
							</div>
							<div class="sl-cross-sells-grid">
								<?php foreach ( $cart['cross_sells'] as $cross ) : ?>
									<div class="sl-cross-item-card">
										<div class="sl-cross-item-thumb"><?php echo wp_kses_post( $cross['thumbnail'] ); ?></div>
										<div class="sl-cross-item-info">
											<a href="<?php echo esc_url( $cross['permalink'] ); ?>" class="sl-cross-item-name"><?php echo esc_html( $cross['name'] ); ?></a>
											<div class="sl-cross-item-price"><?php echo wp_kses_post( $cross['price_html'] ); ?></div>
											<div class="sl-cross-item-actions">
												<button type="button" class="sl-btn sl-cross-item-btn" data-product_id="<?php echo esc_attr( (string) $cross['id'] ); ?>">
													+ <?php esc_html_e( 'Thêm', 'omniwp' ); ?>
												</button>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<!-- Secondary Action: Tiếp tục mua sắm (Đặt ngay bên dưới danh sách sản phẩm) -->
					<div class="sl-cart-bottom-actions">
						<a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ); ?>" class="sl-btn sl-btn--outline sl-continue-shopping-btn">
							← <?php esc_html_e( 'Tiếp tục mua sắm', 'omniwp' ); ?>
						</a>
					</div>

					<!-- Support Widget Card (Chuyển sang Cột Trái thế vị trí khối Trust) -->
					<div class="sl-cart-support-card">
						<div class="sl-support-header">
							<h4 class="sl-support-title"><?php esc_html_e( 'Cần hỗ trợ?', 'omniwp' ); ?></h4>
							<p class="sl-support-sub"><?php esc_html_e( 'Đội ngũ luôn sẵn sàng hỗ trợ bạn', 'omniwp' ); ?></p>
						</div>
						<div class="sl-support-list">
							<a href="tel:1900636321" class="sl-support-item">
								<div class="sl-support-item__icon">
									<?php echo IconSet::get( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
								<div class="sl-support-item__info">
									<strong class="sl-support-item__main">1900 63 63 21</strong>
									<span class="sl-support-item__sub"><?php esc_html_e( '9:00 - 21:00 mỗi ngày', 'omniwp' ); ?></span>
								</div>
							</a>

							<a href="#chat" class="sl-support-item">
								<div class="sl-support-item__icon">
									<?php echo IconSet::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
								<div class="sl-support-item__info">
									<strong class="sl-support-item__main"><?php esc_html_e( 'Chat với chúng tôi', 'omniwp' ); ?></strong>
									<span class="sl-support-item__sub"><?php esc_html_e( 'Trả lời nhanh chóng', 'omniwp' ); ?></span>
								</div>
							</a>
						</div>
					</div>

				</div>

				<!-- Right Column: Sticky Order Summary -->
				<div class="sl-cart-grid__summary">
					
					<!-- Summary Card -->
					<div class="sl-cart-summary-card">
						<h3 class="sl-summary-title"><?php esc_html_e( 'Chi tiết thanh toán', 'omniwp' ); ?></h3>

						<div class="sl-summary-rows-wrap">
							<div class="sl-summary-row">
								<span><?php esc_html_e( 'Tạm tính', 'omniwp' ); ?></span>
								<span id="sl-inpage-subtotal"><?php echo wp_kses_post( $cart['original_subtotal_html'] ?? $cart['subtotal_html'] ); ?></span>
							</div>

							<?php if ( ! empty( $cart['item_discount_total'] ) && $cart['item_discount_total'] > 0 ) : ?>
								<div class="sl-summary-row sl-summary-discount" id="sl-inpage-item-discount-row">
									<span><?php esc_html_e( 'Giảm giá sản phẩm', 'omniwp' ); ?></span>
									<strong class="sl-discount-val">-<?php echo wp_kses_post( $cart['item_discount_html'] ); ?></strong>
								</div>
							<?php endif; ?>

							<?php
							$applied_coupons = $cart['coupons'] ?? array();
							$cp_codes        = array();
							if ( ! empty( $applied_coupons ) ) {
								foreach ( $applied_coupons as $cp ) {
									$cp_codes[] = $cp['code'];
								}
							}
							$cp_str = ! empty( $cp_codes ) ? implode( ', ', $cp_codes ) : '';
							?>
							<?php if ( ! empty( $cart['coupon_discount_total'] ) && $cart['coupon_discount_total'] > 0 ) : ?>
								<div class="sl-summary-row sl-summary-discount" id="sl-inpage-coupon-discount-row">
									<span><?php esc_html_e( 'Voucher áp dụng', 'omniwp' ); ?></span>
									<strong class="sl-discount-val">-<?php echo wp_kses_post( $cart['coupon_discount_html'] ); ?></strong>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $applied_coupons ) ) : ?>
								<div class="sl-applied-coupons sl-applied-coupons--inpage">
									<?php foreach ( $applied_coupons as $cp ) : ?>
										<span class="sl-coupon-tag">
											<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php echo esc_html( $cp['code'] ); ?>
											<button type="button" class="sl-coupon-tag__remove" data-code="<?php echo esc_attr( $cp['code'] ); ?>">&times;</button>
										</span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<div class="sl-summary-row">
								<span><?php esc_html_e( 'Vận chuyển', 'omniwp' ); ?></span>
								<span id="sl-inpage-shipping" class="sl-shipping-val"><?php echo esc_html( $cart['shipping_label'] ?? __( 'Miễn phí', 'omniwp' ) ); ?></span>
							</div>
						</div>

						<!-- Khối Mã Giảm Giá / Voucher: ĐẶT Ở TRÊN TỔNG THANH TOÁN & GOM ẨN GỌN GÀNG -->
						<?php
						$available_coupons = $cart['available_coupons'] ?? array();
						?>
						<div class="sl-summary-voucher-section sl-coupon-accordion" id="sl-inpage-voucher-accordion">
							<button type="button" class="sl-coupon-accordion__toggle" id="sl-inpage-coupon-toggle" aria-expanded="false">
								<span class="sl-coupon-accordion__label">
									<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php esc_html_e( 'Mã giảm giá / Voucher', 'omniwp' ); ?>
									<?php if ( ! empty( $available_coupons ) ) : ?>
										<span class="sl-voucher-count-pill"><?php echo count( $available_coupons ); ?></span>
									<?php endif; ?>
								</span>
								<span class="sl-coupon-accordion__arrow">
									<?php echo IconSet::get( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</span>
							</button>

							<div class="sl-coupon-accordion__content" id="sl-inpage-coupon-content" style="display: none;">
								<form id="sl-inpage-coupon-form" class="sl-coupon-form">
									<div class="sl-coupon-input-group">
										<input type="text" id="sl-inpage-coupon-code" class="sl-input sl-coupon-input" placeholder="<?php esc_attr_e( 'Nhập mã...', 'omniwp' ); ?>" />
										<button type="submit" class="sl-btn sl-coupon-btn"><?php esc_html_e( 'Áp dụng', 'omniwp' ); ?></button>
									</div>
								</form>
								<div id="sl-inpage-coupon-msg" class="sl-coupon-message"></div>

								<!-- Nút Mở Kho Mã Ưu Đãi Khả Dụng -->
								<button type="button" class="sl-voucher-drawer-trigger sl-inpage-voucher-open-btn" id="sl-inpage-open-voucher-modal-btn">
									<span class="sl-voucher-trigger-left">
										<?php echo IconSet::get( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php
										/* translators: %d: count of available coupons. */
										echo ! empty( $available_coupons ) ? sprintf( esc_html__( 'Kho voucher (%d mã có sẵn)', 'omniwp' ), count( $available_coupons ) ) : esc_html__( 'Xem kho voucher', 'omniwp' );
										?>
									</span>
									<span class="sl-arrow-right">›</span>
								</button>
							</div>
						</div>

						<div class="sl-summary-divider"></div>

						<!-- Tổng thanh toán (Sau khi đã trừ khuyến mãi) -->
						<div class="sl-summary-row sl-summary-total">
							<span class="sl-summary-total-label"><?php esc_html_e( 'Tổng thanh toán', 'omniwp' ); ?></span>
							<strong id="sl-inpage-total" class="sl-summary-total-val"><?php echo wp_kses_post( $cart['total_html'] ); ?></strong>
						</div>

						<!-- Primary CTA Button -->
						<div class="sl-checkout-cta-wrap">
							<a href="<?php echo esc_url( $cart['checkout_url'] ); ?>" class="sl-btn sl-btn--checkout sl-btn--block sl-btn--lg">
								<?php esc_html_e( 'Thanh toán ngay', 'omniwp' ); ?>
							</a>
						</div>


					</div>

				</div>

			</div>
		<?php endif; ?>
	</div>

	<!-- Smart Sticky Bottom Floating Checkout Bar (Mobile & Floating UX) -->
	<?php if ( ! $is_empty ) : ?>
		<div id="sl-sticky-checkout-bar" class="sl-sticky-checkout-bar" aria-hidden="true">
			<div class="sl-sticky-checkout-bar__inner">
				<div class="sl-sticky-checkout-bar__info">
					<span class="sl-sticky-checkout-bar__label"><?php esc_html_e( 'Tổng thanh toán', 'omniwp' ); ?></span>
					<strong class="sl-sticky-checkout-bar__total" id="sl-sticky-total"><?php echo wp_kses_post( $cart['total_html'] ); ?></strong>
				</div>
				<a href="<?php echo esc_url( $cart['checkout_url'] ); ?>" class="sl-btn sl-btn--checkout sl-sticky-checkout-bar__btn">
					<?php esc_html_e( 'Thanh toán ngay', 'omniwp' ); ?>
				</a>
			</div>
		</div>
	<?php endif; ?>

	<!-- In-Page Voucher Modal Drawer (Kho mã ưu đãi khả dụng cho Cart Page) -->
	<div id="sl-inpage-voucher-modal" class="sl-voucher-modal" aria-hidden="true">
		<div class="sl-voucher-modal__backdrop" id="sl-inpage-voucher-modal-backdrop"></div>
		<div class="sl-voucher-modal__dialog">
			<header class="sl-voucher-panel__header">
				<h3 class="sl-voucher-panel__title"><?php esc_html_e( 'Kho ưu đãi & Mã giảm giá', 'omniwp' ); ?></h3>
				<button type="button" class="sl-slide-cart__close" id="sl-inpage-voucher-modal-close" aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
					<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</header>

			<div class="sl-voucher-panel__body" id="sl-inpage-voucher-modal-list">
				<?php
				$available_coupons = $cart['available_coupons'] ?? array();
				$ow_mode           = 'cart';
				require __DIR__ . '/voucher-module.php';
				?>
			</div>

			<footer class="sl-voucher-panel__footer">
				<button type="button" id="sl-inpage-voucher-modal-done" class="sl-btn sl-btn--checkout sl-btn--block">
					<?php esc_html_e( 'Xong', 'omniwp' ); ?>
				</button>
			</footer>
		</div>
	</div>
</div>
