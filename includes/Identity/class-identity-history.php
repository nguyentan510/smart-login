<?php
/**
 * Append-only record of what happened to an identity — the "old frame".
 *
 * This table is readable for **policy** ("this number had a previous owner, so
 * add friction to a re-registration") and for support ("why did this account
 * stop working?"). It must never be read for **authentication** ("this number
 * belongs to user 42"). That distinction is the entire subject of
 * docs/identity-model.md §1: the pre-refactor code answered an authentication
 * question from a historical artefact (wp_users.user_login) and produced an
 * account-takeover path.
 *
 * Nothing here ever updates or deletes. A wrong row is corrected by appending.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

use SmartLogin\Installer;

defined( 'ABSPATH' ) || exit;

final class IdentityHistory {

	/** Subject asserted but not yet proven. Reserved for step-up flows. */
	const CLAIMED = 'claimed';

	/** Proof succeeded and a row entered smartlogin_identities. */
	const VERIFIED = 'verified';

	/** Ownership ended. This is what makes Resolution::RETIRED reachable. */
	const RETIRED = 'retired';

	/** Ownership moved from one user to another. */
	const RELINKED = 'relinked';

	/** A claim was refused — conflict, policy, or failed proof. */
	const REJECTED = 'rejected';

	private function events(): array {
		return array( self::CLAIMED, self::VERIFIED, self::RETIRED, self::RELINKED, self::REJECTED );
	}

	/**
	 * Append one event.
	 *
	 * An unrecognised event name is refused rather than stored, so the `event`
	 * column stays a closed vocabulary that queries can rely on.
	 */
	public function record( int $user_id, Claim $claim, string $event, string $reason = '', string $actor = 'self' ): bool {
		if ( $claim->is_empty() || ! in_array( $event, $this->events(), true ) ) {
			return false;
		}

		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::identity_history_table(),
			array(
				'user_id'     => max( 0, $user_id ),
				'channel'     => $claim->channel(),
				'subject'     => $claim->subject(),
				'event'       => $event,
				'reason'      => '' !== $reason ? substr( $reason, 0, 64 ) : null,
				'actor'       => sanitize_key( $actor ) ?: 'system',
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * The user who most recently gave this subject up.
	 *
	 * Feeds Resolution::retired(). Callers must treat the answer as context for a
	 * policy decision, never as an owner — see the class docblock.
	 *
	 * @return int 0 when the subject has never been retired.
	 */
	public function last_retired_owner( Claim $claim ): int {
		if ( $claim->is_empty() ) {
			return 0;
		}

		global $wpdb;

		$table = Installer::identity_history_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE channel = %s AND subject = %s AND event = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$claim->channel(),
				$claim->subject(),
				self::RETIRED
			)
		);
	}

	public function has_history( Claim $claim ): bool {
		if ( $claim->is_empty() ) {
			return false;
		}

		global $wpdb;

		$table = Installer::identity_history_table();

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE channel = %s AND subject = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$claim->channel(),
				$claim->subject()
			)
		);
	}

	public function count_events( Claim $claim, string $event ): int {
		if ( $claim->is_empty() || ! in_array( $event, $this->events(), true ) ) {
			return 0;
		}

		global $wpdb;

		$table = Installer::identity_history_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE channel = %s AND subject = %s AND event = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$claim->channel(),
				$claim->subject(),
				$event
			)
		);
	}

	/**
	 * Recent events for one subject, newest first. For the support screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function for_subject( Claim $claim, int $limit = 20 ): array {
		if ( $claim->is_empty() ) {
			return array();
		}

		global $wpdb;

		$table = Installer::identity_history_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel = %s AND subject = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$claim->channel(),
				$claim->subject(),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove every trace of one user. Called only from account deletion, where
	 * keeping an audit trail of a deleted person is the wrong default.
	 */
	public function forget_user( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::identity_history_table(),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);
	}
}
