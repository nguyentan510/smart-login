<?php
/**
 * Client fingerprint helpers (IP, user agent).
 *
 * @package SmartLogin
 */

namespace SmartLogin\Security;

defined( 'ABSPATH' ) || exit;

class Client {

	/**
	 * Best-effort client IP.
	 *
	 * REMOTE_ADDR is the only header a client cannot forge, so it is the
	 * default. Proxy headers are opt-in because trusting them blindly lets an
	 * attacker sidestep every rate limit by rotating X-Forwarded-For.
	 */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		/**
		 * Set this to true only when the site genuinely sits behind a trusted
		 * reverse proxy (Cloudflare, a load balancer you control).
		 *
		 * @param bool $trust
		 */
		if ( apply_filters( 'smart_login_trust_proxy_headers', false ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$candidate = trim( current( explode( ',', wp_unslash( $_SERVER[ $header ] ) ) ) );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Packed binary form for the VARBINARY(16) columns.
	 *
	 * @return string|null
	 */
	public static function ip_binary(): ?string {
		$ip = self::ip();

		if ( '' === $ip ) {
			return null;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return false === $packed ? null : $packed;
	}

	/**
	 * Short, non-reversible user-agent fingerprint.
	 */
	public static function user_agent_hash(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		return sha1( $ua );
	}

	/**
	 * Stable-ish device token used by the optional "OTP on new device" check.
	 */
	public static function device_fingerprint(): string {
		return substr( hash_hmac( 'sha256', self::user_agent_hash() . '|' . self::ip(), wp_salt( 'auth' ) ), 0, 32 );
	}
}
