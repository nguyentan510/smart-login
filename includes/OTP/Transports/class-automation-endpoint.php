<?php
/**
 * The configured automation endpoint, and the only place a signed envelope is
 * put on the wire.
 *
 * One endpoint serves two roles that must not be confused: the transport role,
 * which delivers a code and is answerable for whether it arrived, and the bus
 * role, which reports that something happened and is answerable for nothing.
 * They differ in exactly one argument here — `$blocking` — and in everything
 * they do with the answer.
 *
 * Keeping the wire-level concern in one class is what lets the guard rail say
 * "exactly one file posts an automation request" and mean it. Two senders would
 * be two places to forget the signature.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class AutomationEndpoint {

	/**
	 * No secret means no signature, which means an endpoint receiving live data
	 * it cannot authenticate. That configuration is not offered to either role.
	 */
	public function is_configured(): bool {
		return '' !== trim( (string) Settings::get( 'automation.url', '' ) )
			&& '' !== Settings::read_secret( 'automation.secret' );
	}

	public function url(): string {
		return trim( (string) Settings::get( 'automation.url', '' ) );
	}

	/**
	 * Clamped here as well as by the registry, for the reason WebhookTransport
	 * records: a value stored under an older, looser ceiling survives until
	 * somebody happens to re-save that tab.
	 */
	public function timeout(): int {
		return min(
			WebhookTransport::MAX_TIMEOUT,
			max( 1, Settings::get_int( 'automation.timeout', 5 ) )
		);
	}

	/**
	 * Sign an envelope and send it.
	 *
	 * @param array $envelope Payload; `event` and `delivery_id` are expected.
	 * @param bool  $blocking Wait for the answer, or fire and forget.
	 * @return array|WP_Error Whatever the HTTP layer returned.
	 */
	public function post( array $envelope, bool $blocking ) {
		$signed  = EnvelopeSigner::sign( $envelope, Settings::read_secret( 'automation.secret' ) );
		$headers = $signed['headers'];

		// Administrator headers may add, never replace. A configured
		// `X-Smart-Login-Signature` would otherwise silently disable the only
		// control that makes this endpoint safe to point anywhere.
		foreach ( (array) Settings::get( 'automation.headers', array() ) as $row ) {
			if ( empty( $row['key'] ) || isset( $headers[ $row['key'] ] ) ) {
				continue;
			}

			$headers[ $row['key'] ] = (string) ( $row['value'] ?? '' );
		}

		return wp_remote_request(
			$this->url(),
			array(
				'method'      => 'POST',
				'timeout'     => $this->timeout(),
				'blocking'    => $blocking,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $signed['body'],
				'user-agent'  => 'SmartLogin/' . SMART_LOGIN_VERSION . '; ' . home_url( '/' ),
			)
		);
	}

	/**
	 * The fields every envelope carries, whichever role built it.
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
}
