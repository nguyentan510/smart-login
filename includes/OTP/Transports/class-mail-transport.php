<?php
/**
 * Deliver OTP by email through wp_mail(), so any SMTP plugin already
 * configured on the site handles transport.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\Placeholders;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class MailTransport implements TransportInterface {

	public function id(): string {
		return 'email';
	}

	public function is_available(): bool {
		return Settings::is_on( 'email.enabled' );
	}

	/**
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'smart_login_email_off', __( 'Kênh email đang tắt.', 'smart-login' ) );
		}

		if ( ! is_email( $destination ) ) {
			return new WP_Error( 'smart_login_bad_email', __( 'Địa chỉ email không hợp lệ.', 'smart-login' ) );
		}

		// Placeholder addresses can never receive mail; failing loudly here beats
		// generating a bounce and a user waiting for a code that will never come.
		if ( UserManager::is_synthetic_email( $destination ) ) {
			return new WP_Error(
				'smart_login_synthetic_email',
				__( 'Tài khoản này chưa có email thật. Vui lòng dùng số điện thoại.', 'smart-login' )
			);
		}

		$map     = Placeholders::build( $destination, $code, $ctx );
		$subject = Placeholders::render( (string) Settings::get( 'email.subject', '' ), $map );
		$body    = Placeholders::render( (string) Settings::get( 'email.body', '' ), $map );
		$is_html = Settings::is_on( 'email.is_html' );

		$headers = array();

		$from_name    = trim( (string) Settings::get( 'email.from_name', '' ) );
		$from_address = trim( (string) Settings::get( 'email.from_address', '' ) );

		if ( '' !== $from_address && is_email( $from_address ) ) {
			$name      = '' !== $from_name ? $from_name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = sprintf( 'From: %s <%s>', $name, $from_address );
		}

		if ( $is_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		} else {
			$body = wp_strip_all_tags( $body );
		}

		/**
		 * Adjust the OTP email right before it is handed to wp_mail().
		 *
		 * @param array  $mail  subject, body, headers
		 * @param string $destination
		 * @param array  $ctx
		 */
		$mail = (array) apply_filters(
			'smart_login_otp_email',
			array(
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$destination,
			$ctx
		);

		$sent = wp_mail( $destination, $mail['subject'], $mail['body'], $mail['headers'] );

		if ( ! $sent ) {
			return new WP_Error(
				'smart_login_mail_failed',
				__( 'Không gửi được email xác thực. Vui lòng thử lại sau ít phút.', 'smart-login' )
			);
		}

		return true;
	}
}
