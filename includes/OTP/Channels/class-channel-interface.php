<?php
/**
 * Contract every OTP delivery channel implements.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Channels;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface ChannelInterface {

	/**
	 * Machine id: sms, email, …
	 */
	public function id(): string;

	/**
	 * Is the channel configured well enough to be used right now?
	 */
	public function is_available(): bool;

	/**
	 * Deliver a code.
	 *
	 * @param string $destination Canonical phone digits or email address.
	 * @param string $code        Plaintext code.
	 * @param array  $ctx         purpose, ttl_seconds, expires_ts, user_name…
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx );
}
