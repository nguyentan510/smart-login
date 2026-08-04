<?php
/**
 * Data access for the OTP table.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP;

use SmartLogin\Installer;

defined( 'ABSPATH' ) || exit;

class OtpRepository {

	private function table(): string {
		return Installer::otp_table();
	}

	/**
	 * @return int Row ID, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'token'            => $data['token'],
				'intent'           => $data['intent'],
				'identity_channel' => (string) ( $data['identity_channel'] ?? '' ),
				'transport'        => $data['transport'],
				'destination'      => $data['destination'],
				'code_hash'        => $data['code_hash'],
				'payload'          => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
				'attempts'         => 0,
				'resend_of'        => $data['resend_of'] ?? null,
				'ip'               => $data['ip'] ?? null,
				'created_at'       => $data['created_at'],
				'expires_at'       => $data['expires_at'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_by_token( string $token ): ?array {
		global $wpdb;

		$table = $this->table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['payload'] = $row['payload'] ? json_decode( $row['payload'], true ) : array();

		return $row;
	}

	public function mark_consumed( int $id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'consumed_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Atomically claim a live code for its single successful consumer.
	 *
	 * @return bool True only for the request that changed the open row.
	 */
	public function consume_if_open( int $id ): bool {
		global $wpdb;

		$table = $this->table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
				current_time( 'mysql', true ),
				$id
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Atomic increment so two concurrent guesses cannot share an attempt slot.
	 *
	 * @return int The attempt count after incrementing.
	 */
	public function increment_attempts( int $id ): int {
		global $wpdb;

		$table = $this->table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Invalidate every live code for a destination/intent pair.
	 *
	 * Called *after* a fresh code has been delivered, so only the newest one
	 * works. It used to run before the send, which meant a gateway failure
	 * destroyed a code the user was already holding — see 10.7.
	 *
	 * @param string $destination Canonical phone digits or email address.
	 * @param string $intent      One of the OtpService::INTENT_* constants.
	 * @param int    $except_id   Row to leave alone, normally the code just sent.
	 *                            Excluding by id rather than by recency is
	 *                            deliberate: two concurrent issues for one
	 *                            destination would otherwise race to consume
	 *                            each other.
	 */
	public function consume_open_codes( string $destination, string $intent, int $except_id = 0 ): void {
		global $wpdb;

		$table = $this->table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET consumed_at = %s WHERE destination = %s AND intent = %s AND consumed_at IS NULL AND id <> %d", // phpcs:ignore WordPress.DB.PreparedSQL
				current_time( 'mysql', true ),
				$destination,
				$intent,
				$except_id
			)
		);
	}

	/**
	 * How many codes went out to this destination in the last N seconds.
	 */
	public function count_recent_by_destination( string $destination, int $seconds ): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE destination = %s AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$destination,
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	/**
	 * How many codes went out to one identity channel in the last N seconds.
	 *
	 * Counting by channel rather than by transport is what survives routing: a
	 * site that points `phone` at the automation endpoint is still sending real
	 * SMS and still spending real money, but no row says `transport = 'sms'` any
	 * more, so the old count read zero while the bill kept arriving.
	 *
	 * Rows written before 10.5 may hold an empty `identity_channel` — nothing
	 * filled it unless a handler passed a claim — and are not counted. Left
	 * uncorrected rather than migrated: the column is populated on every write
	 * now, the plugin has never run in production, and a backfill would need a
	 * schema version bump this phase deliberately does not take.
	 *
	 * @param string $channel PhoneChannel::ID or MailChannel::ID.
	 * @param int    $seconds Window.
	 */
	public function count_recent_by_channel( string $channel, int $seconds ): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE identity_channel = %s AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$channel,
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	/**
	 * How many codes went out across the whole site in the last N seconds.
	 *
	 * The only counter here not scoped to a destination or an IP, which is the
	 * point: those two are the axes an attacker rotates. Served by
	 * KEY created_at, added in DB version 5 for this query.
	 */
	public function count_recent_all( int $seconds ): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	/**
	 * Codes sent over one transport in the last N seconds.
	 *
	 * What makes a spend estimate possible: only the `sms` rows cost money, and
	 * lumping them in with email would give an operator a number that is wrong in
	 * the direction that matters.
	 */
	public function count_recent_by_transport( string $transport, int $seconds ): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE transport = %s AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$transport,
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	public function count_recent_by_ip( ?string $ip_binary, int $seconds ): int {
		if ( null === $ip_binary ) {
			return 0;
		}

		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip = %s AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$ip_binary,
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	/**
	 * Timestamp of the most recent code for a destination, for the resend cooldown.
	 *
	 * @return int Unix timestamp, 0 when none.
	 */
	public function last_sent_at( string $destination, string $intent ): int {
		global $wpdb;

		$table = $this->table();

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE destination = %s AND intent = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$destination,
				$intent
			)
		);

		return $value ? (int) strtotime( $value . ' UTC' ) : 0;
	}
}
