<?php
/**
 * JSON API mirroring the form flow, for SPA/app front-ends.
 *
 * Namespace: omniwp/v1
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Address\AddressBook;
use OmniWP\Address\AddressRepository;
use OmniWP\Auth\LoginHandler;
use OmniWP\Auth\ContactVerificationService;
use OmniWP\Auth\IdentityLinkService;
use OmniWP\Auth\AuthContext;
use OmniWP\Auth\AuthProof;
use OmniWP\Auth\FlowDecision;
use OmniWP\Auth\FlowEngine;
use OmniWP\Auth\PostAuthRedirector;
use OmniWP\Auth\SessionIssuer;
use OmniWP\Auth\PasswordResetHandler;
use OmniWP\Auth\PendingSession;
use OmniWP\Auth\RegisterHandler;
use OmniWP\Identity\UserManager;
use OmniWP\OTP\OtpService;
use OmniWP\Security\RequestGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

class RestController {

	const REST_NAMESPACE = 'omniwp/v1';

	/** @var OtpService|null */
	private $otp;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp;
	}

	/**
	 * Built on demand so the channel-router filter is not fired at plugins_loaded.
	 */
	private function otp(): OtpService {
		if ( null === $this->otp ) {
			$this->otp = new OtpService();
		}

		return $this->otp;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$routes = array(
			// Identifier-first, and it was missing until 19.1. The form flow has
			// asked one question since Phase 16 — phone or email, and the server
			// works out whether that is a sign-in or a registration — while this
			// namespace still offered the two-screen login/register pair the form
			// flow had already left behind. A JSON client could not start the
			// flow at all.
			'identify' => 'handle_identify',
			'register' => 'handle_register',
			'verify'   => 'handle_verify',
			'resend'   => 'handle_resend',
			'login'    => 'handle_login',
			'forgot'   => 'handle_forgot',
			'reset'    => 'handle_reset',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_orders' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/order-detail',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_order_detail' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_order_detail' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/reorder/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_reorder' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/vouchers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_vouchers' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/apply-voucher',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_apply_voucher' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/addresses',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get_addresses' ),
					'permission_callback' => function () {
						return is_user_logged_in();
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_save_address' ),
					'permission_callback' => function () {
						return is_user_logged_in();
					},
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_delete_address' ),
					'permission_callback' => function () {
						return is_user_logged_in();
					},
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/addresses/set-default',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_set_default_address' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/addresses/parse',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_parse_address' ),
				'permission_callback' => function () {
					return is_user_logged_in(); },
			)
		);

		/*
		 * The fragment route, and the only one here that does not require the
		 * `wp_rest` nonce. That is deliberate and it is the reason the dialog
		 * works behind a page cache at all.
		 *
		 * `wp_localize_script()` writes its nonce into the page's HTML. On a site
		 * with full-page caching that nonce is cached with everything else, so a
		 * launcher present on every page cannot be trusted to hold a live one —
		 * the same argument that stops the dialog carrying its form markup.
		 *
		 * What replaces it:
		 *
		 *   - GET renders a public form and changes nothing. There is no state to
		 *     protect, and the response is same-origin only.
		 *   - POST carries the fields of the rendered form — `OMNIWP_nonce`,
		 *     the signed timestamp and the honeypot — all minted when the
		 *     fragment was fetched, seconds earlier. `RequestGuard::verify()`
		 *     checks them inside `FlowEngine`, exactly as it does for a page
		 *     submit.
		 *
		 * So this route is guarded by a fresher nonce than the one it declines,
		 * and the rate limits are what stop bots either way — which
		 * `check_permission()` says in as many words for the anonymous case.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			'/step',
			array(
				'methods'             => 'GET, POST',
				'callback'            => array( $this, 'handle_step' ),
				'permission_callback' => '__return_true',
			)
		);

		$authenticated = array(
			'contact/start'     => 'handle_contact_start',
			'contact/verify'    => 'handle_contact_verify',
			'contact/resend'    => 'handle_contact_resend',
			'contact/cancel'    => 'handle_contact_cancel',
			'identities'        => 'handle_identities',
			'identities/unlink' => 'handle_identity_unlink',
		);

		foreach ( $authenticated as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'check_contact_permission' ),
				)
			);
		}
	}

	/**
	 * These endpoints are public by design, but still require the REST nonce so
	 * they cannot be driven from a third-party page.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'OMNIWP_bad_nonce',
				__( 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.', 'omniwp' ),
				array( 'status' => 403 )
			);
		}

		// The honeypot and fill-time guard, once, for every route rather than
		// repeated in eleven callbacks. Ten of them called nothing before 9.7, and
		// a rule that requires eleven copies is a rule that will be short one the
		// day somebody adds a twelfth route.
		//
		// Worth stating plainly next to the nonce above: for anonymous visitors
		// the wp_rest nonce is constant for 12-24 hours across all of them, so it
		// is a CSRF control and nothing more. The rate limits are what stop bots.
		$guard = RequestGuard::verify_rest( 'rest', $request->get_params() );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return true;
	}

	public function check_contact_permission( WP_REST_Request $request ) {
		$allowed = $this->check_permission( $request );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'OMNIWP_not_logged_in', __( 'Bạn cần đăng nhập để thay đổi thông tin liên hệ.', 'omniwp' ), array( 'status' => 401 ) );
		}
		return true;
	}

	// -----------------------------------------------------------------

	/**
	 * Render a step of the flow as HTML, or run one and render what comes next.
	 *
	 * GET is a render. POST carries `OMNIWP_action` and the fields of the
	 * form that was rendered, and is the dialog's equivalent of submitting a
	 * page.
	 */
	public function handle_step( WP_REST_Request $request ) {
		// In WordPress REST API, if no wp_rest nonce is passed, core sets current user to 0.
		// For the /step endpoint (used by the dialog frontend), restore authenticated user from auth cookie if present.
		if ( ! is_user_logged_in() ) {
			$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $cookie_user_id ) {
				wp_set_current_user( $cookie_user_id );
			}
		}

		$page        = $this->validated_url( (string) $request->get_param( 'page' ) );
		$redirect_to = $this->validated_url( (string) $request->get_param( 'redirect_to' ) );
		$renderer    = new FragmentRenderer( $this->otp() );

		if ( 'POST' === strtoupper( $request->get_method() ) ) {
			$action = sanitize_key( (string) $request->get_param( 'OMNIWP_action' ) );

			return $this->success( $renderer->submit( $action, $request->get_params(), $page, $redirect_to ) );
		}

		$step = sanitize_key( (string) $request->get_param( 'step' ) );

		return $this->success(
			$renderer->render( '' !== $step ? $step : Flow::STEP_IDENTIFY, $page, $redirect_to )
		);
	}

	/**
	 * An on-site URL, or ''.
	 *
	 * `page` and `redirect_to` both end up in an `href` or a `Location`, and both
	 * come straight from a query string. `wp_validate_redirect()` returns '' for
	 * any host outside the allowed list, so an off-site value cannot survive
	 * this — which is the same check `ProviderAuthController::start()` applies to
	 * the return url it is handed.
	 */
	private function validated_url( string $url ): string {
		return '' === $url ? '' : (string) wp_validate_redirect( $url, '' );
	}

	/**
	 * Step 1 over JSON: one identifier, and the flow works out the rest.
	 *
	 * Answers with the step the visitor is being sent to, so a client can render
	 * it without knowing the rules that chose it. `password` means the subject is
	 * registered; `otp` means a code is on its way to a new one.
	 *
	 * The decision comes from `FlowEngine` — the same object `FormController`
	 * asks. That is the whole point of 19.1: this route existing as a second
	 * implementation would be worse than it not existing at all.
	 */
	public function handle_identify( WP_REST_Request $request ) {
		$decision = FlowEngine::for_rest( $this->otp() )->identify( $request->get_params() );

		return $this->from_decision( $decision );
	}

	/**
	 * Translate a flow decision into this namespace's response shape.
	 *
	 * An error notice becomes the error body clients already handle; anything
	 * else is a step plus whatever that step needs.
	 */
	private function from_decision( FlowDecision $decision ): WP_REST_Response {
		foreach ( $decision->notices as $notice ) {
			if ( 'error' === $notice['type'] ) {
				return $this->error( new WP_Error( 'OMNIWP_failed', $notice['message'] ) );
			}
		}

		if ( $decision->is_redirect() ) {
			return $this->success( array( 'redirect' => $decision->redirect ) );
		}

		return $this->success(
			array( 'step' => $decision->step ) + $decision->data
		);
	}

	public function handle_register( WP_REST_Request $request ) {
		$params = $request->get_params();

		// The form guard runs in check_permission() for every route. The challenge
		// does not: it belongs only on the routes that can cause a message to be
		// sent, which is this one and /forgot.
		$challenge = \OmniWP\Security\Captcha::check( $params );

		if ( is_wp_error( $challenge ) ) {
			return $this->error( $challenge );
		}

		$handler = new RegisterHandler( $this->otp() );
		$result  = $handler->start( $params );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		PendingSession::start( $result['token'], OtpService::INTENT_REGISTER );

		return $this->success( $this->public_otp_payload( $result, OtpService::INTENT_REGISTER ) );
	}

	public function handle_verify( WP_REST_Request $request ) {
		$session = $this->session_for( $request );

		if ( is_wp_error( $session ) ) {
			return $this->error( $session );
		}

		$code = (string) $request->get_param( 'code' );

		switch ( $session['intent'] ) {
			case OtpService::INTENT_REGISTER:
				$handler = new RegisterHandler( $this->otp() );
				$user_id = $handler->complete( $session['token'], $code );

				if ( is_wp_error( $user_id ) ) {
					return $this->error( $user_id );
				}

				return $this->success(
					array(
						'user_id'  => $user_id,
						'redirect' => RegisterHandler::post_register_redirect( $user_id ),
					)
				);

			case OtpService::INTENT_RECOVER:
				$handler = new PasswordResetHandler( $this->otp() );
				$grant   = $handler->verify( $session['token'], $code );

				if ( is_wp_error( $grant ) ) {
					return $this->error( $grant );
				}

				return $this->success( array( 'grant' => $grant ) );

			case OtpService::INTENT_LOGIN:
				$row = $this->otp()->verify( $session['token'], $code, OtpService::INTENT_LOGIN );

				if ( is_wp_error( $row ) ) {
					return $this->error( $row );
				}

				$user_id = (int) ( $row['payload']['user_id'] ?? 0 );
				$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

				if ( ! $user ) {
					return $this->error( new WP_Error( 'OMNIWP_no_user', __( 'Không tìm thấy tài khoản.', 'omniwp' ) ) );
				}

				$context = new AuthContext(
					array(
						'auth_method'  => 'otp',
						'user_id'      => $user_id,
						'intended_url' => (string) ( $row['payload']['redirect_to'] ?? '' ),
					)
				);
				$proof   = AuthProof::from_otp( $this->otp()->verified_claim( $row ), $user_id );
				$result  = ( new SessionIssuer() )->issue( $proof, $user, $context, ! isset( $row['payload']['remember'] ) || ! empty( $row['payload']['remember'] ) );
				if ( is_wp_error( $result ) ) {
					return $this->error( $result );
				}
				PendingSession::clear();

				return $this->success(
					array(
						'user_id'  => $user_id,
						'redirect' => ( new PostAuthRedirector() )->redirect( $result, $context->intended_url ),
					)
				);
		}

		return $this->error( new WP_Error( 'OMNIWP_bad_purpose', __( 'Phiên xác thực không hợp lệ.', 'omniwp' ) ) );
	}

	public function handle_resend( WP_REST_Request $request ) {
		$session = $this->session_for( $request );

		if ( is_wp_error( $session ) ) {
			return $this->error( $session );
		}

		$result = $this->otp()->resend( $session['token'] );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		PendingSession::start( $result['token'], $session['intent'] );

		return $this->success( $this->public_otp_payload( $result, $session['intent'] ) );
	}

	public function handle_login( WP_REST_Request $request ) {
		$identity = trim( (string) $request->get_param( 'identity' ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $identity || '' === $password ) {
			return $this->error( new WP_Error( 'OMNIWP_empty', __( 'Vui lòng nhập đầy đủ thông tin đăng nhập.', 'omniwp' ) ) );
		}

		$handler = new LoginHandler();
		$user    = $handler->attempt( $identity, $password );

		if ( $user instanceof WP_User ) {
			$redirect_to = (string) $request->get_param( 'redirect_to' );
			$context     = new AuthContext(
				array(
					'auth_method'  => 'password',
					'user_id'      => $user->ID,
					'intended_url' => $redirect_to,
				)
			);
			$result      = ( new SessionIssuer() )->issue( AuthProof::from_password( $user ), $user, $context, true );
			if ( is_wp_error( $result ) ) {
				return $this->error( $result );
			}
			return $this->success(
				array(
					'user_id'  => $user->ID,
					'redirect' => ( new PostAuthRedirector() )->redirect( $result, $redirect_to ),
				)
			);
		}

		if ( is_wp_error( $user ) && 'OMNIWP_needs_otp' === $user->get_error_code() ) {
			$user_id = (int) ( $user->get_error_data()['user_id'] ?? 0 );
			$account = get_userdata( $user_id );

			if ( ! $account ) {
				return $this->error( new WP_Error( 'OMNIWP_no_user', __( 'Không tìm thấy tài khoản.', 'omniwp' ) ) );
			}

			$phone       = (string) get_user_meta( $user_id, UserManager::META_PHONE, true );
			$destination = '' !== $phone ? $phone : $account->user_email;

			$result = $this->otp()->issue(
				$destination,
				OtpService::INTENT_LOGIN,
				array(
					'user_id'     => $user_id,
					'redirect_to' => (string) $request->get_param( 'redirect_to' ),
					'remember'    => true,
				),
				array( 'user_name' => $account->display_name )
			);

			if ( is_wp_error( $result ) ) {
				return $this->error( $result );
			}

			PendingSession::start( $result['token'], OtpService::INTENT_LOGIN );

			return $this->success( $this->public_otp_payload( $result, OtpService::INTENT_LOGIN ) );
		}

		return $this->error( $user instanceof WP_Error ? $user : new WP_Error( 'OMNIWP_failed', __( 'Đăng nhập không thành công.', 'omniwp' ) ) );
	}

	public function handle_forgot( WP_REST_Request $request ) {
		$challenge = \OmniWP\Security\Captcha::check( $request->get_params() );

		if ( is_wp_error( $challenge ) ) {
			return $this->error( $challenge );
		}

		$handler = new PasswordResetHandler( $this->otp() );
		$result  = $handler->start( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		return $this->success( $this->public_otp_payload( $result, OtpService::INTENT_RECOVER ) );
	}

	public function handle_reset( WP_REST_Request $request ) {
		$handler = new PasswordResetHandler( $this->otp() );
		$result  = $handler->complete( (string) $request->get_param( 'grant' ), $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		return $this->success( array( 'user_id' => $result ) );
	}

	public function handle_contact_start( WP_REST_Request $request ) {
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$result = ( new ContactVerificationService( $this->otp() ) )->start( get_current_user_id(), $type, (string) $request->get_param( 'value' ) );
		return is_wp_error( $result ) ? $this->error( $result ) : $this->success( $this->public_otp_payload( $result, OtpService::INTENT_ADD_IDENTITY ) );
	}

	public function handle_contact_verify( WP_REST_Request $request ) {
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$result = ( new ContactVerificationService( $this->otp() ) )->verify( get_current_user_id(), (string) $request->get_param( 'token' ), (string) $request->get_param( 'code' ), $type );
		return is_wp_error( $result ) ? $this->error( $result ) : $this->success( $result );
	}

	/**
	 * What is linked to the signed-in account.
	 */
	public function handle_identities( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- REST callbacks always receive the request.
		$service = new IdentityLinkService();
		$user_id = get_current_user_id();

		return $this->success(
			array(
				'identities' => $service->linked( $user_id ),
				'can_unlink' => $service->can_unlink( $user_id ),
			)
		);
	}

	/**
	 * Detach one identity. Requires the account password, and refuses to remove
	 * the last way in.
	 */
	public function handle_identity_unlink( WP_REST_Request $request ) {
		$result = ( new IdentityLinkService() )->unlink(
			get_current_user_id(),
			sanitize_key( (string) $request->get_param( 'channel' ) ),
			(string) $request->get_param( 'subject' ),
			(string) $request->get_param( 'password' )
		);

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		$service = new IdentityLinkService();

		return $this->success(
			array(
				'unlinked'   => true,
				'identities' => $service->linked( get_current_user_id() ),
				'can_unlink' => $service->can_unlink( get_current_user_id() ),
			)
		);
	}

	public function handle_contact_resend( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$type  = sanitize_key( (string) $request->get_param( 'type' ) );

		// A reload loses the token, which only ever existed in the browser. The
		// pending row on the server did not, so the client may ask by type
		// instead and let the service find what is in flight.
		if ( '' === $token && '' !== $type ) {
			$result = ( new ContactVerificationService( $this->otp() ) )->resend( get_current_user_id(), $type );

			return is_wp_error( $result )
				? $this->error( $result )
				: $this->success( $this->public_otp_payload( $result, OtpService::INTENT_ADD_IDENTITY ) );
		}

		$row          = $this->otp()->peek( $token );
		$valid_intent = $row && in_array( (string) $row['intent'], array( OtpService::INTENT_ADD_IDENTITY ), true );
		if ( ! $valid_intent || (int) ( $row['payload']['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return $this->error( new WP_Error( 'OMNIWP_contact_session', __( 'Phiên xác thực thông tin liên hệ không hợp lệ.', 'omniwp' ) ) );
		}
		$result = $this->otp()->resend( $token );
		return is_wp_error( $result ) ? $this->error( $result ) : $this->success( $this->public_otp_payload( $result, (string) $row['intent'] ) );
	}

	public function handle_contact_cancel( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$result = ( new ContactVerificationService( $this->otp() ) )->cancel( get_current_user_id() );
		return is_wp_error( $result ) ? $this->error( $result ) : $this->success( array( 'cancelled' => true ) );
	}

	// -----------------------------------------------------------------

	/**
	 * @return array{token:string,intent:string}|WP_Error
	 */
	private function session_for( WP_REST_Request $request ) {
		$session = PendingSession::get();

		if ( $session ) {
			return $session;
		}

		// Cookie-less clients (native apps) may pass the token explicitly.
		$token  = (string) $request->get_param( 'token' );
		$intent = (string) $request->get_param( 'intent' );

		if ( '' !== $token && '' !== $intent ) {
			return array(
				'token'  => $token,
				'intent' => $intent,
			);
		}

		return new WP_Error(
			'OMNIWP_no_session',
			__( 'Phiên xác thực đã hết hạn. Vui lòng thực hiện lại.', 'omniwp' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Strip the internal token from responses for cookie-based clients, but keep
	 * it for cookie-less ones that have to send it back.
	 */
	private function public_otp_payload( array $result, string $intent ): array {
		$payload = array(
			'intent'       => $intent,
			'masked'       => $result['masked'],
			'expires_in'   => $result['expires_in'],
			'resend_after' => $result['resend_after'],
			'channel'      => $result['transport'],
			'token'        => $result['token'],
		);

		if ( isset( $result['dev_code'] ) ) {
			$payload['dev_code'] = $result['dev_code'];
		}

		return $payload;
	}

	private function success( array $data ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	private function error( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400;

		$body = array(
			'success' => false,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);

		if ( is_array( $data ) ) {
			foreach ( array( 'retry_after', 'attempts_left', 'grant' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$body[ $key ] = $data[ $key ];
				}
			}
		}

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Get customer-facing localized status name and CSS badge modifier.
	 *
	 * Maps technical WooCommerce statuses into clear Vietnamese retail/e-commerce terms.
	 *
	 * @param string $status_slug WooCommerce order status slug.
	 * @return array{name:string, class:string, group:string}
	 */
	public static function get_status_display_info( string $status_slug ): array {
		$clean_slug = str_replace( 'wc-', '', strtolower( trim( $status_slug ) ) );

		$map = array(
			'pending'    => array(
				'name'  => __( 'Chờ thanh toán', 'omniwp' ),
				'class' => 'pending',
				'group' => 'wc-pending',
			),
			'failed'     => array(
				'name'  => __( 'Thanh toán thất bại', 'omniwp' ),
				'class' => 'cancelled',
				'group' => 'wc-cancelled',
			),
			'on-hold'    => array(
				'name'  => __( 'Chờ xác nhận', 'omniwp' ),
				'class' => 'processing',
				'group' => 'wc-processing',
			),
			'processing' => array(
				'name'  => __( 'Đang chuẩn bị hàng', 'omniwp' ),
				'class' => 'processing',
				'group' => 'wc-processing',
			),
			'packed'     => array(
				'name'  => __( 'Đã đóng gói', 'omniwp' ),
				'class' => 'packed',
				'group' => 'wc-processing',
			),
			'shipping'   => array(
				'name'  => __( 'Đang giao hàng', 'omniwp' ),
				'class' => 'shipping',
				'group' => 'wc-shipping',
			),
			'completed'  => array(
				'name'  => __( 'Hoàn thành', 'omniwp' ),
				'class' => 'completed',
				'group' => 'wc-completed',
			),
			'cancelled'  => array(
				'name'  => __( 'Đã hủy', 'omniwp' ),
				'class' => 'cancelled',
				'group' => 'wc-cancelled',
			),
			'refunded'   => array(
				'name'  => __( 'Đã hoàn tiền', 'omniwp' ),
				'class' => 'cancelled',
				'group' => 'wc-cancelled',
			),
		);

		if ( isset( $map[ $clean_slug ] ) ) {
			return $map[ $clean_slug ];
		}

		$wc_name = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $clean_slug ) : ucfirst( $clean_slug );

		return array(
			'name'  => $wc_name,
			'class' => $clean_slug,
			'group' => 'all',
		);
	}

	public static function render_order_card( \WC_Order $order ): string {
		$order_id      = $order->get_id();
		$order_number  = $order->get_order_number();
		$order_date    = wc_format_datetime( $order->get_date_created() );
		$status_slug   = $order->get_status();
		$status_info   = self::get_status_display_info( $status_slug );
		$status_name   = $status_info['name'];
		$status_class  = $status_info['class'];
		$order_total   = $order->get_formatted_order_total();
		$item_count    = $order->get_item_count();
		$payment_title = $order->get_payment_method_title();

		$items           = $order->get_items();
		$item_thumbs     = array();
		$first_item_name = '';
		$first_item_qty  = 1;

		foreach ( $items as $item ) {
			if ( empty( $first_item_name ) ) {
				$first_item_name = $item->get_name();
				$first_item_qty  = $item->get_quantity();
			}
			$product       = $item->get_product();
			$image_id      = $product ? $product->get_image_id() : 0;
			$img_url       = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '' );
			$item_thumbs[] = array(
				'url'  => $img_url,
				'name' => $item->get_name(),
			);
		}

		ob_start();
		?>
		<div class="sl-hub-order-card" data-sl-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-sl-order-detail="<?php echo esc_attr( (string) $order_id ); ?>">
			<!-- Dòng 1: Mã đơn, ngày đặt và trạng thái -->
			<div class="sl-hub-order-header">
				<div class="sl-hub-order-meta">
					<span class="sl-hub-order-number">
						<span class="sl-hub-order-icon"><?php echo IconSet::get( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<strong><?php printf( esc_html__( 'Đơn hàng #%s', 'omniwp' ), esc_html( (string) $order_number ) ); ?></strong>
					</span>
					<span class="sl-hub-order-dot">•</span>
					<span class="sl-hub-order-date"><?php echo esc_html( $order_date ); ?></span>
				</div>
				<span class="sl-hub-status-badge sl-hub-status-badge--<?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( $status_name ); ?>
				</span>
			</div>

			<!-- Dòng 2: Ảnh xếp lớp + Tổng số lượng + Tổng tiền (Hiển thị ngang) -->
			<div class="sl-hub-order-row2">
				<div class="sl-hub-order-row2__left">
					<div class="sl-order-thumbs-stack">
						<?php
						$max_display  = 3;
						$shown_thumbs = array_slice( $item_thumbs, 0, $max_display );
						$extra_count  = count( $item_thumbs ) - $max_display;
						?>
						<?php foreach ( $shown_thumbs as $idx => $thumb ) : ?>
							<div class="sl-order-thumb" style="z-index: <?php echo esc_attr( (string) ( 10 - $idx ) ); ?>;">
								<?php if ( ! empty( $thumb['url'] ) ) : ?>
									<img src="<?php echo esc_url( $thumb['url'] ); ?>" alt="<?php esc_attr_e( 'Sản phẩm', 'omniwp' ); ?>" loading="lazy" />
								<?php else : ?>
									<div class="sl-order-thumb__placeholder"><?php echo IconSet::get( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<?php if ( $extra_count > 0 ) : ?>
							<div class="sl-order-thumb sl-order-thumb--extra" style="z-index: 5;">
								+<?php echo esc_html( (string) $extra_count ); ?>
							</div>
						<?php endif; ?>
					</div>
					<span class="sl-order-items-total-badge">
						<?php printf( esc_html__( 'Tổng %d sản phẩm', 'omniwp' ), (int) $item_count ); ?>
					</span>
				</div>

				<div class="sl-hub-order-row2__right">
					<div class="sl-hub-order-price-wrap">
						<div class="sl-hub-order-total">
							<span class="sl-hub-order-total-label"><?php esc_html_e( 'Tổng thanh toán:', 'omniwp' ); ?></span>
							<span class="sl-hub-order-total-amount"><?php echo wp_kses_post( $order_total ); ?></span>
						</div>
						<?php if ( $payment_title ) : ?>
							<span class="sl-hub-order-payment"><?php echo esc_html( $payment_title ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function handle_orders( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$status  = sanitize_text_field( (string) ( $request->get_param( 'status' ) ?: 'all' ) );
		$search  = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: '' ) );

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			return new WP_REST_Response(
				array(
					'counts' => array( 'all' => 0 ),
					'html'   => '<div style="text-align:center; padding: 48px 16px; color:#64748b;"><p>WooCommerce chưa được kích hoạt.</p></div>',
				),
				200
			);
		}

		$statuses = array(
			'all'           => array(),
			'wc-pending'    => array( 'pending', 'failed' ),
			'wc-processing' => array( 'processing', 'on-hold', 'packed' ),
			'wc-shipping'   => array( 'shipping' ),
			'wc-completed'  => array( 'completed' ),
			'wc-cancelled'  => array( 'cancelled', 'refunded' ),
		);

		$counts = array();
		foreach ( $statuses as $key => $wc_status_group ) {
			$args = array(
				'customer_id' => $user_id,
				'return'      => 'ids',
				'limit'       => -1,
			);
			if ( ! empty( $wc_status_group ) ) {
				$args['status'] = $wc_status_group;
			}
			$order_ids      = wc_get_orders( $args );
			$counts[ $key ] = is_array( $order_ids ) ? count( $order_ids ) : 0;
		}

		$query_args = array(
			'customer_id' => $user_id,
			'limit'       => 20,
		);

		if ( isset( $statuses[ $status ] ) && ! empty( $statuses[ $status ] ) ) {
			$query_args['status'] = $statuses[ $status ];
		}

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$orders = wc_get_orders( $query_args );

		ob_start();
		if ( ! empty( $orders ) && is_array( $orders ) ) {
			echo '<div class="sl-hub-orders-list">';
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				echo self::render_order_card( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		} else {
			echo '<div style="text-align:center; padding: 48px 16px; color:#64748b;">';
			echo '<div style="margin-bottom:12px;">' . IconSet::get( 'box' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p style="margin:0; font-weight:500;">' . esc_html__( 'Không tìm thấy đơn hàng nào phù hợp.', 'omniwp' ) . '</p>';
			echo '</div>';
		}
		$html = (string) ob_get_clean();

		return new WP_REST_Response(
			array(
				'counts' => $counts,
				'html'   => $html,
			),
			200
		);
	}

	public function handle_order_detail( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$order_id = absint( $request->get_param( 'id' ) );

		if ( ! $order_id || ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Đơn hàng không hợp lệ.', 'omniwp' ) ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ( (int) $order->get_customer_id() !== (int) $user_id && ! current_user_can( 'manage_woocommerce' ) ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Không tìm thấy đơn hàng hoặc bạn không có quyền truy cập.', 'omniwp' ) ), 403 );
		}

		$items_data = array();
		$line_items = $order->get_items( 'line_item' );
		if ( is_array( $line_items ) || $line_items instanceof \Traversable ) {
			foreach ( $line_items as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
					continue;
				}

				$product   = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
				$image_url = '';
				if ( $product && $product instanceof \WC_Product ) {
					$image_id  = $product->get_image_id();
					$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '' );
				} else {
					$image_url = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '';
				}

				$qty               = max( 1, (int) ( method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 1 ) );
				$line_subtotal_raw = (float) ( method_exists( $item, 'get_subtotal' ) ? $item->get_subtotal() : ( method_exists( $item, 'get_total' ) ? $item->get_total() : 0 ) );
				$line_total_raw    = (float) ( method_exists( $item, 'get_total' ) ? $item->get_total() : 0 );
				$display_price_raw = $qty > 0 ? ( $line_subtotal_raw > 0 ? ( $line_subtotal_raw / $qty ) : ( $line_total_raw > 0 ? ( $line_total_raw / $qty ) : 0 ) ) : 0;

				$subtotal_formatted = method_exists( $order, 'get_formatted_line_subtotal' ) ? $order->get_formatted_line_subtotal( $item ) : '';
				if ( empty( $subtotal_formatted ) ) {
					$subtotal_formatted = wc_price( $line_total_raw > 0 ? $line_total_raw : $line_subtotal_raw, array( 'currency' => $order->get_currency() ) );
				}

				$items_data[] = array(
					'name'       => $item->get_name(),
					'quantity'   => $qty,
					'unit_price' => wc_price( $display_price_raw, array( 'currency' => $order->get_currency() ) ),
					'subtotal'   => wc_price( $line_subtotal_raw, array( 'currency' => $order->get_currency() ) ),
					'total'      => $subtotal_formatted,
					'image'      => $image_url,
					'meta'       => wc_display_item_meta( $item, array( 'echo' => false ) ),
				);
			}
		}

		$customer_name  = $order->get_formatted_billing_full_name() ?: $order->get_formatted_shipping_full_name();
		$customer_phone = $order->get_billing_phone() ?: $order->get_shipping_phone();
		$customer_email = $order->get_billing_email();
		if ( false !== strpos( (string) $customer_email, 'phone.invalid' ) || false !== strpos( (string) $customer_email, 'example.com' ) ) {
			$customer_email = '';
		}

		// Chuẩn hóa địa chỉ chuẩn Việt Nam: Số nhà/đường > Phường/Xã > Tỉnh/Thành phố
		// Trong WooCommerce tích hợp OmniWP:
		// - 'state' lưu Tỉnh/Thành phố (mã số hoặc tên)
		// - 'city' hoặc meta ward lưu Phường/Xã (tên hoặc mã)
		// - 'address_1' lưu Số nhà / Tên đường

		// 1. TỈNH / THÀNH PHỐ (Province từ state)
		$raw_state     = trim( (string) ( $order->get_shipping_state() ?: $order->get_billing_state() ) );
		$province_code = '';
		$province_name = '';

		if ( '' !== $raw_state ) {
			$p_name = AddressRepository::province_name( $raw_state );
			if ( '' !== $p_name ) {
				$province_name = $p_name;
				$province_code = AddressRepository::province_code( $raw_state );
			} elseif ( function_exists( 'WC' ) && WC()->countries ) {
				$wc_states = WC()->countries->get_states( 'VN' );
				if ( is_array( $wc_states ) ) {
					$st_clean = str_replace( 'VN-', '', $raw_state );
					if ( isset( $wc_states[ $st_clean ] ) ) {
						$province_name = $wc_states[ $st_clean ];
					} elseif ( isset( $wc_states[ $raw_state ] ) ) {
						$province_name = $wc_states[ $raw_state ];
					}
				}
			}

			if ( '' === $province_name && ! ctype_digit( $raw_state ) ) {
				$province_name = $raw_state;
			}
		}

		// 2. PHƯỜNG / XÃ (Ward từ city hoặc meta ward)
		$raw_city = trim( (string) ( $order->get_shipping_city() ?: $order->get_billing_city() ) );
		$raw_ward = trim( (string) ( $order->get_meta( '_shipping_ward' ) ?: ( $order->get_meta( '_billing_ward' ) ?: ( $order->get_meta( 'shipping_ward' ) ?: ( $order->get_meta( 'billing_ward' ) ?: ( $order->get_meta( 'OmniWP_shipping_ward_code' ) ?: ( $order->get_meta( 'OmniWP_ward_code' ) ?: ( $order->get_meta( '_shipping_address_2' ) ?: $order->get_meta( '_billing_address_2' ) ) ) ) ) ) ) ) );

		$ward_name = '';

		if ( '' !== $raw_ward ) {
			if ( ctype_digit( $raw_ward ) ) {
				if ( '' !== $province_code ) {
					$ward_name = AddressRepository::ward_name( $raw_ward, $province_code );
				}
				if ( '' === $ward_name ) {
					foreach ( array_keys( AddressRepository::provinces() ) as $p_key ) {
						$w_test = AddressRepository::ward_name( $raw_ward, (string) $p_key );
						if ( '' !== $w_test ) {
							$ward_name = $w_test;
							if ( '' === $province_name ) {
								$province_name = AddressRepository::province_name( (string) $p_key );
							}
							break;
						}
					}
				}
			} else {
				$ward_name = $raw_ward;
			}
		}

		// Nếu chưa có ward_name, kiểm tra raw_city (vì WooCommerce VN lưu Phường/Xã vào trường City)
		if ( '' === $ward_name && '' !== $raw_city ) {
			if ( ctype_digit( $raw_city ) ) {
				if ( '' !== $province_code ) {
					$ward_name = AddressRepository::ward_name( $raw_city, $province_code );
				}
				if ( '' === $ward_name ) {
					foreach ( array_keys( AddressRepository::provinces() ) as $p_key ) {
						$w_test = AddressRepository::ward_name( $raw_city, (string) $p_key );
						if ( '' !== $w_test ) {
							$ward_name = $w_test;
							if ( '' === $province_name ) {
								$province_name = AddressRepository::province_name( (string) $p_key );
							}
							break;
						}
					}
				}
			} else {
				$ward_name = $raw_city;
			}
		}

		// 3. SỐ NHÀ / TÊN ĐƯỜNG (Address 1 & Address 2)
		$addr_1 = trim( (string) ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ) );
		$addr_2 = trim( (string) ( $order->get_shipping_address_2() ?: $order->get_billing_address_2() ) );

		// 4. Ghép nối theo thứ tự chuẩn: Số nhà/đường > Phường/Xã > Tỉnh/Thành phố
		$addr_parts = array();
		if ( '' !== $addr_1 && ! ctype_digit( $addr_1 ) ) {
			$addr_parts[] = $addr_1;
		}
		if ( '' !== $addr_2 && ! ctype_digit( $addr_2 ) && $addr_2 !== $addr_1 && $addr_2 !== $ward_name && $addr_2 !== $province_name ) {
			$addr_parts[] = $addr_2;
		}
		if ( '' !== $ward_name && ! ctype_digit( $ward_name ) && ! in_array( $ward_name, $addr_parts, true ) && $ward_name !== $province_name ) {
			$addr_parts[] = $ward_name;
		}
		if ( '' !== $province_name && ! ctype_digit( $province_name ) && ! in_array( $province_name, $addr_parts, true ) ) {
			$addr_parts[] = $province_name;
		}

		if ( ! empty( $addr_parts ) ) {
			$shipping_address = implode( ', ', $addr_parts );
		} else {
			$raw_fmt   = (string) ( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() );
			$raw_lines = array_filter( array_map( 'trim', explode( '<br/>', str_replace( array( '<br>', '<br />' ), '<br/>', $raw_fmt ) ) ) );
			if ( ! empty( $raw_lines ) && ! empty( $customer_name ) && false !== stripos( $raw_lines[0], $customer_name ) ) {
				array_shift( $raw_lines );
			}
			$shipping_address = implode( ', ', $raw_lines );
		}

		$status_slug = $order->get_status();
		$status_info = self::get_status_display_info( $status_slug );
		$status_name = $status_info['name'];
		$status_cls  = $status_info['class'];

		$is_cancelled_flow = in_array( $status_slug, array( 'cancelled', 'refunded', 'failed' ), true );

		if ( $is_cancelled_flow ) {
			$timeline = array(
				array(
					'step'   => 'created',
					'label'  => __( 'Đã đặt hàng', 'omniwp' ),
					'date'   => wc_format_datetime( $order->get_date_created() ),
					'done'   => true,
					'active' => false,
				),
				array(
					'step'   => 'cancelled',
					'label'  => $status_name,
					'date'   => '',
					'done'   => true,
					'active' => true,
					'cancel' => true,
				),
			);
		} else {
			$timeline = array(
				array(
					'step'   => 'created',
					'label'  => __( 'Đã đặt hàng', 'omniwp' ),
					'date'   => wc_format_datetime( $order->get_date_created() ),
					'done'   => true,
					'active' => in_array( $status_slug, array( 'pending', 'on-hold' ), true ),
				),
				array(
					'step'   => 'processing',
					'label'  => __( 'Đang chuẩn bị', 'omniwp' ),
					'date'   => '',
					'done'   => in_array( $status_slug, array( 'processing', 'packed', 'shipping', 'completed' ), true ),
					'active' => in_array( $status_slug, array( 'processing', 'packed' ), true ),
				),
				array(
					'step'   => 'shipping',
					'label'  => __( 'Đang giao hàng', 'omniwp' ),
					'date'   => '',
					'done'   => in_array( $status_slug, array( 'shipping', 'completed' ), true ),
					'active' => 'shipping' === $status_slug,
				),
				array(
					'step'   => 'completed',
					'label'  => __( 'Hoàn thành', 'omniwp' ),
					'date'   => $order->get_date_completed() ? wc_format_datetime( $order->get_date_completed() ) : '',
					'done'   => 'completed' === $status_slug,
					'active' => 'completed' === $status_slug,
				),
			);
		}

		$data = array(
			'id'               => $order->get_id(),
			'number'           => $order->get_order_number(),
			'date'             => wc_format_datetime( $order->get_date_created() ),
			'status_slug'      => $status_cls,
			'status_name'      => $status_name,
			'is_cancelled'     => $is_cancelled_flow,
			'timeline'         => $timeline,
			'customer_name'    => $customer_name,
			'customer_phone'   => $customer_phone,
			'customer_email'   => $customer_email,
			'shipping_address' => $shipping_address,
			'customer_note'    => $order->get_customer_note(),
			'payment_method'   => $order->get_payment_method_title(),
			'items'            => $items_data,
			'subtotal'         => wc_price( $order->get_subtotal(), array( 'currency' => $order->get_currency() ) ),
			'shipping_total'   => wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ),
			'discount_total'   => (float) $order->get_discount_total() > 0 ? wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ) : '',
			'total'            => $order->get_formatted_order_total(),
			'view_url'         => $order->get_view_order_url(),
		);

		return new WP_REST_Response( array( 'order' => $data ), 200 );
	}

	public function handle_get_addresses( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$addresses = AddressBook::get_addresses( $user_id );

		return new WP_REST_Response( array( 'addresses' => $addresses ), 200 );
	}

	public function handle_save_address( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$params    = $request->get_json_params() ?: $request->get_body_params();
		$saved     = AddressBook::save_address( $user_id, (array) $params );
		$addresses = AddressBook::get_addresses( $user_id );

		return new WP_REST_Response(
			array(
				'success'   => true,
				'saved'     => $saved,
				'addresses' => $addresses,
			),
			200
		);
	}

	public function handle_delete_address( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$id        = sanitize_text_field( (string) ( $request->get_param( 'id' ) ?: '' ) );
		$deleted   = AddressBook::delete_address( $user_id, $id );
		$addresses = AddressBook::get_addresses( $user_id );

		return new WP_REST_Response(
			array(
				'success'   => $deleted,
				'addresses' => $addresses,
			),
			200
		);
	}

	public function handle_set_default_address( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$params    = $request->get_json_params() ?: $request->get_body_params();
		$id        = sanitize_text_field( (string) ( $params['id'] ?? '' ) );
		$set       = AddressBook::set_default( $user_id, $id );
		$addresses = AddressBook::get_addresses( $user_id );

		return new WP_REST_Response(
			array(
				'success'   => $set,
				'addresses' => $addresses,
			),
			200
		);
	}

	public function handle_parse_address( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_body_params();
		$raw    = sanitize_text_field( (string) ( $params['raw'] ?? '' ) );
		$parsed = AddressBook::parse_raw_address( $raw );

		return new WP_REST_Response( array( 'parsed' => $parsed ), 200 );
	}

	public function handle_reorder( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Vui lòng đăng nhập.', 'omniwp' ),
				),
				401
			);
		}

		$order_id = (int) $request->get_param( 'id' );
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order || ( (int) $order->get_customer_id() !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Đơn hàng không hợp lệ.', 'omniwp' ),
				),
				404
			);
		}

		if ( ! function_exists( 'WC' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'WooCommerce chưa được kích hoạt.', 'omniwp' ),
				),
				400
			);
		}

		// REST API trong WordPress mặc định không nạp Session và Cart của WooCommerce
		// Cần chủ động khởi tạo Session và Cart
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( null === WC()->session && class_exists( '\WC_Session_Handler' ) ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}

		if ( null === WC()->customer ) {
			WC()->customer = new \WC_Customer( $user_id, true );
		}

		if ( null === WC()->cart ) {
			if ( method_exists( WC(), 'initialize_cart' ) ) {
				WC()->initialize_cart();
			} else {
				WC()->cart = new \WC_Cart();
			}
		}

		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' );

		// Fallback URL theo chuẩn nonce WooCommerce
		$fallback_reorder_url = wp_nonce_url(
			add_query_arg( 'order_again', $order->get_id(), $cart_url ),
			'woocommerce-order_again'
		);

		if ( ! WC()->cart ) {
			// Chuyển hướng trực tiếp qua endpoint order_again mặc định của WooCommerce
			return new WP_REST_Response(
				array(
					'success'     => true,
					'added_count' => 1,
					'cart_url'    => $fallback_reorder_url,
					'message'     => __( 'Đang chuyển đến giỏ hàng...', 'omniwp' ),
				),
				200
			);
		}

		$items       = $order->get_items();
		$added_count = 0;

		foreach ( $items as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$quantity     = $item->get_quantity();
			$product      = $item->get_product();

			if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
				$variation_data = array();
				if ( $variation_id ) {
					$meta_data = $item->get_meta_data();
					foreach ( $meta_data as $meta ) {
						if ( taxonomy_is_product_attribute( $meta->key ) || 0 === strpos( $meta->key, 'attribute_' ) ) {
							$variation_data[ $meta->key ] = $meta->value;
						}
					}
				}

				$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_data );
				if ( $added ) {
					++$added_count;
				}
			}
		}

		$target_url = $added_count > 0 ? $cart_url : $fallback_reorder_url;

		return new WP_REST_Response(
			array(
				'success'     => true,
				'added_count' => $added_count,
				'cart_url'    => $target_url,
				'message'     => sprintf( __( 'Đã thêm %d sản phẩm vào giỏ hàng.', 'omniwp' ), (int) $added_count ),
			),
			200
		);
	}

	/**
	 * Get vouchers list for the logged-in customer.
	 */
	public function handle_vouchers(): WP_REST_Response {
		$user_id  = get_current_user_id();
		$vouchers = VoucherService::get_customer_vouchers( $user_id );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'vouchers' => $vouchers,
			),
			200
		);
	}

	/**
	 * Apply a voucher code to the active WooCommerce cart.
	 */
	public function handle_apply_voucher( WP_REST_Request $request ): WP_REST_Response {
		$code   = (string) $request->get_param( 'code' );
		$result = VoucherService::apply_to_cart( $code );

		return new WP_REST_Response(
			$result,
			$result['success'] ? 200 : 400
		);
	}
}
