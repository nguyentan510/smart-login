<?php
/**
 * Push the events the plugin already records to the automation endpoint.
 *
 * Nineteen event constants existed and none of them left the site. This is the
 * answer to "tell me when somebody logs in" that does not require the plugin to
 * grow a mail composer with its own templates, recipients and delivery failures:
 * the site owner's automation already knows how to send mail, open a ticket or
 * write a row, and it only needed to be told.
 *
 * Two properties separate this from the transport role, and both are load
 * bearing:
 *
 *   - **It never gates anything.** A dead bus endpoint must not be able to stop
 *     a login. Every failure path here returns void.
 *   - **It never carries the code.** `otp.sent` says a code went out, to a
 *     masked destination, for an intent. Not what the code was.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use SmartLogin\Security\AuditLog;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class EventBus {

	/** Breaker id. Deliberately not the transport's — see dispatch(). */
	const BREAKER_ID = 'automation_bus';

	/**
	 * Re-entrancy guard.
	 *
	 * A failed dispatch records AUTOMATION_BUS_FAILED, which goes through
	 * AuditLog::record(), which dispatches. Without this the first unreachable
	 * endpoint produces an unbounded chain of attempts to report that the
	 * endpoint is unreachable.
	 *
	 * @var bool
	 */
	private static $dispatching = false;

	/** @var AutomationEndpoint */
	private $endpoint;

	public function __construct( ?AutomationEndpoint $endpoint = null ) {
		$this->endpoint = $endpoint ?? new AutomationEndpoint();
	}

	/**
	 * Which audit events are ticked for delivery.
	 *
	 * @return string[]
	 */
	public static function subscribed(): array {
		$chosen = (array) Settings::get( 'automation.events', array() );

		// Intersected with the constants rather than trusted: a stored value
		// naming an event that no longer exists would keep being looked for.
		return array_values( array_intersect( array_map( 'strval', $chosen ), AuditLog::events() ) );
	}

	/**
	 * Send one audit event, if it is subscribed and the endpoint is up.
	 *
	 * Returns nothing on every path, including every failure. That is the
	 * contract, not an oversight: the caller is AuditLog::record(), which is
	 * called from inside login, registration and OTP issuance.
	 *
	 * @param string $event           Audit event constant.
	 * @param string $identity_masked Already masked by the caller.
	 * @param array  $meta            Already scrubbed by the caller.
	 * @param int    $user_id         0 when unknown.
	 */
	public function dispatch( string $event, string $identity_masked = '', array $meta = array(), int $user_id = 0 ): void {
		if ( self::$dispatching ) {
			return;
		}

		if ( ! in_array( $event, self::subscribed(), true ) ) {
			return;
		}

		if ( ! $this->endpoint->is_configured() ) {
			return;
		}

		// A breaker of its own. Sharing the transport's would let a failing
		// analytics endpoint stop OTP delivery, which is the one outcome this
		// whole role must be incapable of causing.
		$breaker = new CircuitBreaker( self::BREAKER_ID );

		if ( $breaker->is_open() ) {
			return;
		}

		$delivery_id = bin2hex( random_bytes( 16 ) );

		$envelope = AutomationEndpoint::base_envelope( $event, $delivery_id ) + array(
			'destination' => $identity_masked,
			'user_id'     => $user_id ?: null,
			'meta'        => $meta,
		);

		/**
		 * Add fields to an outgoing bus event.
		 *
		 * Runs before signing, so additions are covered by the signature.
		 * `event` and `code` are forced afterwards: a filter able to rename the
		 * event would let the envelope lie about itself, and one able to add a
		 * code would defeat the property this class exists to hold.
		 *
		 * @param array  $envelope
		 * @param string $event
		 * @param array  $meta
		 */
		$envelope = (array) apply_filters( 'smart_login_bus_envelope', $envelope, $event, $meta );

		$envelope['event'] = $event;
		unset( $envelope['code'] );

		self::$dispatching = true;

		try {
			// Non-blocking. The consequence is stated rather than hidden: the
			// site cannot know whether the event arrived. Waiting would put a
			// second synchronous HTTP call on every login, which is the version
			// of this feature that takes a site down — see MAX_TIMEOUT's note.
			$response = $this->endpoint->post( $envelope, false );

			if ( is_wp_error( $response ) ) {
				$breaker->record_failure();

				AuditLog::record(
					AuditLog::AUTOMATION_BUS_FAILED,
					'',
					array(
						'event'  => $event,
						'reason' => $response->get_error_message(),
					)
				);
			} else {
				$breaker->record_success();
			}
		} finally {
			self::$dispatching = false;
		}
	}
}
