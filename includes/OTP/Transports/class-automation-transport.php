<?php
/**
 * Hand the code to the site owner's own automation platform.
 *
 * Distinct from WebhookTransport, which exists to satisfy an SMS gateway's API:
 * there the remote end is fixed and the plugin bends to it, which is why that
 * transport has a free-text body template and a preset per provider. Here the
 * remote end is n8n, Make, or something the administrator wrote, and it can be
 * shaped to us — so the payload is one fixed, signed envelope.
 *
 * This is the transport that lets the plaintext code leave the site. That is a
 * property the plugin otherwise holds carefully — hashed at rest, redacted out
 * of logs, on screen only in dev mode — and giving it up is the deliberate cost
 * of letting an external system deliver. The compensating controls are HTTPS
 * enforced at save time, an HMAC the receiver can verify, and a timestamp and
 * delivery id it can use to reject a replay. See docs/delivery-routing.md.
 *
 * What none of that compensates for is an endpoint the administrator should not
 * have trusted. The help text says so rather than implying the signature makes
 * the destination safe.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class AutomationTransport implements TransportInterface {

	const EVENT_OTP_SEND = 'otp.send';

	public function id(): string {
		return 'automation';
	}

	/**
	 * No secret means no signature, which means an endpoint receiving live codes
	 * it cannot authenticate. That configuration is not offered.
	 */
	public function is_available(): bool {
		return '' !== trim( (string) Settings::get( 'automation.url', '' ) )
			&& '' !== Settings::read_secret( 'automation.secret' );
	}

	/**
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'smart_login_automation_unconfigured',
				__( 'Kênh automation chưa được cấu hình. Liên hệ quản trị viên.', 'smart-login' )
			);
		}

		$delivery_id = bin2hex( random_bytes( 16 ) );
		$ttl         = (int) ( $ctx['ttl_seconds'] ?? Settings::get_int( 'otp.ttl', 300 ) );
		$expires_ts  = (int) ( $ctx['expires_ts'] ?? ( time() + $ttl ) );

		$envelope = array(
			'event'       => self::EVENT_OTP_SEND,
			// Named rather than inferred. Placeholders blanks {{email}} for a
			// phone destination and {{phone}} for an email one, which would
			// leave the receiver working out the channel from which field is
			// empty. The routing authority answers instead.
			'channel'     => TransportRouter::channel_for( $destination ),
			'destination' => $destination,
			'intent'      => (string) ( $ctx['intent'] ?? '' ),
			'code'        => $code,
			'ttl_seconds' => $ttl,
			'expires_at'  => gmdate( 'c', $expires_ts ),
			'delivery_id' => $delivery_id,
			'site'        => home_url( '/' ),
			'timestamp'   => time(),
		);

		/**
		 * Add fields to the outgoing envelope.
		 *
		 * Runs before signing, so anything added here is covered by the
		 * signature. `event`, `code` and `delivery_id` are overwritten
		 * afterwards: a filter that could rename the event or replace the code
		 * would be a way to make the envelope lie about itself.
		 *
		 * @param array  $envelope
		 * @param string $destination
		 * @param array  $ctx
		 */
		$envelope = (array) apply_filters( 'smart_login_automation_envelope', $envelope, $destination, $ctx );

		$envelope['event']       = self::EVENT_OTP_SEND;
		$envelope['code']        = $code;
		$envelope['delivery_id'] = $delivery_id;

		$signed = EnvelopeSigner::sign( $envelope, Settings::read_secret( 'automation.secret' ) );

		$headers = $signed['headers'];

		foreach ( (array) Settings::get( 'automation.headers', array() ) as $row ) {
			if ( empty( $row['key'] ) || isset( $headers[ $row['key'] ] ) ) {
				continue;
			}

			$headers[ $row['key'] ] = (string) ( $row['value'] ?? '' );
		}

		// Clamped here as well as by the registry, for the reason
		// WebhookTransport records: a value stored under an older, looser
		// ceiling survives until somebody happens to re-save that tab.
		$timeout = min(
			WebhookTransport::MAX_TIMEOUT,
			max( 1, Settings::get_int( 'automation.timeout', 5 ) )
		);

		$args = array(
			'method'      => 'POST',
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $signed['body'],
			'user-agent'  => 'SmartLogin/' . SMART_LOGIN_VERSION . '; ' . home_url( '/' ),
		);

		$response = wp_remote_request( (string) Settings::get( 'automation.url', '' ), $args );

		if ( is_wp_error( $response ) ) {
			return $this->failed( $response->get_error_message(), 0, $code );
		}

		$succeeded = TransportProbe::matches_success(
			$response,
			(string) Settings::get( 'automation.success_path', '' ),
			(string) Settings::get( 'automation.success_value', '' )
		);

		if ( $succeeded ) {
			return true;
		}

		return $this->failed(
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Endpoint automation trả về HTTP %d.', 'smart-login' ),
				(int) wp_remote_retrieve_response_code( $response )
			),
			(int) wp_remote_retrieve_response_code( $response ),
			$code,
			TransportProbe::redact( substr( (string) wp_remote_retrieve_body( $response ), 0, 4000 ), $code )
		);
	}

	/**
	 * The user-facing message never names the endpoint; the data carries the
	 * detail, with the code masked out of every part of it.
	 */
	private function failed( string $detail, int $status, string $code, string $body = '' ): WP_Error {
		return new WP_Error(
			'smart_login_automation_failed',
			__( 'Không gửi được mã xác thực. Vui lòng thử lại sau ít phút.', 'smart-login' ),
			array(
				'detail'   => TransportProbe::redact( $detail, $code ),
				'status'   => $status,
				'response' => $body,
			)
		);
	}
}
