<?php
/**
 * Picks the delivery channel for a given destination.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Channels;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class ChannelRouter {

	/** @var array<string,ChannelInterface> */
	private $channels;

	public function __construct( ?array $channels = null ) {
		$this->channels = $channels ?? array(
			'sms'   => new WebhookChannel(),
			'email' => new EmailChannel(),
		);

		/**
		 * Register additional channels (Zalo ZNS, in-app push, …).
		 *
		 * @param array<string,ChannelInterface> $channels
		 */
		$this->channels = (array) apply_filters( 'smart_login_otp_channels', $this->channels );
	}

	/**
	 * Email addresses go by email; everything else is a phone number.
	 */
	public function channel_for( string $destination ): string {
		return ( false !== strpos( $destination, '@' ) ) ? 'email' : 'sms';
	}

	public function get( string $id ): ?ChannelInterface {
		$channel = $this->channels[ $id ] ?? null;

		return $channel instanceof ChannelInterface ? $channel : null;
	}

	public function is_available( string $id ): bool {
		$channel = $this->get( $id );

		return $channel && $channel->is_available();
	}

	/**
	 * Deliver a code over the channel that suits the destination.
	 *
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx ) {
		$channel_id = $ctx['channel'] ?? $this->channel_for( $destination );

		/**
		 * Take over delivery entirely. Return anything non-null (true or a
		 * WP_Error) and the built-in channels are skipped.
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

		$channel = $this->get( $channel_id );

		if ( ! $channel ) {
			return new WP_Error(
				'smart_login_no_channel',
				__( 'Chưa cấu hình kênh gửi mã xác thực.', 'smart-login' )
			);
		}

		if ( ! $channel->is_available() ) {
			return new WP_Error(
				'smart_login_channel_unavailable',
				'sms' === $channel_id
					? __( 'Kênh SMS chưa được cấu hình. Liên hệ quản trị viên.', 'smart-login' )
					: __( 'Kênh email chưa được cấu hình. Liên hệ quản trị viên.', 'smart-login' )
			);
		}

		return $channel->send( $destination, $code, $ctx );
	}
}
