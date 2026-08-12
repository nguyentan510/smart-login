<?php
/**
 * Template for OmniWP Address Modals (Shopee-Style).
 *
 * Rendered in wp_footer (OUTSIDE any <form>) to avoid nested form HTML violation.
 * Contains:
 * 1. Address Picker Modal ("Địa Chỉ Của Tôi") with radio selection + edit links.
 * 2. Add/Edit Address Form Modal ("Thêm / Sửa địa chỉ nhận hàng").
 *
 * @package OmniWP
 * @var array $provinces List of 34 Vietnamese provinces.
 * @var array $addresses User saved addresses.
 */

use OmniWP\Address\AddressRepository;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$addresses = $addresses ?? array();
?>

<!-- Modal 1: Address Picker ("Địa Chỉ Của Tôi" - Shopee style) -->
<div id="sl-address-picker-modal" class="sl-address-modal" style="display:none;" aria-hidden="true" role="dialog">
	<div class="sl-address-modal__overlay" id="sl-picker-modal-overlay"></div>
	<div class="sl-address-modal__dialog sl-picker-dialog">
		<header class="sl-address-modal__header">
			<h3><?php esc_html_e( 'Địa Chỉ Của Tôi', 'omniwp' ); ?></h3>
			<button type="button" class="sl-address-modal__close" id="sl-picker-modal-close" aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</header>

		<div class="sl-picker-modal__body">
			<div class="sl-picker-address-list" id="sl-picker-address-list">
				<?php if ( ! empty( $addresses ) ) : ?>
					<?php foreach ( $addresses as $index => $addr ) : ?>
						<?php
						$is_selected = ! empty( $addr['is_default'] ) || 0 === $index;
						$first_name  = trim( (string) ( $addr['first_name'] ?? '' ) . ' ' . (string) ( $addr['last_name'] ?? '' ) );
						$phone       = (string) ( $addr['phone'] ?? '' );
						$address_1   = (string) ( $addr['address_1'] ?? '' );
						$state_code  = (string) ( $addr['state'] ?? $addr['city'] ?? '' );
						$city_code   = (string) ( $addr['city'] ?? '' );
						$ward_code   = (string) ( $addr['ward_code'] ?? $addr['ward'] ?? '' );
						$prov_code   = ! empty( $state_code ) ? $state_code : $city_code;

						$prov_name = (string) ( $addr['state_name'] ?? '' );
						if ( empty( $prov_name ) || is_numeric( $prov_name ) ) {
							$prov_name = AddressRepository::province_name( $prov_code ) ?: $prov_code;
						}

						$ward_name = (string) ( $addr['ward_name'] ?? '' );
						if ( empty( $ward_name ) || is_numeric( $ward_name ) ) {
							$ward_name = AddressRepository::ward_name( $ward_code, $prov_code ) ?: ( AddressRepository::ward_name( $ward_code, $city_code ) ?: '' );
						}

						if ( is_numeric( $ward_name ) || $ward_name === $prov_code ) {
							$ward_name = '';
						}
						if ( is_numeric( $prov_name ) ) {
							$prov_name = '';
						}

						$loc_parts = array();
						if ( '' !== $address_1 ) {
							$loc_parts[] = $address_1;
						}
						if ( '' !== $ward_name ) {
							$loc_parts[] = $ward_name;
						}
						if ( '' !== $prov_name ) {
							$loc_parts[] = $prov_name;
						}

						$full_loc = implode( ', ', array_unique( $loc_parts ) );

						$has_ward      = ! empty( $ward_name );
						$has_state     = ! empty( $prov_name );
						$has_address1  = ! empty( trim( $address_1 ) );
						$is_incomplete = ! $has_ward || ! $has_state || ! $has_address1;

						$addr_data = array_merge(
							$addr,
							array(
								'state_name'    => $prov_name,
								'ward_name'     => $ward_name,
								'is_incomplete' => $is_incomplete,
							)
						);
						?>
						<div class="sl-picker-item <?php echo $is_selected ? 'sl-picker-item--selected' : ''; ?>" data-address="<?php echo esc_attr( (string) wp_json_encode( $addr_data ) ); ?>">
							<input type="radio" name="sl_picker_address_radio" class="sl-picker-radio" <?php checked( $is_selected, true ); ?> />
							<div class="sl-picker-item__info">
								<div class="sl-picker-item__name-phone">
									<strong class="sl-picker-name"><?php echo esc_html( $first_name ); ?></strong>
									<?php if ( '' !== $phone ) : ?>
										<span class="sl-picker-phone">(<?php echo esc_html( $phone ); ?>)</span>
									<?php endif; ?>
								</div>
								<div class="sl-picker-item__address">
									<?php echo esc_html( $full_loc ?: $address_1 ); ?>
								</div>
								<div class="sl-picker-item__tags">
									<?php if ( ! empty( $addr['is_default'] ) ) : ?>
										<span class="sl-address-default-badge"><?php esc_html_e( 'Mặc Định', 'omniwp' ); ?></span>
									<?php endif; ?>
									<?php if ( $is_incomplete ) : ?>
										<span class="sl-address-warning-badge">⚠️ <?php esc_html_e( 'Thiếu Phường/Xã', 'omniwp' ); ?></span>
									<?php endif; ?>
								</div>
							</div>
							<button type="button" class="sl-picker-item__edit-btn sl-btn-edit-address" data-address="<?php echo esc_attr( (string) wp_json_encode( $addr_data ) ); ?>">
								<?php esc_html_e( 'Cập nhật', 'omniwp' ); ?>
							</button>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="sl-picker-empty">
						<p><?php esc_html_e( 'Bạn chưa có địa chỉ giao hàng nào được lưu.', 'omniwp' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<footer class="sl-picker-modal__footer">
			<button type="button" class="sl-btn sl-btn--primary sl-btn--block" id="sl-picker-btn-add-new">
				+ <?php esc_html_e( 'Thêm Địa Chỉ Mới', 'omniwp' ); ?>
			</button>
		</footer>
	</div>
</div>

<!-- Modal 2: Add/Edit Address Form (rendered in wp_footer, outside checkout <form>) -->
<div id="sl-address-modal" class="sl-address-modal" style="display:none;" aria-hidden="true" role="dialog">
	<div class="sl-address-modal__overlay" id="sl-address-modal-overlay"></div>
	<div class="sl-address-modal__dialog">
		<header class="sl-address-modal__header">
			<h3 id="sl-modal-title"><?php esc_html_e( 'Thêm địa chỉ nhận hàng mới', 'omniwp' ); ?></h3>
			<button type="button" class="sl-address-modal__close" id="sl-address-modal-close" aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</header>

		<form id="sl-address-modal-form" class="sl-address-modal__form">
			<input type="hidden" id="sl-modal-address-id" name="address_id" value="" />

			<div class="sl-address-modal__body">
				<div class="sl-form-row sl-form-row--split">
					<div class="sl-form-group">
						<label for="sl-modal-name"><?php esc_html_e( 'Họ và tên người nhận *', 'omniwp' ); ?></label>
						<input type="text" id="sl-modal-name" name="first_name" class="sl-input" required placeholder="<?php esc_attr_e( 'Nguyễn Văn An', 'omniwp' ); ?>" />
					</div>
					<div class="sl-form-group">
						<label for="sl-modal-phone"><?php esc_html_e( 'Số điện thoại *', 'omniwp' ); ?></label>
						<input type="tel" id="sl-modal-phone" name="phone" class="sl-input" required placeholder="<?php esc_attr_e( '0901234567', 'omniwp' ); ?>" />
					</div>
				</div>

				<div class="sl-form-row sl-form-row--split">
					<div class="sl-form-group">
						<label for="sl-modal-state"><?php esc_html_e( 'Tỉnh / Thành phố *', 'omniwp' ); ?></label>
						<select id="sl-modal-state" name="state" class="sl-select" required>
							<option value=""><?php esc_html_e( '-- Chọn Tỉnh / Thành --', 'omniwp' ); ?></option>
							<?php foreach ( $provinces as $code => $prov ) : ?>
								<option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( $prov['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sl-form-group">
						<label for="sl-modal-city"><?php esc_html_e( 'Phường / Xã *', 'omniwp' ); ?></label>
						<select id="sl-modal-city" name="city" class="sl-select" required disabled>
							<option value=""><?php esc_html_e( '-- Chọn Phường / Xã --', 'omniwp' ); ?></option>
						</select>
						<input type="hidden" id="sl-modal-ward-code" name="ward_code" value="" />
					</div>
				</div>

				<div class="sl-form-group">
					<label for="sl-modal-address"><?php esc_html_e( 'Địa chỉ chi tiết (Số nhà, tên đường, thôn/xóm) *', 'omniwp' ); ?></label>
					<input type="text" id="sl-modal-address" name="address_1" class="sl-input" required placeholder="<?php esc_attr_e( '123 Đường Lê Lợi', 'omniwp' ); ?>" />
				</div>

				<div class="sl-form-group sl-form-group--full">
					<label><?php esc_html_e( 'Loại địa chỉ', 'omniwp' ); ?></label>
					<div class="sl-tag-pills sl-tag-pills--full">
						<label class="sl-tag-pill">
							<input type="radio" name="tag" value="<?php esc_attr_e( 'Nhà riêng', 'omniwp' ); ?>" checked />
							<span>
								<?php echo IconSet::get( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php esc_html_e( 'Nhà riêng', 'omniwp' ); ?>
							</span>
						</label>
						<label class="sl-tag-pill">
							<input type="radio" name="tag" value="<?php esc_attr_e( 'Văn phòng', 'omniwp' ); ?>" />
							<span>
								<?php echo IconSet::get( 'briefcase' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php esc_html_e( 'Văn phòng', 'omniwp' ); ?>
							</span>
						</label>
					</div>
				</div>

				<div class="sl-form-group sl-form-group--full" style="margin-top: 12px;">
					<label class="sl-checkbox-label">
						<input type="checkbox" name="is_default" value="1" checked />
						<span><?php esc_html_e( 'Đặt làm địa chỉ mặc định', 'omniwp' ); ?></span>
					</label>
				</div>
			</div>

			<footer class="sl-address-modal__actions">
				<button type="button" class="sl-btn sl-btn--outline" id="sl-modal-cancel"><?php esc_html_e( 'Hủy', 'omniwp' ); ?></button>
				<button type="submit" class="sl-btn sl-btn--primary" id="sl-modal-submit"><?php esc_html_e( 'Lưu và Chọn địa chỉ này', 'omniwp' ); ?></button>
			</footer>
		</form>
	</div>
</div>
