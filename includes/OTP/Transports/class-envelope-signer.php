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
	 * The fields every envelope carries, whichever role built it.
	 *
	 * Lives here rather than on `AutomationEndpoint`, where it used to, because
	 * 20.2 gives the OTP path its own signed provider and Rule 15 requires that
	 * path to touch nothing the event bus configures. This function reads no
	 * setting at all, so it is the half that can be shared; the endpoint keeps
	 * the half that reads `automation.*` and delegates here.
	 *
	 * @param string $event       Event name.
	 * @param string $delivery_id Stable id for deduplication on the far end.
	 */
	public static function base_envelope( string $event, string $delivery_id ): array {
		return array(
			'event'       => $event,
			'delivery_id' => $delivery_id,
			'site'        => home_url( '/' ),
			'timestamp'   => time(),
		);
	}

	/**
	 * Apply administrator headers to a signed request, add-only.
	 *
	 * The rule this enforces was written on `AutomationEndpoint::post()` and is
	 * shared verbatim rather than reimplemented: a configured
	 * `X-Smart-Login-Signature` would otherwise silently disable the only control
	 * that makes a signed endpoint safe to point anywhere.
	 *
	 * @param array<string,string> $signed_headers Headers the signature produced.
	 * @param array                $configured     Rows of key/value from settings.
	 * @return array<string,string>
	 */
	public static function merge_headers( array $signed_headers, array $configured ): array {
		foreach ( $configured as $row ) {
			if ( empty( $row['key'] ) || isset( $signed_headers[ $row['key'] ] ) ) {
				continue;
			}

			$signed_headers[ $row['key'] ] = (string) ( $row['value'] ?? '' );
		}

		return $signed_headers;
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
