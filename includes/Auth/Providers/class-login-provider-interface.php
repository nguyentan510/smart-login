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

	/**
	 * Sign-in copy for the entry screen, e.g. "Tiếp tục với Google".
	 *
	 * A call to action, not a name. Composing it into another sentence produces
	 * "Liên kết Tiếp tục với Google", which is how the account page read until
	 * name() existed to be used instead.
	 */
	public function label(): string;

	/** The bare brand name, e.g. "Google". */
	public function name(): string;

	public function is_available(): bool;
	public function begin( string $return_url = '', bool $linking = false ): ProviderRedirect;
	/** @return ProviderIdentity|WP_Error */
	public function complete( array $request );
}
