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

class AutomationTransport implements TransportInterface, ReportsUnavailability {

	const EVENT_OTP_SEND = 'otp.send';

	/** @var AutomationEndpoint */
	private $endpoint;

	public function __construct( ?AutomationEndpoint $endpoint = null ) {
		$this->endpoint = $endpoint ?? new AutomationEndpoint();
	}

	public function id(): string {
		return 'automation';
	}

	public function is_available(): bool {
		return $this->endpoint->is_configured();
	}

	public function unavailable_message(): string {
		return __( 'Kênh automation chưa được cấu hình. Liên hệ quản trị viên.', 'smart-login' );
	}

	/**
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx ) {
		if ( ! $this->is_available() ) {
			// Reached only by a caller that skipped the router — the admin's
			// "Gửi thử" button does exactly that. One wording either way.
			return new WP_Error( 'smart_login_automation_unconfigured', $this->unavailable_message() );
		}

		$delivery_id = bin2hex( random_bytes( 16 ) );
		$ttl         = (int) ( $ctx['ttl_seconds'] ?? Settings::get_int( 'otp.ttl', 300 ) );
		$expires_ts  = (int) ( $ctx['expires_ts'] ?? ( time() + $ttl ) );

		$envelope = AutomationEndpoint::base_envelope( self::EVENT_OTP_SEND, $delivery_id ) + array(
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

		// Blocking, because this role is answerable for the delivery. The bus
		// role uses the same endpoint and does not wait.
		$response = $this->endpoint->post( $envelope, true );

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
