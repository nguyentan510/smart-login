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

	/**
	 * The brand's own mark, as inline SVG drawn at 18×18.
	 *
	 * It lives here rather than in the template because the template renders a
	 * foreach, and the entry screen used to hold
	 * `'google' === $provider->id() ? 'G' : 'Z'` — a two-provider assumption
	 * written into markup, where the next provider inherits the other one's
	 * letter and nobody finds out until it is on screen. label() and name()
	 * already avoid that; the mark is the third thing that belongs beside them.
	 *
	 * Inline rather than a file URL so the mark survives a theme that overrides
	 * form-auth.php, and so it costs no extra request on the first screen a
	 * visitor sees.
	 *
	 * The colours belong to the brand and are not this plugin's to change.
	 * Google's guidelines forbid recolouring the G — including flattening it to
	 * one colour — so no implementation may use currentColor, which would let
	 * the mark inherit whatever the button's text colour happens to be.
	 */
	public function icon_svg(): string;

	public function is_available(): bool;
	public function begin( string $return_url = '', bool $linking = false ): ProviderRedirect;
	/** @return ProviderIdentity|WP_Error */
	public function complete( array $request );
}
