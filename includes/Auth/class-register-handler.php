<?php
/**
 * Registration: validate → issue OTP → verify → create account → sign in.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\IdentityResolver;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class RegisterHandler {

	/** @var OtpService */
	private $otp;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp ?? new OtpService();
	}

	/**
	 * Step 1: validate the form and send a code. No user row is created yet —
	 * everything the form collected rides along in the OTP payload.
	 *
	 * @param array $input Raw (still slashed) request data.
	 * @return array|WP_Error Result of OtpService::issue().
	 */
	public function start( array $input ) {
		$identity = $this->validate_identity( $input );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$password = $this->validate_password( $input );

		if ( is_wp_error( $password ) ) {
			return $password;
		}

		$full_name = sanitize_text_field( wp_unslash( $input['full_name'] ?? '' ) );

		if ( '' === trim( $full_name ) ) {
			return new WP_Error( 'smart_login_no_name', __( 'Vui lòng nhập họ tên.', 'smart-login' ) );
		}

		if ( empty( $input['terms'] ) ) {
			return new WP_Error( 'smart_login_no_terms', __( 'Vui lòng đồng ý với các điều kiện áp dụng.', 'smart-login' ) );
		}

		$payload = array(
			'identity_type' => $identity['type'],
			'phone'         => IdentityResolver::TYPE_PHONE === $identity['type'] ? $identity['value'] : '',
			'email'         => IdentityResolver::TYPE_EMAIL === $identity['type'] ? $identity['value'] : '',
			'pass_hash'     => wp_hash_password( $password ),
			'full_name'     => $full_name,
			'dob'           => self::parse_dob( (string) ( $input['dob'] ?? '' ) ),
			'gender'        => in_array( $input['gender'] ?? '', array( 'male', 'female', 'other' ), true ) ? $input['gender'] : '',
			'referral_code' => sanitize_text_field( wp_unslash( $input['referral_code'] ?? '' ) ),
		);

		/**
		 * Add custom fields to the pending registration.
		 *
		 * @param array $payload
		 * @param array $input
		 */
		$payload = (array) apply_filters( 'smart_login_registration_payload', $payload, $input );

		AuditLog::record(
			AuditLog::REGISTER_STARTED,
			RateLimiter::mask_identity( $identity['value'] ),
			array( 'type' => $identity['type'] )
		);

		$result = $this->otp->issue(
			$identity['value'],
			OtpService::PURPOSE_REGISTER,
			$payload,
			array( 'user_name' => $full_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PendingSession::start( $result['token'], OtpService::PURPOSE_REGISTER );

		return $result;
	}

	/**
	 * Step 2: check the code, create the account and sign the user in.
	 *
	 * @return int|WP_Error New user ID.
	 */
	public function complete( string $token, string $code ) {
		$row = $this->otp->verify( $token, $code, OtpService::PURPOSE_REGISTER );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( OtpService::PURPOSE_REGISTER !== $row['purpose'] ) {
			return new WP_Error( 'smart_login_wrong_purpose', __( 'Phiên xác thực không hợp lệ.', 'smart-login' ) );
		}

		$payload = is_array( $row['payload'] ) ? $row['payload'] : array();

		// The destination on the row is authoritative — never the payload, which
		// the browser could in principle have influenced.
		if ( IdentityResolver::TYPE_EMAIL === ( $payload['identity_type'] ?? '' ) ) {
			$payload['email'] = $row['destination'];
		} else {
			$payload['phone'] = $row['destination'];
		}

		$user_id = UserManager::create_verified_user( $payload );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		AuditLog::record(
			AuditLog::USER_REGISTERED,
			RateLimiter::mask_identity( $row['destination'] ),
			array( 'type' => $payload['identity_type'] ?? '' ),
			$user_id
		);

		/**
		 * A verified account has just been created.
		 *
		 * @param int   $user_id
		 * @param array $payload
		 */
		do_action( 'smart_login_user_registered', $user_id, $payload );

		$this->sign_in( $user_id );

		PendingSession::clear();

		return $user_id;
	}

	/**
	 * Log the freshly created user in for this and subsequent requests.
	 */
	public function sign_in( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		( new SessionIssuer() )->issue(
			$user,
			new AuthContext(
				array(
					'auth_method'   => 'otp',
					'user_id'       => $user_id,
					'is_new_user'   => true,
					'email_verified' => '' !== (string) get_user_meta( $user_id, UserManager::META_EMAIL_VERIFIED, true ),
				)
			)
		);
	}

	/**
	 * Where to send the user once registration finishes.
	 */
	public static function post_register_redirect( int $user_id ): string {
		$context = new AuthContext( array( 'auth_method' => 'otp', 'user_id' => $user_id, 'is_new_user' => true ) );
		$result  = new AuthResult( $user_id, $context, ( new ProfileCompletionService() )->status( $user_id ) );
		return ( new PostAuthRedirector() )->redirect( $result );
	}

	// -----------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------

	/**
	 * @return array{type:string,value:string}|WP_Error
	 */
	private function validate_identity( array $input ) {
		$raw = (string) ( $input['identity'] ?? $input['phone'] ?? $input['email'] ?? '' );
		$raw = trim( wp_unslash( $raw ) );

		if ( '' === $raw ) {
			return new WP_Error(
				'smart_login_no_identity',
				Settings::phone_enabled()
					? __( 'Vui lòng nhập số điện thoại.', 'smart-login' )
					: __( 'Vui lòng nhập địa chỉ email.', 'smart-login' )
			);
		}

		$identity = IdentityResolver::classify( $raw );

		if ( IdentityResolver::TYPE_PHONE === $identity['type'] ) {
			if ( '' === $identity['value'] ) {
				return new WP_Error( 'smart_login_bad_phone', __( 'Số điện thoại không hợp lệ.', 'smart-login' ) );
			}

			if ( IdentityResolver::user_by_phone( $identity['value'] ) ) {
				return new WP_Error(
					'smart_login_phone_taken',
					__( 'Số điện thoại này đã được đăng ký. Vui lòng đăng nhập.', 'smart-login' )
				);
			}

			return $identity;
		}

		if ( IdentityResolver::TYPE_EMAIL === $identity['type'] ) {
			if ( '' === $identity['value'] ) {
				return new WP_Error( 'smart_login_bad_email', __( 'Địa chỉ email không hợp lệ.', 'smart-login' ) );
			}

			if ( email_exists( $identity['value'] ) ) {
				return new WP_Error(
					'smart_login_email_taken',
					__( 'Email này đã được đăng ký. Vui lòng đăng nhập.', 'smart-login' )
				);
			}

			return $identity;
		}

		return new WP_Error(
			'smart_login_bad_identity',
			sprintf(
				/* translators: %s: identifier label, e.g. "Số điện thoại". */
				__( '%s không hợp lệ.', 'smart-login' ),
				IdentityResolver::identifier_label()
			)
		);
	}

	/**
	 * @return string|WP_Error The accepted plaintext password.
	 */
	private function validate_password( array $input ) {
		// Not sanitised on purpose: any transformation would change the password.
		// Accept the canonical plugin field and the WooCommerce-compatible
		// aliases so a theme/plugin wrapper cannot silently drop the password.
		$password = (string) wp_unslash( $input['password'] ?? '' );

		if ( '' === $password ) {
			$password = (string) wp_unslash( $input['register_password'] ?? $input['password_1'] ?? $input['pass'] ?? '' );
		}
		$min      = max( 6, Settings::get_int( 'min_password_length', 8 ) );

		if ( '' === $password ) {
			return new WP_Error( 'smart_login_no_password', __( 'Vui lòng nhập mật khẩu.', 'smart-login' ) );
		}

		if ( mb_strlen( $password ) < $min ) {
			return new WP_Error(
				'smart_login_weak_password',
				sprintf(
					/* translators: %d: minimum length. */
					__( 'Mật khẩu phải có ít nhất %d ký tự.', 'smart-login' ),
					$min
				)
			);
		}

		$confirmation = $input['password_confirm'] ?? $input['register_password_confirm'] ?? $input['password_2'] ?? null;

		if ( null !== $confirmation && (string) wp_unslash( $confirmation ) !== $password ) {
			return new WP_Error( 'smart_login_password_mismatch', __( 'Mật khẩu nhập lại không khớp.', 'smart-login' ) );
		}

		/**
		 * Enforce an additional password policy.
		 *
		 * @param true|WP_Error $result
		 * @param string        $password
		 */
		$extra = apply_filters( 'smart_login_validate_password', true, $password );

		if ( is_wp_error( $extra ) ) {
			return $extra;
		}

		return $password;
	}

	/**
	 * Accept dd/mm/yyyy (what the mockup shows) as well as yyyy-mm-dd.
	 *
	 * @return string Y-m-d, or '' when unparseable.
	 */
	public static function parse_dob( string $raw ): string {
		$raw = trim( wp_unslash( $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		foreach ( array( 'd/m/Y', 'Y-m-d', 'd-m-Y' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $raw );

			if ( $date && $date->format( $format ) === $raw ) {
				// Reject impossible birth dates rather than storing nonsense.
				$year = (int) $date->format( 'Y' );

				if ( $year < 1900 || $date->getTimestamp() > time() ) {
					return '';
				}

				return $date->format( 'Y-m-d' );
			}
		}

		return '';
	}
}
