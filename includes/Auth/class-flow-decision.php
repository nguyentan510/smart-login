<?php
/**
 * What one step of the auth flow decided, without doing any of it.
 *
 * `FormController` used to decide and act in the same breath: work out that the
 * password was wrong, and in the same method call `Notices::add()`, `Flow::set()`
 * and sometimes `wp_safe_redirect(); exit;`. That is fine while there is one
 * caller. Phase 19 adds a second — a dialog that fetches its markup and posts
 * back over REST — and a second caller cannot reuse a method that ends in
 * `exit`.
 *
 * So the decision becomes a value. `FlowEngine` returns one; whoever asked
 * applies it in the way that suits their transport:
 *
 *   - `FormController` turns `render()` into `Flow::set()` and `go()` into a
 *     redirect header
 *   - the fragment endpoint turns `render()` into HTML and `go()` into
 *     `{ redirect: … }`
 *
 * The alternative was two implementations of the state machine. This project
 * has watched a rename cross an untested boundary five times; two copies of the
 * thing that decides whether somebody gets signed in is not a risk worth taking
 * to save an object.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class FlowDecision {

	/** Step to render, or '' when the outcome is a redirect. */
	public string $step = '';

	/** @var array<string,mixed> Arguments the step needs. */
	public array $data = array();

	/** Where to send the browser, or '' when the outcome is a step. */
	public string $redirect = '';

	/**
	 * Messages to show, in order.
	 *
	 * `flash` distinguishes the two kinds the plugin already had: an in-request
	 * notice, and one that has to survive a redirect in a cookie. Collapsing them
	 * would silently drop every message attached to a redirecting outcome.
	 *
	 * @var array<int,array{message:string,type:string,flash:bool}>
	 */
	public array $notices = array();

	/**
	 * Submitted values to re-populate a rejected form with.
	 *
	 * @var array<string,mixed>
	 */
	public array $remember = array();

	public function render( string $step, array $data = array() ): self {
		$this->step     = $step;
		$this->data     = $data;
		$this->redirect = '';

		return $this;
	}

	public function go( string $url ): self {
		$this->redirect = $url;
		$this->step     = '';
		$this->data     = array();

		return $this;
	}

	public function notice( string $message, string $type = 'error', bool $flash = false ): self {
		if ( '' === trim( $message ) ) {
			return $this;
		}

		$this->notices[] = array(
			'message' => $message,
			'type'    => $type,
			'flash'   => $flash,
		);

		return $this;
	}

	public function error( WP_Error $error, bool $flash = false ): self {
		foreach ( $error->get_error_messages() as $message ) {
			$this->notice( $message, 'error', $flash );
		}

		return $this;
	}

	public function remember( array $values ): self {
		$this->remember = $values;

		return $this;
	}

	public function is_redirect(): bool {
		return '' !== $this->redirect;
	}
}
