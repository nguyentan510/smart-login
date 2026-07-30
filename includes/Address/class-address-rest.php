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

		register_rest_route(
			self::REST_NAMESPACE,
			'/address/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
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

	public function get_search( WP_REST_Request $request ): WP_REST_Response {
		$query   = (string) $request->get_param( 'q' );
		$results = AddressRepository::search( $query, 20 );

		// Search results are still a pure function of the static dataset, so
		// they cache too — keyed by the query itself.
		return $this->cacheable( $results, 'search-' . md5( $query ) );
	}

	/**
	 * Attach long-lived cache headers and a version-stamped ETag.
	 *
	 * The ETag includes the plugin version, so shipping a new dataset
	 * invalidates every cached response automatically.
	 */
	private function cacheable( array $data, string $key ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );

		$response->header( 'Cache-Control', 'public, max-age=' . self::CACHE_SECONDS );
		$response->header( 'ETag', '"' . md5( SMART_LOGIN_VERSION . '|' . $key ) . '"' );

		return $response;
	}
}
