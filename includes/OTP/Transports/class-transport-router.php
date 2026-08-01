<?php
/**
 * Picks the delivery transport for a given destination.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class TransportRouter {

	/** @var array<string,TransportInterface> */
	private $transports;

	public function __construct( ?array $transports = null ) {
		$this->transports = $transports ?? array(
			'sms'   => new WebhookTransport(),
			'email' => new MailTransport(),
		);

		/**
		 * Register additional transports (Zalo ZNS, in-app push, …).
		 *
		 * Adding one means implementing TransportInterface and nothing else: no
		 * new intent constants, no schema change, and no edits to register, login
		 * or recover. That independence is asserted by the identity core tests.
		 *
		 * @param array<string,TransportInterface> $transports
		 */
		$this->transports = (array) apply_filters( 'smart_login_otp_transports', $this->transports );
	}

	/**
	 * Email addresses go by email; everything else is a phone number.
	 */
	public function transport_for( string $destination ): string {
		return ( false !== strpos( $destination, '@' ) ) ? 'email' : 'sms';
	}

	public function get( string $id ): ?TransportInterface {
		$transport = $this->transports[ $id ] ?? null;

		return $transport instanceof TransportInterface ? $transport : null;
	}

	public function is_available( string $id ): bool {
		$transport = $this->get( $id );

		return $transport && $transport->is_available();
	}

	/**
	 * Deliver a code over the transport that suits the destination.
	 *
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx ) {
		$transport_id = $ctx['transport'] ?? $this->transport_for( $destination );

		/**
		 * Take over delivery entirely. Return anything non-null (true or a
		 * WP_Error) and the built-in transports are skipped.
		 *
		 * @param null|true|WP_Error $handled
		 * @param string             $destination
		 * @param string             $code
		 * @param array              $ctx
		 */
		$handled = apply_filters( 'smart_login_dispatch_otp', null, $destination, $code, $ctx );

		if ( null !== $handled ) {
			return $handled;
		}

		$transport = $this->get( $transport_id );

		if ( ! $transport ) {
			return new WP_Error(
				'smart_login_no_transport',
				__( 'Chưa cấu hình kênh gửi mã xác thực.', 'smart-login' )
			);
		}

		if ( ! $transport->is_available() ) {
			return new WP_Error(
				'smart_login_transport_unavailable',
				'sms' === $transport_id
					? __( 'Kênh SMS chưa được cấu hình. Liên hệ quản trị viên.', 'smart-login' )
					: __( 'Kênh email chưa được cấu hình. Liên hệ quản trị viên.', 'smart-login' )
			);
		}

		// The breaker sits here rather than inside WebhookTransport because a
		// hanging SMTP server holds a worker exactly as a hanging HTTP gateway
		// does, and this is the one place both go through. It also leaves the
		// admin's "Gửi thử" button unguarded — that button calls dispatch()
		// directly, and it is how an operator checks whether the gateway is back.
		$breaker = new CircuitBreaker( $transport_id );

		if ( $breaker->is_open() ) {
			return new WP_Error(
				'smart_login_transport_down',
				__( 'Không gửi được mã xác thực. Vui lòng thử lại sau ít phút.', 'smart-login' ),
				array( 'retry_after' => $breaker->opens_in() )
			);
		}

		$result = $transport->send( $destination, $code, $ctx );

		if ( is_wp_error( $result ) ) {
			$breaker->record_failure();
		} else {
			$breaker->record_success();
		}

		return $result;
	}
}
