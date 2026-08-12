<?php
/**
 * Template for OmniWP Checkout Address Book Cards & Modal.
 *
 * @package OmniWP
 * @var array $addresses User saved addresses.
 * @var array $provinces List of 34 Vietnamese provinces.
 */

use OmniWP\Address\AddressRepository;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;
?>
<div class="sl-checkout-address-section">
	<div class="sl-address-section-header">
		<div class="sl-address-section-title">
			<span class="sl-address-icon"><?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<h3><?php esc_html_e( 'Địa chỉ nhận hàng (Sổ địa chỉ OmniWP)', 'omniwp' ); ?></h3>
		</div>
		<button type="button" class="sl-btn sl-btn--outline sl-btn--sm" id="sl-btn-open-address-modal">
			+ <?php esc_html_e( 'Thêm địa chỉ mới', 'omniwp' ); ?>
		</button>
	</div>

	<!-- Address Cards Grid -->
	<div class="sl-address-cards-grid" id="sl-address-cards-container">
		<?php if ( ! empty( $addresses ) ) : ?>
			<?php foreach ( $addresses as $index => $addr ) : ?>
				<?php
				$is_selected = ! empty( $addr['is_default'] ) || 0 === $index;
				$first_name  = trim( (string) ( $addr['first_name'] ?? '' ) . ' ' . (string) ( $addr['last_name'] ?? '' ) );
				$phone       = (string) ( $addr['phone'] ?? '' );
				$address_1   = (string) ( $addr['address_1'] ?? '' );
				$state_code  = (string) ( $addr['state'] ?? '' );
				$city_code   = (string) ( $addr['city'] ?? '' );
				$ward_code   = (string) ( $addr['ward_code'] ?? $addr['ward'] ?? '' );

				$prov_name = AddressRepository::province_name( $state_code ) ?: ( AddressRepository::province_name( $city_code ) ?: '' );
				$ward_name = AddressRepository::ward_name( $ward_code, $state_code ) ?: ( AddressRepository::ward_name( $city_code, $state_code ) ?: '' );

				if ( is_numeric( $ward_name ) || $ward_name === $state_code || $ward_name === $city_code ) {
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

				$addr_data = array_merge(
					$addr,
					array(
						'state_name' => $prov_name,
						'ward_name'  => $ward_name,
					)
				);
				?>
				<div class="sl-address-card <?php echo $is_selected ? 'sl-address-card--selected' : ''; ?>" data-address="<?php echo esc_attr( (string) wp_json_encode( $addr_data ) ); ?>">
					<div class="sl-address-card__header">
						<span class="sl-address-tag"><?php echo esc_html( $addr['tag'] ?? __( 'Nhà riêng', 'omniwp' ) ); ?></span>
						<?php if ( ! empty( $addr['is_default'] ) ) : ?>
							<span class="sl-address-default-badge"><?php esc_html_e( 'Mặc định', 'omniwp' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="sl-address-card__name">
						<strong><?php echo esc_html( $first_name ); ?></strong>
						<?php if ( '' !== $phone ) : ?>
							<span class="sl-address-phone"><?php echo esc_html( $phone ); ?></span>
						<?php endif; ?>
					</div>
					<div class="sl-address-card__full">
						<?php echo esc_html( $full_loc ?: $address_1 ); ?>
					</div>
					<div class="sl-address-card__check">
						<?php echo IconSet::get( 'check-simple' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="sl-address-card sl-address-card--add-first" id="sl-card-add-first">
				<span class="sl-add-icon">➕</span>
				<strong><?php esc_html_e( 'Chưa có địa chỉ nào được lưu', 'omniwp' ); ?></strong>
				<p><?php esc_html_e( 'Bấm để thêm địa chỉ giao hàng đầu tiên', 'omniwp' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</div>

<!-- Modal: Add New Address (Hidden by default) -->
<div id="sl-address-modal" class="sl-address-modal" style="display:none;" aria-hidden="true" role="dialog">
	<div class="sl-address-modal__overlay" id="sl-address-modal-overlay"></div>
	<div class="sl-address-modal__dialog">
		<header class="sl-address-modal__header">
			<h3><?php esc_html_e( 'Thêm địa chỉ nhận hàng mới', 'omniwp' ); ?></h3>
			<button type="button" class="sl-address-modal__close" id="sl-address-modal-close" aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</header>

		<form id="sl-address-modal-form" class="sl-address-modal__form">
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

			<div class="sl-form-row sl-form-row--split">
				<div class="sl-form-group">
					<label><?php esc_html_e( 'Loại địa chỉ', 'omniwp' ); ?></label>
					<div class="sl-tag-pills">
						<label class="sl-tag-pill">
							<input type="radio" name="tag" value="<?php esc_attr_e( 'Nhà riêng', 'omniwp' ); ?>" checked />
							<span>🏠 <?php esc_html_e( 'Nhà riêng', 'omniwp' ); ?></span>
						</label>
						<label class="sl-tag-pill">
							<input type="radio" name="tag" value="<?php esc_attr_e( 'Văn phòng', 'omniwp' ); ?>" />
							<span>🏢 <?php esc_html_e( 'Văn phòng', 'omniwp' ); ?></span>
						</label>
					</div>
				</div>
				<div class="sl-form-group sl-form-group--center">
					<label class="sl-checkbox-label">
						<input type="checkbox" name="is_default" value="1" checked />
						<span><?php esc_html_e( 'Đặt làm địa chỉ mặc định', 'omniwp' ); ?></span>
					</label>
				</div>
			</div>

			<div class="sl-address-modal__actions">
				<button type="button" class="sl-btn sl-btn--outline" id="sl-modal-cancel"><?php esc_html_e( 'Hủy', 'omniwp' ); ?></button>
				<button type="submit" class="sl-btn sl-btn--primary" id="sl-modal-submit"><?php esc_html_e( 'Lưu và Chọn địa chỉ này', 'omniwp' ); ?></button>
			</div>
		</form>
	</div>
</div>
