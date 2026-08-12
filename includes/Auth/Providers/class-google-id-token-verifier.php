<?php
/**
 * Verifies Google ID-token signatures and returns trusted claims.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GoogleIdTokenVerifier {

	const CERT_TRANSIENT = 'OMNIWP_google_certs';

	/** @return array|WP_Error */
	public function verify( string $jwt ) {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return $this->invalid();
		}

		$header    = $this->decode_json( $parts[0] );
		$claims    = $this->decode_json( $parts[1] );
		$signature = $this->base64url_decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || false === $signature ) {
			return $this->invalid();
		}

		$kid = (string) ( $header['kid'] ?? '' );
		if ( 'RS256' !== (string) ( $header['alg'] ?? '' ) || '' === $kid || ! function_exists( 'openssl_verify' ) ) {
			return $this->invalid();
		}

		$certs = $this->certificates();
		if ( is_wp_error( $certs ) ) {
			return $certs;
		}
		if ( ! isset( $certs[ $kid ] ) ) {
			delete_transient( self::CERT_TRANSIENT );
			$certs = $this->certificates();
		}
		if ( is_wp_error( $certs ) || empty( $certs[ $kid ] ) ) {
			return $this->invalid();
		}

		$verified = openssl_verify(
			$parts[0] . '.' . $parts[1],
			$signature,
			(string) $certs[ $kid ],
			OPENSSL_ALGO_SHA256
		);
		return 1 === $verified ? $claims : $this->invalid();
	}

	/** @return array|WP_Error */
	private function certificates() {
		$cached = get_transient( self::CERT_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$url      = (string) apply_filters( 'OMNIWP_google_certificates_url', 'https://www.googleapis.com/oauth2/v1/certs' );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'OMNIWP_google_keys', __( 'Không thể tải khóa xác thực của Google.', 'omniwp' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$certs  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $certs ) || empty( $certs ) ) {
			return new WP_Error( 'OMNIWP_google_keys', __( 'Khóa xác thực của Google không hợp lệ.', 'omniwp' ) );
		}

		$ttl = $this->cache_ttl( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );
		set_transient( self::CERT_TRANSIENT, $certs, $ttl );
		return $certs;
	}

	private function cache_ttl( string $cache_control ): int {
		if ( preg_match( '/max-age=(\d+)/i', $cache_control, $match ) ) {
			return max( 300, min( DAY_IN_SECONDS, (int) $match[1] ) );
		}
		return HOUR_IN_SECONDS;
	}

	private function decode_json( string $encoded ): ?array {
		$decoded = $this->base64url_decode( $encoded );
		if ( false === $decoded ) {
			return null;
		}
		$value = json_decode( $decoded, true );
		return is_array( $value ) ? $value : null;
	}

	/** @return string|false */
	private function base64url_decode( string $encoded ) {
		if ( '' === $encoded || ! preg_match( '/^[A-Za-z0-9_-]+$/', $encoded ) ) {
			return false;
		}
		$padding = strlen( $encoded ) % 4;
		if ( $padding ) {
			$encoded .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $encoded, '-_', '+/' ), true );
	}

	private function invalid(): WP_Error {
		return new WP_Error( 'OMNIWP_google_claims', __( 'Google ID token không đạt điều kiện xác thực.', 'omniwp' ) );
	}
}
