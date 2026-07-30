<?php
/**
 * Contract every OTP delivery transport implements.
 *
 * A *transport* is how a code travels: SMS gateway, mail, push. That is a
 * different axis from an identity *channel*, which is a namespace of subjects —
 * phone numbers, email addresses, a federated provider. Both were called
 * "channel" before this refactor, which is why one of them is a transport now.
 * See docs/identity-model.md §6.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface TransportInterface {

	/**
	 * Machine id: sms, email, …
	 */
	public function id(): string;

	/**
	 * Is the transport configured well enough to be used right now?
	 */
	public function is_available(): bool;

	/**
	 * Deliver a code.
	 *
	 * @param string $destination Canonical phone digits or email address.
	 * @param string $code        Plaintext code.
	 * @param array  $ctx         intent, ttl_seconds, expires_ts, user_name…
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx );
}
