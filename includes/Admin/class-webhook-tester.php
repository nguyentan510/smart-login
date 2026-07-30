<?php
/**
 * "Send a test" button for the SMS webhook and the email channel.
 *
 * Sends a real code over the configured channel and shows the exact request
 * and response, with secrets and the code itself masked. This is what turns a
 * gateway integration from guesswork into a five-minute job.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin;

use SmartLogin\Identity\Phone;
use SmartLogin\OTP\Channels\EmailChannel;
use SmartLogin\OTP\Channels\WebhookChannel;

defined( 'ABSPATH' ) || exit;

class WebhookTester {

	const NONCE  = 'smart_login_test';
	const ACTION = 'smart_login_test_channel';

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'smart-login' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$channel     = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : 'sms';
		$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';

		if ( '' === trim( $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'Vui lòng nhập số điện thoại hoặc email nhận thử.', 'smart-login' ) ) );
		}

		// A throwaway code: this never touches the OTP table, so it cannot be
		// redeemed and it does not consume anybody's hourly quota.
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		$ctx = array(
			'purpose'     => 'test',
			'channel'     => $channel,
			'ttl_seconds' => 300,
			'expires_ts'  => time() + 300,
			'user_name'   => wp_get_current_user()->display_name,
		);

		if ( 'email' === $channel ) {
			$this->test_email( $destination, $code, $ctx );
		}

		$this->test_webhook( $destination, $code, $ctx );
	}

	private function test_webhook( string $destination, string $code, array $ctx ): void {
		$canonical = Phone::normalize( $destination );

		if ( '' === $canonical ) {
			wp_send_json_error( array( 'message' => __( 'Số điện thoại không hợp lệ.', 'smart-login' ) ) );
		}

		if ( ! Phone::is_valid( $canonical ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: normalised number. */
						__( 'Số %s không khớp đầu số hợp lệ. Kiểm tra lại mã quốc gia trong tab Chung.', 'smart-login' ),
						$canonical
					),
				)
			);
		}

		$channel = new WebhookChannel();

		if ( ! $channel->is_available() ) {
			wp_send_json_error( array( 'message' => __( 'Webhook chưa bật hoặc chưa có URL. Hãy lưu cấu hình trước.', 'smart-login' ) ) );
		}

		$result = $channel->dispatch( $canonical, $code, $ctx );

		$payload = array(
			'ok'          => $result['ok'],
			'status'      => $result['status'],
			'duration_ms' => $result['duration_ms'],
			'request'     => $result['request'],
			'response'    => $result['response'],
			'message'     => $result['ok']
				? sprintf(
					/* translators: 1: normalised number, 2: duration in ms. */
					__( 'Gửi thành công tới %1$s (%2$dms). Kiểm tra điện thoại của bạn.', 'smart-login' ),
					$canonical,
					$result['duration_ms']
				)
				: $result['error'],
		);

		if ( $result['ok'] ) {
			wp_send_json_success( $payload );
		}

		wp_send_json_error( $payload );
	}

	private function test_email( string $destination, string $code, array $ctx ): void {
		if ( ! is_email( $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'Địa chỉ email không hợp lệ.', 'smart-login' ) ) );
		}

		$channel = new EmailChannel();

		if ( ! $channel->is_available() ) {
			wp_send_json_error( array( 'message' => __( 'Kênh email đang tắt. Hãy bật và lưu cấu hình trước.', 'smart-login' ) ) );
		}

		$sent = $channel->send( $destination, $code, $ctx );

		if ( is_wp_error( $sent ) ) {
			wp_send_json_error(
				array(
					'message'  => $sent->get_error_message(),
					'response' => __( 'wp_mail() trả về false. Nguyên nhân thường gặp: máy chủ chưa cấu hình SMTP.', 'smart-login' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s: email address. */
					__( 'Đã chuyển email cho WordPress gửi tới %s. Kiểm tra hộp thư (kể cả mục Spam).', 'smart-login' ),
					$destination
				),
			)
		);
	}
}
