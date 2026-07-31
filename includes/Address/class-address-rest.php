<?php
/**
 * Public read-only endpoints for the address pickers.
 *
 * The dataset is static public reference data, so these routes carry no nonce
 * and are aggressively cacheable — a nonce would make every response private
 * and defeat browser and CDN caching for no security gain.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Address;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class AddressRest {

	const REST_NAMESPACE = 'smart-login/v1';
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
		$response = new WP_REST_Response( $data, 200 );

		$response->header( 'Cache-Control', 'public, max-age=' . self::CACHE_SECONDS );
		$response->header( 'ETag', '"' . md5( self::dataset_stamp() . '|' . $key ) . '"' );

		return $response;
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

		$file  = SMART_LOGIN_DIR . 'data/provinces.php';
		$mtime = is_readable( $file ) ? (int) filemtime( $file ) : 0;
		$stamp = $mtime > 0 ? (string) $mtime : SMART_LOGIN_VERSION;

		return $stamp;
	}
}
