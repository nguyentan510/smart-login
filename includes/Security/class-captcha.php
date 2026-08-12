<?php
/**
 * A challenge that appears when the site is under pressure, and not otherwise.
 *
 * The design decision is `adaptive` being the default. With the site budget from
 * 9.1 already capping what an attacker can spend, a challenge shown to everyone
 * buys very little and costs conversion on every ordinary day. Shown only while
 * the budget, the breaker or a per-IP counter says something is wrong, it costs
 * nothing on the days nothing is wrong.
 *
 * Two decisions here point the opposite way to their neighbours elsewhere, both
 * deliberately — see docs/abuse-boundary.md:
 *
 *   - verification failure is **fail closed**, where Client::ip() fails open
 *   - the outbound call is clamped like WebhookTransport, because a hanging
 *     captcha endpoint is the same worker-exhaustion bug wearing a new hat
 *
 * @package OmniWP
 */

namespace OmniWP\Security;

use OmniWP\OTP\OtpRepository;
use OmniWP\OTP\Transports\CircuitBreaker;
use OmniWP\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Captcha {

	/** The registry path this secret is declared under. */
	const SECRET_PATH = 'security.captcha_secret';

	/** Hard ceiling on the verification call, same reasoning as 9.3. */
	const MAX_TIMEOUT = 5;

	/** Fraction of the hourly budget above which adaptive mode challenges. */
	const PRESSURE_RATIO = 0.5;

	const ENDPOINTS = array(
		'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		'hcaptcha'  => 'https://hcaptcha.com/siteverify',
	);

	const SCRIPTS = array(
		'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
		'hcaptcha'  => 'https://js.hcaptcha.com/1/api.js',
	);

	const FIELDS = array(
		'turnstile' => 'cf-turnstile-response',
		'hcaptcha'  => 'h-captcha-response',
	);

	public static function provider(): string {
		$provider = sanitize_key( (string) Settings::get( 'security.captcha_provider', 'off' ) );

		return isset( self::ENDPOINTS[ $provider ] ) ? $provider : 'off';
	}

	public static function site_key(): string {
		return trim( (string) Settings::get( 'security.captcha_site_key', '' ) );
	}

	/**
	 * Storage belongs to Settings, not to this class.
	 *
	 * This accessor and the two below stay as the vocabulary the captcha code
	 * reads in — is_configured() asks for "the secret", not for a path into a
	 * store — but they own nothing. Settings::read_secret() is what knows where
	 * a value sealed before 10.2 still lives.
	 */
	public static function secret(): string {
		return Settings::read_secret( self::SECRET_PATH );
	}

	public static function store_secret( string $secret ): bool {
		Settings::store_secret( self::SECRET_PATH, $secret );

		return true;
	}

	public static function clear_secret(): bool {
		Settings::store_secret( self::SECRET_PATH, '' );

		return true;
	}

	public static function is_configured(): bool {
		return 'off' !== self::provider() && '' !== self::site_key() && '' !== self::secret();
	}

	/**
	 * The name of the POST field the chosen provider's widget fills in.
	 */
	public static function field(): string {
		return self::FIELDS[ self::provider() ] ?? '';
	}

	/**
	 * Should this request be challenged?
	 *
	 * `always` means what it says. `adaptive` — the default — asks whether the
	 * site is currently under pressure, and an ordinary visitor on an ordinary
	 * day is not challenged at all.
	 */
	public static function is_required(): bool {
		if ( ! self::is_configured() ) {
			return false;
		}

		if ( 'always' === sanitize_key( (string) Settings::get( 'security.captcha_mode', 'adaptive' ) ) ) {
			return true;
		}

		return self::under_pressure();
	}

	/**
	 * Any one of these means the site is being leaned on right now.
	 *
	 * Each is a signal an earlier sub-phase already computes, which is why 9.8 is
	 * blocked on 9.1 and 9.3 rather than merely sequenced after them.
	 */
	public static function under_pressure(): bool {
		$limiter = new RateLimiter();

		// The kill switch is on, or was recently.
		if ( $limiter->halted_for() > 0 ) {
			return true;
		}

		// A delivery channel is failing.
		foreach ( array( 'sms', 'email' ) as $transport ) {
			if ( ( new CircuitBreaker( $transport ) )->is_open() ) {
				return true;
			}
		}

		// The hourly budget is more than half spent.
		$ceiling = Settings::get_int( 'security.max_per_site_hour', 100 );

		if ( $ceiling > 0 ) {
			$sent = ( new OtpRepository() )->count_recent_all( HOUR_IN_SECONDS );

			if ( $sent >= (int) ceil( $ceiling * self::PRESSURE_RATIO ) ) {
				return true;
			}
		}

		// This address has spent more than half its lookup allowance.
		$identify_max = Settings::get_int( 'security.max_identify_per_ip_hour', 30 );
		$ip           = Client::ip();

		if ( $identify_max > 0 && '' !== $ip ) {
			$spent = (int) get_transient( 'OMNIWP_idfy_' . md5( $ip . '|' . gmdate( 'YmdH' ) ) );

			if ( $spent >= (int) ceil( $identify_max * self::PRESSURE_RATIO ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check a submitted token, when one is required.
	 *
	 * @param array $request Usually $_POST or the REST params.
	 * @return true|WP_Error
	 */
	public static function check( array $request ) {
		if ( ! self::is_required() ) {
			return true;
		}

		$token = isset( $request[ self::field() ] )
			? sanitize_text_field( wp_unslash( $request[ self::field() ] ) )
			: '';

		if ( '' === $token ) {
			return new WP_Error(
				'OMNIWP_captcha_missing',
				__( 'Vui lòng hoàn tất bước xác minh chống robot.', 'omniwp' )
			);
		}

		if ( ! self::verify_token( $token, self::ENDPOINTS[ self::provider() ] ) ) {
			AuditLog::record( AuditLog::RATE_LIMITED, '', array( 'reason' => 'captcha' ) );

			return new WP_Error(
				'OMNIWP_captcha_failed',
				__( 'Xác minh chống robot không thành công. Vui lòng thử lại.', 'omniwp' )
			);
		}

		return true;
	}

	/**
	 * Ask the provider whether a token is good.
	 *
	 * **Fails closed.** A network error, a timeout, a non-2xx, an unparseable body
	 * and an explicit rejection are all the same answer: no. This is the opposite
	 * of Client::ip()'s fail-open, and the reason is that the only thing failing
	 * open protects here is the attacker.
	 *
	 * Public so the guard rail can drive it against an unreachable endpoint.
	 */
	public static function verify_token( string $token, string $endpoint ): bool {
		if ( '' === $token || '' === $endpoint ) {
			return false;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => self::MAX_TIMEOUT,
				'body'    => array(
					'secret'   => self::secret(),
					'response' => $token,
					'remoteip' => Client::ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) && ! empty( $body['success'] );
	}

	/**
	 * The widget markup, or nothing when no challenge is called for.
	 */
	public static function field_html(): string {
		if ( ! self::is_required() ) {
			return '';
		}

		$class = 'turnstile' === self::provider() ? 'cf-turnstile' : 'h-captcha';

		return sprintf(
			'<div class="sl-captcha %1$s" data-sitekey="%2$s"></div>',
			esc_attr( $class ),
			esc_attr( self::site_key() )
		);
	}

	/**
	 * The provider's script URL, or '' when no challenge is called for.
	 */
	public static function script_url(): string {
		return self::is_required() ? ( self::SCRIPTS[ self::provider() ] ?? '' ) : '';
	}
}
