<?php
/**
 * Picks the delivery transport for a given destination.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use SmartLogin\Settings;
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
	 * Which transport serves each identity channel.
	 *
	 * A constant since 20.1, and the whole point of Phase 20. Between 10.1 and
	 * here this was a pair of settings, so a site could point a channel at a
	 * transport it had not configured — and the screen that offered the choice
	 * said nothing about the cell where nothing gets delivered. An administrator
	 * landed in it; see docs/sending-a-code.md.
	 *
	 * What replaced the flexibility is not less of it. The SMS transport speaks
	 * four wire formats now, chosen by provider, one of them the signed envelope
	 * the routing table used to exist to reach. The choice moved inside the
	 * channel, where it is one question on one screen instead of two questions on
	 * two.
	 */
	const CHANNEL_TRANSPORT = array(
		'phone' => 'sms',
		'email' => 'email',
	);

	/**
	 * Which identity channel a destination belongs to.
	 *
	 * A property of the identifier — an address containing `@` genuinely is an
	 * email identity — and therefore still decided here rather than configured.
	 * What used to be decided here as well, and is not any more, is how the code
	 * travels.
	 */
	public static function channel_for( string $destination ): string {
		return ( false !== strpos( $destination, '@' ) ) ? 'email' : 'phone';
	}

	/**
	 * Which transport carries a code to this destination.
	 *
	 * Reads no setting. 10.1 made this answer configurable and 20.1 took that
	 * back, for the reason `CHANNEL_TRANSPORT` records — the configurability was
	 * reachable through the provider list, and as a routing table it was mostly a
	 * way to get it wrong.
	 *
	 * `smart_login_otp_transports` still works and still matters: a filter that
	 * replaces `sms` or `email` is serving that channel, which is a substitution
	 * rather than a route. What it can no longer do is register a transport that
	 * nothing reaches.
	 */
	public function transport_for( string $destination ): string {
		return self::CHANNEL_TRANSPORT[ self::channel_for( $destination ) ];
	}

	/**
	 * Why a transport cannot carry anything right now, in its own words.
	 *
	 * This used to be a ternary: `sms`, or else email. Two branches describing a
	 * registry that has three built-ins and takes more through a filter, so every
	 * transport that was not the SMS gateway was refused as though it were the
	 * mail one. The visible symptom was a phone number routed at the automation
	 * endpoint coming back as "Kênh email chưa được cấu hình" — the routing was
	 * right, the sentence was not, and it pointed the administrator at a tab
	 * where nothing was wrong.
	 *
	 * Asking the transport makes the mismatch unrepresentable rather than fixed
	 * once: there is no list here to forget to extend. A transport that does not
	 * implement ReportsUnavailability is named by its id, which is plainer than
	 * the built-ins manage but is still the truth about which channel failed.
	 */
	private function unavailable_message( TransportInterface $transport, string $id ): string {
		if ( $transport instanceof ReportsUnavailability ) {
			return $transport->unavailable_message();
		}

		return sprintf(
			/* translators: %s: transport id, e.g. "zns". */
			__( 'Kênh "%s" chưa được cấu hình. Liên hệ quản trị viên.', 'smart-login' ),
			$id
		);
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
				$this->unavailable_message( $transport, $transport_id )
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
