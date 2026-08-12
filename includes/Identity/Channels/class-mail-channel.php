<?php
/**
 * Email addresses as an identity namespace.
 *
 * Named MailChannel, not EmailChannel, because the OTP delivery namespace
 * already owns a class by the latter name and the autoloader derives filenames
 * from class names, so the two would collide on disk. The stored channel id is
 * still `email` — that is the value in the database, and it must not change once
 * rows exist.
 *
 * @package OmniWP
 */

namespace OmniWP\Identity\Channels;

use OmniWP\Identity\Phone;
use OmniWP\Identity\UserManager;
use OmniWP\Identity\VerifiedClaim;

defined( 'ABSPATH' ) || exit;

final class MailChannel implements IdentityChannel {

	const ID = 'email';

	public function id(): string {
		return self::ID;
	}

	public function normalize( string $raw ): string {
		$raw = strtolower( trim( $raw ) );

		return is_email( $raw ) ? (string) sanitize_email( $raw ) : '';
	}

	/**
	 * A synthetic `@phone.invalid` address is a well-formed email that can never
	 * receive anything — RFC 2606 guarantees the domain does not resolve. It is
	 * stored in wp_users.user_email as a placeholder, but admitting it here would
	 * create an identity nobody can prove control of and no code can reach.
	 */
	public function is_valid( string $subject ): bool {
		if ( '' === $subject || ! is_email( $subject ) ) {
			return false;
		}

		return ! UserManager::is_synthetic_email( $subject );
	}

	public function proof_method(): string {
		return VerifiedClaim::PROOF_OTP;
	}

	public function is_self_asserted(): bool {
		return true;
	}

	public function can_receive_otp(): bool {
		return true;
	}

	public function label(): string {
		return __( 'Email', 'omniwp' );
	}

	public function mask( string $subject ): string {
		return Phone::mask_email( $subject );
	}
}
