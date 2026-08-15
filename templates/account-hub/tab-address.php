<?php
/**
 * Address Tab Template — Sổ Địa Chỉ Thông Minh (Smart Address Book).
 *
 * Uses unified OmniWP address modal component (#sl-address-modal).
 *
 * @var \WP_User $user
 * @var array    $tab
 *
 * @package OmniWP
 */

use OmniWP\Address\AddressBook;
use OmniWP\Address\AddressRepository;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$addresses = AddressBook::get_addresses( $user->ID );
$provinces = AddressRepository::provinces();
?>

<!-- Address Header with Compact Add Button -->
<div class="sl-hub-header sl-hub-header--address">
	<div class="sl-hub-header__meta">
		<h2 class="sl-hub-title">
			<span class="sl-hub-title__icon"><?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span><?php esc_html_e( 'Sổ địa chỉ nhận hàng', 'omniwp' ); ?></span>
		</h2>
		<p class="sl-hub-subtitle"><?php esc_html_e( 'Quản lý danh sách địa chỉ nhận hàng của bạn.', 'omniwp' ); ?></p>
	</div>

	<button type="button" class="sl-btn sl-btn--primary sl-btn-add-address" id="sl-btn-open-address-modal">
		<?php echo IconSet::get( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span><?php esc_html_e( 'Thêm địa chỉ mới', 'omniwp' ); ?></span>
	</button>
</div>

<!-- Address Cards Grid List -->
<div class="sl-address-grid" id="sl-account-address-grid">
	<?php if ( ! empty( $addresses ) ) : ?>
		<?php foreach ( $addresses as $addr ) : ?>
			<?php
			$addr_id    = (string) ( $addr['id'] ?? '' );
			$addr_tag   = (string) ( $addr['tag'] ?? 'Nhà riêng' );
			$is_default = ! empty( $addr['is_default'] );
			$name       = trim( (string) ( $addr['first_name'] ?? '' ) . ' ' . (string) ( $addr['last_name'] ?? '' ) );
			$phone      = (string) ( $addr['phone'] ?? '' );
			$address_1  = (string) ( $addr['address_1'] ?? '' );
			$state_code = (string) ( $addr['state'] ?? $addr['city'] ?? '' );
			$city_code  = (string) ( $addr['city'] ?? '' );
			$ward_code  = (string) ( $addr['ward_code'] ?? $addr['ward'] ?? '' );
			$prov_code  = ! empty( $state_code ) ? $state_code : $city_code;

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

			$full_loc = implode(
				', ',
				array_filter(
					array(
						$address_1,
						$ward_name,
						$prov_name,
					)
				)
			);

			$has_ward      = ! empty( $ward_name );
			$has_state     = ! empty( $prov_name );
			$has_address1  = ! empty( trim( $address_1 ) );
			$is_incomplete = ! $has_ward || ! $has_state || ! $has_address1;

			$addr_data = array_merge(
				$addr,
				array(
					'state'         => $state_code ?: $city_code,
					'city'          => $city_code,
					'ward_code'     => $ward_code,
					'state_name'    => $prov_name,
					'ward_name'     => $ward_name,
					'is_incomplete' => $is_incomplete,
				)
			);
			?>
			<div class="sl-address-card<?php echo $is_default ? ' is-default' : ''; ?>" data-address-id="<?php echo esc_attr( $addr_id ); ?>">
				<div class="sl-address-card__info">
					<div class="sl-address-card__head">
						<h4 class="sl-address-card__name">
							<?php echo esc_html( mb_strtoupper( $name, 'UTF-8' ) ); ?>
						</h4>
						<?php if ( $is_default ) : ?>
							<span class="sl-address-badge sl-address-badge--default">
								<?php echo IconSet::get( 'check-simple' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php esc_html_e( 'Địa chỉ mặc định', 'omniwp' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( ! empty( $addr_tag ) && 'Nhà riêng' !== $addr_tag ) : ?>
							<span class="sl-address-badge sl-address-badge--tag"><?php echo esc_html( $addr_tag ); ?></span>
						<?php endif; ?>
						<?php if ( $is_incomplete ) : ?>
							<span class="sl-address-warning-badge">⚠️ <?php esc_html_e( 'Thiếu Phường/Xã', 'omniwp' ); ?></span>
						<?php endif; ?>
					</div>

					<div class="sl-address-card__meta">
						<div class="sl-address-card__line">
							<span class="sl-address-card__label"><?php esc_html_e( 'Địa chỉ:', 'omniwp' ); ?></span>
							<span class="sl-address-card__value"><?php echo esc_html( $full_loc ?: $address_1 ); ?></span>
						</div>
						<?php if ( '' !== $phone ) : ?>
							<div class="sl-address-card__line">
								<span class="sl-address-card__label"><?php esc_html_e( 'Điện thoại:', 'omniwp' ); ?></span>
								<span class="sl-address-card__value"><?php echo esc_html( $phone ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="sl-address-card__actions">
					<?php if ( ! $is_default ) : ?>
						<button type="button" class="sl-btn sl-btn--ghost sl-btn--sm sl-btn-set-default" data-address-id="<?php echo esc_attr( $addr_id ); ?>">
							<?php esc_html_e( 'Đặt mặc định', 'omniwp' ); ?>
						</button>
					<?php endif; ?>
					<button type="button" class="sl-btn sl-btn--ghost sl-btn--sm sl-btn-edit-address" data-address="<?php echo esc_attr( (string) wp_json_encode( $addr_data ) ); ?>">
						<?php esc_html_e( 'Chỉnh sửa', 'omniwp' ); ?>
					</button>
					<?php if ( ! $is_default ) : ?>
						<button type="button" class="sl-btn sl-btn--ghost sl-btn--sm sl-btn-delete-address" style="color:#dc2626;" data-address-id="<?php echo esc_attr( $addr_id ); ?>">
							<?php esc_html_e( 'Xóa', 'omniwp' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php else : ?>
		<div style="text-align:center; padding: 48px 16px; color:#64748b;">
			<div style="margin-bottom:12px;">
				<?php echo IconSet::get( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<p style="margin:0; font-weight:500;"><?php esc_html_e( 'Bạn chưa có địa chỉ giao hàng nào trong sổ địa chỉ.', 'omniwp' ); ?></p>
		</div>
	<?php endif; ?>
</div>
