<?php
/**
 * Builds OAuth authorization URLs without leaking nested callback query args.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth\Providers;

defined( 'ABSPATH' ) || exit;

final class OAuthAuthorizationUrl {

	public static function build( string $endpoint, array $params ): string {
		$endpoint = trim( $endpoint );
		$query    = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		if ( '' === $query ) {
			return $endpoint;
		}

		$fragment = '';
		$position = strpos( $endpoint, '#' );
		if ( false !== $position ) {
			$fragment = substr( $endpoint, $position );
			$endpoint = substr( $endpoint, 0, $position );
		}

		if ( str_ends_with( $endpoint, '?' ) || str_ends_with( $endpoint, '&' ) ) {
			$separator = '';
		} else {
			$separator = str_contains( $endpoint, '?' ) ? '&' : '?';
		}

		return $endpoint . $separator . $query . $fragment;
	}
}
