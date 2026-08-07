<?php
/**
 * P0.2/P0.3 provider qualification gate.
 *
 * Uses a real WordPress/MySQL bootstrap, but mocks only the outbound Google
 * token/certificate HTTP calls. No provider credential or production request is
 * used by this gate.
 *
 * @package SmartLogin
 */

declare( strict_types=1 );

$wp_root = rtrim( (string) getenv( 'SMART_LOGIN_WP_ROOT' ), "\\/" );
$db_host = (string) getenv( 'SMART_LOGIN_DB_HOST' );
$db_name = (string) getenv( 'SMART_LOGIN_DB_NAME' );
$db_user = (string) getenv( 'SMART_LOGIN_DB_USER' );
$db_pass = (string) getenv( 'SMART_LOGIN_DB_PASSWORD' );
$prefix  = (string) getenv( 'SMART_LOGIN_DB_PREFIX' );
$plugin_root = rtrim( (string) getenv( 'SMART_LOGIN_PLUGIN_ROOT' ), "\\/" );

$blocked = static function ( string $message ): never {
	echo "SMART_LOGIN_PROVIDER_GATES_BLOCKED\n";
	echo 'reason=' . $message . "\n";
	exit( 2 );
};

$cleanup_ids  = array();
$cleanup_rows = array();
$cleanup      = static function () use ( &$cleanup_ids, &$cleanup_rows ): void {
	if ( ! function_exists( 'wp_delete_user' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/user.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	if ( class_exists( 'SmartLogin\\Identity\\IdentityRepository' ) ) {
		$repository = new \SmartLogin\Identity\IdentityRepository();
		foreach ( $cleanup_rows as $row ) {
			$claim = \SmartLogin\Identity\Claim::canonical( (string) $row['provider'], (string) $row['subject'] );
			$repository->retire( $claim, 'provider_gate_cleanup', 'system' );
			$repository->history()->forget_user( (int) $row['user_id'] );
		}
	}
	foreach ( $cleanup_ids as $user_id ) {
		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( (int) $user_id );
		}
	}
};

$failed = static function ( string $message ) use ( &$cleanup ): never {
	$cleanup();
	echo "SMART_LOGIN_PROVIDER_GATES_FAILED\n";
	echo 'reason=' . $message . "\n";
	exit( 1 );
};

if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'SMART_LOGIN_WP_ROOT must point to a WordPress public root' );
}
if ( '' === $plugin_root || ! is_file( $plugin_root . DIRECTORY_SEPARATOR . 'smart-login.php' ) ) {
	$blocked( 'SMART_LOGIN_PLUGIN_ROOT must point to the current plugin source' );
}
if ( '' === $db_host || '' === $db_name || '' === $db_user ) {
	$blocked( 'database connection variables are incomplete' );
}
if ( '' === $prefix ) {
	$prefix = 'wp_';
}
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'SMART_LOGIN_DB_PREFIX contains unsupported characters' );
}
if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_sign' ) ) {
	$blocked( 'OpenSSL is required for the Google ID-token fixture' );
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
define( 'SMART_LOGIN_GOOGLE_CLIENT_ID', (string) ( getenv( 'SMART_LOGIN_GOOGLE_CLIENT_ID' ) ?: 'smart-login-staging-client' ) );
define( 'SMART_LOGIN_GOOGLE_CLIENT_SECRET', (string) ( getenv( 'SMART_LOGIN_GOOGLE_CLIENT_SECRET' ) ?: 'staging-fixture-secret' ) );
define( 'SMART_LOGIN_GOOGLE_REDIRECT_URI', 'https://example.test/wp-admin/admin-post.php?action=smart_login_provider_callback&provider=google' );
$table_prefix = $prefix;

try {
	require $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php';
} catch ( Throwable $exception ) {
	$blocked( 'WordPress bootstrap failed: ' . $exception->getMessage() );
}

if ( ! class_exists( 'SmartLogin\\Auth\\Providers\\GoogleProvider' ) || ! class_exists( 'SmartLogin\\Auth\\AccountProvisioner' ) ) {
	$blocked( 'Smart Login provider classes are not loaded' );
}

global $wpdb;
if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb || '' !== (string) $wpdb->last_error ) {
	$blocked( 'WordPress database bootstrap is not healthy' );
}

/*
 * These are the settings the gate needs, forced regardless of how the host site
 * is configured — a gate that reads live configuration is not a gate.
 *
 * The keys were the pre-rename ones (`google_enabled`, `field_email_optional`
 * and friends) long after class-installer.php's migration map moved them to the
 * dotted namespaces below. A filter whose key never matches costs nothing and
 * says nothing, so the gate silently inherited the site's own settings instead:
 * on an install with `profile.email_optional = 1` it failed with "provider-only
 * account did not enter required email onboarding", and on an install with 0 it
 * would have passed for the wrong reason.
 *
 * Asserted below rather than trusted, because that is the failure this had.
 */
$sl_forced_settings = array(
	'providers.google.enabled' => 1,
	'providers.auto_link_email' => 1,
	'profile.email_optional'   => 0,
	/*
	 * Added in 15.3. Phase 15 wiped the database, and a fresh install defaults to
	 * `phone_only` — so `claim_any()` could not build an email claim at all and the
	 * 14.4 door assertions read
	 * `SMART_LOGIN_PROVIDER_GATES_FAILED: the provider address does not even form an
	 * email claim`. They had been passing on configuration somebody set by hand months
	 * earlier. Same failure as 14.4's vacuous doors, in the settings table rather than
	 * the identities one.
	 */
	'identity.mode'            => 'both',
);

add_filter(
	'smart_login_setting',
	static function ( $value, $key ) use ( $sl_forced_settings ) {
		return array_key_exists( $key, $sl_forced_settings ) ? $sl_forced_settings[ $key ] : $value;
	},
	99,
	2
);

// A key nobody reads is a key that has been renamed out from under this file.
foreach ( $sl_forced_settings as $sl_key => $sl_expected ) {
	// Compared as strings: `identity.mode` is 'both', and an int cast would make every
	// string setting look like 0 and agree with itself for the wrong reason.
	if ( (string) \SmartLogin\Settings::get( $sl_key ) !== (string) $sl_expected ) {
		echo "SMART_LOGIN_PROVIDER_GATES_BLOCKED\n";
		echo 'reason=forced setting did not take effect: ' . $sl_key . " — has it been renamed?\n";
		exit( 2 );
	}
}

try {
	// P0.2: construct a signed Google ID token and mock only Google HTTP calls.
	$key_pair = openssl_pkey_new(
		array(
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'private_key_bits' => 2048,
		)
	);
	if ( false === $key_pair ) {
		$failed( 'could not generate the Google fixture key pair' );
	}
	openssl_pkey_export( $key_pair, $private_key );
	$key_details = openssl_pkey_get_details( $key_pair );
	$public_key  = (string) ( $key_details['key'] ?? '' );
	if ( '' === $private_key || '' === $public_key ) {
		$failed( 'Google fixture key pair is incomplete' );
	}

	$provider = new \SmartLogin\Auth\Providers\GoogleProvider();
	if ( ! $provider->is_available() ) {
		$failed( 'Google provider did not become available with staging configuration' );
	}
	$redirect = $provider->begin( 'https://example.test/account', false );
	$query    = array();
	parse_str( (string) wp_parse_url( $redirect->url, PHP_URL_QUERY ), $query );
	$state = (string) ( $query['state'] ?? '' );
	if ( '' === $state ) {
		$failed( 'Google begin response did not include OAuth state' );
	}
	$transaction = get_transient( \SmartLogin\Auth\OAuthTransactionStore::PREFIX . $state );
	if ( ! is_array( $transaction ) || '' === (string) ( $transaction['nonce'] ?? '' ) ) {
		$failed( 'Google OAuth transaction was not persisted' );
	}

	$base64url = static function ( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	};
	$header = $base64url( wp_json_encode( array( 'alg' => 'RS256', 'kid' => 'gate-key', 'typ' => 'JWT' ) ) );
	$body   = $base64url(
		wp_json_encode(
			array(
				'iss'            => 'https://accounts.google.com',
				'aud'            => SMART_LOGIN_GOOGLE_CLIENT_ID,
				'exp'            => time() + 300,
				'iat'            => time(),
				'nonce'          => $transaction['nonce'],
				'sub'            => 'google-staging-subject-' . wp_generate_uuid4(),
				'email'          => 'google.staging@example.test',
				'email_verified' => true,
				'name'           => 'Google Staging User',
				'picture'        => 'https://example.test/avatar.png',
			)
		)
	);
	openssl_sign( $header . '.' . $body, $signature, $private_key, OPENSSL_ALGO_SHA256 );
	$id_token = $header . '.' . $body . '.' . $base64url( $signature );

	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) use ( $id_token, $public_key ) {
			if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'access_token' => 'fixture-only', 'id_token' => $id_token, 'token_type' => 'Bearer' ) ),
				);
			}
			if ( false !== strpos( $url, 'googleapis.com/oauth2/v1/certs' ) ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array( 'cache-control' => 'max-age=3600' ),
					'body'     => wp_json_encode( array( 'gate-key' => $public_key ) ),
				);
			}
			return $preempt;
		},
		10,
		3
	);
	delete_transient( \SmartLogin\Auth\Providers\GoogleIdTokenVerifier::CERT_TRANSIENT );

	$consumed = ( new \SmartLogin\Auth\OAuthTransactionStore() )->consume( $state, 'google' );
	if ( is_wp_error( $consumed ) ) {
		$failed( 'Google OAuth state could not be consumed: ' . $consumed->get_error_code() );
	}
	$identity = $provider->complete( array( 'code' => 'fixture-code', '_transaction' => $consumed ) );
	if ( is_wp_error( $identity ) ) {
		$failed( 'Google callback fixture failed: ' . $identity->get_error_code() );
	}
	if ( 'google' !== $identity->provider || ! $identity->email_verified || 'google.staging@example.test' !== $identity->email ) {
		$failed( 'Google claims mapping did not produce the expected verified identity' );
	}
	if ( ! is_wp_error( ( new \SmartLogin\Auth\OAuthTransactionStore() )->consume( $state, 'google' ) ) ) {
		$failed( 'Google OAuth state was reusable after consumption' );
	}

	$resolved = ( new \SmartLogin\Auth\AccountProvisioner() )->resolve( $identity, $consumed );
	if ( is_wp_error( $resolved ) || empty( $resolved['context']->is_new_user ) ) {
		$failed( 'Google first-login provisioning did not create a new user' );
	}
	$cleanup_ids[]  = (int) $resolved['user']->ID;
	$cleanup_rows[] = array( 'provider' => 'google', 'subject' => $identity->subject, 'user_id' => (int) $resolved['user']->ID );

	/*
	 * Phase 14 — the two doors. These need a store that actually stores, which is
	 * why they are here rather than in the contract suite: that $wpdb stub does not
	 * parse SQL, deliberately, so it cannot answer "is this address resolvable
	 * now". They fail until 14.4, and are the assertions that sub-phase turns green.
	 *
	 * The account provisioned above holds a Google-verified address in
	 * wp_users.user_email and one federated identity row. Typing that address is
	 * what the account holder does the next day.
	 */
	$door_claim = ( new \SmartLogin\Identity\ChannelRegistry() )->claim_any( (string) $identity->email );
	if ( $door_claim->is_empty() ) {
		$failed( 'the provider address does not even form an email claim' );
	}
	$cleanup_rows[]  = array( 'provider' => 'email', 'subject' => $door_claim->subject(), 'user_id' => (int) $resolved['user']->ID );
	$door_resolution = ( new \SmartLogin\Identity\IdentityDirectory() )->resolve( $door_claim );

	/*
	 * The owner must be the account THIS run provisioned, not merely somebody.
	 *
	 * Asserting only ISSUE_SESSION made both doors vacuous: an email row left behind
	 * by an earlier run pointed at a since-deleted user, resolved KNOWN, and satisfied
	 * the decision whether or not the code under test did anything. Verified by
	 * reverting the provisioner to its pre-14.4 state and watching the gate pass
	 * anyway. Pinning the id is what makes the assertion about this run.
	 */
	if ( $door_resolution->user_id() !== (int) $resolved['user']->ID ) {
		$failed(
			'door 1: the verified address does not resolve to the account just provisioned'
			. ' — resolved to user ' . $door_resolution->user_id()
			. ', expected ' . (int) $resolved['user']->ID
		);
	}

	$door_login = \SmartLogin\Auth\AuthAction::for_resolution( \SmartLogin\Auth\AuthAction::LOGIN, $door_resolution );
	if ( \SmartLogin\Auth\AuthAction::ISSUE_SESSION !== $door_login ) {
		$failed(
			'door 1: the identify screen does not recognise a provider account by its own'
			. ' verified address — login resolves to ' . $door_login
		);
	}

	$door_recover = \SmartLogin\Auth\AuthAction::for_resolution( \SmartLogin\Auth\AuthAction::RECOVER, $door_resolution );
	if ( \SmartLogin\Auth\AuthAction::ISSUE_RESET_GRANT !== $door_recover ) {
		$failed(
			'door 2: recovery reports a provider account as never registered —'
			. ' recover resolves to ' . $door_recover
		);
	}

	/*
	 * 14.4 — the flag is the whole gate, and off must mean untouched.
	 *
	 * Provision a second account with providers.google.email_identity off and assert
	 * no email row exists for its address. Byte-identical to pre-14.4 behaviour is the
	 * property; a site that does not want its addresses becoming login identifiers has
	 * to be able to say so and be obeyed.
	 */
	$off_was = \SmartLogin\Settings::get( 'providers.google.email_identity' );
	\SmartLogin\Settings::update( array( 'providers.google.email_identity' => 0 ) );

	$off_identity = new \SmartLogin\Auth\Providers\ProviderIdentity(
		array(
			'provider'       => 'google',
			'subject'        => 'flag-off-' . wp_generate_uuid4(),
			'email'          => 'flag.off.' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test',
			'email_verified' => true,
			'display_name'   => 'Flag Off User',
		)
	);
	$off_resolved = ( new \SmartLogin\Auth\AccountProvisioner() )->resolve( $off_identity, array() );
	if ( is_wp_error( $off_resolved ) ) {
		\SmartLogin\Settings::update( array( 'providers.google.email_identity' => $off_was ) );
		$failed( 'provisioning with the email-identity flag off failed: ' . $off_resolved->get_error_code() );
	}
	$off_user_id    = (int) $off_resolved['user']->ID;
	$cleanup_ids[]  = $off_user_id;
	$cleanup_rows[] = array( 'provider' => 'google', 'subject' => $off_identity->subject, 'user_id' => $off_user_id );

	$off_row = ( new \SmartLogin\Identity\IdentityRepository() )->find(
		( new \SmartLogin\Identity\ChannelRegistry() )->claim( 'email', $off_identity->email )
	);

	\SmartLogin\Settings::update( array( 'providers.google.email_identity' => $off_was ) );

	if ( $off_row ) {
		$failed( 'the email-identity flag was off and an email identity was claimed anyway' );
	}

	// An address the provider did not mark verified must never earn a row, flag or no
	// flag: the flag says whose assertion is trusted, not that one can be skipped.
	$unverified = new \SmartLogin\Auth\Providers\ProviderIdentity(
		array(
			'provider'       => 'google',
			'subject'        => 'unverified-' . wp_generate_uuid4(),
			'email'          => 'unverified.' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test',
			'email_verified' => false,
			'display_name'   => 'Unverified Email User',
		)
	);
	$unverified_resolved = ( new \SmartLogin\Auth\AccountProvisioner() )->resolve( $unverified, array() );
	if ( is_wp_error( $unverified_resolved ) ) {
		$failed( 'provisioning an unverified-email identity failed: ' . $unverified_resolved->get_error_code() );
	}
	$unverified_id  = (int) $unverified_resolved['user']->ID;
	$cleanup_ids[]  = $unverified_id;
	$cleanup_rows[] = array( 'provider' => 'google', 'subject' => $unverified->subject, 'user_id' => $unverified_id );

	if ( ( new \SmartLogin\Identity\IdentityRepository() )->find(
		( new \SmartLogin\Identity\ChannelRegistry() )->claim( 'email', $unverified->email )
	) ) {
		$failed( 'an address the provider did not verify earned an email identity' );
	}

	// P0.3: verified-email auto-link to an existing non-synthetic account.
	$existing_id = wp_insert_user(
		array(
			'user_login'   => 'sl_autolink_' . strtolower( wp_generate_password( 8, false, false ) ),
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => 'Auto.Link@example.test',
			'display_name' => 'Existing Auto Link User',
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $existing_id ) ) {
		$failed( 'could not create auto-link fixture user' );
	}
	$cleanup_ids[] = (int) $existing_id;
	$auto_identity = new \SmartLogin\Auth\Providers\ProviderIdentity(
		array(
			'provider'       => 'google',
			'subject'        => 'auto-link-' . wp_generate_uuid4(),
			'email'          => 'AUTO.LINK@example.test',
			'email_verified' => true,
			'display_name'   => 'Auto Link Provider',
		)
	);
	$auto_resolved = ( new \SmartLogin\Auth\AccountProvisioner() )->resolve( $auto_identity, array( 'linking' => false ) );
	if ( is_wp_error( $auto_resolved ) || (int) $auto_resolved['user']->ID !== (int) $existing_id || ! empty( $auto_resolved['context']->is_new_user ) ) {
		$failed( 'verified-email auto-link did not resolve to the existing user' );
	}
	$cleanup_rows[] = array( 'provider' => 'google', 'subject' => $auto_identity->subject, 'user_id' => (int) $existing_id );
	// 14.4 adopts on this branch too, so the email row is this run's to clean up.
	$cleanup_rows[] = array( 'provider' => 'email', 'subject' => strtolower( (string) $auto_identity->email ), 'user_id' => (int) $existing_id );
	$linked = ( new \SmartLogin\Identity\IdentityRepository() )->find(
		\SmartLogin\Identity\Claim::canonical( 'google', $auto_identity->subject )
	);
	if ( ! $linked || \SmartLogin\Identity\IdentityRecord::BY_AUTO_EMAIL !== $linked->linked_by() ) {
		$failed( 'auto-link identity was not persisted with the expected audit reason' );
	}

	// Two users with the same email must fail closed instead of selecting one.
	$conflict_one = wp_insert_user(
		array(
			'user_login'   => 'sl_conflict_one_' . strtolower( wp_generate_password( 8, false, false ) ),
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => 'conflict@example.test',
			'display_name' => 'Conflict One',
			'role'         => 'subscriber',
		)
	);
	$conflict_two = wp_insert_user(
		array(
			'user_login'   => 'sl_conflict_two_' . strtolower( wp_generate_password( 8, false, false ) ),
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => 'conflict-two@example.test',
			'display_name' => 'Conflict Two',
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $conflict_one ) || is_wp_error( $conflict_two ) ) {
		$failed( 'could not create duplicate-email conflict fixtures' );
	}
	$cleanup_ids[] = (int) $conflict_one;
	$cleanup_ids[] = (int) $conflict_two;
	$wpdb->update( $wpdb->users, array( 'user_email' => 'conflict@example.test' ), array( 'ID' => (int) $conflict_two ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	clean_user_cache( (int) $conflict_two );
	$conflict_identity = new \SmartLogin\Auth\Providers\ProviderIdentity(
		array(
			'provider'       => 'google',
			'subject'        => 'conflict-' . wp_generate_uuid4(),
			'email'          => 'CONFLICT@example.test',
			'email_verified' => true,
			'display_name'   => 'Conflict Provider',
		)
	);
	$conflict_result = ( new \SmartLogin\Auth\AccountProvisioner() )->resolve( $conflict_identity, array( 'linking' => false ) );
	if ( ! is_wp_error( $conflict_result ) || 'smart_login_provider_conflict' !== $conflict_result->get_error_code() ) {
		$failed( 'duplicate verified email did not fail closed with provider conflict' );
	}

	/*
	 * Zalo Login was removed. What replaces its half of this gate is the removal
	 * itself, asserted against a real WordPress rather than believed.
	 *
	 * The rule that greps the shipped source lives in `run-fitness-tests.php` and
	 * answers "is the code gone". These answer the questions it cannot: does the
	 * runtime still offer the provider, and does an install that *had* it get its
	 * stored state cleaned up.
	 */
	if ( null !== ( new \SmartLogin\Auth\Providers\ProviderRegistry() )->get( 'zalo' ) ) {
		$failed( 'a removed provider is still resolvable from the registry' );
	}
	if ( array_keys( ( new \SmartLogin\Auth\Providers\ProviderRegistry() )->available() ) !== array( 'google' ) ) {
		$failed( 'the registry offers something other than the one shipped provider' );
	}

	/*
	 * The upgrade path, which had never run anywhere.
	 *
	 * A site that used Zalo holds two things no screen can reach any more: its
	 * settings block, and its sealed secret. Seeded here exactly as such a site
	 * would hold them, then `maybe_upgrade()` is made to run by resetting the
	 * version option — the same trigger a real upgrade uses.
	 */
	$sl_removed_settings = get_option( \SmartLogin\Settings::OPTION, array() );
	$sl_removed_settings['providers']['zalo'] = array(
		'enabled' => 1,
		'app_id'  => 'left-over-app-id',
	);
	update_option( \SmartLogin\Settings::OPTION, $sl_removed_settings );
	\SmartLogin\Security\SecretBox::put( \SmartLogin\Auth\Providers\ProviderCredentials::SECRET_OPTION, 'zalo', 'left-over-secret' );
	\SmartLogin\Settings::flush_cache();

	if ( '' === \SmartLogin\Security\SecretBox::get( \SmartLogin\Auth\Providers\ProviderCredentials::SECRET_OPTION, 'zalo' ) ) {
		$failed( 'the leftover fixture did not seal, so the cleanup below would pass for the wrong reason' );
	}

	update_option( \SmartLogin\Installer::DB_VERSION_OPTION, '0' );
	\SmartLogin\Installer::maybe_upgrade();
	\SmartLogin\Settings::flush_cache();

	$sl_after = get_option( \SmartLogin\Settings::OPTION, array() );

	if ( isset( $sl_after['providers']['zalo'] ) ) {
		$failed( 'the removed provider kept its settings block through an upgrade' );
	}
	if ( '' !== \SmartLogin\Security\SecretBox::get( \SmartLogin\Auth\Providers\ProviderCredentials::SECRET_OPTION, 'zalo' ) ) {
		$failed( 'the removed provider kept a sealed secret no screen can clear' );
	}
	if ( ! isset( $sl_after['providers']['google'] ) || ! array_key_exists( 'auto_link_email', $sl_after['providers'] ) ) {
		$failed( 'the cleanup took the shipped provider or the shared policy with it' );
	}

} catch ( Throwable $exception ) {
	$failed( 'provider gate raised an exception: ' . $exception->getMessage() );
}

$cleanup();
echo "SMART_LOGIN_GOOGLE_STAGING_SMOKE_OK\n";
echo "SMART_LOGIN_PROVIDER_LINKING_OK\n";
echo "SMART_LOGIN_PROVIDER_REMOVAL_OK\n";
