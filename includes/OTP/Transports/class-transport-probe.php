<?php
/**
 * Reading a gateway's answer, and keeping a live code out of the evidence.
 *
 * Both HTTP transports need the same three things: decide whether a 2xx really
 * means success, mask the code before anything is shown or logged, and know
 * which headers are secrets. Copying them would have been the shorter diff and
 * the wrong one — a redaction rule that exists twice is a redaction rule that
 * will be fixed once.
 *
 * @package OmniWP
 */

namespace OmniWP\OTP\Transports;

defined( 'ABSPATH' ) || exit;

final class TransportProbe {

	/**
	 * A 2xx is necessary but often not sufficient — several Vietnamese gateways
	 * answer 200 with an error code in the JSON body.
	 *
	 * @param array|\WP_Error $response Whatever the HTTP layer returned.
	 * @param string          $path     Dotted JSON path, empty to accept any 2xx.
	 * @param string          $expected Value that path must hold.
	 */
	public static function matches_success( $response, string $path, string $expected ): bool {
		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return false;
		}

		$path = trim( $path );

		if ( '' === $path ) {
			return true;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return false;
		}

		$actual = self::dig( $decoded, $path );

		return null !== $actual && (string) $actual === $expected;
	}

	/**
	 * Read a dotted path out of a decoded JSON structure.
	 *
	 * @param array  $data Decoded body.
	 * @param string $path Dotted path, e.g. `data.status`.
	 * @return mixed|null
	 */
	public static function dig( array $data, string $path ) {
		$cursor = $data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}

			$cursor = $cursor[ $segment ];
		}

		return is_scalar( $cursor ) ? $cursor : null;
	}

	/**
	 * Never echo a live OTP back into an admin screen or a log file.
	 */
	public static function redact( string $text, string $code ): string {
		if ( '' === $code ) {
			return $text;
		}

		return str_replace( $code, str_repeat( '*', strlen( $code ) ), $text );
	}

	/**
	 * Header names whose value is a credential rather than metadata.
	 */
	public static function is_secret_header( string $name ): bool {
		$name = strtolower( $name );

		foreach ( array( 'authorization', 'api-key', 'apikey', 'token', 'secret', 'password', 'signature' ) as $needle ) {
			if ( false !== strpos( $name, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A shareable copy of outgoing headers: credentials masked, code removed.
	 *
	 * @param array  $headers Header name => value.
	 * @param string $code    Plaintext code to mask.
	 * @return array<string,string>
	 */
	public static function redact_headers( array $headers, string $code ): array {
		$out = array();

		foreach ( $headers as $key => $value ) {
			$out[ $key ] = self::is_secret_header( (string) $key )
				? '***'
				: self::redact( (string) $value, $code );
		}

		return $out;
	}
}
