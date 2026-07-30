<?php
/**
 * Phone numbers as an identity namespace.
 *
 * All normalisation and validation logic stays in Identity\Phone, which is
 * already covered by the regression suite. This class only adapts it to the
 * channel contract.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity\Channels;

use SmartLogin\Identity\Phone;
use SmartLogin\Identity\VerifiedClaim;

defined( 'ABSPATH' ) || exit;

final class PhoneChannel implements IdentityChannel {

	const ID = 'phone';

	public function id(): string {
		return self::ID;
	}

	public function normalize( string $raw ): string {
		return Phone::normalize( $raw );
	}

	public function is_valid( string $subject ): bool {
		return '' !== $subject && Phone::is_valid( $subject );
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
		return __( 'Số điện thoại', 'smart-login' );
	}

	public function mask( string $subject ): string {
		return Phone::mask( $subject );
	}
}
