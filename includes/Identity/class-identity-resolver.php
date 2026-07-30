<?php
/**
 * Turns whatever the user typed into a WP_User.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

use SmartLogin\Settings;
use WP_User;

defined( 'ABSPATH' ) || exit;

class IdentityResolver {

	const META_PHONE = 'smartlogin_phone';

	const TYPE_PHONE   = 'phone';
	const TYPE_EMAIL   = 'email';
	const TYPE_UNKNOWN = 'unknown';

	/**
	 * Classify raw input against the configured ID mode.
	 *
	 * @return array{type:string,value:string} `value` is normalised; empty when invalid.
	 */
	public static function classify( string $input ): array {
		$input = trim( wp_unslash( $input ) );

		if ( '' === $input ) {
			return array(
				'type'  => self::TYPE_UNKNOWN,
				'value' => '',
			);
		}

		if ( Settings::email_enabled() && is_email( $input ) ) {
			return array(
				'type'  => self::TYPE_EMAIL,
				'value' => sanitize_email( $input ),
			);
		}

		if ( Settings::phone_enabled() && Phone::looks_like_phone( $input ) ) {
			$canonical = Phone::normalize( $input );

			return array(
				'type'  => self::TYPE_PHONE,
				'value' => Phone::is_valid( $canonical ) ? $canonical : '',
			);
		}

		return array(
			'type'  => self::TYPE_UNKNOWN,
			'value' => '',
		);
	}

	/**
	 * Find the account behind a canonical phone number.
	 */
	public static function user_by_phone( string $canonical ): ?WP_User {
		if ( '' === $canonical ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'    => self::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $canonical,       // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'      => 1,
				'count_total' => false,
				'fields'      => 'all',
			)
		);

		if ( $users ) {
			return $users[0];
		}

		// Fallback for accounts created before the meta existed: user_login is
		// the canonical number, so this also catches manual imports.
		$by_login = get_user_by( 'login', $canonical );

		return $by_login ?: null;
	}

	public static function user_by_email( string $email ): ?WP_User {
		$user = get_user_by( 'email', $email );

		return $user ?: null;
	}

	/**
	 * Resolve raw input to an account, whatever the identifier type.
	 */
	public static function resolve( string $input ): ?WP_User {
		$id = self::classify( $input );

		if ( '' === $id['value'] ) {
			return null;
		}

		if ( self::TYPE_PHONE === $id['type'] ) {
			return self::user_by_phone( $id['value'] );
		}

		if ( self::TYPE_EMAIL === $id['type'] ) {
			return self::user_by_email( $id['value'] );
		}

		return null;
	}

	/**
	 * Human label for the identifier field, driven by ID mode.
	 */
	public static function identifier_label(): string {
		switch ( Settings::get( 'id_mode' ) ) {
			case 'email_only':
				return __( 'Email', 'smart-login' );
			case 'both':
				return __( 'Số điện thoại hoặc Email', 'smart-login' );
			case 'phone_only':
			default:
				return __( 'Số điện thoại', 'smart-login' );
		}
	}
}
