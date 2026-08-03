<?php
/**
 * Serialise an event once, and sign exactly what will be sent.
 *
 * The signature and the body are produced together and returned together, so
 * they cannot be computed over different bytes. Encoding twice — once to sign,
 * once to send — is the classic way a webhook signature becomes decorative: any
 * difference in key order, escaping or float formatting between the two passes
 * makes every signature fail, or worse, makes verification pass on a body that
 * is not the one that arrived.
 *
 * This is also why the envelope is fixed rather than templated. A signature over
 * an administrator-editable body is a signature over something the administrator
 * can accidentally make unparseable.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

defined( 'ABSPATH' ) || exit;

final class EnvelopeSigner {

	const SIGNATURE_HEADER = 'X-Smart-Login-Signature';
	const TIMESTAMP_HEADER = 'X-Smart-Login-Timestamp';
	const DELIVERY_HEADER  = 'X-Smart-Login-Delivery';
	const EVENT_HEADER     = 'X-Smart-Login-Event';

	/**
	 * Build the body and the headers that authenticate it.
	 *
	 * The timestamp and delivery id travel as headers as well as inside the
	 * payload so a receiver can reject a replay before parsing anything, and so
	 * the two can be compared — a mismatch means someone rewrote one of them.
	 *
	 * @param array  $payload Envelope contents; `event` and `delivery_id` required.
	 * @param string $secret  Shared HMAC key.
	 * @return array{body:string,headers:array<string,string>}
	 */
	public static function sign( array $payload, string $secret ): array {
		$body = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return array(
			'body'    => $body,
			'headers' => array(
				'Content-Type'         => 'application/json; charset=utf-8',
				self::SIGNATURE_HEADER => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
				self::TIMESTAMP_HEADER => (string) ( $payload['timestamp'] ?? time() ),
				self::DELIVERY_HEADER  => (string) ( $payload['delivery_id'] ?? '' ),
				self::EVENT_HEADER     => (string) ( $payload['event'] ?? '' ),
			),
		);
	}

	/**
	 * The verification snippet shown in the admin help text.
	 *
	 * A signature nobody verifies is decoration, and "compute an HMAC" is the
	 * step integrators skip. Kept here beside the implementation so the two
	 * cannot drift.
	 */
	public static function verification_example(): string {
		return "// Node / n8n\nconst expected = 'sha256=' + require('crypto')\n"
			. "  .createHmac('sha256', SECRET)\n"
			. "  .update(rawBody)          // the raw string, not the parsed object\n"
			. "  .digest('hex');\n"
			. "crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(headers['x-smart-login-signature']));";
	}
}
