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

use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Phone;
use SmartLogin\OTP\Transports\AutomationTransport;
use SmartLogin\OTP\Transports\MailTransport;
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\OTP\Transports\WebhookTransport;

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

		// One field name. 10.2 accepted `channel` as well because the admin JS posted
		// it; 15.3 renamed the attribute, the JS and this read together, and bumped the
		// asset version so a cached admin.js cannot post a name nothing reads.
		$transport   = isset( $_POST['transport'] ) ? sanitize_key( wp_unslash( $_POST['transport'] ) ) : 'sms';
		$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';

		if ( '' === trim( $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'Vui lòng nhập số điện thoại hoặc email nhận thử.', 'smart-login' ) ) );
		}

		// A throwaway code: this never touches the OTP table, so it cannot be
		// redeemed and it does not consume anybody's hourly quota.
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		$ctx = array(
			'intent'      => 'test',
			'transport'   => $transport,
			'ttl_seconds' => 300,
			'expires_ts'  => time() + 300,
			'user_name'   => wp_get_current_user()->display_name,
		);

		if ( 'email' === $transport ) {
			$this->test_email( $destination, $code, $ctx );
		}

		if ( 'automation' === $transport ) {
			$this->test_automation( $destination, $code, $ctx );
		}

		$this->test_webhook( $destination, $code, $ctx );
	}

	/**
	 * Post a signed envelope, whatever the routing table currently says.
	 *
	 * Deliberately independent of the route: an operator checking whether an
	 * endpoint has come back should not have to point a live channel at it first.
	 * Like the other two testers this calls the transport directly, so the
	 * circuit breaker in TransportRouter does not stand in the way — which is the
	 * whole reason the button is usable while the breaker is open.
	 */
	private function test_automation( string $destination, string $code, array $ctx ): void {
		$transport = new AutomationTransport();

		if ( ! $transport->is_available() ) {
			wp_send_json_error(
				array( 'message' => __( 'Endpoint automation chưa có URL hoặc chưa có khoá ký. Hãy lưu cấu hình trước.', 'smart-login' ) )
			);
		}

		// A phone number is normalised so the envelope carries the same shape a
		// real send would; an email address is passed through untouched. Which
		// one this is comes from the routing authority, not from a seventh copy
		// of the `@` test — the guard rail caught that on the first run.
		$canonical = MailChannel::ID === TransportRouter::channel_for( $destination )
			? $destination
			: Phone::normalize( $destination );

		if ( '' === $canonical ) {
			wp_send_json_error( array( 'message' => __( 'Số điện thoại không hợp lệ.', 'smart-login' ) ) );
		}

		$result = $transport->send( $canonical, $code, $ctx );

		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();

			wp_send_json_error(
				array(
					'message'  => $result->get_error_message(),
					'status'   => $data['status'] ?? 0,
					'response' => $data['detail'] ?? '',
				)
			);
		}

		wp_send_json_success(
			array(
				'ok'      => true,
				'message' => __( 'Endpoint đã nhận gói tin đã ký. Kiểm tra phía automation để xác nhận chữ ký khớp.', 'smart-login' ),
			)
		);
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

		$channel = new WebhookTransport();

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

		$channel = new MailTransport();

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
