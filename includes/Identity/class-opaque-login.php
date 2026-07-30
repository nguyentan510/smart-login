<?php
/**
 * Generates the internal, unguessable wp_users.user_login value.
 *
 * This is the structural half of Invariant 1, and it exists because the
 * violation it prevents lives in WordPress core rather than in this plugin:
 * `wp_authenticate_username_password()` runs on the `authenticate` filter at
 * priority 20 and resolves user_login directly. Plugin identity code runs at 30.
 * So if user_login held a phone number, core would authenticate that number
 * before IdentityDirectory was ever consulted — and WordPress offers no
 * supported way to change user_login once set, leaving it permanently stale
 * after the user changes their phone.
 *
 * Making the value unguessable removes the attack surface instead of racing core
 * for it. See docs/identity-model.md §3.
 *
 * wp_users.user_email is deliberately NOT opaque: wp_update_user() keeps it in
 * sync on change, so it is self-correcting rather than stale, and it must remain
 * deliverable.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

defined( 'ABSPATH' ) || exit;

final class OpaqueLogin {

	const PREFIX = 'sl_';

	/** Bytes of entropy. 12 bytes → 24 hex characters → 27 with the prefix. */
	const BYTES = 12;

	/**
	 * A fresh login name. 27 characters, well inside WordPress's 60-character
	 * user_login column, and containing nothing a human would ever type.
	 */
	public static function generate(): string {
		return self::PREFIX . bin2hex( random_bytes( self::BYTES ) );
	}

	/**
	 * Whether a login was produced by this class.
	 *
	 * Used by the admin identity column, and by tests asserting that no phone
	 * number or email address ever reaches the column.
	 */
	public static function is_opaque( string $login ): bool {
		return (bool) preg_match(
			'/^' . preg_quote( self::PREFIX, '/' ) . '[0-9a-f]{' . ( self::BYTES * 2 ) . '}$/',
			$login
		);
	}
}
