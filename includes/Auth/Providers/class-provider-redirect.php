<?php
/**
 * Safe result of starting an external provider flow.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth\Providers;

defined( 'ABSPATH' ) || exit;

final class ProviderRedirect {
	public string $url;

	public function __construct( string $url ) {
		$this->url = esc_url_raw( $url );
	}
}
