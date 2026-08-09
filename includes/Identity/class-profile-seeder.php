<?php
/**
 * The only writer of profile fields, and the boundary in Invariant 2.
 *
 * Identity is proven and never accepted from a form. Profile is what the
 * customer asserts about themselves: it may be wrong, and it is theirs to
 * change. Before this class the two shared one write path, and the login phone
 * overwrote `billing_phone` on every address save — so a customer whose delivery
 * contact was a family member's number could not keep it. Saving the address
 * book silently reset it.
 *
 * Two methods, because there are exactly two legitimate directions:
 *
 *   seed_if_empty()        identity → profile. Fills a blank so checkout is
 *                          pre-filled, and never touches a value the customer
 *                          already has.
 *
 *   set_from_user_input()  the customer just typed this. Their input wins,
 *                          including over a previous value.
 *
 * There is deliberately no third method, and no direction from profile back to
 * identity: a billing form cannot prove ownership of anything.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

defined( 'ABSPATH' ) || exit;

final class ProfileSeeder {

	/**
	 * Profile keys this class is willing to write.
	 *
	 * An allowlist rather than a free-for-all, so a typo cannot quietly create
	 * `biling_phone` and leave the real field untouched forever.
	 *
	 * @var string[]
	 */
	const WRITABLE = array(
		'billing_first_name',
		'billing_last_name',
		'billing_company',
		'billing_phone',
		'billing_email',
		'billing_country',
		'billing_state',
		'billing_city',
		'billing_address_1',
		'billing_address_2',
		'billing_postcode',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_phone',
		'shipping_country',
		'shipping_state',
		'shipping_city',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_postcode',
	);

	private static function writable( string $key ): bool {
		return in_array( $key, self::WRITABLE, true );
	}

	/**
	 * Fill a profile field from identity data, but only if it is currently empty.
	 *
	 * @return bool True when a value was written.
	 */
	public static function seed_if_empty( int $user_id, string $key, string $value ): bool {
		if ( $user_id <= 0 || '' === $value || ! self::writable( $key ) ) {
			return false;
		}

		$current = (string) get_user_meta( $user_id, $key, true );

		if ( '' !== trim( $current ) ) {
			// The customer already has a value here. It is theirs, even when it
			// differs from the identity we could have supplied.
			return false;
		}

		update_user_meta( $user_id, $key, $value );

		return true;
	}

	/**
	 * Seed several fields at once.
	 *
	 * @param int                  $user_id
	 * @param array<string,string> $pairs
	 * @return int Number of fields actually written.
	 */
	public static function seed_many( int $user_id, array $pairs ): int {
		$written = 0;

		foreach ( $pairs as $key => $value ) {
			if ( self::seed_if_empty( $user_id, (string) $key, (string) $value ) ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Write a profile field the customer just submitted.
	 *
	 * Overwrites on purpose — this is their own form, and they are entitled to
	 * change their mind. An empty value clears the field rather than being
	 * ignored, so a customer can remove something.
	 */
	public static function set_from_user_input( int $user_id, string $key, string $value ): bool {
		if ( $user_id <= 0 || ! self::writable( $key ) ) {
			return false;
		}

		if ( '' === $value ) {
			delete_user_meta( $user_id, $key );

			return true;
		}

		update_user_meta( $user_id, $key, $value );

		return true;
	}

	/**
	 * @param int                  $user_id
	 * @param array<string,string> $pairs
	 */
	public static function set_many_from_user_input( int $user_id, array $pairs ): int {
		$written = 0;

		foreach ( $pairs as $key => $value ) {
			if ( self::set_from_user_input( $user_id, (string) $key, (string) $value ) ) {
				++$written;
			}
		}

		return $written;
	}
}
