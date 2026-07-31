<?php
/**
 * Registration: validate → issue OTP → verify → create account → sign in.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\Claim;
use SmartLogin\Identity\IdentityDirectory;
use SmartLogin\Identity\IdentityHistory;
use SmartLogin\Identity\UserManager;
use SmartLogin\Identity\VerifiedClaim;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class RegisterHandler {

	/** @var OtpService */
	private $otp;

	private IdentityDirectory $directory;

	public function __construct( ?OtpService $otp = null, ?IdentityDirectory $directory = null ) {
		$this->otp       = $otp ?? new OtpService();
		$this->directory = $directory ?? new IdentityDirectory();
	}

	/**
	 * Step 1: validate the form and send a code. No user row is created yet —
	 * everything the form collected rides along in the OTP payload.
	 *
	 * @param array $input Raw (still slashed) request data.
	 * @return array|WP_Error Result of OtpService::issue().
	 */
	public function start( array $input ) {
		$claim = $this->validate_identity( $input );

		if ( is_wp_error( $claim ) ) {
			return $claim;
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
			'channel'   => $claim->channel(),
			'subject'   => $claim->subject(),
			'pass_hash' => wp_hash_password( $password ),
			'full_name' => $full_name,
			'dob'       => self::parse_dob( (string) ( $input['dob'] ?? '' ) ),
			'gender'    => in_array( $input['gender'] ?? '', array( 'male', 'female', 'other' ), true ) ? $input['gender'] : '',
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
			RateLimiter::mask_identity( $claim->subject() ),
			array( 'channel' => $claim->channel() )
		);

		$result = $this->otp->issue(
			$claim->subject(),
			OtpService::INTENT_REGISTER,
			$payload,
			array( 'user_name' => $full_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PendingSession::start( $result['token'], OtpService::INTENT_REGISTER );

		return $result;
	}

	/**
	 * Identifier-first step 1: the visitor has typed one identifier and nothing
	 * else. Check it is registerable and send a code straight away.
	 *
	 * The name, password and terms are collected *after* verification by
	 * finish_signup(). Nothing is persisted until then, so an abandoned signup
	 * leaves no row behind — exactly as in the collect-everything-first flow.
	 *
	 * @param array $input Raw (still slashed) request data.
	 * @return array|WP_Error Result of OtpService::issue().
	 */
	public function start_identity( array $input ) {
		$claim = $this->validate_identity( $input );

		if ( is_wp_error( $claim ) ) {
			return $claim;
		}

		AuditLog::record(
			AuditLog::REGISTER_STARTED,
			RateLimiter::mask_identity( $claim->subject() ),
			array( 'channel' => $claim->channel() )
		);

		$result = $this->otp->issue(
			$claim->subject(),
			OtpService::INTENT_REGISTER,
			array(
				'channel' => $claim->channel(),
				'subject' => $claim->subject(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PendingSession::start( $result['token'], OtpService::INTENT_REGISTER );

		return $result;
	}

	/**
	 * Step 2: check the code.
	 *
	 * Two shapes come back, because two flows share this OTP intent. When the
	 * pending payload already carries a password hash the registration was
	 * started by start() and everything needed is present, so the account is
	 * created here and `user_id` is returned. When it does not, the code was
	 * issued by start_identity() and the caller still has to collect a name and
	 * a password: `grant` comes back instead, to be handed to finish_signup().
	 *
	 * @return array{user_id:int}|array{grant:string}|WP_Error
	 */
	public function verify( string $token, string $code ) {
		$row = $this->otp->verify( $token, $code, OtpService::INTENT_REGISTER );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( OtpService::INTENT_REGISTER !== $row['intent'] ) {
			return new WP_Error( 'smart_login_wrong_purpose', __( 'Phiên xác thực không hợp lệ.', 'smart-login' ) );
		}

		$payload = is_array( $row['payload'] ) ? $row['payload'] : array();

		// The destination on the row is authoritative — never the payload, which
		// the browser could in principle have influenced. The channel comes from
		// the payload but is re-validated by the registry, so a tampered value
		// yields an empty claim rather than a mismatched identity.
		$claim = $this->directory->channels()->claim(
			(string) ( $payload['channel'] ?? '' ),
			(string) $row['destination']
		);

		if ( $claim->is_empty() ) {
			return new WP_Error( 'smart_login_bad_identity', __( 'Thông tin định danh không hợp lệ.', 'smart-login' ) );
		}

		if ( '' !== (string) ( $payload['pass_hash'] ?? '' ) ) {
			$user_id = $this->provision( $claim, $payload );

			return is_wp_error( $user_id ) ? $user_id : array( 'user_id' => (int) $user_id );
		}

		// The proof stands on its own from here; the OTP row is spent.
		PendingSession::clear();

		return array(
			'grant' => PendingSession::grant_signup(
				array(
					'channel' => $claim->channel(),
					'subject' => $claim->subject(),
				)
			),
		);
	}

	/**
	 * Identifier-first step 3: name and password against a verified identifier.
	 *
	 * @return int|WP_Error New user ID.
	 */
	public function finish_signup( string $grant, array $input ) {
		$proven = PendingSession::consume_signup( $grant );

		if ( null === $proven ) {
			return new WP_Error(
				'smart_login_grant_expired',
				__( 'Phiên đăng ký đã hết hạn. Vui lòng thực hiện lại.', 'smart-login' )
			);
		}

		$claim = $this->directory->channels()->claim( $proven['channel'], $proven['subject'] );

		if ( $claim->is_empty() ) {
			return new WP_Error( 'smart_login_bad_identity', __( 'Thông tin định danh không hợp lệ.', 'smart-login' ) );
		}

		$full_name = sanitize_text_field( wp_unslash( $input['full_name'] ?? '' ) );

		if ( '' === trim( $full_name ) ) {
			return $this->retry_signup( 'smart_login_no_name', __( 'Vui lòng nhập họ tên.', 'smart-login' ), $proven );
		}

		if ( empty( $input['terms'] ) ) {
			return $this->retry_signup( 'smart_login_no_terms', __( 'Vui lòng đồng ý với các điều kiện áp dụng.', 'smart-login' ), $proven );
		}

		$password = $this->validate_password( $input );

		if ( is_wp_error( $password ) ) {
			return $this->retry_signup( $password->get_error_code(), $password->get_error_message(), $proven );
		}

		$payload = array(
			'channel'   => $claim->channel(),
			'subject'   => $claim->subject(),
			'pass_hash' => wp_hash_password( $password ),
			'full_name' => $full_name,
			'dob'       => '',
			'gender'    => '',
		);

		/** This filter documented on start(); the deferred flow honours it too. */
		$payload = (array) apply_filters( 'smart_login_registration_payload', $payload, $input );

		return $this->provision( $claim, $payload );
	}

	/**
	 * The grant was spent by consume_signup(), so a rejected form has to be given
	 * a fresh one. Otherwise a mistyped password would cost the visitor another
	 * SMS and another round through the OTP screen.
	 */
	private function retry_signup( string $code, string $message, array $proven ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array( 'grant' => PendingSession::grant_signup( $proven ) )
		);
	}

	/**
	 * Step 2 (collect-everything-first): check the code and create the account.
	 *
	 * @return int|WP_Error New user ID.
	 */
	public function complete( string $token, string $code ) {
		$result = $this->verify( $token, $code );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['user_id'] ) ) {
			return (int) $result['user_id'];
		}

		// Reached only if a caller pairs start_identity() with complete(); the two
		// halves belong to different flows.
		return new WP_Error(
			'smart_login_signup_incomplete',
			__( 'Chưa đủ thông tin để tạo tài khoản.', 'smart-login' )
		);
	}

	/**
	 * Create the account behind a just-proven claim and sign the user in.
	 *
	 * @return int|WP_Error New user ID.
	 */
	private function provision( Claim $claim, array $payload ) {
		// The OTP proved control of this subject just now.
		$verified = VerifiedClaim::from( $claim, VerifiedClaim::PROOF_OTP );

		// A subject with a previous owner is a recycled identifier, not the same
		// person. Note it before the new account exists so the trail is complete.
		$resolution = $this->directory->resolve( $claim );

		if ( AuthAction::ALREADY_REGISTERED === AuthAction::for_resolution( AuthAction::REGISTER, $resolution ) ) {
			return new WP_Error( 'smart_login_exists', __( 'Tài khoản đã tồn tại.', 'smart-login' ) );
		}

		if ( AuthAction::CREATE_NEW_USER === AuthAction::for_resolution( AuthAction::REGISTER, $resolution ) ) {
			$this->directory->identities()->history()->record(
				$resolution->prior_user_id(),
				$claim,
				IdentityHistory::RELINKED,
				'subject_reused',
				'system'
			);
		}

		$user_id = UserManager::create_verified_user( $verified, $payload, $this->directory );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		AuditLog::record(
			AuditLog::USER_REGISTERED,
			RateLimiter::mask_identity( $claim->subject() ),
			array( 'channel' => $claim->channel() ),
			$user_id
		);

		/**
		 * A verified account has just been created.
		 *
		 * @param int   $user_id
		 * @param array $payload
		 */
		do_action( 'smart_login_user_registered', $user_id, $payload );

		$this->sign_in( $user_id, AuthProof::from_otp( $verified, $user_id ) );

		PendingSession::clear();

		return $user_id;
	}

	/**
	 * Log the freshly created user in for this and subsequent requests.
	 */
	public function sign_in( int $user_id, AuthProof $proof ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		( new SessionIssuer() )->issue(
			$proof,
			$user,
			new AuthContext(
				array(
					'auth_method'    => 'otp',
					'user_id'        => $user_id,
					'is_new_user'    => true,
					'email_verified' => '' !== (string) get_user_meta( $user_id, UserManager::META_EMAIL_VERIFIED, true ),
				)
			)
		);
	}

	/**
	 * Where to send the user once registration finishes.
	 */
	public static function post_register_redirect( int $user_id ): string {
		$context = new AuthContext(
			array(
				'auth_method' => 'otp',
				'user_id'     => $user_id,
				'is_new_user' => true,
			)
		);
		$result  = new AuthResult( $user_id, $context, ( new ProfileCompletionService() )->status( $user_id ) );
		return ( new PostAuthRedirector() )->redirect( $result );
	}

	// -----------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------

	/**
	 * Turn raw form input into a claim this site is willing to register.
	 *
	 * Availability is decided by the decision table rather than by an ad-hoc
	 * uniqueness check, so a recycled subject (RETIRED) is correctly treated as
	 * available while an owned one (KNOWN) is not.
	 *
	 * @return Claim|WP_Error
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

		$claim = $this->directory->channels()->claim_any( $raw );

		if ( $claim->is_empty() ) {
			return new WP_Error(
				'smart_login_bad_identity',
				sprintf(
					/* translators: %s: identifier label, e.g. "Số điện thoại". */
					__( '%s không hợp lệ.', 'smart-login' ),
					self::identifier_label()
				)
			);
		}

		switch ( AuthAction::for_resolution( AuthAction::REGISTER, $this->directory->resolve( $claim ) ) ) {
			case AuthAction::CREATE_USER:
			case AuthAction::CREATE_NEW_USER:
				return $claim;

			case AuthAction::ALREADY_REGISTERED:
				return new WP_Error(
					'smart_login_identity_taken',
					__( 'Thông tin này đã được đăng ký. Vui lòng đăng nhập.', 'smart-login' )
				);

			default:
				return new WP_Error( 'smart_login_bad_identity', __( 'Không thể đăng ký với thông tin này.', 'smart-login' ) );
		}
	}

	/**
	 * Human label for the identifier field, driven by which channels are enabled.
	 */
	public static function identifier_label(): string {
		$phone = Settings::phone_enabled();
		$email = Settings::email_enabled();

		if ( $phone && $email ) {
			return __( 'Số điện thoại hoặc Email', 'smart-login' );
		}

		return $email ? __( 'Email', 'smart-login' ) : __( 'Số điện thoại', 'smart-login' );
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

		$confirmation = $input['password_confirm'] ?? $input['register_password_confirm'] ?? $input['password_2'] ?? null;

		$verdict = PasswordPolicy::validate(
			$password,
			null !== $confirmation ? (string) wp_unslash( $confirmation ) : null
		);

		return is_wp_error( $verdict ) ? $verdict : $password;
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
