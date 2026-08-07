<?php
/**
 * WordPress entry points for external provider redirects and callbacks.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Auth\Providers\LoginProviderInterface;
use SmartLogin\Auth\Providers\ProviderCredentials;
use SmartLogin\Auth\Providers\ProviderIdentity;
use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\Notices;
use SmartLogin\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ProviderAuthController {

	/** Where a finished connection test leaves its result, per administrator. */
	const TEST_RESULT_PREFIX = 'smart_login_provider_test_';

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

	/**
	 * Marker carried on the return url of a round trip a dialog started.
	 *
	 * It rides on the return url rather than being a fifth column on the
	 * transaction, because the alternative is a new parameter on
	 * `LoginProviderInterface::begin()` — an interface third-party providers
	 * implement, and widening it would break every one of them for a fact only
	 * this controller reads. `callback()` strips it before the url is used.
	 */
	const IN_PLACE_ARG = 'sl_place';

	/**
	 * @param bool $in_place Whether a dialog started this, and so expects the
	 *                       visitor back on the page they left rather than on
	 *                       the account screen. See AuthContext::$in_place.
	 */
	public static function start_url( string $provider, string $return_url = '', bool $linking = false, bool $in_place = false ): string {
		$provider = sanitize_key( $provider );

		if ( $in_place && '' !== $return_url ) {
			$return_url = add_query_arg( self::IN_PLACE_ARG, '1', $return_url );
		}

		$url = add_query_arg(
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

	/**
	 * The same start URL, flagged as a diagnostic.
	 *
	 * It carries the identical nonce, because it is the identical entry point —
	 * `start()` reads `sl_test` only after verifying that nonce, and pairs it
	 * with a `manage_options` check, since this exchanges the site's own
	 * credentials and is not a thing a visitor may cause.
	 */
	public static function test_url( string $provider ): string {
		return add_query_arg( 'sl_test', '1', self::start_url( $provider ) );
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
		// A connection test, not a login. Administrators only — this exchanges the
		// site's own credentials with the provider, which is not a thing a
		// visitor may cause, and the nonce above is scoped to the provider rather
		// than to the capability.
		$is_test = ! empty( $_GET['sl_test'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification

		$provider = $is_test
			? $this->test_provider( $provider_id )
			: $this->providers->get( $provider_id );

		// A test may run against a provider that is configured but switched off;
		// a login may not. `is_available()` is `enabled && configured`, so this is
		// what keeps a disabled provider from ever creating a login transaction.
		if ( ! $provider || ( ! $is_test && ! $provider->is_available() ) ) {
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

		/*
		 * Availability is checked after the transaction is known, not here.
		 *
		 * A connection test has to be able to run against a provider that is
		 * configured but switched off — that is precisely the state a test exists
		 * to get an administrator out of, and requiring them to enable it first
		 * means showing a possibly-broken button to real visitors while they
		 * check.
		 *
		 * That is safe because a *login* transaction for a disabled provider
		 * cannot exist: start() still refuses to create one. The re-check below
		 * is the second lock on the same door.
		 */
		if ( ! $provider ) {
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
		$is_test = OAuthTransactionStore::is_test( $transaction );

		/*
		 * Where a refusal from here on returns to.
		 *
		 * Only for a linking attempt. A failed *sign-in* must not be sent to the
		 * page the visitor was heading for — they are not signed in, so that
		 * page cannot serve them, and the sign-in screen is exactly where they
		 * need to be. A failed *link* is the opposite case: the visitor is
		 * signed in, started on their account page, and the sign-in step is a
		 * screen they never see — which is where the one sentence explaining
		 * the refusal was being delivered.
		 */
		$failure_return = ! empty( $transaction['linking'] )
			? (string) ( $transaction['return_url'] ?? '' )
			: '';

		// The check moved from the top of this method. Anything that is not a
		// test still needs the provider switched on before it goes any further.
		if ( ! $is_test && ! $provider->is_available() ) {
			AuditLog::record(
				AuditLog::PROVIDER_FAILED,
				'',
				array(
					'provider' => $provider_id,
					'reason'   => 'unavailable',
					'linking'  => ! empty( $transaction['linking'] ),
				)
			);
			$this->fail(
				new \WP_Error( 'smart_login_provider_unavailable', __( 'Phương thức đăng nhập chưa sẵn sàng.', 'smart-login' ) ),
				$failure_return
			);
		}
		$request                 = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
		$request['_transaction'] = $transaction;
		$identity                = $provider->complete( $request );
		if ( is_wp_error( $identity ) ) {
			/*
			 * The provider's own words, when it supplied any.
			 *
			 * `reason` is this plugin's error code, which says which step
			 * failed and nothing about why. A misconfigured provider app once
			 * spent a live debugging session hiding behind one such code; the
			 * detail that would have named it in a sentence was on the WP_Error
			 * all along and was being dropped here.
			 */
			$detail = is_array( $identity->get_error_data() ) ? $identity->get_error_data() : array();
			AuditLog::record(
				AuditLog::PROVIDER_FAILED,
				'',
				array_merge(
					array(
						'provider' => $provider_id,
						'reason'   => $identity->get_error_code(),
						'linking'  => ! empty( $transaction['linking'] ),
					),
					array_intersect_key(
						$detail,
						array_flip( array( 'status', 'provider_error', 'provider_error_name' ) )
					)
				)
			);
			$this->fail( $identity, $failure_return );
		}

		/*
		 * The line this whole sub-phase exists to hold.
		 *
		 * Everything above is what a test is for: the code exchange, the token,
		 * and the identity the provider actually returned. Everything below
		 * writes — AccountProvisioner resolves or creates a user, SessionIssuer
		 * signs them in. A diagnostic that fell through here would log the
		 * administrator in and, on an account that does not exist yet, provision
		 * one, and nobody would notice, because signing in successfully is what
		 * success looks like.
		 */
		if ( $is_test ) {
			$this->report_test( $provider_id, $identity );
		}

		$resolved = ( new AccountProvisioner() )->resolve( $identity, $transaction );
		if ( is_wp_error( $resolved ) ) {
			/*
			 * The fourth refusal, which for a long time was the only silent one.
			 *
			 * The three branches above record; this one did not, and it is the
			 * one a visitor actually meets — `smart_login_provider_conflict`,
			 * raised when the provider account is already linked to somebody
			 * else. Three presses of "Liên kết" left an empty log and the
			 * diagnosis had to be rebuilt against the database by hand.
			 */
			AuditLog::record(
				AuditLog::PROVIDER_FAILED,
				'',
				array(
					'provider' => $provider_id,
					'reason'   => $resolved->get_error_code(),
					'linking'  => ! empty( $transaction['linking'] ),
				)
			);
			$this->fail( $resolved, $failure_return );
		}
		$context = $resolved['context'];

		/*
		 * Where the visitor came from, and whether a dialog sent them.
		 *
		 * A provider hand-off is a full-page navigation and cannot be otherwise —
		 * `window.open` plus `postMessage` loses to popup blockers on mobile, to
		 * COOP headers, and to providers that refuse embedded contexts. So the
		 * dialog closes when the visitor leaves and has to be reopened when they
		 * come back, which is what the marker is for.
		 */
		$return_url            = (string) ( $transaction['return_url'] ?? '' );
		$in_place              = '' !== $return_url && (bool) self::in_place_marker( $return_url );
		$return_url            = remove_query_arg( self::IN_PLACE_ARG, $return_url );
		$context->intended_url = $return_url;
		$context->in_place     = $in_place;
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
		$destination = ( new PostAuthRedirector() )->redirect( $result, $context->intended_url );

		/*
		 * '' means "a new member, and the flow draws its own welcome screen" —
		 * see PostAuthRedirector. There is no dialog to draw it in yet, because
		 * the visitor has been at Google since it closed, so they go back to the
		 * page they left carrying the flag that reopens it.
		 *
		 * `smartlogin_welcome=1` rather than a marker of this phase's own: it is
		 * already what a finished registration puts in a URL, already read by
		 * `Shortcodes::is_welcome_request()`, and adding a second spelling of it
		 * is the mistake rule 9 exists to prevent.
		 */
		if ( '' === $destination ) {
			$destination = add_query_arg( 'smartlogin_welcome', '1', $context->intended_url );
		}

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Whether a return url was stamped by a dialog.
	 */
	private static function in_place_marker( string $url ): bool {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $query ) {
			return false;
		}

		parse_str( $query, $args );

		return ! empty( $args[ self::IN_PLACE_ARG ] );
	}

	/**
	 * The same provider, with a store that stamps its transaction as a test.
	 *
	 * Rebuilt rather than mutated: the registry's instance is the one every other
	 * request uses, and a provider that could be switched into test mode after
	 * construction is a provider that could be left there.
	 *
	 * Only the shipped class is rebuilt. A third-party provider from
	 * `smart_login_providers` cannot be, because nothing guarantees its
	 * constructor takes a store — so it simply has no test button, which is a
	 * better failure than a test that silently signs somebody in.
	 */
	private function test_provider( string $provider_id ): ?LoginProviderInterface {
		$store = new OAuthTransactionStore( OAuthTransactionStore::MODE_TEST );

		switch ( $provider_id ) {
			case 'google':
				return new \SmartLogin\Auth\Providers\GoogleProvider( $store );

			default:
				return null;
		}
	}

	/**
	 * The result of the last connection test this administrator ran, once.
	 *
	 * @return array|null
	 */
	public static function take_test_result(): ?array {
		$key    = self::TEST_RESULT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Report a successful round trip and stop, without writing anything.
	 *
	 * Deliberately a redirect back to the provider tab rather than a rendered
	 * page: the administrator started this from that screen and the result
	 * belongs there, and a transient carries it across the redirect so nothing
	 * lands in the URL.
	 */
	private function report_test( string $provider_id, ProviderIdentity $identity ): void {
		AuditLog::record(
			AuditLog::PROVIDER_LOGIN,
			'',
			array(
				'provider' => $provider_id,
				'mode'     => OAuthTransactionStore::MODE_TEST,
			)
		);

		set_transient(
			self::TEST_RESULT_PREFIX . get_current_user_id(),
			array(
				'provider' => $provider_id,
				'ok'       => true,
				// The subject, not the email: enough to prove the exchange
				// returned a real identity, and not a value worth storing even
				// for the five minutes this lives.
				'subject'  => substr( (string) $identity->subject, 0, 12 ) . '…',
				'callback' => ProviderCredentials::redirect_uri( $provider_id ),
			),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=smart-login&tab=providers' ) );
		exit;
	}

	private function fail( \WP_Error $error, string $return_url = '' ): void {
		Notices::flash( $error->get_error_message(), 'error' );
		wp_safe_redirect( self::failure_url( $return_url ) );
		exit;
	}

	/**
	 * Where a failed provider round trip lands.
	 *
	 * `$return_url` is the page a linking attempt started on. It is validated
	 * here rather than trusted, so an off-site value falls back to the sign-in
	 * step instead of becoming an open redirect — and the decision lives in one
	 * function that a test can call, rather than inside a method that exits.
	 */
	public static function failure_url( string $return_url = '' ): string {
		$base = function_exists( 'wc_get_page_permalink' )
			? (string) wc_get_page_permalink( 'myaccount' )
			: '';
		if ( '' === $base ) {
			$base = home_url( '/' );
		}

		$fallback = add_query_arg( 'smart_login_step', Flow::STEP_LOGIN, $base );
		$filtered = (string) apply_filters( 'smart_login_provider_failure_redirect', $fallback );
		$fallback = wp_validate_redirect( $filtered, $fallback );

		return '' !== $return_url ? wp_validate_redirect( $return_url, $fallback ) : $fallback;
	}
}
