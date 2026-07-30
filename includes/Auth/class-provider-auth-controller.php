<?php
/**
 * WordPress entry points for external provider redirects and callbacks.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\Notices;
use SmartLogin\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ProviderAuthController {

	private ProviderRegistry $providers;
	private OAuthTransactionStore $transactions;

	public function __construct( ?ProviderRegistry $providers = null, ?OAuthTransactionStore $transactions = null ) {
		$this->transactions = $transactions ?? new OAuthTransactionStore();
		$this->providers    = $providers ?? new ProviderRegistry();
	}

	public function register(): void {
		add_action( 'admin_post_nopriv_smart_login_provider_start', array( $this, 'start' ) );
		add_action( 'admin_post_smart_login_provider_start', array( $this, 'start' ) );
		add_action( 'admin_post_nopriv_smart_login_provider_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_smart_login_provider_callback', array( $this, 'callback' ) );
	}

	public static function start_url( string $provider, string $return_url = '', bool $linking = false ): string {
		$provider = sanitize_key( $provider );
		$url      = add_query_arg(
			array(
				'action'      => 'smart_login_provider_start',
				'provider'    => $provider,
				'redirect_to' => wp_validate_redirect( $return_url, '' ),
				'linking'     => $linking ? '1' : '0',
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'smart_login_provider_start_' . $provider );
	}

	public function available(): array {
		return $this->providers->available();
	}

	public function start(): void {
		$provider_id = sanitize_key( wp_unslash( $_GET['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		// wp_verify_nonce() does its own validation; sanitising first would only
		// risk altering the token before comparison.
		if ( ! wp_verify_nonce( (string) wp_unslash( $_GET['_wpnonce'] ?? '' ), 'smart_login_provider_start_' . $provider_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->fail( new \WP_Error( 'smart_login_bad_nonce', __( 'Phiên đăng nhập đã hết hạn. Vui lòng thử lại.', 'smart-login' ) ) );
		}
		$provider = $this->providers->get( $provider_id );
		if ( ! $provider || ! $provider->is_available() ) {
			$this->fail( new \WP_Error( 'smart_login_provider_unavailable', __( 'Phương thức đăng nhập chưa sẵn sàng.', 'smart-login' ) ) );
		}
		// wp_validate_redirect() returns '' for any host outside the allowed list,
		// so $return_url cannot carry an off-site destination even though it comes
		// straight from the query string.
		$return_url = wp_validate_redirect( (string) wp_unslash( $_GET['redirect_to'] ?? '' ), '' ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$linking    = ! empty( $_GET['linking'] ) && is_user_logged_in(); // phpcs:ignore WordPress.Security.NonceVerification
		$redirect   = $provider->begin( $return_url, $linking );

		// wp_redirect(), not wp_safe_redirect(), and deliberately so: this is the
		// hand-off to the provider's own authorization endpoint, which is by
		// definition off-site and would be blocked by the safe variant. The URL is
		// built by OAuthAuthorizationUrl from stored settings, never from request
		// input — the visitor's own redirect_to is kept in the transaction store,
		// not in this URL.
		wp_redirect( $redirect->url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public function callback(): void {
		$provider_id = sanitize_key( wp_unslash( $_REQUEST['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$provider    = $this->providers->get( $provider_id );
		// The state token is looked up verbatim in the transaction store, which is
		// the validation. Sanitising it would only break the comparison.
		$state = (string) wp_unslash( $_REQUEST['state'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $provider || ! $provider->is_available() ) {
			$this->fail( new \WP_Error( 'smart_login_provider_unavailable', __( 'Phương thức đăng nhập chưa sẵn sàng.', 'smart-login' ) ) );
		}
		if ( ! empty( $_REQUEST['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			AuditLog::record(
				AuditLog::PROVIDER_FAILED,
				'',
				array(
					'provider' => $provider_id,
					'reason'   => 'cancelled',
				)
			);
			$this->fail( new \WP_Error( 'smart_login_provider_cancelled', __( 'Đăng nhập qua nhà cung cấp đã bị hủy.', 'smart-login' ) ) );
		}
		$transaction = $this->transactions->consume( $state, $provider_id );
		if ( is_wp_error( $transaction ) ) {
			AuditLog::record(
				AuditLog::PROVIDER_FAILED,
				'',
				array(
					'provider' => $provider_id,
					'reason'   => $transaction->get_error_code(),
				)
			);
			$this->fail( $transaction );
		}
		$request                 = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
		$request['_transaction'] = $transaction;
		$identity                = $provider->complete( $request );
		if ( is_wp_error( $identity ) ) {
			AuditLog::record(
				AuditLog::PROVIDER_FAILED,
				'',
				array(
					'provider' => $provider_id,
					'reason'   => $identity->get_error_code(),
				)
			);
			$this->fail( $identity );
		}
		$resolved = ( new AccountProvisioner() )->resolve( $identity, $transaction );
		if ( is_wp_error( $resolved ) ) {
			$this->fail( $resolved );
		}
		$context               = $resolved['context'];
		$context->intended_url = (string) ( $transaction['return_url'] ?? '' );
		$proof                 = AuthProof::from_oauth(
			\SmartLogin\Identity\VerifiedClaim::from(
				\SmartLogin\Identity\Claim::canonical( $identity->provider, $identity->subject ),
				\SmartLogin\Identity\VerifiedClaim::PROOF_OAUTH
			),
			(int) $resolved['user']->ID
		);
		$result                = ( new SessionIssuer() )->issue( $proof, $resolved['user'], $context );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}
		AuditLog::record(
			AuditLog::PROVIDER_LOGIN,
			'',
			array(
				'provider' => $provider_id,
				'new_user' => $context->is_new_user,
			),
			$result->user_id
		);
		wp_safe_redirect( ( new PostAuthRedirector() )->redirect( $result, $context->intended_url ) );
		exit;
	}

	private function fail( \WP_Error $error ): void {
		Notices::flash( $error->get_error_message(), 'error' );
		wp_safe_redirect( self::failure_url() );
		exit;
	}

	public static function failure_url(): string {
		$base = function_exists( 'wc_get_page_permalink' )
			? (string) wc_get_page_permalink( 'myaccount' )
			: '';
		if ( '' === $base ) {
			$base = home_url( '/' );
		}

		$fallback = add_query_arg( 'smart_login_step', Flow::STEP_LOGIN, $base );
		$filtered = (string) apply_filters( 'smart_login_provider_failure_redirect', $fallback );

		return wp_validate_redirect( $filtered, $fallback );
	}
}
