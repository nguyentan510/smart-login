<?php
/**
 * Login: phone-aware authentication, lockouts and the optional new-device OTP.
 *
 * Everything hangs off the core `authenticate` filter so wp-login.php,
 * WooCommerce and the plugin's own form all behave identically.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\IdentityDirectory;
use SmartLogin\Identity\ProfileSeeder;
use SmartLogin\Identity\UserManager;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\Client;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class LoginHandler {

	const META_DEVICES = 'smartlogin_known_devices';

	/** Only the plugin's own forms can be interrupted for a device check. */
	private static $enforce_device_check = false;

	/** @var RateLimiter */
	private $limiter;

	private IdentityDirectory $directory;

	public function __construct( ?RateLimiter $limiter = null, ?IdentityDirectory $directory = null ) {
		$this->limiter   = $limiter ?? new RateLimiter();
		$this->directory = $directory ?? new IdentityDirectory();
	}

	public function register(): void {
		add_filter( 'authenticate', array( $this, 'gate_lockout' ), 5, 3 );
		add_filter( 'authenticate', array( $this, 'authenticate_by_identity' ), 30, 3 );
		add_filter( 'authenticate', array( $this, 'maybe_require_device_otp' ), 40, 3 );

		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'on_login_success' ), 10, 2 );

		// Only mirror an already verified canonical phone into Woo billing data.
		add_action( 'profile_update', array( $this, 'sync_phone_from_profile' ), 10, 1 );
	}

	/**
	 * Sign in through the plugin's own form, with the device check enabled.
	 *
	 * @return WP_User|WP_Error
	 */
	public function attempt( string $identity, string $password, bool $remember = true ) {
		self::$enforce_device_check = true;
		$user = wp_authenticate( $identity, $password );
		self::$enforce_device_check = false;

		return $user;
	}

	// -----------------------------------------------------------------
	// authenticate filter chain
	// -----------------------------------------------------------------

	/**
	 * Refuse everything while the identity/IP pair is locked out.
	 *
	 * @param WP_User|WP_Error|null $user
	 * @return WP_User|WP_Error|null
	 */
	public function gate_lockout( $user, $username, $password ) {
		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}

		$remaining = $this->limiter->login_lock_remaining( (string) $username );

		if ( $remaining <= 0 ) {
			return $user;
		}

		return new WP_Error(
			'smart_login_locked',
			sprintf(
				/* translators: %d: minutes remaining. */
				__( 'Tài khoản tạm thời bị khoá do đăng nhập sai nhiều lần. Vui lòng thử lại sau %d phút.', 'smart-login' ),
				max( 1, (int) ceil( $remaining / MINUTE_IN_SECONDS ) )
			)
		);
	}

	/**
	 * Core could not resolve the identifier — ask the identity directory.
	 *
	 * Runs at priority 30, after core's own handlers at 20. Core can still
	 * resolve a real email address, which is intended: wp_users.user_email is
	 * kept in sync when a user changes it, so it is not a stale shadow copy.
	 * user_login is, which is exactly why it is opaque and unguessable now.
	 *
	 * A RETIRED subject falls through to generic_failure() rather than to its
	 * previous owner. That is the account-takeover fix at the login path.
	 *
	 * @param WP_User|WP_Error|null $user
	 * @return WP_User|WP_Error|null
	 */
	public function authenticate_by_identity( $user, $username, $password ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}

		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}

		$claim = $this->directory->channels()->claim_any( (string) $username );

		if ( $claim->is_empty() ) {
			return $user;
		}

		$decision = AuthAction::for_resolution(
			AuthAction::LOGIN,
			$this->directory->resolve( $claim )
		);

		if ( AuthAction::ISSUE_SESSION !== $decision ) {
			return $this->generic_failure();
		}

		$found = $this->directory->owner( $claim );

		if ( ! $found ) {
			return $this->generic_failure();
		}

		if ( ! wp_check_password( (string) $password, $found->user_pass, $found->ID ) ) {
			return $this->generic_failure();
		}

		return $found;
	}

	/**
	 * Interrupt an otherwise valid login when the device is unrecognised.
	 *
	 * @param WP_User|WP_Error|null $user
	 * @return WP_User|WP_Error|null
	 */
	public function maybe_require_device_otp( $user, $username, $password ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		if ( ! self::$enforce_device_check || ! Settings::is_on( 'login_otp_new_device' ) ) {
			return $user;
		}

		if ( self::is_known_device( $user->ID ) ) {
			return $user;
		}

		// The password was correct, so this is not a failed attempt — the caller
		// picks up the user ID from the error data and starts an OTP step.
		return new WP_Error(
			'smart_login_needs_otp',
			__( 'Chúng tôi cần xác thực thiết bị mới của bạn.', 'smart-login' ),
			array( 'user_id' => $user->ID )
		);
	}

	/**
	 * Identical message whether the account exists or the password was wrong.
	 */
	private function generic_failure(): WP_Error {
		return new WP_Error(
			'smart_login_invalid_credentials',
			Settings::phone_enabled()
				? __( 'Số điện thoại hoặc mật khẩu không đúng.', 'smart-login' )
				: __( 'Email hoặc mật khẩu không đúng.', 'smart-login' )
		);
	}

	// -----------------------------------------------------------------
	// Bookkeeping
	// -----------------------------------------------------------------

	/**
	 * @param string             $username
	 * @param WP_Error|null      $error
	 */
	public function on_login_failed( $username, $error = null ): void {
		$code = ( $error instanceof WP_Error ) ? $error->get_error_code() : '';

		// A pending device check is not a credential failure.
		if ( 'smart_login_needs_otp' === $code || 'smart_login_locked' === $code ) {
			return;
		}

		$this->limiter->record_login_failure( (string) $username );

		AuditLog::record(
			AuditLog::LOGIN_FAILED,
			RateLimiter::mask_identity( (string) $username ),
			array( 'code' => $code )
		);
	}

	/**
	 * @param string  $user_login
	 * @param WP_User $user
	 */
	public function on_login_success( $user_login, $user = null ): void {
		$this->limiter->clear_login_failures( (string) $user_login );

		if ( $user instanceof WP_User ) {
			self::remember_device( $user->ID );

			AuditLog::record(
				AuditLog::LOGIN_SUCCESS,
				RateLimiter::mask_identity( (string) $user_login ),
				array(),
				$user->ID
			);
		}
	}

	/**
	 * Mirror the verified canonical phone into Woo billing metadata.
	 *
	 * Billing forms are not an authentication boundary. They must never promote
	 * a user-entered number into the canonical login identity.
	 */
	public function sync_phone_from_profile( $user_id ): void {
		if ( ! Settings::is_on( 'woo_sync_billing_phone' ) ) {
			return;
		}

		$canonical = (string) get_user_meta( $user_id, UserManager::META_PHONE, true );
		$verified  = (string) get_user_meta( $user_id, UserManager::META_PHONE_VERIFIED, true );
		if ( '' === $canonical || '' === $verified ) {
			return;
		}

		$canonical = \SmartLogin\Identity\Phone::normalize( $canonical );
		if ( '' === $canonical || ! \SmartLogin\Identity\Phone::is_valid( $canonical ) ) {
			return;
		}

		// Seed only. The old code short-circuited when billing already matched the
		// login phone and otherwise overwrote it — so any deliberately different
		// delivery contact was reset on the next profile save.
		ProfileSeeder::seed_if_empty( (int) $user_id, 'billing_phone', \SmartLogin\Identity\Phone::to_local( $canonical ) );
	}

	// -----------------------------------------------------------------
	// Device memory
	// -----------------------------------------------------------------

	public static function is_known_device( int $user_id ): bool {
		$devices = get_user_meta( $user_id, self::META_DEVICES, true );
		$devices = is_array( $devices ) ? $devices : array();

		return isset( $devices[ Client::device_fingerprint() ] );
	}

	public static function remember_device( int $user_id ): void {
		if ( ! Settings::is_on( 'login_otp_new_device' ) ) {
			return;
		}

		$devices = get_user_meta( $user_id, self::META_DEVICES, true );
		$devices = is_array( $devices ) ? $devices : array();

		$devices[ Client::device_fingerprint() ] = time();

		// Keep the newest ten so the meta cannot grow without bound.
		arsort( $devices );
		$devices = array_slice( $devices, 0, 10, true );

		update_user_meta( $user_id, self::META_DEVICES, $devices );
	}

	/**
	 * Where to send the user after signing in.
	 */
	public static function post_login_redirect( string $requested = '' ): string {
		if ( '' !== $requested ) {
			$url = $requested;
		} else {
			$configured = trim( (string) Settings::get( 'redirect_after_login', '' ) );

			if ( '' !== $configured ) {
				$url = $configured;
			} elseif ( function_exists( 'wc_get_page_permalink' ) ) {
				$url = wc_get_page_permalink( 'myaccount' );
			} else {
				$url = home_url( '/' );
			}
		}

		/**
		 * @param string $url
		 */
		$url = wp_validate_redirect( $url, home_url( '/' ) );
		return wp_validate_redirect( (string) apply_filters( 'smart_login_post_login_redirect', $url ), $url );
	}
}
