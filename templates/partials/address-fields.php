<?php
/**
 * Two-level address picker: Tỉnh/Thành phố → Phường/Xã.
 *
 * Renders plain <select> elements. assets/js/address.js upgrades them into
 * searchable comboboxes; the selects stay in the form and remain the value
 * that gets submitted, so nothing depends on JavaScript being present.
 *
 * Override at yourtheme/omniwp/partials/address-fields.php
 *
 * @var array $values       province_code, province_name, ward_code, ward_name, street
 * @var bool  $required
 * @var array $provinces    code => [name, short, type]
 * @var array $wards        code => [name, type]  (only for the selected province)
 *
 * @package OmniWP
 */

use OmniWP\Address\AddressFields;

defined( 'ABSPATH' ) || exit;

$ow_uid = wp_unique_id( 'sl-addr-' );
$ow_req = ! empty( $required );
?>
<div class="sl-address" data-sl-address>

	<div class="sl-field">
		<label class="sl-label" for="<?php echo esc_attr( $ow_uid ); ?>-province">
			<?php esc_html_e( 'Tỉnh/Thành phố', 'omniwp' ); ?>
			<?php
			if ( $ow_req ) :
				?>
				<span class="sl-required">*</span><?php endif; ?>
		</label>
		<select
			class="sl-input sl-address__province"
			id="<?php echo esc_attr( $ow_uid ); ?>-province"
			name="<?php echo esc_attr( AddressFields::FIELD_PROVINCE ); ?>"
			data-sl-province
			<?php echo $ow_req ? 'required' : ''; ?>
		>
			<option value=""><?php esc_html_e( '— Chọn Tỉnh/Thành phố —', 'omniwp' ); ?></option>
			<?php foreach ( $provinces as $ow_code => $ow_province ) : ?>
				<option value="<?php echo esc_attr( $ow_code ); ?>" <?php selected( $values['province_code'], (string) $ow_code ); ?>>
					<?php echo esc_html( $ow_province['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="sl-field">
		<label class="sl-label" for="<?php echo esc_attr( $ow_uid ); ?>-ward">
			<?php esc_html_e( 'Phường/Xã', 'omniwp' ); ?>
			<?php
			if ( $ow_req ) :
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
		$ow_ward_waiting = empty( $wards );
		?>
		<select
			class="sl-input sl-address__ward"
			id="<?php echo esc_attr( $ow_uid ); ?>-ward"
			name="<?php echo esc_attr( AddressFields::FIELD_WARD ); ?>"
			data-sl-ward
			<?php echo $ow_req ? 'required' : ''; ?>
			<?php echo $ow_ward_waiting ? 'disabled aria-describedby="' . esc_attr( $ow_uid ) . '-ward-hint"' : ''; ?>
		>
			<option value=""><?php esc_html_e( '— Chọn Phường/Xã —', 'omniwp' ); ?></option>
			<?php foreach ( $wards as $ow_code => $ow_ward ) : ?>
				<option value="<?php echo esc_attr( $ow_code ); ?>" <?php selected( $values['ward_code'], (string) $ow_code ); ?>>
					<?php echo esc_html( $ow_ward['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<?php if ( $ow_ward_waiting ) : ?>
			<p class="sl-hint" id="<?php echo esc_attr( $ow_uid ); ?>-ward-hint" data-sl-ward-hint>
				<?php esc_html_e( 'Chọn Tỉnh/Thành phố trước để hiện danh sách Phường/Xã.', 'omniwp' ); ?>
			</p>
		<?php endif; ?>

		<noscript>
			<p class="sl-hint">
				<?php esc_html_e( 'Trình duyệt đang tắt JavaScript: hãy chọn Tỉnh/Thành phố và bấm Cập nhật trước, sau đó danh sách Phường/Xã sẽ hiện ra để bạn chọn tiếp.', 'omniwp' ); ?>
			</p>
		</noscript>
	</div>

	<div class="sl-field">
		<label class="sl-label" for="<?php echo esc_attr( $ow_uid ); ?>-street">
			<?php esc_html_e( 'Số nhà, tên đường', 'omniwp' ); ?>
		</label>
		<?php
		/*
		 * The same example WooCommerce's own checkout shows for this field —
		 * WooAddress::relabel_default_fields() has set it there since Phase 5, and
		 * this copy of the field never got it. One field, two screens, one hint.
		 *
		 * An example, not a repeat of the label, which is the rule this project
		 * settled on: a placeholder says the *format* or gives an instance, and
		 * anything that restates the label above it is noise that disappears the
		 * moment somebody types.
		 */
		?>
		<input
			type="text"
			class="sl-input"
			id="<?php echo esc_attr( $ow_uid ); ?>-street"
			name="<?php echo esc_attr( AddressFields::FIELD_STREET ); ?>"
			value="<?php echo esc_attr( $values['street'] ); ?>"
			placeholder="<?php esc_attr_e( 'Ví dụ: 12 Trần Duy Hưng', 'omniwp' ); ?>"
			autocomplete="address-line1"
		/>
	</div>
</div>
