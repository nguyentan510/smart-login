<?php
/**
 * JSON API mirroring the form flow, for SPA/app front-ends.
 *
 * Namespace: smart-login/v1
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Auth\LoginHandler;
use SmartLogin\Auth\ContactVerificationService;
use SmartLogin\Auth\AuthContext;
use SmartLogin\Auth\AuthProof;
use SmartLogin\Auth\PostAuthRedirector;
use SmartLogin\Auth\SessionIssuer;
use SmartLogin\Auth\PasswordResetHandler;
use SmartLogin\Auth\PendingSession;
use SmartLogin\Auth\RegisterHandler;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\RequestGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

class RestController {

	const REST_NAMESPACE = 'smart-login/v1';

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

		foreach ( array( 'contact/start' => 'handle_contact_start', 'contact/verify' => 'handle_contact_verify', 'contact/resend' => 'handle_contact_resend' ) as $route => $callback ) {
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
				'smart_login_bad_nonce',
				__( 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.', 'smart-login' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function check_contact_permission( WP_REST_Request $request ) {
		$allowed = $this->check_permission( $request );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'smart_login_not_logged_in', __( 'Bạn cần đăng nhập để thay đổi thông tin liên hệ.', 'smart-login' ), array( 'status' => 401 ) );
		}
		return true;
	}

	// -----------------------------------------------------------------

	public function handle_register( WP_REST_Request $request ) {
		$params = $request->get_params();

		$guard = RequestGuard::verify_rest( 'register', $params );

		if ( is_wp_error( $guard ) ) {
			return $this->error( $guard );
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
					return $this->error( new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) ) );
				}

				$context = new AuthContext( array( 'auth_method' => 'otp', 'user_id' => $user_id, 'intended_url' => (string) ( $row['payload']['redirect_to'] ?? '' ) ) );
				$proof   = AuthProof::from_otp( $this->otp()->verified_claim( $row ), $user_id );
				$result = ( new SessionIssuer() )->issue( $proof, $user, $context, ! isset( $row['payload']['remember'] ) || ! empty( $row['payload']['remember'] ) );
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

		return $this->error( new WP_Error( 'smart_login_bad_purpose', __( 'Phiên xác thực không hợp lệ.', 'smart-login' ) ) );
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
			return $this->error( new WP_Error( 'smart_login_empty', __( 'Vui lòng nhập đầy đủ thông tin đăng nhập.', 'smart-login' ) ) );
		}

		$handler = new LoginHandler();
		$user    = $handler->attempt( $identity, $password, true );

		if ( $user instanceof WP_User ) {
			$redirect_to = (string) $request->get_param( 'redirect_to' );
			$context = new AuthContext( array( 'auth_method' => 'password', 'user_id' => $user->ID, 'intended_url' => $redirect_to ) );
			$result = ( new SessionIssuer() )->issue( AuthProof::from_password( $user ), $user, $context, true );
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

		if ( is_wp_error( $user ) && 'smart_login_needs_otp' === $user->get_error_code() ) {
			$user_id = (int) ( $user->get_error_data()['user_id'] ?? 0 );
			$account = get_userdata( $user_id );

			if ( ! $account ) {
				return $this->error( new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) ) );
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

		return $this->error( $user instanceof WP_Error ? $user : new WP_Error( 'smart_login_failed', __( 'Đăng nhập không thành công.', 'smart-login' ) ) );
	}

	public function handle_forgot( WP_REST_Request $request ) {
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
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$result = ( new ContactVerificationService( $this->otp() ) )->start( get_current_user_id(), $type, (string) $request->get_param( 'value' ) );
		return is_wp_error( $result ) ? $this->error( $result ) : $this->success( $this->public_otp_payload( $result, OtpService::INTENT_ADD_IDENTITY ) );
	}

	public function handle_contact_verify( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$result = ( new ContactVerificationService( $this->otp() ) )->verify( get_current_user_id(), (string) $request->get_param( 'token' ), (string) $request->get_param( 'code' ), $type );
		return is_wp_error( $result ) ? $this->error( $result ) : $this->success( $result );
	}

	public function handle_contact_resend( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$row = $this->otp()->peek( $token );
		$valid_intent = $row && in_array( (string) $row['intent'], array( OtpService::INTENT_ADD_IDENTITY ), true );
		if ( ! $valid_intent || (int) ( $row['payload']['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return $this->error( new WP_Error( 'smart_login_contact_session', __( 'Phiên xác thực thông tin liên hệ không hợp lệ.', 'smart-login' ) ) );
		}
		$result = $this->otp()->resend( $token );
		return is_wp_error( $result ) ? $this->error( $result ) : $this->success( $this->public_otp_payload( $result, (string) $row['intent'] ) );
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
			'smart_login_no_session',
			__( 'Phiên xác thực đã hết hạn. Vui lòng thực hiện lại.', 'smart-login' ),
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
}
