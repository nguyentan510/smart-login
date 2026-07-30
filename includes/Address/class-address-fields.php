<?php
/**
 * The shared address field group: rendering, validation and persistence.
 *
 * Used by the profile form and the [smart_address] shortcode. WooCommerce's own
 * screens go through WooAddress instead, because Woo owns the markup there.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Address;

use SmartLogin\Frontend\Assets;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class AddressFields {

	const FIELD_PROVINCE = 'smartlogin_province_code';
	const FIELD_WARD     = 'smartlogin_ward_code';
	const FIELD_STREET   = 'smartlogin_address_1';

	/** Ward code is kept alongside Woo's name-based billing_city. */
	const META_WARD_CODE = 'smartlogin_ward_code';

	/**
	 * Current address of a user, in the shape the template expects.
	 *
	 * @return array{province_code:string,province_name:string,ward_code:string,ward_name:string,street:string}
	 */
	public static function get_for_user( int $user_id ): array {
		$province_code = (string) get_user_meta( $user_id, 'billing_state', true );
		$ward_code     = (string) get_user_meta( $user_id, self::META_WARD_CODE, true );

		// Names always come from the dataset, never from stored display text —
		// so a renamed unit corrects itself on the next page load.
		$province_name = AddressRepository::province_name( $province_code );
		$ward_name     = AddressRepository::ward_name( $ward_code, $province_code );

		return array(
			'province_code' => $province_code,
			'province_name' => $province_name,
			'ward_code'     => $ward_name ? $ward_code : '',
			'ward_name'     => $ward_name,
			'street'        => (string) get_user_meta( $user_id, 'billing_address_1', true ),
		);
	}

	/**
	 * Render the field group.
	 *
	 * @param array $args {
	 *     @type array $values   Output of get_for_user(), or empty.
	 *     @type bool  $required Mark province/ward as required.
	 * }
	 */
	public static function render( array $args = array() ): string {
		Assets::enqueue_address();

		$values = $args['values'] ?? array(
			'province_code' => '',
			'province_name' => '',
			'ward_code'     => '',
			'ward_name'     => '',
			'street'        => '',
		);

		return TemplateLoader::render(
			'partials/address-fields',
			array(
				'values'       => $values,
				'required'     => $args['required'] ?? true,
				'quick_search' => Settings::is_on( 'address_quick_search' ),
				'provinces'    => AddressRepository::provinces(),
				// Only the selected province's wards are needed for the initial
				// render; the rest arrive over REST when the user picks one.
				'wards'        => '' !== $values['province_code'] ? AddressRepository::wards( $values['province_code'] ) : array(),
			)
		);
	}

	public static function output( array $args = array() ): void {
		echo self::render( $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- template escapes its own output.
	}

	/**
	 * Validate a submitted address.
	 *
	 * Only the two codes are trusted. Names are looked up, never accepted from
	 * the request — this is what stops a tampered form from pairing a ward with
	 * a province it does not belong to.
	 *
	 * @param array $input     Raw request data (already unslashed).
	 * @param bool  $required  Whether an empty address is an error.
	 * @return array|WP_Error
	 */
	public static function validate( array $input, bool $required = false ) {
		// Canonicalise straight away so a code that lost its leading zero in
		// transit still matches, and so what gets stored is always padded.
		$province_code = AddressRepository::province_code( (string) ( $input[ self::FIELD_PROVINCE ] ?? '' ) );
		$ward_code     = AddressRepository::ward_code( (string) ( $input[ self::FIELD_WARD ] ?? '' ) );
		$street        = sanitize_text_field( (string) ( $input[ self::FIELD_STREET ] ?? '' ) );

		$empty = ( '' === $province_code && '' === $ward_code && '' === $street );

		if ( $empty ) {
			if ( $required ) {
				return new WP_Error( 'smart_login_address_required', __( 'Vui lòng chọn địa chỉ.', 'smart-login' ) );
			}

			return array(
				'province_code' => '',
				'province_name' => '',
				'ward_code'     => '',
				'ward_name'     => '',
				'street'        => '',
			);
		}

		if ( '' === $province_code ) {
			return new WP_Error( 'smart_login_no_province', __( 'Vui lòng chọn Tỉnh/Thành phố.', 'smart-login' ) );
		}

		$province = AddressRepository::find_province( $province_code );

		if ( ! $province ) {
			return new WP_Error( 'smart_login_bad_province', __( 'Tỉnh/Thành phố không hợp lệ.', 'smart-login' ) );
		}

		if ( '' === $ward_code ) {
			return new WP_Error( 'smart_login_no_ward', __( 'Vui lòng chọn Phường/Xã.', 'smart-login' ) );
		}

		$ward = AddressRepository::find_ward( $ward_code, $province_code );

		if ( ! $ward ) {
			return new WP_Error(
				'smart_login_ward_mismatch',
				__( 'Phường/Xã không thuộc Tỉnh/Thành phố đã chọn. Vui lòng chọn lại.', 'smart-login' )
			);
		}

		return array(
			'province_code' => $province_code,
			'province_name' => $province['name'],
			'ward_code'     => $ward_code,
			'ward_name'     => $ward['name'],
			'street'        => $street,
		);
	}

	/**
	 * Persist a validated address onto a user.
	 *
	 * @param array $clean Output of validate().
	 */
	public static function save_for_user( int $user_id, array $clean ): void {
		if ( '' === $clean['province_code'] ) {
			return;
		}

		// billing_state / billing_city are WooCommerce-native, so shipping
		// zones, order emails and invoices keep working untouched.
		update_user_meta( $user_id, 'billing_state', $clean['province_code'] );
		update_user_meta( $user_id, 'billing_city', $clean['ward_name'] );
		update_user_meta( $user_id, self::META_WARD_CODE, $clean['ward_code'] );
		update_user_meta( $user_id, 'billing_country', 'VN' );

		if ( '' !== $clean['street'] ) {
			update_user_meta( $user_id, 'billing_address_1', $clean['street'] );
		}

		/**
		 * @param int   $user_id
		 * @param array $clean
		 */
		do_action( 'smart_login_address_saved', $user_id, $clean );
	}

	/**
	 * One-line address for display: "12 Trần Duy Hưng, Phường Cầu Giấy, Hà Nội".
	 */
	public static function format( array $address ): string {
		$parts = array_filter(
			array(
				$address['street'] ?? '',
				$address['ward_name'] ?? '',
				$address['province_name'] ?? '',
			)
		);

		return implode( ', ', $parts );
	}

	/**
	 * Is the user's address complete? Used by the profile nudge.
	 */
	public static function is_complete( int $user_id ): bool {
		$address = self::get_for_user( $user_id );

		return '' !== $address['province_code'] && '' !== $address['ward_code'];
	}
}
