<?php
/**
 * Front-end CSS/JS registration.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class Assets {

	const HANDLE         = 'omniwp';
	const ADDRESS_HANDLE = 'omniwp-address';
	const CAPTCHA_HANDLE = 'omniwp-captcha';

	/**
	 * The design tokens, split out in 21.1.
	 *
	 * A dependency of every stylesheet that reads a `--sl-*`, so no caller has
	 * to remember it and no second surface has to restate a colour.
	 */
	const TOKENS_HANDLE = 'omniwp-tokens';
	const BASE_HANDLE   = 'omniwp-base';

	/**
	 * The header button and its account menu, split out in 21.5.
	 *
	 * Separate from HANDLE deliberately. A page that only carries the button
	 * must not pull 1,500 lines of form CSS for a control that shares a colour
	 * token with them and nothing else — that is the cost the two-stage asset
	 * split declined, and a header button must not quietly re-incur it.
	 */
	const BUTTON_HANDLE = 'omniwp-button';
	const HUB_HANDLE    = 'omniwp-account-hub';

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
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		foreach ( array( 'omniwp_auth', 'smart_auth', 'omniwp', 'smart_login', 'omniwp_register', 'smart_register', 'omniwp_verify_otp', 'smart_verify_otp', 'omniwp_forgot_password', 'smart_forgot_password', 'omniwp_profile', 'smart_profile', 'omniwp_account', 'smart_account' ) as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				self::enqueue();
				return;
			}
		}

		/*
		 * The button asks for a different stylesheet, so it is a separate scan
		 * rather than a seventh tag above. Missing from that list entirely until
		 * 21.5, which is finding 1: the one trigger built for people who cannot
		 * edit templates was the only one that rendered unstyled.
		 *
		 * Still worth doing here even though render_button() also asks, for the
		 * reason this method exists — a late enqueue prints in the footer, and a
		 * header button that arrives unstyled and then snaps into place is more
		 * visible than a form doing the same.
		 */
		if ( has_shortcode( $post->post_content, 'omniwp_button' ) || has_shortcode( $post->post_content, 'smart_button' ) ) {
			self::enqueue_button();
		}
	}

	public function register_assets(): void {
		wp_register_style(
			self::TOKENS_HANDLE,
			OMNIWP_URL . 'assets/css/omniwp-tokens.css',
			array(),
			OMNIWP_VERSION
		);

		$primary_color = (string) Settings::get( 'branding.primary_color', '#e30613' );
		$primary_hover = (string) Settings::get( 'branding.primary_hover', '#c00410' );
		$text_color    = (string) Settings::get( 'branding.text_color', '#1f2430' );
		$bg_color      = (string) Settings::get( 'branding.bg_color', '#ffffff' );
		$border_radius = (string) Settings::get( 'branding.border_radius', '5px' );

		$custom_css = ":root {\n";
		if ( ! empty( $primary_color ) ) {
			$custom_css .= "  --sl-accent: {$primary_color} !important;\n";
		}
		if ( ! empty( $primary_hover ) ) {
			$custom_css .= "  --sl-accent-dark: {$primary_hover} !important;\n";
		}
		if ( ! empty( $text_color ) ) {
			$custom_css .= "  --sl-text: {$text_color} !important;\n";
		}
		if ( ! empty( $bg_color ) ) {
			$custom_css .= "  --sl-surface: {$bg_color} !important;\n";
		}
		if ( ! empty( $border_radius ) ) {
			$custom_css .= "  --sl-radius: {$border_radius} !important;\n";
			$custom_css .= "  --sl-radius-card: {$border_radius} !important;\n";
			$custom_css .= "  --sl-radius-sm: {$border_radius} !important;\n";
		}
		$custom_css .= "}\n";

		wp_add_inline_style( self::TOKENS_HANDLE, $custom_css );

		wp_register_style(
			self::BASE_HANDLE,
			OMNIWP_URL . 'assets/css/omniwp-base.css',
			array( self::TOKENS_HANDLE ),
			OMNIWP_VERSION
		);

		wp_register_style(
			self::HANDLE,
			OMNIWP_URL . 'assets/css/omniwp.css',
			array( self::BASE_HANDLE ),
			OMNIWP_VERSION
		);

		wp_register_style(
			self::BUTTON_HANDLE,
			OMNIWP_URL . 'assets/css/omniwp-button.css',
			array( self::BASE_HANDLE ),
			OMNIWP_VERSION
		);

		// Manners, not mechanics: the menu already opens and closes without it.
		// See the file header for what it adds and what it must never remove.
		wp_register_script(
			self::BUTTON_HANDLE,
			OMNIWP_URL . 'assets/js/omniwp-account.js',
			array(),
			OMNIWP_VERSION,
			true
		);

		wp_register_script(
			self::HANDLE,
			OMNIWP_URL . 'assets/js/omniwp.js',
			array(),
			OMNIWP_VERSION,
			true
		);

		wp_register_script(
			self::ADDRESS_HANDLE,
			self::address_script(),
			array(),
			OMNIWP_VERSION,
			true
		);

		// Registered only while a challenge is actually called for. In adaptive
		// mode — the default — that means an ordinary visitor on an ordinary day
		// loads no third-party script at all, which is half the reason the mode
		// exists: a captcha costs a request and a privacy footprint even when
		// nobody looks at it.
		$captcha_script = \OmniWP\Security\Captcha::script_url();

		if ( '' !== $captcha_script ) {
			wp_register_script( self::CAPTCHA_HANDLE, $captcha_script, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the provider versions its own endpoint.
		}

		wp_localize_script( self::ADDRESS_HANDLE, 'OmniWPAddress', self::address_config() );

		wp_localize_script(
			self::HANDLE,
			'OmniWPData',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'omniwp/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				// The same signed timestamp the HTML forms carry. Until it was
				// sent, RequestGuard::verify_rest() had nothing to inspect on the
				// JS path — it was not weak there, it was inert.
				'stamp'     => \OmniWP\Security\RequestGuard::stamp( 'rest' ),
				'otpLength' => min( 8, max( 4, Settings::get_int( 'otp.length', 6 ) ) ),
				'i18n'      => array(
					'resend'           => __( 'Gửi lại', 'omniwp' ),
					/* translators: %d: seconds remaining before the code can be resent. */
					'resendIn'         => __( 'Gửi lại sau %ds', 'omniwp' ),
					'expired'          => __( 'Mã đã hết hạn', 'omniwp' ),
					'sending'          => __( 'Đang gửi…', 'omniwp' ),
					'showPass'         => __( 'Hiện mật khẩu', 'omniwp' ),
					'hidePass'         => __( 'Ẩn mật khẩu', 'omniwp' ),
					/* translators: %s: masked phone number or email address. */
					'contactSent'      => __( 'Mã OTP đã được gửi tới %s.', 'omniwp' ),
					'contactDone'      => __( 'Thông tin liên hệ đã được xác thực.', 'omniwp' ),
					'contactCancelled' => __( 'Đã hủy yêu cầu xác thực.', 'omniwp' ),
					'contactWait'      => __( 'Đang xử lý…', 'omniwp' ),
					'unsaved'          => __( 'Có thay đổi chưa lưu', 'omniwp' ),
				),
			)
		);
	}

	/**
	 * The address picker's script, and everything it needs to run.
	 *
	 * Two callers, one array: the localize call above, and
	 * `LoginDialog::contract()`. The dialog loads this script itself — the
	 * enqueue below is a no-op inside the REST request that renders its
	 * fragments, which is 19.12 — and a second copy of the config assembled over
	 * there is how the two drift. Same argument as the step allowlist, applied to
	 * a payload instead of a list.
	 */
	public static function address_script(): string {
		return OMNIWP_URL . 'assets/js/address.js';
	}

	/**
	 * @return array{restUrl:string,i18n:array<string,string>}
	 */
	public static function address_config(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'omniwp/v1/' ) ),
			'i18n'    => array(
				'chooseProvince' => __( 'Chọn Tỉnh/Thành phố', 'omniwp' ),
				'chooseWard'     => __( 'Chọn Phường/Xã', 'omniwp' ),
				'loading'        => __( 'Đang tải…', 'omniwp' ),
				'noResults'      => __( 'Không tìm thấy', 'omniwp' ),
			),
		);
	}

	/**
	 * Called by whatever renders a form.
	 */
	/**
	 * The header button only.
	 *
	 * Never calls enqueue(): the point of the split is that a page carrying the
	 * button does not load the sign-in form's stylesheet. The token dependency
	 * comes along on its own, declared at registration.
	 */
	public static function enqueue_button(): void {
		wp_enqueue_style( self::BUTTON_HANDLE );
		wp_enqueue_script( self::BUTTON_HANDLE );
	}

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
