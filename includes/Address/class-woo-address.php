<?php
/**
 * WooCommerce address integration.
 *
 * Strategy: reuse Woo's own fields rather than invent new ones.
 *   billing_state -> province code   (keeps shipping zones working)
 *   billing_city  -> ward name       (keeps order emails and invoices right)
 *   _smartlogin_ward_code            -> exact ward reference
 *
 * Woo keeps ownership of the province field everywhere it renders one; only
 * the city field is converted into a ward picker.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Address;

use SmartLogin\Frontend\Assets;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class WooAddress {

	const ORDER_META_WARD = '_smartlogin_ward_code';

	public function register(): void {
		add_filter( 'woocommerce_states', array( $this, 'replace_states' ), 20, 1 );

		add_filter( 'woocommerce_get_country_locale', array( $this, 'filter_locale' ), 20, 1 );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'relabel_default_fields' ), 20, 1 );
		add_filter( 'woocommerce_billing_fields', array( $this, 'convert_city_field' ), 30, 1 );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'convert_city_field' ), 30, 1 );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'convert_checkout_fields' ), 30, 1 );

		// The ward <select> is keyed by code while Woo stores the display name,
		// so both value providers have to hand back the code instead.
		add_filter( 'woocommerce_my_account_edit_address_field_value', array( $this, 'address_field_value' ), 10, 3 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'checkout_field_value' ), 10, 2 );

		// The substitution has to happen on a filter with a return value.
		// woocommerce_after_checkout_validation is fired with do_action(), which
		// passes the array BY VALUE, so the assignment there was discarded and the
		// order stored the raw ward code (00076) instead of the name. And it runs
		// after get_posted_data() has already produced the array create_order()
		// uses, so mutating $_POST that late changes nothing either.
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'normalise_posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_order_ward' ), 10, 2 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'store_customer_ward' ), 5, 2 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Checkout and the address book render Woo's own markup, so the picker
	 * script has to be requested here rather than by a template.
	 */
	public function enqueue(): void {
		$needed = ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );

		if ( $needed ) {
			Assets::enqueue_address();
		}
	}

	/**
	 * Replace WooCommerce's built-in 63-province list with the 34 units in
	 * force since 2025-07-01.
	 */
	public function replace_states( $states ) {
		$provinces = AddressRepository::provinces();

		if ( ! $provinces ) {
			// No dataset installed — leave Woo's own list alone rather than
			// wiping the state dropdown entirely.
			return $states;
		}

		$vn = array();

		foreach ( $provinces as $code => $province ) {
			$vn[ (string) $code ] = $province['name'];
		}

		$states['VN'] = $vn;

		return $states;
	}

	/**
	 * Vietnamese addresses have no meaningful postcode for ecommerce; make the
	 * province and city fields required and optionally hide the postcode.
	 */
	public function filter_locale( $locale ) {
		$locale['VN'] = array_merge(
			$locale['VN'] ?? array(),
			array(
				'state' => array(
					'required' => true,
					'label'    => __( 'Tỉnh/Thành phố', 'smart-login' ),
				),
				'city'  => array(
					'required' => true,
					'label'    => __( 'Phường/Xã', 'smart-login' ),
				),
			)
		);

		return $locale;
	}

	/**
	 * Address book: hand the ward code back so the <select> preselects.
	 *
	 * @param mixed  $value
	 * @param string $key          e.g. billing_city
	 * @param string $load_address billing|shipping
	 * @return mixed
	 */
	public function address_field_value( $value, $key, $load_address ) {
		if ( ! in_array( $key, array( 'billing_city', 'shipping_city' ), true ) ) {
			return $value;
		}

		$code = $this->stored_ward_code( $load_address );

		return '' !== $code ? $code : $value;
	}

	/**
	 * Checkout: same substitution. Woo already prefers $_POST over this, so a
	 * failed validation keeps whatever the customer picked.
	 *
	 * @param mixed  $value
	 * @param string $key
	 * @return mixed
	 */
	public function checkout_field_value( $value, $key ) {
		if ( ! in_array( $key, array( 'billing_city', 'shipping_city' ), true ) ) {
			return $value;
		}

		$code = $this->stored_ward_code( 0 === strpos( $key, 'shipping' ) ? 'shipping' : 'billing' );

		return '' !== $code ? $code : $value;
	}

	private function stored_ward_code( string $load_address ): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		$meta = 'shipping' === $load_address
			? AddressFields::META_SHIPPING_WARD_CODE
			: AddressFields::META_WARD_CODE;

		return (string) get_user_meta( $user_id, $meta, true );
	}

	/**
	 * Relabel the shared address field definitions.
	 */
	public function relabel_default_fields( $fields ) {
		if ( isset( $fields['address_1'] ) ) {
			$fields['address_1']['label']       = __( 'Số nhà, tên đường', 'smart-login' );
			$fields['address_1']['placeholder'] = __( 'Ví dụ: 12 Trần Duy Hưng', 'smart-login' );
		}

		if ( isset( $fields['city'] ) ) {
			$fields['city']['label'] = __( 'Phường/Xã', 'smart-login' );
		}

		if ( isset( $fields['state'] ) ) {
			$fields['state']['label'] = __( 'Tỉnh/Thành phố', 'smart-login' );
		}

		return $fields;
	}

	/**
	 * Turn the free-text city input into a ward <select>.
	 *
	 * The option list is seeded server-side from whichever province is already
	 * selected, so a validation-failed reload keeps the chosen ward. JavaScript
	 * repopulates it whenever the province changes.
	 *
	 * @param array $fields
	 */
	public function convert_city_field( $fields ) {
		if ( ! AddressRepository::is_dataset_installed() ) {
			return $fields;
		}

		// Vietnamese ecommerce does not use postcodes; removing the field is
		// cleaner than leaving an optional box nobody fills in.
		if ( Settings::is_on( 'address.hide_postcode' ) ) {
			unset( $fields['billing_postcode'], $fields['shipping_postcode'], $fields['postcode'] );
		}

		foreach ( array( 'billing_city', 'shipping_city', 'city' ) as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			$prefix = ( 0 === strpos( $key, 'shipping' ) ) ? 'shipping' : 'billing';

			$fields[ $key ]['type']    = 'select';
			$fields[ $key ]['options'] = $this->ward_options( $this->current_province( $prefix ) );
			$fields[ $key ]['class']   = array_merge(
				(array) ( $fields[ $key ]['class'] ?? array() ),
				array( 'sl-woo-ward' )
			);
			// Deliberately NOT wc-enhanced-select: selectWoo would fight the
			// plugin's own combobox for the same element.
		}

		return $fields;
	}

	/**
	 * @param array $fields
	 */
	public function convert_checkout_fields( $fields ) {
		foreach ( array( 'billing', 'shipping' ) as $section ) {
			if ( isset( $fields[ $section ] ) ) {
				$fields[ $section ] = $this->convert_city_field( $fields[ $section ] );
			}
		}

		return $fields;
	}

	/**
	 * Ward options keyed by code, with the ward NAME as the label. Woo stores
	 * the option key, so `billing_city` ends up holding the code at POST time —
	 * validate_checkout() swaps in the display name before the order is saved.
	 *
	 * @return array<string,string>
	 */
	private function ward_options( string $province_code ): array {
		$options = array( '' => __( '— Chọn Phường/Xã —', 'smart-login' ) );

		foreach ( AddressRepository::wards( $province_code ) as $code => $ward ) {
			$options[ (string) $code ] = $ward['name'];
		}

		return $options;
	}

	/**
	 * Province currently in play: what was just posted, else the customer's
	 * saved one.
	 */
	private function current_province( string $prefix ): string {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only, for pre-filling a dropdown.
		if ( ! empty( $_POST[ $prefix . '_state' ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			return AddressRepository::province_code( sanitize_text_field( wp_unslash( $_POST[ $prefix . '_state' ] ) ) );
		}

		if ( function_exists( 'WC' ) && WC()->customer ) {
			return (string) ( 'shipping' === $prefix ? WC()->customer->get_shipping_state() : WC()->customer->get_billing_state() );
		}

		return '';
	}

	// -----------------------------------------------------------------
	// Validation and persistence
	// -----------------------------------------------------------------

	/**
	 * Replace each posted ward code with the official ward name.
	 *
	 * Runs on woocommerce_checkout_posted_data, inside get_posted_data(), so the
	 * returned array is the one create_order() goes on to use. The ward code is
	 * remembered for store_order_ward(), and a code that does not belong to the
	 * chosen province is recorded for validate_checkout() to report — this filter
	 * has no WP_Error to add to, and failing silently here would let a mismatched
	 * pair through.
	 *
	 * @param array $data
	 * @return array
	 */
	public function normalise_posted_data( $data ) {
		if ( ! is_array( $data ) || ! AddressRepository::is_dataset_installed() ) {
			return $data;
		}

		$this->posted_wards = array();
		$this->ward_errors  = array();

		foreach ( array( 'billing', 'shipping' ) as $prefix ) {
			$city = (string) ( $data[ $prefix . '_city' ] ?? '' );

			if ( '' === $city ) {
				continue;
			}

			$province = AddressRepository::province_code( (string) ( $data[ $prefix . '_state' ] ?? '' ) );
			$ward     = AddressRepository::ward_code( $city );

			if ( 'shipping' === $prefix && '' === $ward && '' === $province ) {
				continue;
			}

			if ( '' === $ward ) {
				// Not a ward code at all. Woo's own "required" check covers a blank,
				// and a free-text city is none of our business.
				continue;
			}

			$found = AddressRepository::find_ward( $ward, $province );

			if ( ! $found ) {
				$this->ward_errors[ $prefix ] = true;
				continue;
			}

			$data[ $prefix . '_city' ] = $found['name'];

			// Kept in sync for any third-party code still reading the raw request.
			$_POST[ $prefix . '_city' ] = $found['name']; // phpcs:ignore WordPress.Security.NonceVerification

			$this->posted_wards[ $prefix ] = $ward;
		}

		return $data;
	}

	/**
	 * Surface a ward/province mismatch detected while normalising.
	 *
	 * @param array     $data
	 * @param \WP_Error $errors
	 */
	public function validate_checkout( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $data is by-value and deliberately unused; see normalise_posted_data().
		if ( ! $this->ward_errors ) {
			return;
		}

		$errors->add(
			'smart_login_ward_mismatch',
			__( 'Phường/Xã không thuộc Tỉnh/Thành phố đã chọn. Vui lòng chọn lại.', 'smart-login' )
		);
	}

	/** @var array<string,string> Ward codes captured while normalising posted data. */
	private $posted_wards = array();

	/** @var array<string,bool> Prefixes whose ward did not match their province. */
	private $ward_errors = array();

	/**
	 * Persist the exact ward code on the order.
	 *
	 * @param \WC_Order $order
	 * @param array     $data
	 */
	public function store_order_ward( $order, $data ): void {
		if ( ! empty( $this->posted_wards['billing'] ) ) {
			$order->update_meta_data( self::ORDER_META_WARD, $this->posted_wards['billing'] );
		}

		if ( ! empty( $this->posted_wards['shipping'] ) ) {
			$order->update_meta_data( '_smartlogin_shipping_ward_code', $this->posted_wards['shipping'] );
		}
	}

	/**
	 * Address book save: the posted city is a ward code, so translate it to the
	 * official name and keep the code in user meta.
	 *
	 * Hooked at priority 5, before the existing phone sync at 10.
	 *
	 * @param int    $user_id
	 * @param string $load_address billing|shipping
	 */
	public function store_customer_ward( $user_id, $load_address ): void {
		if ( ! AddressRepository::is_dataset_installed() ) {
			return;
		}

		$province = (string) get_user_meta( $user_id, $load_address . '_state', true );
		$city     = (string) get_user_meta( $user_id, $load_address . '_city', true );

		// Woo has already saved whatever was posted; if it looks like a code,
		// resolve it. A name that is already a name simply will not match.
		if ( '' === $city || ! ctype_digit( $city ) ) {
			return;
		}

		$ward = AddressRepository::find_ward( $city, $province );

		if ( ! $ward ) {
			return;
		}

		$code = AddressRepository::ward_code( $city );

		update_user_meta( $user_id, $load_address . '_city', $ward['name'] );

		if ( 'billing' === $load_address ) {
			update_user_meta( $user_id, AddressFields::META_WARD_CODE, $code );
		} else {
			update_user_meta( $user_id, AddressFields::META_SHIPPING_WARD_CODE, $code );
		}
	}
}
