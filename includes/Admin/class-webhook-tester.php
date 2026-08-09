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
use SmartLogin\OTP\Transports\AutomationEndpoint;
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
			$this->test_automation();
		}

		$this->test_webhook( $destination, $code, $ctx );
	}

	/**
	 * Post one signed ping so an operator can see the endpoint answer.
	 *
	 * Until 20.1 this sent an OTP envelope, because the endpoint could be a
	 * transport. It cannot any more — a code that leaves the site now leaves it
	 * through the SMS channel's signed provider, and *that* is what the SMS
	 * screen's own test button exercises. What is left here is the bus, so this
	 * sends what the bus sends: an event, carrying no code.
	 *
	 * Blocking, unlike a real bus dispatch. A fire-and-forget test tells the
	 * operator nothing, which is the one thing a test button may not do.
	 *
	 * Takes no destination and no code, and that is the visible shape of the
	 * change: this endpoint has nothing to deliver to anybody any more.
	 */
	private function test_automation(): void {
		$endpoint = new AutomationEndpoint();

		if ( ! $endpoint->is_configured() ) {
			wp_send_json_error(
				array( 'message' => __( 'Endpoint chưa có URL hoặc chưa có khoá ký. Hãy lưu cấu hình trước.', 'smart-login' ) )
			);
		}

		$response = $endpoint->post(
			AutomationEndpoint::base_envelope( 'test.ping', bin2hex( random_bytes( 16 ) ) ),
			true
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message'  => $response->get_error_message(),
					'status'   => 0,
					'response' => '',
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			wp_send_json_error(
				array(
					/* translators: %d: HTTP status code. */
					'message'  => sprintf( __( 'Endpoint trả về HTTP %d.', 'smart-login' ), $status ),
					'status'   => $status,
					'response' => substr( (string) wp_remote_retrieve_body( $response ), 0, 500 ),
				)
			);
		}

		wp_send_json_success(
			array(
				'ok'      => true,
				'message' => __( 'Endpoint đã nhận gói tin đã ký. Kiểm tra phía nhận để xác nhận chữ ký khớp.', 'smart-login' ),
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
			wp_send_json_error( array( 'message' => __( 'Kênh SMS chưa bật hoặc chưa chọn nhà cung cấp. Hãy lưu cấu hình trước.', 'smart-login' ) ) );
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
