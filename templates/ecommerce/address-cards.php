<?php
/**
 * Template for OmniWP Checkout Address Summary Line (Shopee-Style).
 *
 * Rendered inside the checkout <form> via woocommerce_before_checkout_billing_form.
 * Displays Shopee-style single-line address summary with a "Thay Đổi" button that opens the Address Picker Modal.
 * Detects incomplete address (missing Ward / Province) and shows warning badge + "Cập Nhật Ngay" link.
 *
 * @package OmniWP
 * @var array $addresses User saved addresses.
 */

use OmniWP\Address\AddressRepository;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$selected_addr = null;
if ( ! empty( $addresses ) ) {
	foreach ( $addresses as $addr ) {
		if ( ! empty( $addr['is_default'] ) ) {
			$selected_addr = $addr;
			break;
		}
	}
	if ( null === $selected_addr ) {
		$selected_addr = $addresses[0];
	}
}

if ( $selected_addr ) :
	$first_name = trim( (string) ( $selected_addr['first_name'] ?? '' ) . ' ' . (string) ( $selected_addr['last_name'] ?? '' ) );
	$phone      = (string) ( $selected_addr['phone'] ?? '' );
	$address_1  = (string) ( $selected_addr['address_1'] ?? '' );
	$state_code = (string) ( $selected_addr['state'] ?? $selected_addr['city'] ?? '' );
	$city_code  = (string) ( $selected_addr['city'] ?? '' );
	$ward_code  = (string) ( $selected_addr['ward_code'] ?? $selected_addr['ward'] ?? '' );
	$prov_code  = ! empty( $state_code ) ? $state_code : $city_code;

	$prov_name = (string) ( $selected_addr['state_name'] ?? '' );
	if ( empty( $prov_name ) || is_numeric( $prov_name ) ) {
		$prov_name = AddressRepository::province_name( $prov_code ) ?: $prov_code;
	}

	$ward_name = (string) ( $selected_addr['ward_name'] ?? '' );
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
		$selected_addr,
		array(
			'state_name'    => $prov_name,
			'ward_name'     => $ward_name,
			'is_incomplete' => $is_incomplete,
		)
	);
	?>
	<div class="sl-co-address-single-line <?php echo $is_incomplete ? 'sl-co-address-single-line--incomplete' : ''; ?>" id="sl-co-selected-address-summary" data-address="<?php echo esc_attr( (string) wp_json_encode( $addr_data ) ); ?>">
		<div class="sl-co-address-single-line__content">
			<div class="sl-co-address-details">
				<div class="sl-co-address-detail-item">
					<span class="sl-co-address-icon" title="<?php esc_attr_e( 'Họ & tên', 'omniwp' ); ?>"><?php echo IconSet::get( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<strong class="sl-co-address-value" id="sl-summary-name"><?php echo esc_html( $first_name ); ?></strong>
					<span class="sl-address-default-badge" id="sl-summary-default-badge" style="<?php echo empty( $selected_addr['is_default'] ) ? 'display:none;' : ''; ?>">
						<?php esc_html_e( 'Mặc Định', 'omniwp' ); ?>
					</span>
					<span class="sl-address-warning-badge" id="sl-summary-warning-badge" style="<?php echo $is_incomplete ? 'display:inline-flex;' : 'display:none;'; ?>">
						⚠️ <?php esc_html_e( 'Thiếu Phường/Xã', 'omniwp' ); ?>
					</span>
				</div>
				<div class="sl-co-address-detail-item" id="sl-summary-phone-wrap" style="<?php echo '' !== $phone ? '' : 'display:none;'; ?>">
					<span class="sl-co-address-icon" title="<?php esc_attr_e( 'Số điện thoại', 'omniwp' ); ?>"><?php echo IconSet::get( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="sl-co-address-value" id="sl-summary-phone"><?php echo esc_html( $phone ); ?></span>
				</div>
				<div class="sl-co-address-detail-item">
					<span class="sl-co-address-icon" title="<?php esc_attr_e( 'Địa chỉ', 'omniwp' ); ?>"><?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="sl-co-address-value" id="sl-summary-full-loc"><?php echo esc_html( $full_loc ?: $address_1 ); ?></span>
				</div>
			</div>
		</div>

		<button type="button" class="sl-btn-link sl-btn-change-address <?php echo $is_incomplete ? 'sl-btn-change-address--incomplete' : ''; ?>" id="sl-btn-open-address-picker">
			<?php echo $is_incomplete ? esc_html__( 'Cập Nhật Ngay', 'omniwp' ) . ' &gt;' : esc_html__( 'Thay Đổi', 'omniwp' ) . ' &gt;'; ?>
		</button>
	</div>
<?php else : ?>
	<div class="sl-co-address-empty-card" id="sl-co-address-empty-card">
		<div class="sl-co-address-empty-card__icon-wrap">
			<span class="sl-co-address-empty-card__icon">
				<?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		</div>
		<div class="sl-co-address-empty-card__content">
			<div class="sl-co-address-empty-card__head">
				<strong class="sl-co-address-empty-card__title">
					<?php esc_html_e( 'Bạn chưa có địa chỉ nhận hàng', 'omniwp' ); ?>
				</strong>
				<span class="sl-co-address-empty-card__badge">
					<?php esc_html_e( 'Cần thiết cho đơn hàng', 'omniwp' ); ?>
				</span>
			</div>
			<p class="sl-co-address-empty-card__desc">
				<?php esc_html_e( 'Vui lòng thêm địa chỉ nhận hàng để hệ thống tính phí vận chuyển và giao hàng nhanh chóng, chính xác đến bạn.', 'omniwp' ); ?>
			</p>
		</div>
		<div class="sl-co-address-empty-card__action">
			<button type="button" class="sl-btn sl-btn--primary sl-btn-add-first-address" id="sl-btn-open-address-modal">
				<?php echo IconSet::get( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( '+ Thêm Địa Chỉ Nhận Hàng', 'omniwp' ); ?></span>
			</button>
		</div>
	</div>
<?php endif; ?>
