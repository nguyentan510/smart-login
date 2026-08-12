<?php
/**
 * Public read-only endpoints for the address pickers.
 *
 * The dataset is static public reference data, so these routes carry no nonce
 * and are aggressively cacheable — a nonce would make every response private
 * and defeat browser and CDN caching for no security gain.
 *
 * @package OmniWP
 */

namespace OmniWP\Address;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class AddressRest {

	const REST_NAMESPACE = 'omniwp/v1';
	const CACHE_SECONDS  = DAY_IN_SECONDS;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/address/provinces',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_provinces' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/address/wards/(?P<province>[0-9]{1,2})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_wards' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'province' => array(
						'required'          => true,
						'sanitize_callback' => static function ( $value ) {
							return preg_replace( '/[^0-9]/', '', (string) $value );
						},
					),
				),
			)
		);
	}

	public function get_provinces(): WP_REST_Response {
		$out = array();

		foreach ( AddressRepository::provinces() as $code => $province ) {
			$out[] = array(
				'code'  => (string) $code,
				'name'  => $province['name'],
				'short' => $province['short'],
			);
		}

		return $this->cacheable( $out, 'provinces' );
	}

	public function get_wards( WP_REST_Request $request ): WP_REST_Response {
		$province = AddressRepository::province_code( (string) $request['province'] );
		$out      = array();

		foreach ( AddressRepository::wards( $province ) as $code => $ward ) {
			$out[] = array(
				'code' => (string) $code,
				'name' => $ward['name'],
			);
		}

		return $this->cacheable( $out, 'wards-' . $province );
	}


	/**
	 * Attach long-lived cache headers and a dataset-stamped ETag.
	 *
	 * Stamped with the dataset's own modification time, not the plugin version.
	 * The README tells operators to regenerate the administrative units after a
	 * boundary change, and that happens without touching the plugin version — so
	 * a version-based ETag left every client serving stale ward names for up to
	 * CACHE_SECONDS, which is exactly the case the header exists to handle.
	 */
	private function cacheable( array $data, string $key ): WP_REST_Response {
		$etag = '"' . md5( self::dataset_stamp() . '|' . $key ) . '"';

		// Measured before it was written: a live request confirms the plugin's
		// Cache-Control and ETag do reach the client — WordPress only sends its
		// nocache headers for logged-in REST requests, and these routes are
		// anonymous. What was missing was the other half of the exchange. Without
		// it a client whose max-age lapsed re-downloaded the whole dataset to be
		// told nothing had changed.
		if ( self::matches_etag( $etag ) ) {
			$response = new WP_REST_Response( null, 304 );
			$response->header( 'Cache-Control', 'public, max-age=' . self::CACHE_SECONDS );
			$response->header( 'ETag', $etag );

			return $response;
		}

		$response = new WP_REST_Response( $data, 200 );

		$response->header( 'Cache-Control', 'public, max-age=' . self::CACHE_SECONDS );
		$response->header( 'ETag', $etag );

		return $response;
	}

	/**
	 * Does the request already hold this version?
	 *
	 * If-None-Match is a comma-separated list and entries may carry the `W/`
	 * weak-comparison prefix, which is correct to ignore here: these responses
	 * are byte-identical for a given dataset stamp, so weak and strong agree.
	 */
	private static function matches_etag( string $etag ): bool {
		$header = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) )
			: '';

		if ( '' === $header ) {
			return false;
		}

		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( 0 === strpos( $candidate, 'W/' ) ) {
				$candidate = trim( substr( $candidate, 2 ) );
			}

			if ( '*' === $candidate || $candidate === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A value that changes whenever the dataset is rebuilt.
	 *
	 * Falls back to the plugin version when the file cannot be read, so a missing
	 * dataset still produces a stable, valid ETag rather than an empty one.
	 */
	private static function dataset_stamp(): string {
		static $stamp = null;

		if ( null !== $stamp ) {
			return $stamp;
		}

		$file  = OMNIWP_DIR . 'data/provinces.php';
		$mtime = is_readable( $file ) ? (int) filemtime( $file ) : 0;
		$stamp = $mtime > 0 ? (string) $mtime : OMNIWP_VERSION;

		return $stamp;
	}
}
