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
				'token'       => $data['token'],
				'purpose'     => $data['purpose'],
				'channel'     => $data['channel'],
				'destination' => $data['destination'],
				'code_hash'   => $data['code_hash'],
				'payload'     => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
				'attempts'    => 0,
				'resend_of'   => $data['resend_of'] ?? null,
				'ip'          => $data['ip'] ?? null,
				'created_at'  => $data['created_at'],
				'expires_at'  => $data['expires_at'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
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
	 * Invalidate every live code for a destination/purpose pair.
	 * Called before issuing a fresh one so only the newest code works.
	 */
	public function consume_open_codes( string $destination, string $purpose ): void {
		global $wpdb;

		$table = $this->table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET consumed_at = %s WHERE destination = %s AND purpose = %s AND consumed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
				current_time( 'mysql', true ),
				$destination,
				$purpose
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
	public function last_sent_at( string $destination, string $purpose ): int {
		global $wpdb;

		$table = $this->table();

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE destination = %s AND purpose = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$destination,
				$purpose
			)
		);

		return $value ? (int) strtotime( $value . ' UTC' ) : 0;
	}
}
