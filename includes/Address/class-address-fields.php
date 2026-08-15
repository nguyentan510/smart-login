<?php
/**
 * The shared address field group: rendering, validation and persistence.
 *
 * Used by the profile form and the [smart_address] shortcode. WooCommerce's own
 * screens go through WooAddress instead, because Woo owns the markup there.
 *
 * @package OmniWP
 */

namespace OmniWP\Address;

use OmniWP\Frontend\Assets;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Identity\ProfileSeeder;
use OmniWP\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class AddressFields {

	const FIELD_PROVINCE = 'OmniWP_province_code';
	const FIELD_WARD     = 'OmniWP_ward_code';
	const FIELD_STREET   = 'OmniWP_address_1';

	/** Ward code is kept alongside Woo's name-based billing_city. */
	const META_WARD_CODE = 'OmniWP_ward_code';

	/**
	 * The same, for the shipping side.
	 *
	 * The key is not new — WooAddress has read and written it since Phase 5 for
	 * checkout's shipping fields. Naming it here in 17.4 gives the two halves one
	 * owner, rather than a constant on one side and a string literal on the other.
	 */
	const META_SHIPPING_WARD_CODE = 'OmniWP_shipping_ward_code';

	/**
	 * Current address of a user, in the shape the template expects.
	 *
	 * @return array{province_code:string,province_name:string,ward_code:string,ward_name:string,street:string}
	 */
	public static function get_for_user( int $user_id ): array {
		$province_code = (string) get_user_meta( $user_id, 'billing_state', true );
		$ward_code     = (string) get_user_meta( $user_id, self::META_WARD_CODE, true );
		$street        = (string) get_user_meta( $user_id, 'billing_address_1', true );

		// Names always come from the dataset, never from stored display text —
		// so a renamed unit corrects itself on the next page load.
		$province_name = AddressRepository::province_name( $province_code );
		$ward_name     = AddressRepository::ward_name( $ward_code, $province_code );

		return array(
			'province_code' => $province_code,
			'province_name' => $province_name,
			'ward_code'     => $ward_name ? $ward_code : '',
			'ward_name'     => $ward_name,
			'street'        => $street,
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
				'values'    => $values,
				'required'  => $args['required'] ?? true,
				'provinces' => AddressRepository::provinces(),
				// Only the selected province's wards are needed for the initial
				// render; the rest arrive over REST when the user picks one.
				'wards'     => '' !== $values['province_code'] ? AddressRepository::wards( $values['province_code'] ) : array(),
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
				return new WP_Error( 'OMNIWP_address_required', __( 'Vui lòng chọn địa chỉ.', 'omniwp' ) );
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
			return new WP_Error( 'OMNIWP_no_province', __( 'Vui lòng chọn Tỉnh/Thành phố.', 'omniwp' ) );
		}

		$province = AddressRepository::find_province( $province_code );

		if ( ! $province ) {
			return new WP_Error( 'OMNIWP_bad_province', __( 'Tỉnh/Thành phố không hợp lệ.', 'omniwp' ) );
		}

		if ( '' === $ward_code ) {
			return new WP_Error( 'OMNIWP_no_ward', __( 'Vui lòng chọn Phường/Xã.', 'omniwp' ) );
		}

		$ward = AddressRepository::find_ward( $ward_code, $province_code );

		if ( ! $ward ) {
			return new WP_Error(
				'OMNIWP_ward_mismatch',
				__( 'Phường/Xã không thuộc Tỉnh/Thành phố đã chọn. Vui lòng chọn lại.', 'omniwp' )
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
	 * **Both of WooCommerce's address books, since 17.4.** The card that collects
	 * this is headed "Địa chỉ nhận hàng" and until then it wrote `billing_*` and
	 * nothing else — so for any customer who had ever saved a separate shipping
	 * address, the heading named an address the form did not touch.
	 *
	 * The decision is one address, mirrored, and it has a cost that is written
	 * down in docs/account-card.md rather than discovered later: a customer who
	 * deliberately keeps a different delivery address loses it the next time they
	 * save this form. `set_from_user_input()` is already the right semantic for
	 * that — they just typed it.
	 *
	 * Billing stays the only side that is *read*. `get_for_user()` and
	 * `is_complete()` are unchanged, so the mirror cannot become a second source
	 * of truth that disagrees with the first.
	 *
	 * @param int   $user_id
	 * @param array $clean   Output of validate().
	 */
	public static function save_for_user( int $user_id, array $clean ): void {
		if ( '' === $clean['province_code'] ) {
			return;
		}

		// billing_state / billing_city are WooCommerce-native, so shipping
		// zones, order emails and invoices keep working untouched.
		//
		// set_from_user_input(), not seed_if_empty(): the customer just picked
		// these on their own address form, so their choice overwrites whatever was
		// there. That is the other half of Invariant 2 — profile data belongs to
		// the customer, and identity never gets a veto over it.
		$fields = array(
			'state'   => $clean['province_code'],
			'city'    => $clean['ward_name'],
			'country' => 'VN',
		);

		$pairs = array();

		foreach ( array( 'billing', 'shipping' ) as $prefix ) {
			foreach ( $fields as $field => $value ) {
				$pairs[ $prefix . '_' . $field ] = $value;
			}

			if ( '' !== $clean['street'] ) {
				$pairs[ $prefix . '_address_1' ] = $clean['street'];
			}
		}

		ProfileSeeder::set_many_from_user_input( $user_id, $pairs );

		// The ward code is identity-adjacent bookkeeping, not a Woo profile field,
		// so it does not go through the seeder. Two keys because WooAddress reads
		// a different one per prefix — see stored_ward_code().
		update_user_meta( $user_id, self::META_WARD_CODE, $clean['ward_code'] );
		update_user_meta( $user_id, self::META_SHIPPING_WARD_CODE, $clean['ward_code'] );

		// Also update or seed the default entry in AddressBook (_OMNIWP_address_book)
		// so Checkout and Account Hub Sổ địa chỉ are instantly in sync!
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		$first_name = (string) get_user_meta( $user_id, 'shipping_first_name', true ) ?: (string) get_user_meta( $user_id, 'billing_first_name', true );
		if ( empty( $first_name ) && $user ) {
			$first_name = $user->display_name ?: trim( ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? '' ) );
		}
		$phone = (string) get_user_meta( $user_id, 'shipping_phone', true ) ?: (string) get_user_meta( $user_id, 'billing_phone', true );

		AddressBook::save_address(
			$user_id,
			array(
				'id'         => 'addr_default',
				'tag'        => 'Nhà riêng',
				'is_default' => true,
				'first_name' => $first_name,
				'phone'      => $phone,
				'address_1'  => $clean['street'] ?? '',
				'city'       => $clean['province_code'],
				'state'      => $clean['province_code'],
				'ward'       => $clean['ward_code'],
				'ward_code'  => $clean['ward_code'],
				'state_name' => $clean['province_name'] ?? '',
				'ward_name'  => $clean['ward_name'] ?? '',
				'country'    => 'VN',
			)
		);

		/**
		 * @param int   $user_id
		 * @param array $clean
		 */
		do_action( 'OMNIWP_address_saved', $user_id, $clean );
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
