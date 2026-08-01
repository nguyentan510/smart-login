<?php
/**
 * Two-level address picker: Tỉnh/Thành phố → Phường/Xã.
 *
 * Renders plain <select> elements. assets/js/address.js upgrades them into
 * searchable comboboxes; the selects stay in the form and remain the value
 * that gets submitted, so nothing depends on JavaScript being present.
 *
 * Override at yourtheme/smart-login/partials/address-fields.php
 *
 * @var array $values       province_code, province_name, ward_code, ward_name, street
 * @var bool  $required
 * @var array $provinces    code => [name, short, type]
 * @var array $wards        code => [name, type]  (only for the selected province)
 *
 * @package SmartLogin
 */

use SmartLogin\Address\AddressFields;

defined( 'ABSPATH' ) || exit;

$sl_uid = wp_unique_id( 'sl-addr-' );
$sl_req = ! empty( $required );
?>
<div class="sl-address" data-sl-address>

	<div class="sl-field">
		<label class="sl-label" for="<?php echo esc_attr( $sl_uid ); ?>-province">
			<?php esc_html_e( 'Tỉnh/Thành phố', 'smart-login' ); ?>
			<?php
			if ( $sl_req ) :
				?>
				<span class="sl-required">*</span><?php endif; ?>
		</label>
		<select
			class="sl-input sl-address__province"
			id="<?php echo esc_attr( $sl_uid ); ?>-province"
			name="<?php echo esc_attr( AddressFields::FIELD_PROVINCE ); ?>"
			data-sl-province
			<?php echo $sl_req ? 'required' : ''; ?>
		>
			<option value=""><?php esc_html_e( '— Chọn Tỉnh/Thành phố —', 'smart-login' ); ?></option>
			<?php foreach ( $provinces as $sl_code => $sl_province ) : ?>
				<option value="<?php echo esc_attr( $sl_code ); ?>" <?php selected( $values['province_code'], (string) $sl_code ); ?>>
					<?php echo esc_html( $sl_province['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="sl-field">
		<label class="sl-label" for="<?php echo esc_attr( $sl_uid ); ?>-ward">
			<?php esc_html_e( 'Phường/Xã', 'smart-login' ); ?>
			<?php
			if ( $sl_req ) :
				?>
				<span class="sl-required">*</span><?php endif; ?>
		</label>
		<?php
		/*
		 * Inert until a province is chosen, because there is nothing to choose
		 * from until then — but a grey box that never says why reads as broken
		 * rather than as waiting. The hint is tied to the control through
		 * aria-describedby so it is not only a visual explanation.
		 */
		$sl_ward_waiting = empty( $wards );
		?>
		<select
			class="sl-input sl-address__ward"
			id="<?php echo esc_attr( $sl_uid ); ?>-ward"
			name="<?php echo esc_attr( AddressFields::FIELD_WARD ); ?>"
			data-sl-ward
			<?php echo $sl_req ? 'required' : ''; ?>
			<?php echo $sl_ward_waiting ? 'disabled aria-describedby="' . esc_attr( $sl_uid ) . '-ward-hint"' : ''; ?>
		>
			<option value=""><?php esc_html_e( '— Chọn Phường/Xã —', 'smart-login' ); ?></option>
			<?php foreach ( $wards as $sl_code => $sl_ward ) : ?>
				<option value="<?php echo esc_attr( $sl_code ); ?>" <?php selected( $values['ward_code'], (string) $sl_code ); ?>>
					<?php echo esc_html( $sl_ward['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<?php if ( $sl_ward_waiting ) : ?>
			<p class="sl-hint" id="<?php echo esc_attr( $sl_uid ); ?>-ward-hint" data-sl-ward-hint>
				<?php esc_html_e( 'Chọn Tỉnh/Thành phố trước để hiện danh sách Phường/Xã.', 'smart-login' ); ?>
			</p>
		<?php endif; ?>

		<noscript>
			<p class="sl-hint">
				<?php esc_html_e( 'Trình duyệt đang tắt JavaScript: hãy chọn Tỉnh/Thành phố và bấm Cập nhật trước, sau đó danh sách Phường/Xã sẽ hiện ra để bạn chọn tiếp.', 'smart-login' ); ?>
			</p>
		</noscript>
	</div>

	<div class="sl-field">
		<label class="sl-label" for="<?php echo esc_attr( $sl_uid ); ?>-street">
			<?php esc_html_e( 'Số nhà, tên đường', 'smart-login' ); ?>
		</label>
		<input
			type="text"
			class="sl-input"
			id="<?php echo esc_attr( $sl_uid ); ?>-street"
			name="<?php echo esc_attr( AddressFields::FIELD_STREET ); ?>"
			value="<?php echo esc_attr( $values['street'] ); ?>"
			autocomplete="address-line1"
		/>
	</div>
</div>
