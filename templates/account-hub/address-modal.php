<?php
/**
 * Address Modal Template — Sổ Địa Chỉ Thông Minh (Smart Address Book).
 *
 * Placed outside the main <form class="sl-form"> to avoid invalid HTML form nesting.
 *
 * @var \WP_User $user
 *
 * @package OmniWP
 */

use OmniWP\Address\AddressRepository;

defined( 'ABSPATH' ) || exit;

$provinces = AddressRepository::provinces();
?>

<!-- Address Form Modal Popup -->
<div class="sl-logout-modal-backdrop" data-sl-address-modal>
	<div class="sl-logout-modal sl-address-modal">
		<div class="sl-address-modal__header">
			<h3 class="sl-address-modal__title" data-sl-address-modal-title>
				<?php esc_html_e( 'Thêm địa chỉ mới', 'omniwp' ); ?>
			</h3>
		</div>

		<form class="sl-address-form" data-sl-address-form>
			<input type="hidden" name="id" data-sl-addr-id value="" />

			<!-- Address Tag Selector -->
			<div class="sl-address-form__field">
				<label class="sl-address-form__label"><?php esc_html_e( 'Loại địa chỉ:', 'omniwp' ); ?></label>
				<div class="sl-address-tag-pills">
					<label class="sl-radio-pill">
						<input type="radio" name="tag" value="Nhà riêng" checked />
						<span><?php esc_html_e( 'Nhà riêng', 'omniwp' ); ?></span>
					</label>
					<label class="sl-radio-pill">
						<input type="radio" name="tag" value="Văn phòng" />
						<span><?php esc_html_e( 'Văn phòng', 'omniwp' ); ?></span>
					</label>
					<label class="sl-radio-pill">
						<input type="radio" name="tag" value="Khác" />
						<span><?php esc_html_e( 'Khác', 'omniwp' ); ?></span>
					</label>
				</div>
			</div>

			<!-- Name & Phone -->
			<div class="sl-address-form__row">
				<div class="sl-address-form__field">
					<label class="sl-address-form__label"><?php esc_html_e( 'Họ và tên người nhận *', 'omniwp' ); ?></label>
					<input type="text" name="first_name" class="sl-input sl-address-form__input" required placeholder="<?php esc_attr_e( 'Họ và tên', 'omniwp' ); ?>" />
				</div>
				<div class="sl-address-form__field">
					<label class="sl-address-form__label"><?php esc_html_e( 'Số điện thoại *', 'omniwp' ); ?></label>
					<input type="tel" name="phone" class="sl-input sl-address-form__input" required placeholder="<?php esc_attr_e( 'Số điện thoại', 'omniwp' ); ?>" />
				</div>
			</div>

			<!-- Location Selectors (Province & Ward) -->
			<div class="sl-address-form__row">
				<div class="sl-address-form__field">
					<label class="sl-address-form__label"><?php esc_html_e( 'Tỉnh / Thành phố *', 'omniwp' ); ?></label>
					<select name="city" class="sl-select sl-address-form__select" data-sl-province-select required>
						<option value=""><?php esc_html_e( '-- Chọn Tỉnh / Thành --', 'omniwp' ); ?></option>
						<?php foreach ( $provinces as $p_code => $p_data ) : ?>
							<option value="<?php echo esc_attr( (string) $p_code ); ?>"><?php echo esc_html( (string) $p_data['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="sl-address-form__field">
					<label class="sl-address-form__label"><?php esc_html_e( 'Phường / Xã *', 'omniwp' ); ?></label>
					<select name="ward" class="sl-select sl-address-form__select" data-sl-ward-select required>
						<option value=""><?php esc_html_e( '-- Chọn Phường / Xã --', 'omniwp' ); ?></option>
					</select>
				</div>
			</div>

			<!-- Address Detail -->
			<div class="sl-address-form__field">
				<label class="sl-address-form__label"><?php esc_html_e( 'Địa chỉ cụ thể (Số nhà, tên đường) *', 'omniwp' ); ?></label>
				<input type="text" name="address_1" class="sl-input sl-address-form__input" required placeholder="<?php esc_attr_e( 'Ví dụ: 123 Đường Nguyễn Trãi', 'omniwp' ); ?>" />
			</div>

			<!-- Default Checkbox -->
			<label class="sl-address-checkbox-label">
				<input type="checkbox" name="is_default" value="1" class="sl-address-checkbox" />
				<span><?php esc_html_e( 'Đặt làm địa chỉ giao hàng mặc định', 'omniwp' ); ?></span>
			</label>

			<!-- Form Submit Actions -->
			<div class="sl-address-modal__actions">
				<button type="button" class="sl-btn sl-btn-modal-cancel" data-sl-address-modal-close>
					<?php esc_html_e( 'Hủy', 'omniwp' ); ?>
				</button>
				<button type="submit" class="sl-btn sl-btn--primary sl-btn-modal-submit">
					<?php esc_html_e( 'Lưu địa chỉ', 'omniwp' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>
