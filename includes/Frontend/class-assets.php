<?php
/**
 * Front-end CSS/JS registration.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class Assets {

	const HANDLE         = 'smart-login';
	const ADDRESS_HANDLE = 'smart-login-address';
	const CAPTCHA_HANDLE = 'smart-login-captcha';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 15 );
	}

	/**
	 * Enqueue early when the page visibly contains one of our shortcodes.
	 *
	 * Assets::enqueue() called at render time still works — WordPress prints
	 * late styles in the footer — but doing it here avoids the flash of
	 * unstyled form.
	 */
	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || '' === (string) $post->post_content ) {
			return;
		}

		foreach ( array( 'smart_auth', 'smart_login', 'smart_register', 'smart_verify_otp', 'smart_forgot_password', 'smart_profile' ) as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				self::enqueue();
				return;
			}
		}
	}

	public function register_assets(): void {
		wp_register_style(
			self::HANDLE,
			SMART_LOGIN_URL . 'assets/css/smart-login.css',
			array(),
			SMART_LOGIN_VERSION
		);

		wp_register_script(
			self::HANDLE,
			SMART_LOGIN_URL . 'assets/js/smart-login.js',
			array(),
			SMART_LOGIN_VERSION,
			true
		);

		wp_register_script(
			self::ADDRESS_HANDLE,
			SMART_LOGIN_URL . 'assets/js/address.js',
			array(),
			SMART_LOGIN_VERSION,
			true
		);

		// Registered only while a challenge is actually called for. In adaptive
		// mode — the default — that means an ordinary visitor on an ordinary day
		// loads no third-party script at all, which is half the reason the mode
		// exists: a captcha costs a request and a privacy footprint even when
		// nobody looks at it.
		$captcha_script = \SmartLogin\Security\Captcha::script_url();

		if ( '' !== $captcha_script ) {
			wp_register_script( self::CAPTCHA_HANDLE, $captcha_script, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the provider versions its own endpoint.
		}

		wp_localize_script(
			self::ADDRESS_HANDLE,
			'SmartLoginAddress',
			array(
				'restUrl' => esc_url_raw( rest_url( 'smart-login/v1/' ) ),
				'i18n'    => array(
					'chooseProvince' => __( 'Chọn Tỉnh/Thành phố', 'smart-login' ),
					'chooseWard'     => __( 'Chọn Phường/Xã', 'smart-login' ),
					'loading'        => __( 'Đang tải…', 'smart-login' ),
					'noResults'      => __( 'Không tìm thấy', 'smart-login' ),
				),
			)
		);

		wp_localize_script(
			self::HANDLE,
			'SmartLoginData',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'smart-login/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				// The same signed timestamp the HTML forms carry. Until it was
				// sent, RequestGuard::verify_rest() had nothing to inspect on the
				// JS path — it was not weak there, it was inert.
				'stamp'     => \SmartLogin\Security\RequestGuard::stamp( 'rest' ),
				'otpLength' => min( 8, max( 4, Settings::get_int( 'otp.length', 6 ) ) ),
				'i18n'      => array(
					'resend'      => __( 'Gửi lại', 'smart-login' ),
					/* translators: %d: seconds remaining before the code can be resent. */
					'resendIn'    => __( 'Gửi lại sau %ds', 'smart-login' ),
					'expired'     => __( 'Mã đã hết hạn', 'smart-login' ),
					'sending'     => __( 'Đang gửi…', 'smart-login' ),
					'showPass'    => __( 'Hiện mật khẩu', 'smart-login' ),
					'hidePass'    => __( 'Ẩn mật khẩu', 'smart-login' ),
					/* translators: %s: masked phone number or email address. */
					'contactSent' => __( 'Mã OTP đã được gửi tới %s.', 'smart-login' ),
					'contactDone' => __( 'Thông tin liên hệ đã được xác thực.', 'smart-login' ),
					'contactWait' => __( 'Đang xử lý…', 'smart-login' ),
					'unsaved'     => __( 'Có thay đổi chưa lưu', 'smart-login' ),
				),
			)
		);
	}

	/**
	 * Called by whatever renders a form.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		if ( wp_script_is( self::CAPTCHA_HANDLE, 'registered' ) ) {
			wp_enqueue_script( self::CAPTCHA_HANDLE );
		}
	}

	/**
	 * The address picker: styles live in the main stylesheet, so pull that in too.
	 */
	public static function enqueue_address(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::ADDRESS_HANDLE );
	}
}
