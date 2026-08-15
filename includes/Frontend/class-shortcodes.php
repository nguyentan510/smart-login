<?php
/**
 * Shortcodes rendering the auth flow anywhere on the site.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Address\AddressFields;
use OmniWP\Auth\PendingSession;
use OmniWP\Frontend\Flow;
use OmniWP\Identity\UserManager;
use OmniWP\OTP\OtpService;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class Shortcodes {

	/**
	 * Every tag this plugin registers, and the defaults it accepts.
	 *
	 * The one list. `register()` walks it, the two render methods that take
	 * attributes read their `shortcode_atts()` defaults out of it, and the
	 * Hướng dẫn screen documents it. A tag that is registered but undocumented
	 * cannot be expressed, because there is no second list to leave it out of —
	 * which is the state this replaced: nine tags registered, six named in
	 * `README.md`, and `[smart_account]` and `[smart_address]` named nowhere.
	 *
	 * **Structure only.** No labels, no help text: this array is built on every
	 * front-end request on its way to rendering a login form, and it must not be
	 * paying to translate copy that is only ever read in `wp-admin`. The prose
	 * lives on the screen, keyed by the same tags, and a rule requires the two
	 * key sets to be identical in both directions.
	 *
	 * @var array<string,array{callback:string,atts:array<string,string>}>
	 */
	public const CATALOG = array(
		'smart_auth'             => array(
			'callback' => 'render_login',
			'atts'     => array(),
		),
		'smart_login'            => array(
			'callback' => 'render_login',
			'atts'     => array(),
		),
		'omniwp_auth'            => array(
			'callback' => 'render_login',
			'atts'     => array(),
		),
		'omniwp_login'           => array(
			'callback' => 'render_login',
			'atts'     => array(),
		),
		'smart_register'         => array(
			'callback' => 'render_register',
			'atts'     => array(),
		),
		'omniwp_register'        => array(
			'callback' => 'render_register',
			'atts'     => array(),
		),
		'smart_verify_otp'       => array(
			'callback' => 'render_otp',
			'atts'     => array(),
		),
		'omniwp_verify_otp'      => array(
			'callback' => 'render_otp',
			'atts'     => array(),
		),
		'smart_forgot_password'  => array(
			'callback' => 'render_forgot',
			'atts'     => array(),
		),
		'omniwp_forgot_password' => array(
			'callback' => 'render_forgot',
			'atts'     => array(),
		),
		'smart_profile'          => array(
			'callback' => 'render_profile',
			'atts'     => array(),
		),
		'omniwp_profile'         => array(
			'callback' => 'render_profile',
			'atts'     => array(),
		),
		'smart_account'          => array(
			'callback' => 'render_account',
			'atts'     => array(),
		),
		'omniwp_account'         => array(
			'callback' => 'render_account',
			'atts'     => array(),
		),
		'smart_address'          => array(
			'callback' => 'render_address',
			'atts'     => array( 'required' => 'yes' ),
		),
		'omniwp_address'         => array(
			'callback' => 'render_address',
			'atts'     => array( 'required' => 'yes' ),
		),
		'smart_vouchers'         => array(
			'callback' => 'render_vouchers',
			'atts'     => array(),
		),
		'omniwp_vouchers'        => array(
			'callback' => 'render_vouchers',
			'atts'     => array(),
		),
		'smart_login_button'     => array(
			'callback' => 'render_button',
			'atts'     => array(
				'step'     => Flow::STEP_IDENTIFY,
				'label'    => '',
				'class'    => '',
				'collapse' => 'mobile',
			),
		),
		'omniwp_button'          => array(
			'callback' => 'render_button',
			'atts'     => array(
				'step'     => Flow::STEP_IDENTIFY,
				'label'    => '',
				'class'    => '',
				'collapse' => 'mobile',
			),
		),
		'smart_cart'             => array(
			'callback' => 'render_cart',
			'atts'     => array(),
		),
		'omniwp_cart'            => array(
			'callback' => 'render_cart',
			'atts'     => array(),
		),
		'smart_checkout'         => array(
			'callback' => 'render_checkout',
			'atts'     => array(),
		),
		'omniwp_checkout'        => array(
			'callback' => 'render_checkout',
			'atts'     => array(),
		),
		'smart_cart_button'      => array(
			'callback' => 'render_cart_button',
			'atts'     => array(
				'label' => '',
				'class' => '',
			),
		),
		'omniwp_cart_button'     => array(
			'callback' => 'render_cart_button',
			'atts'     => array(
				'label' => '',
				'class' => '',
			),
		),
	);

	public function register(): void {
		foreach ( self::CATALOG as $tag => $entry ) {
			add_shortcode( $tag, array( $this, $entry['callback'] ) );
		}
	}

	/**
	 * A trigger for the dialog, for a site nobody can edit templates on.
	 *
	 * Every other trigger in the contract requires markup: a `data-` attribute,
	 * a hand-written `#login`, a call into `window.OmniWP`. Somebody building
	 * a page in an editor has none of those, and telling them to ask a developer
	 * for a button is how a feature goes unused.
	 *
	 * It renders an **anchor**, not a button, and its `href` is the real sign-in
	 * page. With the script blocked the visitor gets a link that works; the
	 * launcher intercepts the click and never touches the href. When no page
	 * hosts the shortcode `Flow::login_url()` is '' and the fragment stands in —
	 * the same "there is no third answer" case that method already documents.
	 */
	public function render_button( $atts = array() ): string {
		$atts = shortcode_atts(
			self::CATALOG['omniwp_button']['atts'],
			(array) $atts,
			'omniwp_button'
		);

		Assets::enqueue_button();

		$step     = in_array( $atts['step'], Flow::public_steps(), true ) ? (string) $atts['step'] : Flow::STEP_IDENTIFY;
		$href     = Flow::login_url();
		$collapse = 'none' !== $atts['collapse'];

		if ( is_user_logged_in() ) {
			return TemplateLoader::render(
				'account-button',
				array(
					'label'    => self::account_label(),
					'items'    => AccountMenu::items( get_current_user_id() ),
					'class'    => trim( (string) $atts['class'] ),
					'collapse' => $collapse,
				)
			);
		}

		return TemplateLoader::render(
			'login-button',
			array(
				'label'    => '' !== trim( (string) $atts['label'] )
					? (string) $atts['label']
					: __( 'Đăng nhập', 'omniwp' ),
				'step'     => $step,
				'href'     => '' !== $href ? add_query_arg( 'OMNIWP_step', $step, $href ) : '#login',
				'class'    => trim( (string) $atts['class'] ),
				'collapse' => $collapse,
			)
		);
	}

	/**
	 * What the signed-in button calls the member.
	 *
	 * `auto` is the phone when there is one and the display name otherwise. No
	 * email option exists, and that is spec decision 12 rather than an oversight:
	 * an OTP registration mints an address nobody chose, so a member whose only
	 * address is synthetic would see a machine-generated string in the header.
	 */
	private static function account_label(): string {
		$user_id = get_current_user_id();
		$source  = (string) Settings::get( 'account_menu.label_source', 'auto' );
		$phone   = (string) get_user_meta( $user_id, UserManager::META_PHONE, true );
		$user    = wp_get_current_user();
		$name    = $user ? (string) $user->display_name : '';

		if ( 'phone' === $source ) {
			return '' !== $phone ? $phone : $name;
		}

		if ( 'name' === $source ) {
			return '' !== $name ? $name : $phone;
		}

		return '' !== $phone ? $phone : $name;
	}

	/**
	 * The editable account surface, on any page, with or without WooCommerce.
	 *
	 * [smart_profile] shows a member what is on file; this lets them change it.
	 * Until Phase 8.3 the only way to edit anything was the WooCommerce account
	 * page, so a site without WooCommerce had no profile editing at all and the
	 * summary's "Cập nhật ngay" link pointed into wp-admin.
	 */
	public function render_account( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_flow( Flow::STEP_IDENTIFY, (array) $atts );
		}

		ob_start();
		AccountHub::render( (array) $atts );
		return (string) ob_get_clean();
	}

	/**
	 * Standalone address picker, usable inside any form on the site.
	 *
	 * Renders only the fields — the surrounding <form>, nonce and submit button
	 * belong to whoever is embedding it.
	 */
	public function render_address( $atts = array() ): string {
		if ( ! Settings::is_on( 'address.enabled' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			self::CATALOG['smart_address']['atts'],
			(array) $atts,
			'smart_address'
		);

		$values = is_user_logged_in()
			? AddressFields::get_for_user( get_current_user_id() )
			: array();

		return AddressFields::render(
			array(
				'values'   => $values ?: null,
				'required' => 'no' !== $atts['required'],
			)
		);
	}

	/**
	 * Render the standalone Voucher list.
	 */
	public function render_vouchers( $atts = array() ): string {
		$atts = shortcode_atts( array(), (array) $atts, 'omniwp_vouchers' );
		unset( $atts );
		if ( ! is_user_logged_in() ) {
			return '<p class="sl-guest-notice">' . esc_html__( 'Bạn cần đăng nhập để xem các mã giảm giá dành riêng cho mình.', 'omniwp' ) . '</p>';
		}

		AccountHub::enqueue_assets();

		$user = wp_get_current_user();

		ob_start();
		echo '<div class="omniwp omniwp--vouchers-standalone">';
		TemplateLoader::output(
			'account-hub/tab-vouchers',
			array(
				'user' => $user,
				'tab'  => array(
					'key'   => 'vouchers',
					'label' => __( 'Mã giảm giá', 'omniwp' ),
				),
			)
		);
		TemplateLoader::output( 'account-hub/voucher-modal', array( 'user' => $user ) );
		echo '</div>';
		return (string) ob_get_clean();
	}

	public function render_login( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_IDENTIFY, (array) $atts );
	}

	/**
	 * Identifier-first has no separate registration screen — the entry step
	 * works out which one the visitor needs. The shortcode survives because
	 * pages are already using it, and only changes the framing of step 1.
	 */
	public function render_register( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_IDENTIFY, (array) $atts + array( 'mode' => 'register' ) );
	}

	public function render_otp( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_OTP, (array) $atts );
	}

	public function render_forgot( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_FORGOT, (array) $atts );
	}

	/**
	 * The whole state machine in one place, so a single page can host every step.
	 */
	public function render_flow( string $default_step, array $atts = array() ): string {
		Assets::enqueue();

		return $this->render_step( $default_step, $atts );
	}

	/**
	 * The same render, without enqueueing.
	 *
	 * Split out in 19.2 for the fragment endpoint. A REST request has no
	 * `wp_enqueue_scripts`, so calling `Assets::enqueue()` there is a silent
	 * no-op that looks like it works — the dialog loads its own assets before it
	 * ever asks for markup.
	 *
	 * Everything below this line is shared, which is the point: one set of
	 * templates serves the page and the dialog, so the two cannot drift.
	 */
	public function render_step( string $default_step, array $atts = array() ): string {
		$step = Flow::step( $default_step );

		// A just-registered member arrives back here by redirect carrying the
		// welcome flag. Both the native flow and an OAuth signup land this way, so
		// this is the only place that decides to show onboarding.
		if ( self::is_welcome_request() ) {
			$step = Flow::STEP_ONBOARD;
		}

		// STEP_ONBOARD renders for a user who has just been signed in, so it is
		// the one authenticated step this box may show.
		if ( is_user_logged_in() && ! in_array( $step, array( Flow::STEP_DONE, Flow::STEP_ONBOARD ), true ) ) {
			return TemplateLoader::render(
				'logged-in',
				array(
					'user'       => wp_get_current_user(),
					'notices'    => Notices::all(),
					'my_account' => \OmniWP\Auth\LoginHandler::post_login_redirect(),
				)
			);
		}

		$common = array(
			'notices' => Notices::all(),
			'atts'    => $atts,
		);

		switch ( $step ) {
			case Flow::STEP_PASSWORD:
				return TemplateLoader::render(
					'form-password',
					$common + array( 'identity' => (string) Flow::data( 'identity', Flow::old( 'identity' ) ) )
				);

			case Flow::STEP_SIGNUP:
				return TemplateLoader::render(
					'form-signup',
					$common + array(
						'grant'        => (string) Flow::data( 'grant', '' ),
						'terms_url'    => (string) Settings::get( 'signup.terms_url', '' ),
						'min_password' => max( 6, Settings::get_int( 'signup.min_password_length', 8 ) ),
					)
				);

			case Flow::STEP_OTP:
				return TemplateLoader::render( 'form-otp', $common + $this->otp_args() );

			case Flow::STEP_FORGOT:
				return TemplateLoader::render( 'form-forgot', $common );

			case Flow::STEP_RESET:
				return TemplateLoader::render( 'form-reset', $common + array( 'grant' => (string) Flow::data( 'grant', '' ) ) );

			case Flow::STEP_ONBOARD:
				return TemplateLoader::render( 'onboarding', $common + self::onboarding_args() );

			case Flow::STEP_DONE:
				return TemplateLoader::render(
					'registered-success',
					$common + array(
						'redirect' => (string) Flow::data( 'redirect', home_url( '/' ) ),
						'user_id'  => (int) Flow::data( 'user_id', 0 ),
					)
				);

			case Flow::STEP_IDENTIFY:
			default:
				return TemplateLoader::render( 'form-auth', $common + $this->identify_args( $atts ) );
		}
	}

	/**
	 * @param array $atts Shortcode attributes; `mode=register` only changes the
	 *                    wording, never which form is shown.
	 */
	private function identify_args( array $atts ): array {
		return array(
			'mode'      => 'register' === ( $atts['mode'] ?? '' ) ? 'register' : 'login',
			'terms_url' => (string) Settings::get( 'signup.terms_url', '' ),
		);
	}

	/**
	 * Whether this request is a member returning from registration or needing profile completion.
	 */
	public static function is_welcome_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only presentation switch.
		return is_user_logged_in() && ! empty( $_GET['OmniWP_welcome'] );
	}

	/**
	 * Arguments for the welcome screen, whether it was reached in place after a
	 * registration or by landing on the account page with ?OmniWP_welcome=1.
	 */
	public static function onboarding_args(): array {
		$user_id  = (int) Flow::data( 'user_id', get_current_user_id() );
		$profiles = new \OmniWP\Auth\ProfileCompletionService();
		$fields   = Flow::data( 'fields', null );

		$is_new_user = true;
		if ( isset( $_GET['incomplete'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_new_user = false;
		} elseif ( ! isset( $_GET['new'] ) && $profiles->has_seen( $user_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_new_user = false;
		}

		if ( $user_id > 0 && ! $profiles->has_seen( $user_id ) ) {
			$profiles->mark_seen( $user_id, 'otp' );
		}

		$redirect = '';
		if ( isset( $_GET['redirect_to'] ) && '' !== $_GET['redirect_to'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect = wp_validate_redirect( rawurldecode( sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $redirect ) {
			$redirect = (string) Flow::data( 'redirect', \OmniWP\Auth\RegisterHandler::post_register_redirect( $user_id ) );
		}

		return array(
			'user'          => $user_id > 0 ? get_userdata( $user_id ) : wp_get_current_user(),
			'is_new_user'   => $is_new_user,
			'fields'        => is_array( $fields ) ? $fields : $profiles->onboarding_fields( $user_id ),
			// Where "Hoàn tất" and "Để sau" both lead.
			'redirect'      => $redirect,
			'address'       => Settings::is_on( 'address.enabled' )
				? AddressFields::get_for_user( $user_id )
				: array(),
			// Onboarding has no email input — changing one needs its own OTP
			// round-trip — so the screen points at the profile page instead.
			'email_missing' => UserManager::user_has_synthetic_email( $user_id ),
		);
	}

	/**
	 * Rebuild the OTP screen state, falling back to the live row when the page
	 * was reloaded rather than reached through a form post.
	 */
	private function otp_args(): array {
		$intent       = (string) Flow::data( 'intent', '' );
		$masked       = (string) Flow::data( 'masked', '' );
		$expires_in   = Flow::data( 'expires_in', null );
		$resend_after = (int) Flow::data( 'resend_after', 0 );
		$transport    = (string) Flow::data( 'transport', 'sms' );

		if ( null === $expires_in ) {
			$service = new OtpService();
			$session = PendingSession::get();
			$row     = $session ? $service->peek( $session['token'] ) : null;

			if ( $row ) {
				$intent     = $session['intent'];
				$masked     = \OmniWP\Security\RateLimiter::mask_identity( $row['destination'] );
				$expires_in = $service->seconds_left( $row );
				$transport  = $row['transport'];
			} else {
				$expires_in = 0;
			}
		}

		return array(
			'intent'       => $intent,
			'masked'       => $masked,
			'expires_in'   => (int) $expires_in,
			'resend_after' => $resend_after,
			'transport'    => $transport,
			'otp_length'   => min( 8, max( 4, Settings::get_int( 'otp.length', 6 ) ) ),
			'dev_code'     => (string) Flow::data( 'dev_code', '' ),
			'has_session'  => (bool) PendingSession::token(),
		);
	}

	/**
	 * Profile summary with the "complete your profile" nudge from the plan.
	 */
	public function render_profile( $atts = array() ): string {
		Assets::enqueue();

		if ( ! is_user_logged_in() ) {
			return $this->render_flow( Flow::STEP_LOGIN, (array) $atts );
		}

		$user_id = get_current_user_id();
		$status  = ( new \OmniWP\Auth\ProfileCompletionService() )->status( $user_id );

		return TemplateLoader::render(
			'profile-summary',
			array(
				'user'      => wp_get_current_user(),
				'notices'   => Notices::all(),
				'missing'   => UserManager::missing_profile_fields( $user_id ),
				'status'    => $status,
				'pending'   => ( new \OmniWP\Auth\ContactVerificationService() )->pending( $user_id ),
				'phone'     => (string) get_user_meta( $user_id, UserManager::META_PHONE, true ),
				'synthetic' => UserManager::user_has_synthetic_email( $user_id ),
				'welcome'   => ! empty( $_GET['OmniWP_welcome'] ), // phpcs:ignore WordPress.Security.NonceVerification
			)
		);
	}

	public function render_cart( $atts = array() ): string {
		$atts = shortcode_atts( array(), (array) $atts, 'omniwp_cart' );
		unset( $atts );

		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '<div class="omniwp-cart-preview" style="padding:24px;text-align:center;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;"><strong>' . esc_html__( '[Giỏ hàng OmniWP Cart]', 'omniwp' ) . '</strong></div>';
		}

		return TemplateLoader::render( 'ecommerce/cart-page' );
	}

	public function render_checkout( $atts = array() ): string {
		$atts = shortcode_atts( array(), (array) $atts, 'omniwp_checkout' );
		unset( $atts );

		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '<div class="omniwp-checkout-preview" style="padding:24px;text-align:center;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;"><strong>' . esc_html__( '[Trang thanh toán OmniWP Checkout]', 'omniwp' ) . '</strong></div>';
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return $this->render_thankyou();
		}

		return TemplateLoader::render( 'ecommerce/checkout-page' );
	}

	/**
	 * Render Thank You / Order Received page content.
	 */
	public function render_thankyou(): string {
		global $wp;

		$order_id  = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id && isset( $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( $_GET['order_id'] );
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( $order && $order_key && ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			$order = false;
		}

		if ( ! $order ) {
			return '<div class="omniwp sl-thankyou-wrapper" style="padding:30px;text-align:center;"><p>' . esc_html__( 'Không tìm thấy thông tin đơn hàng.', 'omniwp' ) . '</p></div>';
		}

		ob_start();
		do_action( 'woocommerce_before_thankyou', $order->get_id() );

		if ( \OmniWP\Settings::is_on( 'ecommerce.thankyou_custom_enabled', true ) ) {
			( new \OmniWP\Ecommerce\ThankYouService() )->render_custom_thankyou( $order->get_id() );
		} else {
			wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) );
		}

		return (string) ob_get_clean();
	}

	public function render_cart_button( $atts = array() ): string {
		$atts = shortcode_atts(
			self::CATALOG['omniwp_cart_button']['atts'],
			(array) $atts,
			'omniwp_cart_button'
		);

		$count = function_exists( 'WC' ) && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
		$label = ! empty( $atts['label'] ) ? esc_html( $atts['label'] ) : __( 'Giỏ hàng', 'omniwp' );
		$class = ! empty( $atts['class'] ) ? ' ' . esc_attr( $atts['class'] ) : '';

		return sprintf(
			'<button type="button" class="sl-btn sl-btn--outline sl-cart-trigger%s" data-omniwp="cart">
				🛒 %s <span class="sl-cart-badge">(%d)</span>
			</button>',
			$class,
			$label,
			$count
		);
	}
}
