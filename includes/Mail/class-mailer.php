<?php
/**
 * Sends the messages that are not one-time codes.
 *
 * `MailTransport` exists to deliver an OTP: it is chosen by the router, guarded
 * by the circuit breaker, and answerable for whether the code arrived. An
 * operational alert is none of those things — it is not routed, must not open a
 * breaker, and nothing waits on it — so routing one through that class would
 * have meant giving the transport a second job and a second set of rules.
 *
 * Two senders, therefore, and exactly two: this and `MailTransport`. The mail
 * guard rail asserts that, which is what keeps "compose it inline right here"
 * from being the easy option next time.
 *
 * @package OmniWP
 */

namespace OmniWP\Mail;

use OmniWP\OTP\Placeholders;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	/**
	 * Render a registry message and send it.
	 *
	 * @param string $message_id Row id in MailRegistry.
	 * @param string $to         Recipient.
	 * @param array  $tokens     Token name => value, without braces.
	 * @return bool False when the message is switched off, has no recipient, or
	 *               wp_mail() refused it.
	 */
	public static function send( string $message_id, string $to, array $tokens = array() ): bool {
		if ( ! self::is_enabled( $message_id ) ) {
			return false;
		}

		$to = trim( $to );

		if ( '' === $to ) {
			return false;
		}

		$message = MailRegistry::resolve( $message_id );

		$map = array_merge(
			array(
				'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'site_url'  => home_url( '/' ),
			),
			$tokens
		);

		// Placeholders::render() is the same token expander the OTP templates
		// use. Only the map differs, which is the point of it taking one.
		$subject = Placeholders::render( $message['subject'], $map );
		$body    = Placeholders::render( $message['body'], $map );

		return (bool) wp_mail( $to, $subject, wp_strip_all_tags( $body ) );
	}

	/**
	 * Whether this message is switched on.
	 *
	 * Messages without a switch are always on: only the operational alerts
	 * declare one, because they are the two an administrator may legitimately
	 * already be receiving through the automation bus (10.4) and can currently
	 * silence in neither place.
	 */
	public static function is_enabled( string $message_id ): bool {
		$row = MailRegistry::get( $message_id );

		if ( ! $row || empty( $row['switchable'] ) ) {
			return (bool) $row;
		}

		return Settings::is_on( MailRegistry::PATH_PREFIX . $message_id . '.enabled' );
	}

	/** The address operational alerts go to. */
	public static function admin_address(): string {
		return (string) get_option( 'admin_email', '' );
	}
}
