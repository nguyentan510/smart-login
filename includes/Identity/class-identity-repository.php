<?php
/**
 * Persistence for smartlogin_identities — the authorization index.
 *
 * This table answers exactly one question: which user owns a given
 * (channel, subject) right now. `UNIQUE KEY subject_owner` is what makes the
 * answer single-valued, and claim() relies on the database to arbitrate races
 * rather than on a read-then-write check that two requests could both pass.
 *
 * The repository owns an IdentityHistory collaborator instead of leaving history
 * to callers. Retiring an identity without leaving a trace would make
 * Resolution::RETIRED unreachable, and RETIRED is the state that keeps the
 * account-takeover defect unrepresentable. Pairing them here means no caller can
 * forget.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

use SmartLogin\Installer;

defined( 'ABSPATH' ) || exit;

final class IdentityRepository {

	/**
	 * $wpdb formats, in the key order produced by IdentityRecord::to_row().
	 * Keep the two in step.
	 */
	const FORMATS = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

	private IdentityHistory $history;

	public function __construct( ?IdentityHistory $history = null ) {
		$this->history = $history ?? new IdentityHistory();
	}

	public function history(): IdentityHistory {
		return $this->history;
	}

	/**
	 * The current owner record for a subject, or null when unowned.
	 */
	public function find( Claim $claim ): ?IdentityRecord {
		if ( $claim->is_empty() ) {
			return null;
		}

		global $wpdb;

		$table = Installer::identities_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel = %s AND subject = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$claim->channel(),
				$claim->subject()
			),
			ARRAY_A
		);

		return is_array( $row ) ? IdentityRecord::from_row( $row ) : null;
	}

	/**
	 * Every identity belonging to a user, optionally filtered to one channel.
	 *
	 * @return IdentityRecord[]
	 */
	public function for_user( int $user_id, string $channel = '' ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$table = Installer::identities_table();

		if ( '' !== $channel ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d AND channel = %s ORDER BY is_primary DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
					$user_id,
					sanitize_key( $channel )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d ORDER BY is_primary DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
					$user_id
				),
				ARRAY_A
			);
		}

		return array_map(
			static function ( array $row ): IdentityRecord {
				return IdentityRecord::from_row( $row );
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Take ownership of a subject.
	 *
	 * Returns false when the subject is already owned. The UNIQUE index is the
	 * arbiter, so two concurrent requests cannot both succeed — a losing caller
	 * should re-read with find() to discover who won.
	 *
	 * Errors are suppressed around the insert because a duplicate-key failure is
	 * an expected outcome here, not a fault worth logging on every race.
	 */
	public function claim( IdentityRecord $record ): bool {
		if ( $record->user_id() <= 0 || '' === $record->channel() || '' === $record->subject() ) {
			return false;
		}

		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::identities_table(),
			$record->to_row(),
			self::FORMATS
		);

		$wpdb->suppress_errors( $suppressed );

		if ( false === $inserted ) {
			return false;
		}

		$this->history->record(
			$record->user_id(),
			$record->claim(),
			IdentityHistory::VERIFIED,
			$record->linked_by()
		);

		return true;
	}

	/**
	 * End ownership of a subject and leave a trace.
	 *
	 * @return int The user who owned it, or 0 when it was unowned.
	 */
	public function retire( Claim $claim, string $reason = '', string $actor = 'self' ): int {
		$record = $this->find( $claim );

		if ( ! $record ) {
			return 0;
		}

		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::identities_table(),
			array( 'id' => $record->id() ),
			array( '%d' )
		);

		if ( ! $deleted ) {
			return 0;
		}

		$this->history->record( $record->user_id(), $claim, IdentityHistory::RETIRED, $reason, $actor );

		return $record->user_id();
	}

	/**
	 * Move a subject to a different user in one step, so it is never unowned.
	 *
	 * @return bool False when the subject is unowned or already belongs there.
	 */
	public function relink( Claim $claim, int $new_user_id, string $reason = '', string $actor = 'admin' ): bool {
		$record = $this->find( $claim );

		if ( ! $record || $new_user_id <= 0 || $record->user_id() === $new_user_id ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::identities_table(),
			array( 'user_id' => $new_user_id ),
			array( 'id' => $record->id() ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$this->history->record( $new_user_id, $claim, IdentityHistory::RELINKED, $reason, $actor );

		return true;
	}

	/**
	 * Make one identity the primary for its channel, demoting its siblings.
	 */
	public function set_primary( int $user_id, Claim $claim ): bool {
		$record = $this->find( $claim );

		if ( ! $record || $record->user_id() !== $user_id ) {
			return false;
		}

		global $wpdb;

		$table = Installer::identities_table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET is_primary = 0 WHERE user_id = %d AND channel = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id,
				$claim->channel()
			)
		);

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'is_primary' => 1 ),
			array( 'id' => $record->id() ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * The primary identity for a channel, falling back to the oldest one.
	 */
	public function primary_for( int $user_id, string $channel ): ?IdentityRecord {
		$records = $this->for_user( $user_id, $channel );

		return $records[0] ?? null;
	}

	/**
	 * How many identities a user has. Phase 6 uses this to refuse unlinking the
	 * last one and stranding the account.
	 */
	public function count_for_user( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		$table = Installer::identities_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id
			)
		);
	}

	/**
	 * Drop every identity of a deleted user, recording each retirement.
	 *
	 * @return int Rows removed.
	 */
	public function retire_all_for_user( int $user_id, string $reason = 'user_deleted' ): int {
		$removed = 0;

		foreach ( $this->for_user( $user_id ) as $record ) {
			if ( $this->retire( $record->claim(), $reason, 'system' ) > 0 ) {
				++$removed;
			}
		}

		return $removed;
	}
}
