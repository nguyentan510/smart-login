<?php
/**
 * Persistence boundary for provider subject to WordPress user mappings.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Installer;

defined( 'ABSPATH' ) || exit;

final class ExternalIdentityRepository {

	public function find( string $provider, string $subject ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::external_identities_table() . ' WHERE provider = %s AND provider_subject = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				sanitize_key( $provider ),
				$subject
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public function find_by_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . Installer::external_identities_table() . ' WHERE user_id = %d ORDER BY id ASC', $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function find_by_verified_email( string $email ): array {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::external_identities_table() . ' WHERE email = %s AND email_verified = 1 ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL
				$email
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function insert( string $provider, string $subject, int $user_id, string $email, bool $email_verified, array $profile, string $linked_by ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::external_identities_table(),
			array(
				'provider'         => sanitize_key( $provider ),
				'provider_subject' => $subject,
				'user_id'          => $user_id,
				'email'            => '' !== $email ? strtolower( sanitize_email( $email ) ) : null,
				'email_verified'   => $email_verified ? 1 : 0,
				'profile_json'     => wp_json_encode( $profile ),
				'linked_by'        => sanitize_key( $linked_by ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public function link( string $provider, string $subject, int $user_id, string $email, bool $email_verified, array $profile, string $linked_by ): bool {
		return $this->insert( $provider, $subject, $user_id, $email, $email_verified, $profile, $linked_by );
	}

	public function delete( string $provider, string $subject, int $user_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::external_identities_table(),
			array( 'provider' => sanitize_key( $provider ), 'provider_subject' => $subject, 'user_id' => $user_id ),
			array( '%s', '%s', '%d' )
		);
	}
}
