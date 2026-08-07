<?php
/**
 * Forms that have to be emitted outside the form they appear inside.
 *
 * HTML forbids a `<form>` inside a `<form>`, and browsers do not merely ignore
 * the inner one — the parser drops its start tag, so the inner `</form>` closes
 * the **outer** form. Everything after it stops being part of the form at all.
 *
 * That is not a theory. Rendered for a real account holding a removable Google
 * identity, `myaccount/form-edit-account.php` produced:
 *
 *     <form class="woocommerce-EditAccountForm …">   at 401
 *       <form method="post" class="sl-identity-unlink-form">   at 8171
 *       </form>                                                at 9273  ← closes the outer one
 *     …
 *     the save bar, its nonce and its submit button              at 28359
 *
 * So the "Lưu thay đổi" button had no form to submit, and pressing it did
 * nothing. Silently: no error, no notice, no request.
 *
 * The fix HTML gives us is the `form` attribute: a control may live anywhere in
 * the document and belong to a form by id. So the controls stay inside the
 * `<details>` where they make sense, and the `<form>` element itself is
 * collected here and flushed after the outer form closes.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

defined( 'ABSPATH' ) || exit;

final class DeferredForms {

	/** @var array<string,string> id => markup */
	private static array $pending = array();

	/**
	 * Register a form to be emitted once the surrounding form has closed.
	 *
	 * Keyed by id so a partial rendered twice — the summary and the editing
	 * surface on one page — cannot emit the same form twice and give the
	 * document two elements with one id.
	 */
	public static function add( string $id, string $markup ): void {
		self::$pending[ $id ] = $markup;
	}

	public static function has(): bool {
		return array() !== self::$pending;
	}

	/**
	 * Emit everything registered, and forget it.
	 *
	 * Not escaped: the markup is built by the plugin's own partials, which have
	 * escaped their own values on the way in.
	 */
	public static function flush(): void {
		foreach ( self::$pending as $markup ) {
			echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		self::$pending = array();
	}

	/**
	 * For tests, which render several surfaces into one process.
	 */
	public static function reset(): void {
		self::$pending = array();
	}
}
