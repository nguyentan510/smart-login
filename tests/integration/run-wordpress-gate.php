<?php
/**
 * WordPress/MySQL qualification gate for the authentication refactor.
 *
 * This is intentionally an opt-in local integration check. It uses a real
 * WordPress bootstrap and a real MySQL connection, then exercises the
 * migration, external identity repository, and profile completeness boundary.
 *
 * @package OmniWP
 */

declare( strict_types=1 );

$wp_root  = rtrim( (string) getenv( 'OMNIWP_WP_ROOT' ), "\\/" );
$db_host  = (string) getenv( 'OMNIWP_DB_HOST' );
$db_name  = (string) getenv( 'OMNIWP_DB_NAME' );
$db_user  = (string) getenv( 'OMNIWP_DB_USER' );
$db_pass  = (string) getenv( 'OMNIWP_DB_PASSWORD' );
$prefix   = (string) getenv( 'OMNIWP_DB_PREFIX' );
$plugin_root = rtrim( (string) getenv( 'OMNIWP_PLUGIN_ROOT' ), "\\/" );
/*
 * `$ow_plugin`, not `$plugin`.
 *
 * wp-settings.php uses $plugin as the loop variable for active plugins and
 * unset()s it afterwards, and this file requires it into the same scope. So the
 * guard below — which is only reachable when the plugin was NOT already loaded,
 * i.e. exactly when it matters — read a variable WordPress had just destroyed,
 * and reported `TypeError: is_file(): Argument #1 must be of type string, null
 * given` instead of the clean blocker it was written to give.
 */
$ow_plugin = $plugin_root . DIRECTORY_SEPARATOR . 'omniwp.php';

$blocked = static function ( string $message ): never {
	echo "OMNIWP_AUTH_INTEGRATION_BLOCKED\n";
	echo 'reason=' . $message . "\n";
	exit( 2 );
};

$failed = static function ( string $message ): never {
	echo "OMNIWP_AUTH_INTEGRATION_FAILED\n";
	echo 'reason=' . $message . "\n";
	exit( 1 );
};

if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'OMNIWP_WP_ROOT must point to a WordPress public root' );
}
if ( '' === $plugin_root || ! is_file( $ow_plugin ) ) {
	$blocked( 'OMNIWP_PLUGIN_ROOT must point to the current plugin source' );
}

if ( '' === $db_host || '' === $db_name || '' === $db_user ) {
	$blocked( 'OMNIWP_DB_HOST, OMNIWP_DB_NAME and OMNIWP_DB_USER are required' );
}

if ( '' === $prefix ) {
	$prefix = 'wp_';
}
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'OMNIWP_DB_PREFIX contains unsupported characters' );
}

if ( ! extension_loaded( 'mysqli' ) && ! extension_loaded( 'pdo_mysql' ) ) {
	$blocked( 'PHP MySQL extension is not loaded' );
}

define( 'ABSPATH', $wp_root . DIRECTORY_SEPARATOR );
define( 'DB_NAME', $db_name );
define( 'DB_USER', $db_user );
define( 'DB_PASSWORD', $db_pass );
define( 'DB_HOST', $db_host );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', false );
define( 'WP_PLUGIN_DIR', dirname( $plugin_root ) );
define( 'WP_PLUGIN_URL', 'https://example.test/wp-content/plugins' );
$table_prefix = $prefix;

try {
	require $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php';
} catch ( Throwable $exception ) {
	$blocked( 'WordPress bootstrap failed: ' . $exception->getMessage() );
}

if ( ! class_exists( 'OmniWP\\Installer' ) ) {
	if ( ! is_file( $ow_plugin ) ) {
		$blocked( 'Smart Login plugin is not present in the WordPress installation' );
	}
	require_once $ow_plugin;
}

global $wpdb;
if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb ) {
	$blocked( 'WordPress did not initialise wpdb' );
}

if ( '' !== (string) $wpdb->last_error ) {
	$blocked( 'Database bootstrap reported an error: ' . $wpdb->last_error );
}

try {
	\OmniWP\Installer::maybe_upgrade();

	$identities = \OmniWP\Installer::identities_table();
	$history    = \OmniWP\Installer::identity_history_table();

	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$identities}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'user_id', 'channel', 'subject', 'is_primary', 'verified_at', 'linked_by', 'created_at' ) as $column ) {
		if ( ! is_array( $columns ) || ! in_array( $column, $columns, true ) ) {
			$failed( 'identities table is missing the ' . $column . ' column' );
		}
	}

	$history_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$history}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'user_id', 'channel', 'subject', 'event', 'actor', 'occurred_at' ) as $column ) {
		if ( ! is_array( $history_columns ) || ! in_array( $column, $history_columns, true ) ) {
			$failed( 'identity history table is missing the ' . $column . ' column' );
		}
	}

	// Ownership must be single-valued at the storage layer, not merely by
	// convention in PHP.
	$indexes = $wpdb->get_results( "SHOW INDEX FROM {$identities} WHERE Key_name = 'subject_owner'", ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! is_array( $indexes ) || 2 !== count( $indexes ) || '0' !== (string) $indexes[0]['Non_unique'] ) {
		$failed( 'subject_owner must be a UNIQUE index over (channel, subject)' );
	}

	// The superseded table must be gone, not merely unused.
	$legacy = $wpdb->prefix . 'OMNIWP_external_identities';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) ) { // phpcs:ignore WordPress.DB
		$failed( 'the superseded external identities table still exists' );
	}

	// A second call proves the upgrade path is idempotent for the installed schema.
	\OmniWP\Installer::maybe_upgrade();
	if ( (string) get_option( \OmniWP\Installer::DB_VERSION_OPTION, '' ) !== (string) OMNIWP_DB_VERSION ) {
		$failed( 'database version option does not match OMNIWP_DB_VERSION' );
	}

	// Measured, not assumed: a definition dbDelta cannot match would issue an
	// ALTER TABLE on every request.
	$pending = \OmniWP\Installer::pending_schema_changes();
	if ( $pending ) {
		$failed( 'dbDelta is not idempotent; pending changes: ' . implode( ' | ', $pending ) );
	}

	$login = 'ow_gate_' . strtolower( wp_generate_password( 10, false, false ) );
	$email = $login . '@example.test';
	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => $email,
			'display_name' => $login,
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		$failed( 'temporary WordPress user could not be created: ' . $user_id->get_error_message() );
	}

	$repository = new \OmniWP\Identity\IdentityRepository();
	$history_log = $repository->history();
	$subject     = 'gate-' . wp_generate_uuid4();
	$claim       = \OmniWP\Identity\Claim::canonical( 'google', $subject );

	$record = \OmniWP\Identity\IdentityRecord::create(
		(int) $user_id,
		\OmniWP\Identity\VerifiedClaim::from( $claim, \OmniWP\Identity\VerifiedClaim::PROOF_OAUTH ),
		\OmniWP\Identity\IdentityRecord::BY_OAUTH,
		true,
		array( 'source' => 'integration-gate' )
	);

	if ( ! $repository->claim( $record ) ) {
		$failed( 'claiming a fresh subject failed: ' . $wpdb->last_error );
	}

	$found = $repository->find( $claim );
	if ( ! $found || $found->user_id() !== (int) $user_id ) {
		$failed( 'identity lookup returned the wrong user' );
	}
	if ( 'integration-gate' !== ( $found->meta()['source'] ?? '' ) ) {
		$failed( 'meta_json did not round-trip through the database' );
	}
	if ( ! $found->is_primary() ) {
		$failed( 'the primary flag did not round-trip' );
	}

	// The database, not PHP, must refuse a second owner. A different user
	// claiming the same subject has to lose.
	$rival_id = wp_insert_user(
		array(
			'user_login'   => $login . '_rival',
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => $login . '_rival@example.test',
			'display_name' => $login . '_rival',
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $rival_id ) ) {
		$failed( 'rival user could not be created: ' . $rival_id->get_error_message() );
	}

	$rival_record = \OmniWP\Identity\IdentityRecord::create(
		(int) $rival_id,
		\OmniWP\Identity\VerifiedClaim::from( $claim, \OmniWP\Identity\VerifiedClaim::PROOF_OAUTH ),
		\OmniWP\Identity\IdentityRecord::BY_OAUTH
	);
	if ( $repository->claim( $rival_record ) ) {
		$failed( 'UNIQUE KEY subject_owner allowed a second owner for one subject' );
	}
	if ( $repository->find( $claim )->user_id() !== (int) $user_id ) {
		$failed( 'a losing claim changed the existing owner' );
	}

	$status = ( new \OmniWP\Auth\ProfileCompletionService() )->status( (int) $user_id );
	if ( ! isset( $status['complete'], $status['required_missing'], $status['recommended_missing'] ) || ! is_array( $status['required_missing'] ) ) {
		$failed( 'profile completion service returned an invalid status contract' );
	}

	// Retiring must end ownership and leave exactly one trace.
	if ( $repository->retire( $claim, 'integration_gate' ) !== (int) $user_id ) {
		$failed( 'retire did not report the previous owner' );
	}
	if ( null !== $repository->find( $claim ) ) {
		$failed( 'a retired subject still has an owner' );
	}
	if ( 1 !== $history_log->count_events( $claim, \OmniWP\Identity\IdentityHistory::RETIRED ) ) {
		$failed( 'retiring an identity did not write exactly one history row' );
	}

	// This is the takeover fix at the storage layer: the previous owner is
	// recoverable for policy, but the subject has no current owner.
	if ( $history_log->last_retired_owner( $claim ) !== (int) $user_id ) {
		$failed( 'history did not preserve the previous owner for policy use' );
	}

	// A recycled subject may be claimed by someone else afterwards.
	if ( ! $repository->claim( $rival_record ) ) {
		$failed( 'a retired subject could not be claimed by a new owner' );
	}
	if ( $repository->find( $claim )->user_id() !== (int) $rival_id ) {
		$failed( 'the recycled subject did not transfer to the new owner' );
	}

	// ---------------------------------------------------------------
	// Phase 6: no sequence of unlinks can leave an account with nothing.
	// ---------------------------------------------------------------
	$guard_pass  = wp_generate_password( 24, true, true );
	$guard_login = 'ow_gate_guard_' . strtolower( wp_generate_password( 8, false, false ) );
	$guard_id    = wp_insert_user(
		array(
			'user_login'   => $guard_login,
			'user_pass'    => $guard_pass,
			'user_email'   => $guard_login . '@example.test',
			'display_name' => $guard_login,
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $guard_id ) ) {
		$failed( 'guard user could not be created: ' . $guard_id->get_error_message() );
	}

	$guard_service = new \OmniWP\Auth\IdentityLinkService();
	$guard_claims  = array(
		\OmniWP\Identity\Claim::canonical( 'google', 'guard-' . wp_generate_uuid4() ),
		\OmniWP\Identity\Claim::canonical( 'google', 'guard-' . wp_generate_uuid4() ),
	);

	foreach ( $guard_claims as $guard_claim ) {
		$ok = $repository->claim(
			\OmniWP\Identity\IdentityRecord::create(
				(int) $guard_id,
				\OmniWP\Identity\VerifiedClaim::from( $guard_claim, \OmniWP\Identity\VerifiedClaim::PROOF_OAUTH ),
				\OmniWP\Identity\IdentityRecord::BY_OAUTH
			)
		);
		if ( ! $ok ) {
			$failed( 'guard identity could not be claimed: ' . $wpdb->last_error );
		}
	}

	if ( 2 !== $repository->count_for_user( (int) $guard_id ) ) {
		$failed( 'guard user should hold exactly two identities' );
	}

	// A wrong password must not detach anything, even with a spare identity.
	$wrong = $guard_service->unlink( (int) $guard_id, 'google', $guard_claims[0]->subject(), 'not-the-password' );
	if ( ! is_wp_error( $wrong ) || 'OMNIWP_bad_password' !== $wrong->get_error_code() ) {
		$failed( 'unlink accepted a wrong password' );
	}
	if ( 2 !== $repository->count_for_user( (int) $guard_id ) ) {
		$failed( 'a failed re-authentication still removed an identity' );
	}

	// Someone else's identity must not be detachable through your own session.
	$foreign = $guard_service->unlink( (int) $guard_id, 'google', $subject, $guard_pass );
	if ( ! is_wp_error( $foreign ) || 'OMNIWP_not_linked' !== $foreign->get_error_code() ) {
		$failed( 'unlink accepted an identity belonging to another account' );
	}

	// The first removal succeeds: a spare remains.
	$first = $guard_service->unlink( (int) $guard_id, 'google', $guard_claims[0]->subject(), $guard_pass );
	if ( is_wp_error( $first ) ) {
		$failed( 'the first unlink failed: ' . $first->get_error_message() );
	}
	if ( 1 !== $repository->count_for_user( (int) $guard_id ) ) {
		$failed( 'the first unlink did not remove exactly one identity' );
	}

	// The second must be refused, whatever the caller does.
	$second = $guard_service->unlink( (int) $guard_id, 'google', $guard_claims[1]->subject(), $guard_pass );
	if ( ! is_wp_error( $second ) || 'OMNIWP_last_identity' !== $second->get_error_code() ) {
		$failed( 'unlink removed the last identity and orphaned the account' );
	}
	if ( 1 !== $repository->count_for_user( (int) $guard_id ) ) {
		$failed( 'the account was left with no way to sign in' );
	}
	if ( $guard_service->can_unlink( (int) $guard_id ) ) {
		$failed( 'can_unlink() disagrees with unlink() about the last identity' );
	}

	// Retrying does not wear the guard down.
	for ( $attempt = 0; $attempt < 3; $attempt++ ) {
		$guard_service->unlink( (int) $guard_id, 'google', $guard_claims[1]->subject(), $guard_pass );
	}
	if ( 1 !== $repository->count_for_user( (int) $guard_id ) ) {
		$failed( 'repeated unlink attempts eventually orphaned the account' );
	}

	/*
	 * 14.7 — deleting a user must release the subjects it held.
	 *
	 * Before 14.7 nothing hooked `deleted_user`, so the rows stayed live and pointed
	 * at an account that no longer existed. resolve() answered KNOWN, and
	 * create_verified_user() then refused that number or address as already
	 * registered — for ever. Login failed closed, so it was a denial rather than a
	 * takeover, which is why it survived every gate.
	 *
	 * Deliberately its own fixture rather than reusing the accounts above: those are
	 * torn down by the gate itself, and a rule about deletion must own the deletion.
	 */
	$release_login = 'ow_release_' . strtolower( wp_generate_password( 8, false, false ) );
	$release_phone = '849' . str_pad( (string) random_int( 10000000, 99999999 ), 8, '0' );
	$release_id    = wp_insert_user(
		array(
			'user_login' => $release_login,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => $release_login . '@example.test',
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $release_id ) ) {
		$failed( 'could not create the identity-release fixture user' );
	}

	$release_claim = \OmniWP\Identity\Claim::canonical( 'phone', $release_phone );
	if ( ! $repository->claim(
		\OmniWP\Identity\IdentityRecord::create(
			(int) $release_id,
			\OmniWP\Identity\VerifiedClaim::from( $release_claim, \OmniWP\Identity\VerifiedClaim::PROOF_OTP ),
			\OmniWP\Identity\IdentityRecord::BY_REGISTRATION,
			true
		)
	) ) {
		wp_delete_user( (int) $release_id );
		$failed( 'could not claim the identity-release fixture subject' );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $release_id );

	if ( $repository->find( $release_claim ) ) {
		$repository->retire_all_for_user( (int) $release_id, 'gate_cleanup' );
		$history_log->forget_user( (int) $release_id );
		$failed( 'deleting a user left its identity row live, so that subject can never be registered again' );
	}

	// And the subject is genuinely available again, not merely absent from the table.
	$release_state = ( new \OmniWP\Identity\IdentityDirectory() )->resolve( $release_claim )->state();
	if ( \OmniWP\Identity\Resolution::STATE_KNOWN === $release_state ) {
		$history_log->forget_user( (int) $release_id );
		$failed( 'the released subject still resolves as owned: ' . $release_state );
	}
	$history_log->forget_user( (int) $release_id );

	/*
	 * 17.4's shipping mirror, against a real user meta table.
	 *
	 * The account card suite exercises save_for_user() against a stubbed meta
	 * store, which cannot see WordPress's own serialisation, its cache, or
	 * ProfileSeeder's allowlist meeting a real update_user_meta(). 17.4's brief
	 * asked for this and recorded that it was not run; this is where it runs.
	 *
	 * The cost the spec states is asserted as behaviour, not assumed: an existing
	 * shipping address different from billing IS overwritten. A gate that only
	 * checked the mirror on an empty profile would pass while the documented
	 * consequence quietly stopped happening.
	 */
	$address_login = 'ow_addr_' . strtolower( wp_generate_password( 8, false, false ) );
	$address_id    = wp_insert_user(
		array(
			'user_login' => $address_login,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => $address_login . '@example.test',
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $address_id ) ) {
		$failed( 'could not create the address fixture user' );
	}

	// A customer who deliberately delivers somewhere else.
	update_user_meta( (int) $address_id, 'shipping_address_1', 'Số 9, ngõ cũ' );

	$province = (string) array_key_first( \OmniWP\Address\AddressRepository::provinces() );
	$wards    = \OmniWP\Address\AddressRepository::wards( $province );
	$ward     = (string) array_key_first( $wards );

	$clean = \OmniWP\Address\AddressFields::validate(
		array(
			\OmniWP\Address\AddressFields::FIELD_PROVINCE => $province,
			\OmniWP\Address\AddressFields::FIELD_WARD     => $ward,
			\OmniWP\Address\AddressFields::FIELD_STREET   => '12 Trần Duy Hưng',
		)
	);

	if ( is_wp_error( $clean ) ) {
		wp_delete_user( (int) $address_id );
		$failed( 'the address fixture did not validate: ' . $clean->get_error_message() );
	}

	\OmniWP\Address\AddressFields::save_for_user( (int) $address_id, $clean );

	foreach ( array( 'state', 'city', 'address_1' ) as $part ) {
		$billing  = (string) get_user_meta( (int) $address_id, 'billing_' . $part, true );
		$shipping = (string) get_user_meta( (int) $address_id, 'shipping_' . $part, true );

		if ( '' === $billing || $billing !== $shipping ) {
			wp_delete_user( (int) $address_id );
			$failed( sprintf( 'the shipping book does not mirror billing on %s: %s vs %s', $part, $billing, $shipping ) );
		}
	}

	if ( \OmniWP\Address\AddressFields::META_WARD_CODE
		&& (string) get_user_meta( (int) $address_id, \OmniWP\Address\AddressFields::META_WARD_CODE, true )
			!== (string) get_user_meta( (int) $address_id, \OmniWP\Address\AddressFields::META_SHIPPING_WARD_CODE, true ) ) {
		wp_delete_user( (int) $address_id );
		$failed( 'the ward code was not mirrored onto the shipping side' );
	}

	if ( 'Số 9, ngõ cũ' === (string) get_user_meta( (int) $address_id, 'shipping_address_1', true ) ) {
		wp_delete_user( (int) $address_id );
		$failed( 'a separate shipping address survived, so the documented cost of one address is not what the code does' );
	}

	wp_delete_user( (int) $address_id );

	/*
	 * 14.5's backfill assertions lived here and went with the code in 15.2.
	 *
	 * Kept as a note rather than deleted silently, because their Outcome recorded a real
	 * defect: the migration cursor outlived the migration and nothing would have resumed
	 * it, so any site larger than one batch would have reported success having done a
	 * fraction of the work. The finding survives in docs/email-identity/14.5-backfill.md
	 * and in the tracker; only the code it asserted is gone.
	 */

	$repository->retire_all_for_user( (int) $guard_id, 'integration_gate' );
	$repository->retire_all_for_user( (int) $rival_id, 'integration_gate' );
	$history_log->forget_user( (int) $guard_id );
	$history_log->forget_user( (int) $rival_id );
	$history_log->forget_user( (int) $user_id );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $guard_id );
	wp_delete_user( (int) $rival_id );
	wp_delete_user( (int) $user_id );
} catch ( Throwable $exception ) {
	if ( function_exists( 'wp_delete_user' ) ) {
		// The 14.5 and 14.7 fixtures belong here too. They did not, and the first red
		// run of the backfill rule left two users and two identity rows behind — which
		// is how 14.4's doors became vacuous in the first place.
		foreach ( array( $user_id ?? 0, $rival_id ?? 0, $guard_id ?? 0, $release_id ?? 0, $legacy_id ?? 0, $synth_id ?? 0 ) as $orphan ) {
			if ( $orphan > 0 && ! is_wp_error( $orphan ) ) {
				@wp_delete_user( (int) $orphan );
			}
		}
	}
	$failed( 'integration assertion raised an exception: ' . $exception->getMessage() );
}

echo "OMNIWP_AUTH_INTEGRATION_OK\n";
echo 'wordpress=' . get_bloginfo( 'version' ) . "\n";
echo 'identities_table=' . \OmniWP\Installer::identities_table() . "\n";
echo 'identity_history_table=' . \OmniWP\Installer::identity_history_table() . "\n";
echo 'db_version=' . get_option( \OmniWP\Installer::DB_VERSION_OPTION ) . "\n";
