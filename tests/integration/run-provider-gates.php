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
define( 'SMART_LOGIN_ZALO_APP_ID', (string) ( getenv( 'SMART_LOGIN_ZALO_APP_ID' ) ?: 'smart-login-zalo-staging-app' ) );
define( 'SMART_LOGIN_ZALO_APP_SECRET', (string) ( getenv( 'SMART_LOGIN_ZALO_APP_SECRET' ) ?: 'zalo-staging-fixture-secret' ) );
define( 'SMART_LOGIN_ZALO_REDIRECT_URI', 'https://example.test/wp-admin/admin-post.php?action=smart_login_provider_callback&provider=zalo' );
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
 * on an install with `profile.email_optional = 1` it failed with "Zalo
 * provider-only account did not enter required email onboarding", and on an
 * install with 0 it would have passed for the wrong reason.
 *
 * Asserted below rather than trusted, because that is the failure this had.
 */
$sl_forced_settings = array(
	'providers.google.enabled' => 1,
	'providers.zalo.enabled'   => 1,
	'providers.auto_link_email' => 1,
	'profile.email_optional'   => 0,
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
	if ( (int) \SmartLogin\Settings::get( $sl_key ) !== (int) $sl_expected ) {
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
			'provider'       => 'zalo',
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

	// P0.4: Zalo Login with PKCE, provider-only provisioning, and no token persistence.
	$zalo_provider = new \SmartLogin\Auth\Providers\ZaloProvider();
	if ( ! $zalo_provider->is_available() ) {
		$failed( 'Zalo provider did not become available with staging configuration' );
	}
	$zalo_redirect = $zalo_provider->begin( 'https://example.test/account', false );
	$zalo_query    = array();
	parse_str( (string) wp_parse_url( $zalo_redirect->url, PHP_URL_QUERY ), $zalo_query );
	$zalo_state = (string) ( $zalo_query['state'] ?? '' );
	$zalo_transaction = get_transient( \SmartLogin\Auth\OAuthTransactionStore::PREFIX . $zalo_state );
	if ( ! is_array( $zalo_transaction ) ) {
		$failed( 'Zalo OAuth transaction was not persisted' );
	}
	$expected_challenge = \SmartLogin\Auth\OAuthTransactionStore::challenge( (string) $zalo_transaction['pkce_verifier'] );
	if (
		$expected_challenge !== (string) ( $zalo_query['code_challenge'] ?? '' )
		|| 'S256' !== (string) ( $zalo_query['code_challenge_method'] ?? '' )
	) {
		$failed( 'Zalo authorization redirect did not bind the PKCE challenge' );
	}
	$zalo_consumed = ( new \SmartLogin\Auth\OAuthTransactionStore() )->consume( $zalo_state, 'zalo' );
	if ( is_wp_error( $zalo_consumed ) ) {
		$failed( 'Zalo OAuth state could not be consumed' );
	}

	$zalo_mode = 'success';
	$zalo_pkce_seen = false;
	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) use ( &$zalo_mode, &$zalo_pkce_seen, $zalo_consumed ) {
			if ( false !== strpos( $url, 'oauth.zaloapp.com/v4/access_token' ) ) {
				$zalo_pkce_seen = hash_equals(
					(string) $zalo_consumed['pkce_verifier'],
					(string) ( $args['body']['code_verifier'] ?? '' )
				);
				if ( 'token_error' === $zalo_mode ) {
					return array(
						'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'error' => -14014 ) ),
					);
				}
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'access_token' => 'zalo-fixture-access', 'expires_in' => 3600 ) ),
				);
			}
			if ( false !== strpos( $url, 'graph.zalo.me/v2.0/me' ) ) {
				if ( false === strpos( $url, 'access_token=zalo-fixture-access' ) ) {
					return new \WP_Error( 'zalo_fixture_token', 'Missing fixture access token' );
				}
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'id'      => 'zalo-staging-subject-' . wp_generate_uuid4(),
								'name'    => 'Zalo Staging User',
								'picture' => array( 'data' => array( 'url' => 'https://example.test/zalo-avatar.png' ) ),
							),
						)
					),
				);
			}
			return $preempt;
		},
		10,
		3
	);

	$zalo_identity = $zalo_provider->complete( array( 'code' => 'zalo-fixture-code', '_transaction' => $zalo_consumed ) );
	if ( is_wp_error( $zalo_identity ) ) {
		$failed( 'Zalo callback fixture failed: ' . $zalo_identity->get_error_code() );
	}
	if ( ! $zalo_pkce_seen || 'zalo' !== $zalo_identity->provider || '' === $zalo_identity->subject || '' !== $zalo_identity->email ) {
		$failed( 'Zalo profile mapping or PKCE token request is invalid' );
	}
	if ( ! is_wp_error( ( new \SmartLogin\Auth\OAuthTransactionStore() )->consume( $zalo_state, 'zalo' ) ) ) {
		$failed( 'Zalo OAuth state was reusable after consumption' );
	}

	$zalo_resolved = ( new \SmartLogin\Auth\AccountProvisioner() )->resolve( $zalo_identity, $zalo_consumed );
	if ( is_wp_error( $zalo_resolved ) || empty( $zalo_resolved['context']->is_new_user ) ) {
		$failed( 'Zalo provider-only provisioning did not create a new user' );
	}
	$zalo_user_id = (int) $zalo_resolved['user']->ID;
	$cleanup_ids[]  = $zalo_user_id;
	$cleanup_rows[] = array( 'provider' => 'zalo', 'subject' => $zalo_identity->subject, 'user_id' => $zalo_user_id );
	if (
		! \SmartLogin\Identity\UserManager::is_synthetic_email( (string) $zalo_resolved['user']->user_email )
		|| ! get_user_meta( $zalo_user_id, \SmartLogin\Identity\UserManager::META_SYNTHETIC, true )
	) {
		$failed( 'Zalo provider-only account was not marked with a synthetic email' );
	}
	$zalo_status = ( new \SmartLogin\Auth\ProfileCompletionService() )->status( $zalo_user_id );
	$required_keys = array_column( $zalo_status['required_missing'], 'key' );
	if ( ! in_array( 'email', $required_keys, true ) ) {
		$failed( 'Zalo provider-only account did not enter required email onboarding' );
	}
	$zalo_row = ( new \SmartLogin\Identity\IdentityRepository() )->find(
		\SmartLogin\Identity\Claim::canonical( 'zalo', $zalo_identity->subject )
	);
	if ( ! $zalo_row || false !== strpos( (string) wp_json_encode( $zalo_row->meta() ), 'zalo-fixture-access' ) ) {
		$failed( 'Zalo identity persistence is missing or contains an access token' );
	}

	// A rejected token exchange must return an error and consume state once.
	$zalo_failure_redirect = $zalo_provider->begin( '', false );
	$zalo_failure_query = array();
	parse_str( (string) wp_parse_url( $zalo_failure_redirect->url, PHP_URL_QUERY ), $zalo_failure_query );
	$zalo_failure_state = (string) ( $zalo_failure_query['state'] ?? '' );
	$zalo_failure_transaction = ( new \SmartLogin\Auth\OAuthTransactionStore() )->consume( $zalo_failure_state, 'zalo' );
	if ( is_wp_error( $zalo_failure_transaction ) ) {
		$failed( 'Zalo failure fixture state could not be consumed' );
	}
	$zalo_mode = 'token_error';
	$zalo_failure = $zalo_provider->complete( array( 'code' => 'rejected-code', '_transaction' => $zalo_failure_transaction ) );
	if ( ! is_wp_error( $zalo_failure ) || 'smart_login_zalo_token' !== $zalo_failure->get_error_code() ) {
		$failed( 'Zalo rejected token exchange did not fail closed' );
	}
	if ( ! is_wp_error( ( new \SmartLogin\Auth\OAuthTransactionStore() )->consume( $zalo_failure_state, 'zalo' ) ) ) {
		$failed( 'Rejected Zalo callback left a reusable OAuth state' );
	}
} catch ( Throwable $exception ) {
	$failed( 'provider gate raised an exception: ' . $exception->getMessage() );
}

$cleanup();
echo "SMART_LOGIN_GOOGLE_STAGING_SMOKE_OK\n";
echo "SMART_LOGIN_PROVIDER_LINKING_OK\n";
echo "SMART_LOGIN_ZALO_STAGING_SMOKE_OK\n";
