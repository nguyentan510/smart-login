<?php
/**
 * The identifier-first state machine, with no transport attached.
 *
 * Every method here takes the submitted values and returns a FlowDecision. None
 * of them echo, redirect, or exit — that is the whole point. See
 * `class-flow-decision.php` for why the decision became a value.
 *
 * **Guards run here, not in the caller.** The brief for 19.1 put
 * `RequestGuard::verify()` in the controller; porting the handlers showed why it
 * cannot live there. `handle_signup()` reacts to a guard failure by re-rendering
 * the signup step *with the unspent grant*, and `handle_login()` picks its
 * failure step from `ow_from_password` — both are decisions about the flow, and
 * a controller that owned the guard would have to own those too. The fragment
 * endpoint posts the same fields the HTML form does, so the same guard applies
 * unchanged to both callers.
 *
 * Cookie writes — `PendingSession::start()` and `::clear()` — also stay here.
 * They are not output: both transports run before headers are sent, and pulling
 * them out would leave the caller responsible for a step of the protocol it has
 * no reason to know about.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth;

use OmniWP\Address\AddressFields;
use OmniWP\Frontend\Flow;
use OmniWP\Identity\IdentityDirectory;
use OmniWP\Identity\Phone;
use OmniWP\Identity\UserManager;
use OmniWP\OTP\OtpService;
use OmniWP\Security\Captcha;
use OmniWP\Security\RateLimiter;
use OmniWP\Security\RequestGuard;
use OmniWP\Settings;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class FlowEngine {

	/** @var OtpService|null */
	private $otp;

	/**
	 * Whether to run the HTML form guard.
	 *
	 * `RequestGuard` has two verifiers because there are two kinds of client. A
	 * browser posting a rendered form carries `OMNIWP_nonce` and the signed
	 * timestamp as hidden inputs; a JSON client carries the `wp_rest` nonce in a
	 * header and is checked once, for every route, by
	 * `RestController::check_permission()`.
	 *
	 * Running the form verifier against a JSON client would reject every request
	 * for want of a field that client has no way to hold. So the caller says
	 * which it is, and neither one goes unguarded.
	 *
	 * The fragment endpoint in 19.2 is deliberately **not** a JSON client for
	 * this purpose: it posts the fields of a real rendered form, so it uses the
	 * form guard exactly as a page submit does.
	 */
	private bool $verify_form = true;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp;
	}

	/**
	 * Whether this flow draws its own screens and must not navigate away.
	 *
	 * See `AuthContext::$in_place`. Default false, so a page-hosted flow behaves
	 * exactly as it always has.
	 */
	private bool $in_place = false;

	/**
	 * Mark this flow as one that owns its own surface.
	 */
	public function in_place( bool $yes = true ): self {
		$this->in_place = $yes;

		return $this;
	}

	/**
	 * An engine for a JSON caller that `check_permission()` has already guarded.
	 */
	public static function for_rest( ?OtpService $otp = null ): self {
		$engine              = new self( $otp );
		$engine->verify_form = false;

		return $engine;
	}

	/**
	 * @return true|WP_Error
	 */
	private function guard( string $action, array $input, string $prefix = '' ) {
		return $this->verify_form ? RequestGuard::verify( $action, $input, $prefix ) : true;
	}

	/**
	 * Built on demand: the channel router fires a filter, and constructing it at
	 * plugins_loaded would run before other plugins can hook it.
	 */
	private function otp(): OtpService {
		if ( null === $this->otp ) {
			$this->otp = new OtpService();
		}

		return $this->otp;
	}

	/**
	 * Route one submitted action to the step that owns it.
	 *
	 * Returns null for an action this engine does not own — `save_profile` and
	 * `unlink_identity` are account-surface actions performed by somebody who is
	 * already signed in, not steps of the sign-in flow, and they stay with the
	 * controller that has always had them.
	 */
	public function handle( string $action, array $input ): ?FlowDecision {
		switch ( $action ) {
			case 'identify':
				return $this->identify( $input );

			case 'signup':
				return $this->signup( $input );

			case 'onboard':
				return $this->onboard( $input );

			case 'register':
				return $this->register( $input );

			case 'verify_otp':
				return $this->verify_otp( $input );

			case 'resend_otp':
				return $this->resend_otp( $input );

			case 'login':
				return $this->login( $input );

			case 'forgot':
				return $this->forgot( $input );

			case 'reset_password':
				return $this->reset_password( $input );
		}

		return null;
	}

	// -----------------------------------------------------------------
	// Identifier-first
	// -----------------------------------------------------------------

	/**
	 * Step 1: one identifier, and the flow works out the rest.
	 *
	 * A registered subject goes to the password screen; anything else starts a
	 * registration. The visitor is never asked to declare up front whether they
	 * already have an account — they usually do not know, and getting it wrong
	 * used to mean an error message and a retyped form.
	 */
	public function identify( array $input ): FlowDecision {
		$identity = trim( (string) ( $input['identity'] ?? '' ) );
		$decision = ( new FlowDecision() )->remember( array( 'identity' => $identity ) );

		$guard = $this->guard( 'identify', $input );

		if ( is_wp_error( $guard ) ) {
			return $decision->error( $guard )->render( Flow::STEP_IDENTIFY );
		}

		if ( '' === $identity ) {
			return $decision->notice(
				sprintf(
					/* translators: %s: identifier label, e.g. "số điện thoại". */
					__( 'Vui lòng nhập %s.', 'omniwp' ),
					mb_strtolower( RegisterHandler::identifier_label() )
				)
			)->render( Flow::STEP_IDENTIFY );
		}

		// Before the lookup, not after. The lookup is the enumeration oracle —
		// it reveals whether a subject is registered by which screen comes back —
		// so a limit applied afterwards would leave the oracle intact.
		$allowed = ( new RateLimiter() )->check_identify( $identity );

		if ( is_wp_error( $allowed ) ) {
			return $decision->error( $allowed )->render( Flow::STEP_IDENTIFY );
		}

		$challenge = Captcha::check( $input );

		if ( is_wp_error( $challenge ) ) {
			return $decision->error( $challenge )->render( Flow::STEP_IDENTIFY );
		}

		$directory = new IdentityDirectory();
		$claim     = $directory->channels()->claim_any( $identity );

		if ( $claim->is_empty() ) {
			return $decision->notice(
				sprintf(
					/* translators: %s: identifier label, e.g. "Số điện thoại". */
					__( '%s không hợp lệ.', 'omniwp' ),
					RegisterHandler::identifier_label()
				)
			)->render( Flow::STEP_IDENTIFY );
		}

		$action = AuthAction::for_resolution( AuthAction::LOGIN, $directory->resolve( $claim ) );

		if ( AuthAction::ISSUE_SESSION === $action ) {
			return $decision->render( Flow::STEP_PASSWORD, array( 'identity' => $identity ) );
		}

		// Anything else means there is nothing to sign in to. NO_ACCOUNT covers
		// both "never seen" and "the previous owner gave this subject up", and
		// both are a new account here. REJECT is unreachable — resolve() cannot
		// return CONFLICT — and start_identity() refuses it in any case, so the
		// fall-through is not load-bearing.
		$result = ( new RegisterHandler( $this->otp() ) )->start_identity( array( 'identity' => $identity ) );

		if ( is_wp_error( $result ) ) {
			return $decision->error( $result )->render( Flow::STEP_IDENTIFY );
		}

		return $decision->render( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_REGISTER ) );
	}

	/**
	 * Step 3 of a registration: name and password, against an already-proven
	 * identifier held server-side behind the grant.
	 */
	public function signup( array $input ): FlowDecision {
		$decision = ( new FlowDecision() )->remember( $input );
		$grant    = (string) ( $input['grant'] ?? '' );
		$guard    = $this->guard( 'signup', $input );

		if ( is_wp_error( $guard ) ) {
			// The grant was not consumed, so it is still good for the retry.
			return $this->fail_signup( $decision, $guard, $grant );
		}

		$user_id = ( new RegisterHandler( $this->otp() ) )->finish_signup( $grant, $input );

		if ( is_wp_error( $user_id ) ) {
			return $this->fail_signup( $decision, $user_id, '' );
		}

		return $this->after_registration( $decision, (int) $user_id );
	}

	/**
	 * A registration finished. Where the new member goes next.
	 *
	 * The page-hosted flow redirects, and the reason is not style — see
	 * `welcome_url()`. A dialog has somewhere to put the welcome screen without
	 * navigating, which is the whole of the request this phase answers: register
	 * from a product page and finish on the product page.
	 *
	 * The nonce hazard `welcome_url()` documents does not apply to the in-place
	 * branch. The fragment is fetched by a *fresh request* carrying the auth
	 * cookie the browser has by then accepted, so its nonce is bound to a real
	 * session token rather than to an empty one.
	 */
	private function after_registration( FlowDecision $decision, int $user_id = 0 ): FlowDecision {
		if ( ! $this->in_place ) {
			return $decision->go( $this->welcome_url() );
		}

		return $decision->render(
			Flow::STEP_ONBOARD,
			array(
				'user_id'  => $user_id,
				'redirect' => Flow::base(),
			)
		);
	}

	/**
	 * @param FlowDecision $decision
	 * @param WP_Error     $error          Carries a fresh grant when one was issued.
	 * @param string       $fallback_grant Used when the failure happened before
	 *                                     the grant was spent, so no fresh one
	 *                                     exists.
	 */
	private function fail_signup( FlowDecision $decision, WP_Error $error, string $fallback_grant ): FlowDecision {
		$decision->error( $error );

		$data  = $error->get_error_data();
		$grant = is_array( $data ) && ! empty( $data['grant'] ) ? (string) $data['grant'] : $fallback_grant;

		if ( '' === $grant ) {
			// No proof left to build an account on; the identifier must be
			// verified again from the start.
			return $decision->render( Flow::STEP_IDENTIFY );
		}

		return $decision->render( Flow::STEP_SIGNUP, array( 'grant' => $grant ) );
	}

	/**
	 * The current page, flagged so whatever renders it shows the welcome screen.
	 *
	 * This has to be a redirect, and the reason is not style. Rendering the
	 * welcome screen straight into the sign-in response mints its nonce during
	 * the same request that set the auth cookie — and wp_get_session_token()
	 * reads that cookie out of $_COOKIE, which setcookie() does not populate
	 * until the browser sends it back. So the nonce is bound to an empty session
	 * token, and the first thing the new member does on the welcome screen fails
	 * with "Phiên làm việc đã hết hạn".
	 *
	 * Post/Redirect/Get is the right shape here anyway: the welcome screen is a
	 * form, and a form that lives inside a POST response re-submits the
	 * registration on refresh.
	 *
	 * Not marked seen here. The redirect carries the flag that shows the screen,
	 * and marking it before it has actually rendered would lose the welcome
	 * entirely if the redirect went astray.
	 */
	public function welcome_url(): string {
		$strip = array( 'OMNIWP_step', 'OmniWP_welcome' );
		$base  = Flow::base();
		$here  = '' !== $base ? remove_query_arg( $strip, $base ) : remove_query_arg( $strip );

		return add_query_arg( 'OmniWP_welcome', '1', $here );
	}

	/**
	 * Save whatever the welcome screen collected, then get out of the way.
	 *
	 * Every field here is optional by construction: "Để sau" posts the same form
	 * with nothing filled in, and that is a valid outcome rather than a
	 * validation error.
	 */
	public function onboard( array $input ): FlowDecision {
		$decision = new FlowDecision();
		$redirect = wp_validate_redirect( (string) ( $input['redirect_to'] ?? '' ), '' );
		$redirect = '' !== $redirect ? $redirect : LoginHandler::post_login_redirect();

		if ( ! is_user_logged_in() ) {
			return $decision->go( $redirect );
		}

		// "Để sau" writes nothing, so it is let through without the form guard.
		// RequestGuard enforces a two-second minimum fill time, and somebody who
		// decides immediately is exactly who that button is for — failing them
		// for answering too quickly would be the opposite of taking no for an
		// answer.
		if ( ! empty( $input['ow_skip'] ) ) {
			return $decision->go( $redirect );
		}

		$guard = $this->guard( 'onboard', $input );

		if ( is_wp_error( $guard ) ) {
			// Stay on the screen rather than redirecting: a redirect here would
			// throw away everything they had just typed.
			return $decision->error( $guard )->render( Flow::STEP_ONBOARD, array( 'redirect' => $redirect ) );
		}

		$this->save_onboarding( $decision, get_current_user_id(), $input );

		return $decision->go( $redirect );
	}

	/**
	 * Write the optional profile fields the welcome screen collected.
	 *
	 * Public because `FormController::handle_save_profile()` shares it — the
	 * account surface saves the same three fields under WooCommerce's names.
	 */
	public function save_onboarding( FlowDecision $decision, int $user_id, array $input ): void {
		$full_name = sanitize_text_field( (string) ( $input['full_name'] ?? '' ) );

		if ( '' !== trim( $full_name ) ) {
			$names = UserManager::split_name( $full_name );

			wp_update_user(
				array(
					'ID'           => $user_id,
					'first_name'   => $names['first'],
					'last_name'    => '' !== $names['last'] ? $names['last'] : $names['first'],
					'display_name' => $full_name,
				)
			);

			\OmniWP\Identity\ProfileSeeder::set_many_from_user_input(
				$user_id,
				array(
					'billing_first_name'  => $names['first'],
					'billing_last_name'   => $names['last'],
					'shipping_first_name' => $names['first'],
					'shipping_last_name'  => $names['last'],
				)
			);
		}

		$dob = RegisterHandler::parse_dob( (string) ( $input['dob'] ?? '' ) );

		if ( '' !== $dob ) {
			update_user_meta( $user_id, UserManager::META_DOB, $dob );
		}

		$raw_phone = sanitize_text_field( (string) ( $input['phone'] ?? ( $input['shipping_phone'] ?? ( $input['billing_phone'] ?? '' ) ) ) );
		if ( '' !== trim( $raw_phone ) ) {
			$canonical_phone = Phone::normalize( $raw_phone );
			if ( Phone::is_valid( $canonical_phone ) ) {
				$local_phone = Phone::to_local( $canonical_phone );
				update_user_meta( $user_id, 'shipping_phone', $local_phone );
				\OmniWP\Identity\ProfileSeeder::seed_if_empty( $user_id, 'billing_phone', $local_phone );
				update_user_meta( $user_id, UserManager::META_PHONE, $canonical_phone );
			}
		}

		$gender = sanitize_key( (string) ( $input['gender'] ?? '' ) );

		if ( in_array( $gender, array( 'male', 'female', 'other' ), true ) ) {
			update_user_meta( $user_id, UserManager::META_GENDER, $gender );
		}

		if ( ! isset( $input[ AddressFields::FIELD_PROVINCE ] ) ) {
			return;
		}

		// Never required on this screen, whatever address_required_in_profile
		// says: a welcome that can be failed is not a welcome.
		$address = AddressFields::validate( $input, false );

		if ( is_wp_error( $address ) ) {
			$decision->notice( $address->get_error_message(), 'error', true );
			return;
		}

		AddressFields::save_for_user( $user_id, $address );
	}

	// -----------------------------------------------------------------

	/**
	 * The registration step, after the identifier has been proved.
	 *
	 * @param array $input Posted fields, in whatever names the surrounding form
	 *                     used — normalising those is the first thing it does.
	 */
	public function register( array $input ): FlowDecision {
		if ( empty( $input['identity'] ) && array_key_exists( 'register_identity', $input ) ) {
			$input['identity'] = $input['register_identity'];
		}

		// WooCommerce/theme wrappers may use password_1/password_2 names.
		// Normalize them before the shared registration handler validates input.
		if ( empty( $input['password'] ) && array_key_exists( 'password_1', $input ) ) {
			$input['password'] = $input['password_1'];
		}

		if ( empty( $input['password'] ) && array_key_exists( 'register_password', $input ) ) {
			$input['password'] = $input['register_password'];
		}

		if ( empty( $input['password_confirm'] ) && array_key_exists( 'password_2', $input ) ) {
			$input['password_confirm'] = $input['password_2'];
		}

		if ( empty( $input['password_confirm'] ) && array_key_exists( 'register_password_confirm', $input ) ) {
			$input['password_confirm'] = $input['register_password_confirm'];
		}

		$decision = ( new FlowDecision() )->remember( $input );
		$guard    = $this->guard( 'register', $input, 'register_' );

		if ( is_wp_error( $guard ) ) {
			return $decision->error( $guard )->render( Flow::STEP_REGISTER );
		}

		$result = ( new RegisterHandler( $this->otp() ) )->start( $input );

		if ( is_wp_error( $result ) ) {
			return $decision->error( $result )->render( Flow::STEP_REGISTER );
		}

		return $decision->render( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_REGISTER ) );
	}

	public function verify_otp( array $input ): FlowDecision {
		$decision = new FlowDecision();
		$guard    = $this->guard( 'otp', $input );

		if ( is_wp_error( $guard ) ) {
			return $decision->error( $guard )->render( Flow::STEP_OTP );
		}

		$session = PendingSession::get();

		if ( ! $session ) {
			return $decision->notice(
				__( 'Phiên xác thực đã hết hạn. Vui lòng thực hiện lại.', 'omniwp' )
			)->render( Flow::STEP_LOGIN );
		}

		$code = $this->extract_code( $input );

		switch ( $session['intent'] ) {
			case OtpService::INTENT_REGISTER:
				return $this->finish_registration( $decision, $session['token'], $code );

			case OtpService::INTENT_RECOVER:
				return $this->finish_reset_verification( $decision, $session['token'], $code );

			case OtpService::INTENT_LOGIN:
				return $this->finish_device_login( $decision, $session['token'], $code );
		}

		return $decision->notice(
			__( 'Phiên xác thực không hợp lệ.', 'omniwp' )
		)->render( Flow::STEP_LOGIN );
	}

	/**
	 * The code checked out. Either the account can be created right now, or the
	 * name and password are still to come — see RegisterHandler::verify().
	 */
	private function finish_registration( FlowDecision $decision, string $token, string $code ): FlowDecision {
		$result = ( new RegisterHandler( $this->otp() ) )->verify( $token, $code );

		if ( is_wp_error( $result ) ) {
			return $this->fail_otp( $decision, $result, $token );
		}

		if ( isset( $result['grant'] ) ) {
			return $decision->render( Flow::STEP_SIGNUP, array( 'grant' => (string) $result['grant'] ) );
		}

		// No user id to hand over: the account was created inside verify(), which
		// issued the session too. `onboarding_args()` falls back to the current
		// user, which is that account.
		return $this->after_registration( $decision );
	}

	private function finish_reset_verification( FlowDecision $decision, string $token, string $code ): FlowDecision {
		$grant = ( new PasswordResetHandler( $this->otp() ) )->verify( $token, $code );

		if ( is_wp_error( $grant ) ) {
			return $this->fail_otp( $decision, $grant, $token );
		}

		PendingSession::clear();

		return $decision->render( Flow::STEP_RESET, array( 'grant' => $grant ) );
	}

	private function finish_device_login( FlowDecision $decision, string $token, string $code ): FlowDecision {
		$row = $this->otp()->verify( $token, $code, OtpService::INTENT_LOGIN );

		if ( is_wp_error( $row ) ) {
			return $this->fail_otp( $decision, $row, $token );
		}

		$user_id = (int) ( $row['payload']['user_id'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return $decision->notice(
				__( 'Không tìm thấy tài khoản.', 'omniwp' )
			)->render( Flow::STEP_LOGIN );
		}

		$context = new AuthContext(
			array(
				'auth_method'  => 'otp',
				'user_id'      => $user_id,
				'intended_url' => (string) ( $row['payload']['redirect_to'] ?? '' ),
				'in_place'     => $this->in_place,
			)
		);
		$proof   = AuthProof::from_otp( $this->otp()->verified_claim( $row ), $user_id );
		$result  = ( new SessionIssuer() )->issue( $proof, $user, $context, ! isset( $row['payload']['remember'] ) || ! empty( $row['payload']['remember'] ) );

		PendingSession::clear();

		return $this->after_session( $decision, $result, $context->intended_url );
	}

	/**
	 * Turn an issued session into the next thing the visitor sees.
	 *
	 * `PostAuthRedirector::redirect()` answers '' for a new member of an
	 * in-place flow — there is nowhere to send them, because the welcome screen
	 * goes where they already are. Any other answer is a destination, including
	 * for a failed issue, which falls back to the ordinary login redirect.
	 *
	 * @param FlowDecision         $decision
	 * @param AuthResult|\WP_Error $result
	 * @param string               $intended_url
	 */
	private function after_session( FlowDecision $decision, $result, string $intended_url ): FlowDecision {
		if ( is_wp_error( $result ) ) {
			return $decision->go( LoginHandler::post_login_redirect( $intended_url ) );
		}

		$url = ( new PostAuthRedirector() )->redirect( $result, $intended_url );

		if ( '' === $url ) {
			return $this->after_registration( $decision, (int) $result->user_id );
		}

		return $decision->go( $url );
	}

	public function resend_otp( array $input ): FlowDecision {
		$decision = new FlowDecision();
		$guard    = $this->guard( 'otp', $input );

		if ( is_wp_error( $guard ) ) {
			return $decision->error( $guard )->render( Flow::STEP_OTP );
		}

		$session = PendingSession::get();

		if ( ! $session ) {
			return $decision->notice(
				__( 'Phiên xác thực đã hết hạn. Vui lòng thực hiện lại.', 'omniwp' )
			)->render( Flow::STEP_LOGIN );
		}

		$result = $this->otp()->resend( $session['token'] );

		if ( is_wp_error( $result ) ) {
			// Keep the user on the OTP screen. The code they already have is
			// still valid — issue() retires the previous ones only after a
			// delivery succeeds, so a failed resend costs them nothing. Before
			// 10.7 this comment was here and was false.
			$decision->error( $result );

			return $this->restore_otp_step( $decision, $session['token'], $session['intent'] );
		}

		PendingSession::start( $result['token'], $session['intent'] );

		return $decision->notice(
			__( 'Đã gửi lại mã xác thực.', 'omniwp' ),
			'success'
		)->render( Flow::STEP_OTP, $result + array( 'intent' => $session['intent'] ) );
	}

	public function login( array $input ): FlowDecision {
		if ( empty( $input['identity'] ) && array_key_exists( 'login_identity', $input ) ) {
			$input['identity'] = $input['login_identity'];
		}

		if ( empty( $input['password'] ) && array_key_exists( 'login_password', $input ) ) {
			$input['password'] = $input['login_password'];
		}

		$identity = trim( (string) ( $input['identity'] ?? '' ) );
		$decision = ( new FlowDecision() )->remember( array( 'identity' => $input['identity'] ?? '' ) );

		// Identifier-first posts from the password screen, which already knows the
		// identifier is registered. A rejected password must land back there
		// rather than throwing the visitor out to step 1 to retype it.
		$fail_step = empty( $input['ow_from_password'] ) ? Flow::STEP_IDENTIFY : Flow::STEP_PASSWORD;
		$fail_data = Flow::STEP_PASSWORD === $fail_step ? array( 'identity' => $identity ) : array();

		$guard = $this->guard( 'login', $input, 'login_' );

		if ( is_wp_error( $guard ) ) {
			return $decision->error( $guard )->render( $fail_step, $fail_data );
		}

		$password = (string) ( $input['password'] ?? '' );
		$remember = ! empty( $input['remember'] );

		if ( '' === $identity || '' === $password ) {
			return $decision->notice(
				__( 'Vui lòng nhập đầy đủ thông tin đăng nhập.', 'omniwp' )
			)->render( $fail_step, $fail_data );
		}

		$user = ( new LoginHandler() )->attempt( $identity, $password );

		if ( $user instanceof WP_User ) {
			return $this->complete_login( $decision, $user, (string) ( $input['redirect_to'] ?? '' ), $remember );
		}

		if ( is_wp_error( $user ) && 'OMNIWP_needs_otp' === $user->get_error_code() ) {
			$data = (array) $user->get_error_data();

			return $this->start_device_otp( $decision, (int) ( $data['user_id'] ?? 0 ), (string) ( $input['redirect_to'] ?? '' ), $remember );
		}

		$decision->error(
			$user instanceof WP_Error ? $user : new WP_Error( 'OMNIWP_failed', __( 'Đăng nhập không thành công.', 'omniwp' ) )
		);

		return $decision->render( $fail_step, $fail_data );
	}

	/**
	 * Password was right but the device is new: send a code before letting them in.
	 */
	private function start_device_otp( FlowDecision $decision, int $user_id, string $redirect_to, bool $remember = true ): FlowDecision {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return $decision->notice(
				__( 'Không tìm thấy tài khoản.', 'omniwp' )
			)->render( Flow::STEP_LOGIN );
		}

		$phone       = (string) get_user_meta( $user_id, UserManager::META_PHONE, true );
		$destination = '' !== $phone ? $phone : $user->user_email;

		if ( '' === $phone && UserManager::is_synthetic_email( $user->user_email ) ) {
			// Nothing to send to — let them in rather than locking them out.
			return $this->complete_login( $decision, $user, $redirect_to, $remember );
		}

		$result = $this->otp()->issue(
			$destination,
			OtpService::INTENT_LOGIN,
			array(
				'user_id'     => $user_id,
				'redirect_to' => $redirect_to,
				'remember'    => $remember,
			),
			array( 'user_name' => $user->display_name )
		);

		if ( is_wp_error( $result ) ) {
			return $decision->error( $result )->render( Flow::STEP_LOGIN );
		}

		PendingSession::start( $result['token'], OtpService::INTENT_LOGIN );

		return $decision->notice(
			__( 'Thiết bị mới được phát hiện. Vui lòng nhập mã xác thực vừa gửi cho bạn.', 'omniwp' ),
			'info'
		)->render( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_LOGIN ) );
	}

	private function complete_login( FlowDecision $decision, WP_User $user, string $redirect_to, bool $remember = true ): FlowDecision {
		$context = new AuthContext(
			array(
				'auth_method'  => 'password',
				'user_id'      => $user->ID,
				'intended_url' => $redirect_to,
				'in_place'     => $this->in_place,
			)
		);
		$result  = ( new SessionIssuer() )->issue( AuthProof::from_password( $user ), $user, $context, $remember );

		return $this->after_session( $decision, $result, $redirect_to );
	}

	public function forgot( array $input ): FlowDecision {
		$decision = ( new FlowDecision() )->remember( array( 'identity' => $input['identity'] ?? '' ) );
		$guard    = $this->guard( 'forgot', $input );

		if ( is_wp_error( $guard ) ) {
			return $decision->error( $guard )->render( Flow::STEP_FORGOT );
		}

		$challenge = Captcha::check( $input );

		if ( is_wp_error( $challenge ) ) {
			return $decision->error( $challenge )->render( Flow::STEP_FORGOT );
		}

		$result = ( new PasswordResetHandler( $this->otp() ) )->start( $input );

		if ( is_wp_error( $result ) ) {
			return $decision->error( $result )->render( Flow::STEP_FORGOT );
		}

		return $decision->render( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_RECOVER ) );
	}

	public function reset_password( array $input ): FlowDecision {
		$decision = new FlowDecision();
		$guard    = $this->guard( 'reset', $input );

		if ( is_wp_error( $guard ) ) {
			return $decision->error( $guard )->render( Flow::STEP_RESET );
		}

		$grant  = (string) ( $input['grant'] ?? '' );
		$result = ( new PasswordResetHandler( $this->otp() ) )->complete( $grant, $input );

		if ( is_wp_error( $result ) ) {
			$decision->error( $result );

			$data = $result->get_error_data();

			if ( is_array( $data ) && ! empty( $data['grant'] ) ) {
				return $decision->render( Flow::STEP_RESET, array( 'grant' => $data['grant'] ) );
			}

			return $decision->render( Flow::STEP_FORGOT );
		}

		return $decision->notice(
			__( 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập bằng mật khẩu mới.', 'omniwp' ),
			'success',
			true
		)->go( Flow::url( Flow::STEP_LOGIN ) );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * The OTP screen renders one input per digit; JS mirrors them into a single
	 * hidden field, and this joins them when JS is unavailable.
	 */
	private function extract_code( array $input ): string {
		$code = preg_replace( '/\D/', '', (string) ( $input['otp_code'] ?? '' ) );

		if ( '' !== $code ) {
			return $code;
		}

		if ( empty( $input['otp_digit'] ) || ! is_array( $input['otp_digit'] ) ) {
			return '';
		}

		$digits = array_map(
			static function ( $value ) {
				return preg_replace( '/\D/', '', (string) $value );
			},
			$input['otp_digit']
		);

		return implode( '', $digits );
	}

	/**
	 * An OTP attempt failed: stay on the OTP screen unless the code is gone
	 * for good, in which case send the user back to the start.
	 */
	private function fail_otp( FlowDecision $decision, WP_Error $error, string $token ): FlowDecision {
		$decision->error( $error );

		$fatal = array( 'OMNIWP_otp_invalid', 'OMNIWP_otp_used', 'OMNIWP_wrong_purpose', 'OMNIWP_exists' );

		if ( in_array( $error->get_error_code(), $fatal, true ) ) {
			PendingSession::clear();

			return $decision->render( Flow::STEP_LOGIN );
		}

		$session = PendingSession::get();

		return $this->restore_otp_step( $decision, $token, $session['intent'] ?? '' );
	}

	/**
	 * Re-render the OTP screen with the live countdown of the existing code.
	 */
	private function restore_otp_step( FlowDecision $decision, string $token, string $intent ): FlowDecision {
		$row = $this->otp()->peek( $token );

		if ( ! $row ) {
			return $decision->render(
				Flow::STEP_OTP,
				array(
					'intent'     => $intent,
					'expires_in' => 0,
				)
			);
		}

		return $decision->render(
			Flow::STEP_OTP,
			array(
				'intent'       => $intent,
				'masked'       => RateLimiter::mask_identity( $row['destination'] ),
				'expires_in'   => $this->otp()->seconds_left( $row ),
				// Derived from when the code actually went out, not reset to zero.
				// Showing an enabled "Gửi lại" while RateLimiter still holds the
				// cooldown open only buys the visitor an error message.
				'resend_after' => $this->resend_wait( $row ),
				'transport'    => $row['transport'],
			)
		);
	}

	/**
	 * Seconds left on the resend cooldown for a pending code.
	 */
	private function resend_wait( array $row ): int {
		$sent_at = strtotime( ( $row['created_at'] ?? '' ) . ' UTC' );

		if ( ! $sent_at ) {
			return 0;
		}

		return max( 0, Settings::get_int( 'otp.resend_cooldown', 60 ) - ( time() - $sent_at ) );
	}
}
