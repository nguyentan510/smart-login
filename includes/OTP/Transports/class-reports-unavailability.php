<?php
/**
 * A transport that can say, in its own words, why it is not usable.
 *
 * Separate from TransportInterface, and optional, for a compatibility reason
 * rather than a stylistic one: `smart_login_otp_transports` is published API and
 * the router's own docblock promises that adding a transport means implementing
 * TransportInterface "and nothing else". Adding a fourth required method would
 * fatal every transport a site wrote against that promise — including this
 * repository's own test doubles, which is how the cost was measured.
 *
 * What the router does without it is still correct, just less fluent: it names
 * the transport by its id. What it must never do again is describe one transport
 * in another's words. Until 19.x the unavailable message was a two-branch
 * ternary — `sms`, or else email — so a phone number routed at the automation
 * endpoint was refused as "Kênh email chưa được cấu hình", naming a channel that
 * had not been asked to do anything. A fixed list of ids cannot describe an open
 * registry; only the transport itself can.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

defined( 'ABSPATH' ) || exit;

interface ReportsUnavailability {

	/**
	 * What to show a user when `is_available()` is false.
	 *
	 * Addressed to whoever is trying to sign in, so it says which channel is at
	 * fault and who can fix it — not what the missing setting is called.
	 */
	public function unavailable_message(): string;
}
