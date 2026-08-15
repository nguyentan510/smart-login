<?php
/**
 * Smart Address Book — Multiple Shipping Addresses Manager.
 *
 * Stores user address book in `_OMNIWP_address_book` usermeta
 * and syncs default address 2-way with WooCommerce native `shipping_*`.
 *
 * @package OmniWP
 */

namespace OmniWP\Address;

use WP_User;

defined( 'ABSPATH' ) || exit;

class AddressBook {

	const META_KEY = '_OMNIWP_address_book';

	/**
	 * Clean corrupted unicode strings (e.g. "Phu01b0u1eddng" -> "Phường", "u00e0" -> "à").
	 *
	 * @param string $str
	 * @return string
	 */
	public static function clean_unicode( string $str ): string {
		$str = trim( $str );
		if ( '' === $str ) {
			return '';
		}

		// Replace any \uXXXX or uXXXX with its actual UTF-8 character directly
		$cleaned = preg_replace_callback(
			'/(?:\\\\u|u)([0-9a-fA-F]{4})/',
			function ( $matches ) {
				return mb_convert_encoding( pack( 'H*', $matches[1] ), 'UTF-8', 'UCS-2BE' );
			},
			$str
		);

		return is_string( $cleaned ) ? $cleaned : $str;
	}

	/**
	 * Get all addresses for a user.
	 *
	 * @param int $user_id
	 * @return array<int, array>
	 */
	public static function get_addresses( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );

		$list = array();
		if ( is_array( $raw ) ) {
			$list = array_values( $raw );
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$list = array_values( $decoded );
			}
		}

		if ( empty( $list ) ) {
			$default = self::get_default_from_woo( $user_id );
			if ( ! empty( $default['address_1'] ) || ! empty( $default['first_name'] ) ) {
				$list = array( $default );
				self::save_addresses( $user_id, $list );
			}
		}

		// Sanitize all unicode strings on retrieval
		foreach ( $list as &$item ) {
			if ( is_array( $item ) ) {
				$item['tag']        = self::clean_unicode( (string) ( $item['tag'] ?? 'Nhà riêng' ) );
				$item['first_name'] = self::clean_unicode( (string) ( $item['first_name'] ?? '' ) );
				$item['last_name']  = self::clean_unicode( (string) ( $item['last_name'] ?? '' ) );
				$item['phone']      = sanitize_text_field( (string) ( $item['phone'] ?? '' ) );
				$item['address_1']  = self::clean_unicode( (string) ( $item['address_1'] ?? '' ) );
				$item['city']       = self::clean_unicode( (string) ( $item['city'] ?? '' ) );
				$item['district']   = self::clean_unicode( (string) ( $item['district'] ?? '' ) );
				$item['ward']       = self::clean_unicode( (string) ( $item['ward'] ?? '' ) );

				// If city or ward is text rather than numeric code, resolve to code
				if ( ! is_numeric( $item['city'] ) && '' !== $item['city'] ) {
					$p_code = AddressRepository::find_province_code_by_name( $item['city'] );
					if ( '' !== $p_code ) {
						$item['city'] = $p_code;
					}
				}
				if ( ! is_numeric( $item['ward'] ) && '' !== $item['ward'] && '' !== $item['city'] ) {
					$w_code = AddressRepository::find_ward_code_by_name( $item['ward'], $item['city'] );
					if ( '' !== $w_code ) {
						$item['ward'] = $w_code;
					}
				}
			}
		}

		return $list;
	}

	/**
	 * Save addresses array to usermeta.
	 *
	 * @param int   $user_id
	 * @param array $addresses
	 * @return bool
	 */
	public static function save_addresses( int $user_id, array $addresses ): bool {
		$clean       = array();
		$has_default = false;

		foreach ( $addresses as $addr ) {
			if ( ! is_array( $addr ) ) {
				continue;
			}
			$is_def = ! empty( $addr['is_default'] );
			if ( $is_def ) {
				$has_default = true;
			}

			$clean[] = array(
				'id'         => (string) ( $addr['id'] ?? 'addr_' . wp_generate_password( 8, false ) ),
				'tag'        => self::clean_unicode( sanitize_text_field( (string) ( $addr['tag'] ?? 'Nhà riêng' ) ) ),
				'is_default' => $is_def,
				'first_name' => self::clean_unicode( sanitize_text_field( (string) ( $addr['first_name'] ?? '' ) ) ),
				'last_name'  => self::clean_unicode( sanitize_text_field( (string) ( $addr['last_name'] ?? '' ) ) ),
				'phone'      => sanitize_text_field( (string) ( $addr['phone'] ?? '' ) ),
				'address_1'  => self::clean_unicode( sanitize_text_field( (string) ( $addr['address_1'] ?? '' ) ) ),
				'city'       => sanitize_text_field( (string) ( $addr['city'] ?? '' ) ),
				'district'   => sanitize_text_field( (string) ( $addr['district'] ?? '' ) ),
				'ward'       => sanitize_text_field( (string) ( $addr['ward'] ?? '' ) ),
				'state_name' => self::clean_unicode( sanitize_text_field( (string) ( $addr['state_name'] ?? '' ) ) ),
				'ward_name'  => self::clean_unicode( sanitize_text_field( (string) ( $addr['ward_name'] ?? '' ) ) ),
				'country'    => sanitize_text_field( (string) ( $addr['country'] ?? 'VN' ) ),
			);
		}

		if ( ! $has_default && ! empty( $clean ) ) {
			$clean[0]['is_default'] = true;
		}

		$updated = update_user_meta( $user_id, self::META_KEY, $clean );

		foreach ( $clean as $item ) {
			if ( ! empty( $item['is_default'] ) ) {
				self::sync_to_woo( $user_id, $item );
				break;
			}
		}

		return (bool) $updated;
	}

	/**
	 * Add or update an address.
	 *
	 * @param int   $user_id
	 * @param array $data
	 * @return array
	 */
	public static function save_address( int $user_id, array $data ): array {
		$addresses = self::get_addresses( $user_id );
		$id        = (string) ( $data['id'] ?? '' );

		if ( '' === $id ) {
			$id = 'addr_' . wp_generate_password( 8, false );
		}

		$target_index = -1;
		foreach ( $addresses as $index => $addr ) {
			if ( (string) ( $addr['id'] ?? '' ) === $id ) {
				$target_index = $index;
				break;
			}
		}

		$is_default = ! empty( $data['is_default'] ) || empty( $addresses );

		if ( $is_default ) {
			foreach ( $addresses as &$a ) {
				$a['is_default'] = false;
			}
		}

		$new_entry = array(
			'id'         => $id,
			'tag'        => self::clean_unicode( sanitize_text_field( (string) ( $data['tag'] ?? 'Nhà riêng' ) ) ),
			'is_default' => $is_default,
			'first_name' => self::clean_unicode( sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ) ),
			'last_name'  => self::clean_unicode( sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ) ),
			'phone'      => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
			'address_1'  => self::clean_unicode( sanitize_text_field( (string) ( $data['address_1'] ?? '' ) ) ),
			'city'       => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
			'district'   => sanitize_text_field( (string) ( $data['district'] ?? '' ) ),
			'ward'       => sanitize_text_field( (string) ( $data['ward'] ?? '' ) ),
			'state_name' => self::clean_unicode( sanitize_text_field( (string) ( $data['state_name'] ?? '' ) ) ),
			'ward_name'  => self::clean_unicode( sanitize_text_field( (string) ( $data['ward_name'] ?? '' ) ) ),
			'country'    => sanitize_text_field( (string) ( $data['country'] ?? 'VN' ) ),
		);

		if ( $target_index >= 0 ) {
			$addresses[ $target_index ] = $new_entry;
		} else {
			$addresses[] = $new_entry;
		}

		self::save_addresses( $user_id, $addresses );

		return $new_entry;
	}

	/**
	 * Delete an address by ID (cannot delete default address).
	 *
	 * @param int    $user_id
	 * @param string $id
	 * @return bool
	 */
	public static function delete_address( int $user_id, string $id ): bool {
		$addresses = self::get_addresses( $user_id );
		$filtered  = array();
		$deleted   = false;

		foreach ( $addresses as $addr ) {
			if ( (string) ( $addr['id'] ?? '' ) === $id ) {
				// Default address cannot be deleted
				if ( ! empty( $addr['is_default'] ) ) {
					return false;
				}
				$deleted = true;
				continue;
			}
			$filtered[] = $addr;
		}

		if ( $deleted ) {
			self::save_addresses( $user_id, $filtered );
		}

		return $deleted;
	}

	/**
	 * Set an address as default.
	 *
	 * @param int    $user_id
	 * @param string $id
	 * @return bool
	 */
	public static function set_default( int $user_id, string $id ): bool {
		$addresses = self::get_addresses( $user_id );
		$found     = false;

		foreach ( $addresses as &$addr ) {
			if ( (string) ( $addr['id'] ?? '' ) === $id ) {
				$addr['is_default'] = true;
				$found              = true;
			} else {
				$addr['is_default'] = false;
			}
		}

		if ( $found ) {
			self::save_addresses( $user_id, $addresses );
		}

		return $found;
	}

	/**
	 * Sync default address to native WooCommerce shipping usermeta.
	 *
	 * @param int   $user_id
	 * @param array $item
	 */
	public static function sync_to_woo( int $user_id, array $item ): void {
		$city_code = $item['city'] ?? '';
		$ward_code = $item['ward'] ?? '';

		$prov_name = ! empty( $item['state_name'] ) ? $item['state_name'] : ( AddressRepository::province_name( $city_code ) ?: $city_code );
		$ward_name = ! empty( $item['ward_name'] ) ? $item['ward_name'] : ( AddressRepository::ward_name( $ward_code, $city_code ) ?: $ward_code );

		$fields = array(
			'first_name' => $item['first_name'] ?? '',
			'last_name'  => $item['last_name'] ?? '',
			'address_1'  => $item['address_1'] ?? '',
			'city'       => $ward_name,
			'state'      => $city_code,
			'postcode'   => $ward_code,
			'country'    => $item['country'] ?? 'VN',
			'phone'      => $item['phone'] ?? '',
		);

		foreach ( $fields as $key => $value ) {
			update_user_meta( $user_id, 'shipping_' . $key, $value );
			\OmniWP\Identity\ProfileSeeder::seed_if_empty( $user_id, 'billing_' . $key, (string) $value );
		}
		if ( ! empty( $ward_code ) ) {
			update_user_meta( $user_id, AddressFields::META_WARD_CODE, $ward_code );
			update_user_meta( $user_id, AddressFields::META_SHIPPING_WARD_CODE, $ward_code );
		}
	}

	/**
	 * Build default address entry from Woo shipping usermeta.
	 *
	 * @param int $user_id
	 * @return array
	 */
	private static function get_default_from_woo( int $user_id ): array {
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;

		$raw_city = (string) get_user_meta( $user_id, 'shipping_state', true );
		$raw_ward = (string) get_user_meta( $user_id, 'shipping_city', true );

		$city_clean = self::clean_unicode( $raw_city );
		$ward_clean = self::clean_unicode( $raw_ward );

		$p_code = is_numeric( $city_clean ) ? $city_clean : AddressRepository::find_province_code_by_name( $city_clean );
		$w_code = is_numeric( $ward_clean ) ? $ward_clean : ( $p_code ? AddressRepository::find_ward_code_by_name( $ward_clean, $p_code ) : '' );

		return array(
			'id'         => 'addr_default',
			'tag'        => 'Nhà riêng',
			'is_default' => true,
			'first_name' => self::clean_unicode( (string) get_user_meta( $user_id, 'shipping_first_name', true ) ?: ( $user ? $user->display_name : '' ) ),
			'last_name'  => self::clean_unicode( (string) get_user_meta( $user_id, 'shipping_last_name', true ) ),
			'phone'      => (string) get_user_meta( $user_id, 'shipping_phone', true ) ?: (string) get_user_meta( $user_id, 'billing_phone', true ),
			'address_1'  => self::clean_unicode( (string) get_user_meta( $user_id, 'shipping_address_1', true ) ),
			'city'       => $p_code ?: $city_clean,
			'district'   => '',
			'ward'       => $w_code ?: $ward_clean,
			'country'    => 'VN',
		);
	}
}
