<?php
/**
 * Contract for external identity providers, not OTP delivery channels.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface LoginProviderInterface {
	public function id(): string;
	public function label(): string;
	public function is_available(): bool;
	public function begin( string $return_url = '', bool $linking = false ): ProviderRedirect;
	/** @return ProviderIdentity|WP_Error */
	public function complete( array $request );
}
