<?php
/**
 * Client fingerprint helpers (IP, user agent).
 *
 * @package OmniWP
 */

namespace OmniWP\Security;

use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class Client {

	/** Proxy headers consulted, in order of preference. */
	const FORWARD_HEADERS = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );

	/**
	 * Is $ip inside $cidr?
	 *
	 * Pure, and handles v4 and v6 through inet_pton, which normalises both into
	 * packed bytes of known length — 4 or 16. A length mismatch means the two are
	 * different families and cannot match, which is checked rather than assumed.
	 *
	 * A bare address with no `/` is treated as a single host.
	 */
	public static function in_cidr( string $ip, string $cidr ): bool {
		$ip   = trim( $ip );
		$cidr = trim( $cidr );

		if ( '' === $ip || '' === $cidr ) {
			return false;
		}

		$bits = null;

		if ( false !== strpos( $cidr, '/' ) ) {
			list( $cidr, $suffix ) = explode( '/', $cidr, 2 );

			// A non-numeric suffix must not fall through to (int) — `10.0.0.0/oops`
			// would become /0 and trust the entire internet off a typo.
			if ( ! ctype_digit( trim( $suffix ) ) ) {
				return false;
			}

			$bits = (int) trim( $suffix );
		}

		$ip_packed  = @inet_pton( $ip );   // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$net_packed = @inet_pton( trim( $cidr ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $ip_packed || false === $net_packed ) {
			return false;
		}

		if ( strlen( $ip_packed ) !== strlen( $net_packed ) ) {
			return false;
		}

		$width = strlen( $ip_packed ) * 8;
		$bits  = null === $bits ? $width : $bits;

		if ( $bits < 0 || $bits > $width ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $whole > 0 && 0 !== strncmp( $ip_packed, $net_packed, $whole ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $rest ) ) - 1 ) & 0xFF;

		return ( ord( $ip_packed[ $whole ] ) & $mask ) === ( ord( $net_packed[ $whole ] ) & $mask );
	}

	/**
	 * The proxy ranges this site will accept forwarded headers from.
	 *
	 * No Cloudflare list is shipped. A hardcoded one goes stale silently, which is
	 * how a security control turns into a liability; the settings screen links to
	 * the published ranges instead.
	 *
	 * @return string[]
	 */
	public static function trusted_cidrs(): array {
		$raw   = (string) Settings::get( 'security.trusted_proxy_cidrs', '' );
		$cidrs = array();

		foreach ( preg_split( '/[\s,;]+/', $raw ) ?: array() as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' !== $candidate ) {
				$cidrs[] = $candidate;
			}
		}

		/**
		 * Supply proxy ranges from code, for centrally managed deployments.
		 *
		 * @param string[] $cidrs
		 */
		return array_values( (array) apply_filters( 'omniwp_trusted_proxy_cidrs', $cidrs ) );
	}

	/**
	 * May this peer's forwarded headers be believed?
	 *
	 * Both conditions are required, and that is the whole design. A bare "trust
	 * the headers" switch is worse than trusting nothing: an attacker who reaches
	 * the origin directly then sets CF-Connecting-IP per request and dissolves
	 * every per-IP limit in the plugin. The header may only be trusted when the
	 * *peer* is trusted.
	 */
	private static function trusts_peer( string $remote ): bool {
		if ( '' === $remote ) {
			return false;
		}

		/**
		 * Turn proxy-header handling on. Enabling it is necessary but not
		 * sufficient — the peer must still match a trusted range.
		 *
		 * Before 9.5 this filter alone granted trust unconditionally. It no longer
		 * does, deliberately: the guard rail asks that no configuration make a
		 * header trusted from an unverified peer, and an escape hatch that can
		 * reopen the hole is not an escape hatch. Managed deployments pair it with
		 * `OMNIWP_trusted_proxy_cidrs`.
		 *
		 * @param bool $trust
		 */
		$enabled = (bool) apply_filters(
			'omniwp_trust_proxy_headers',
			Settings::is_on( 'security.trust_proxy' )
		);

		if ( ! $enabled ) {
			return false;
		}

		foreach ( self::trusted_cidrs() as $cidr ) {
			if ( self::in_cidr( $remote, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Best-effort client IP.
	 *
	 * REMOTE_ADDR is the only header a client cannot forge, so it is the
	 * default. Proxy headers are opt-in because trusting them blindly lets an
	 * attacker sidestep every rate limit by rotating X-Forwarded-For.
	 *
	 * These values are validated rather than sanitised: every path ends in
	 * filter_var( …, FILTER_VALIDATE_IP ), which rejects anything that is not an
	 * address outright. Running an IP through a text sanitiser first would only
	 * risk turning invalid input into something that passes.
	 */
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	public static function ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		if ( ! self::trusts_peer( $remote ) ) {
			return $remote;
		}

		foreach ( self::FORWARD_HEADERS as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			// X-Forwarded-For is a chain; the client is the leftmost entry.
			$candidate = trim( current( explode( ',', wp_unslash( $_SERVER[ $header ] ) ) ) );

			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return $remote;
	}

	/**
	 * Does this request carry the marks of a CDN in front of it?
	 *
	 * Header presence, not an address list — the situation is detected with
	 * nothing to keep up to date, which is why it beats range matching for the
	 * readiness warning.
	 */
	public static function looks_proxied(): bool {
		return ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] );
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
	 *
	 * The raw string is never stored or echoed — sha1() is the sanitiser, and its
	 * output is fixed-length hex whatever the input was.
	 */
	public static function user_agent_hash(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		return sha1( $ua );
	}
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	/**
	 * Stable-ish device token used by the optional "OTP on new device" check.
	 */
	public static function device_fingerprint(): string {
		return substr( hash_hmac( 'sha256', self::user_agent_hash() . '|' . self::ip(), wp_salt( 'auth' ) ), 0, 32 );
	}
}
