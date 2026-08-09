<?php
/**
 * Resolves, auto-links or creates a WordPress user for an external identity.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Auth\Providers\ProviderIdentity;
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\ChannelRegistry;
use SmartLogin\Identity\Claim;
use SmartLogin\Identity\IdentityDirectory;
use SmartLogin\Identity\IdentityRecord;
use SmartLogin\Identity\IdentityRepository;
use SmartLogin\Identity\OpaqueLogin;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\ProfileSeeder;
use SmartLogin\Identity\UserManager;
use SmartLogin\Identity\VerifiedClaim;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AccountProvisioner {

	/**
	 * Which setting decides whether a provider's verified email earns a row.
	 *
	 * A map of literal paths rather than `'providers.' . $slug . '.email_identity'`.
	 * Phase 9's rule 8 went red on the concatenated form — correctly: a built dot
	 * path that misses resolves to the fallback in silence, which is how the
	 * configured retention was ignored for months. The literals stay greppable, and
	 * the abuse suite asserts each one is declared by `FieldRegistry`.
	 *
	 * A provider absent from this map gets no row. Fail closed: adding a provider
	 * should not silently widen how its accounts can be reached.
	 */
	const EMAIL_IDENTITY_FLAG = array(
		'google' => 'providers.google.email_identity',
	);

	private IdentityRepository $identities;

	public function __construct( ?IdentityRepository $identities = null ) {
		$this->identities = $identities ?? new IdentityRepository();
	}

	/**
	 * Built from the injected repository, not from a fresh one: a caller that
	 * supplied its own storage must not find half this class writing somewhere else.
	 */
	private function directory(): IdentityDirectory {
		return new IdentityDirectory( $this->identities );
	}

	/**
	 * A federated provider is just another identity channel now.
	 */
	private function claim_for( ProviderIdentity $identity ): Claim {
		return Claim::canonical( $identity->provider, $identity->subject );
	}

	/** @return array{user:\WP_User,context:AuthContext}|WP_Error */
	public function resolve( ProviderIdentity $identity, array $transaction ) {
		if ( '' === $identity->provider || '' === $identity->subject || strlen( $identity->subject ) > 191 ) {
			return new WP_Error( 'smart_login_provider_identity', __( 'Nhà cung cấp đăng nhập không trả về định danh hợp lệ.', 'smart-login' ) );
		}

		$existing = $this->identities->find( $this->claim_for( $identity ) );
		if ( $existing ) {
			$user = get_userdata( $existing->user_id() );
			if ( ! $user ) {
				return new WP_Error( 'smart_login_provider_orphan', __( 'Liên kết tài khoản không còn hợp lệ.', 'smart-login' ) );
			}
			if ( ! empty( $transaction['linking'] ) && (int) ( $transaction['user_id'] ?? 0 ) !== (int) $user->ID ) {
				return new WP_Error( 'smart_login_provider_conflict', __( 'Tài khoản nhà cung cấp đã liên kết với người dùng khác.', 'smart-login' ) );
			}
			return array(
				'user'    => $user,
				'context' => $this->context( $identity, (int) $user->ID, false, ! empty( $transaction['linking'] ) ),
			);
		}

		$linking      = ! empty( $transaction['linking'] );
		$link_user_id = (int) ( $transaction['user_id'] ?? 0 );
		if ( $linking ) {
			if ( $link_user_id <= 0 || get_current_user_id() !== $link_user_id ) {
				return new WP_Error( 'smart_login_provider_link_auth', __( 'Phiên liên kết tài khoản không còn hợp lệ.', 'smart-login' ) );
			}
			$user = get_userdata( $link_user_id );
			if ( ! $user ) {
				return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
			}
			if ( $identity->email_verified && '' !== $identity->email ) {
				$email_owner = get_user_by( 'email', $identity->email );
				if ( $email_owner && (int) $email_owner->ID !== $link_user_id ) {
					return new WP_Error( 'smart_login_provider_conflict', __( 'Email của nhà cung cấp đã thuộc về tài khoản khác.', 'smart-login' ) );
				}
			}
			if ( ! $this->link( $identity, (int) $user->ID, IdentityRecord::BY_OAUTH ) ) {
				return new WP_Error( 'smart_login_provider_link', __( 'Không thể liên kết tài khoản nhà cung cấp.', 'smart-login' ) );
			}
			return array(
				'user'    => $user,
				'context' => $this->context( $identity, (int) $user->ID, false, true ),
			);
		}

		if ( Settings::is_on( 'providers.auto_link_email' ) && $identity->email_verified && '' !== $identity->email ) {
			global $wpdb;
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no core API matches an email across users case-insensitively, and the answer decides account linking so it must not be cached.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s ORDER BY ID ASC",
					strtolower( $identity->email )
				)
			);
			// The only route to Resolution::CONFLICT in the whole system:
			// smartlogin_identities cannot be ambiguous, but two WordPress users
			// sharing an address can be. Fail closed rather than pick one.
			if ( count( $ids ) > 1 ) {
				return new WP_Error( 'smart_login_provider_conflict', __( 'Email đang thuộc về nhiều tài khoản. Không thể tự động liên kết.', 'smart-login' ) );
			}
			$user = 1 === count( $ids ) ? get_userdata( (int) $ids[0] ) : null;
			if ( $user && ! UserManager::is_synthetic_email( (string) $user->user_email ) ) {
				if ( ! $this->link( $identity, (int) $user->ID, IdentityRecord::BY_AUTO_EMAIL ) ) {
					return new WP_Error( 'smart_login_provider_link', __( 'Không thể tự động liên kết tài khoản hiện có.', 'smart-login' ) );
				}
				// This branch has already decided the address identifies this account —
				// that is the whole of what auto-linking means. Leaving it without a row
				// would rebuild the same split inside the one place that has judged it.
				$this->adopt_provider_email( $identity, (int) $user->ID );
				return array(
					'user'    => $user,
					'context' => $this->context( $identity, (int) $user->ID, false, false ),
				);
			}
		}

		$user = $this->create_provider_user( $identity );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! $this->link( $identity, (int) $user->ID, IdentityRecord::BY_REGISTRATION ) ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( (int) $user->ID );
			$race = $this->identities->find( $this->claim_for( $identity ) );
			if ( $race ) {
				$race_user = get_userdata( $race->user_id() );
				if ( $race_user ) {
					return array(
						'user'    => $race_user,
						'context' => $this->context( $identity, (int) $race_user->ID, false, false ),
					);
				}
			}
			return new WP_Error( 'smart_login_provider_link', __( 'Không thể lưu liên kết tài khoản nhà cung cấp.', 'smart-login' ) );
		}

		$this->adopt_provider_email( $identity, (int) $user->ID );

		return array(
			'user'    => $user,
			'context' => $this->context( $identity, (int) $user->ID, true, false ),
		);
	}

	/**
	 * Let a provider-verified address become an identity of its own.
	 *
	 * The defect this repairs: `create_provider_user()` writes the verified address
	 * into `wp_users.user_email`, which core resolves at `authenticate` priority 20,
	 * but linked only the federated claim. One fact — this account owns this address
	 * — lived in two stores, and they disagreed. Typing that address the next day
	 * bought a registration OTP and a refusal two steps later; recovery said the
	 * address was never registered; `wp-login.php` alone found the account, and asked
	 * for a password the holder has never seen.
	 *
	 * Per provider, because the guarantees differ. Google asserts `email_verified`;
	 * a provider that does not is absent from EMAIL_IDENTITY_FLAG and gets no row,
	 * which is the fail-closed reading.
	 *
	 * Deliberately **after** the federated row is claimed, and deliberately not
	 * fatal. The email row is an addition; a provider login that cannot have one must
	 * still work exactly as it did. A failure here leaves the account in the state
	 * every provider account was in before this sub-phase, which is the state the
	 * rest of the phase is built to tolerate.
	 */
	private function adopt_provider_email( ProviderIdentity $identity, int $user_id ): void {
		if ( ! $identity->email_verified || '' === $identity->email ) {
			return;
		}

		$flag = self::EMAIL_IDENTITY_FLAG[ $identity->provider ] ?? '';

		if ( '' === $flag || ! Settings::is_on( $flag ) ) {
			return;
		}

		$claim = ( new ChannelRegistry() )->claim( MailChannel::ID, $identity->email );

		if ( $claim->is_empty() ) {
			return;
		}

		$adopted = UserManager::adopt_verified_email(
			$user_id,
			VerifiedClaim::from( $claim, VerifiedClaim::PROOF_OAUTH ),
			IdentityRecord::BY_OAUTH,
			$this->directory()
		);

		if ( is_wp_error( $adopted ) ) {
			AuditLog::record(
				AuditLog::PROVIDER_FAILED,
				RateLimiter::mask_identity( $identity->email ),
				array(
					'provider' => $identity->provider,
					'stage'    => 'email_identity',
					'code'     => $adopted->get_error_code(),
				),
				$user_id
			);
		}
	}

	private function create_provider_user( ProviderIdentity $identity ) {
		// One opaque token, used for both the login and the placeholder mailbox,
		// so neither can be derived from the provider subject. Generated before
		// the email so the two agree.
		$opaque = OpaqueLogin::generate();
		$email  = $identity->email_verified && '' !== $identity->email
			? $identity->email
			: UserManager::synthetic_email( $opaque );

		if ( email_exists( $email ) ) {
			return new WP_Error( 'smart_login_exists', __( 'Tài khoản đã tồn tại.', 'smart-login' ) );
		}
		$login   = $opaque;
		$names   = UserManager::split_name( $identity->display_name );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => '' !== $identity->display_name ? $identity->display_name : $login,
				'first_name'   => $names['first'],
				'last_name'    => $names['last'],
				'role'         => UserManager::default_role(),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$now = current_time( 'mysql', true );
		if ( $identity->email_verified && '' !== $identity->email ) {
			update_user_meta( $user_id, UserManager::META_EMAIL_VERIFIED, $now );
			ProfileSeeder::seed_if_empty( (int) $user_id, 'billing_email', $identity->email );
		} else {
			update_user_meta( $user_id, UserManager::META_SYNTHETIC, 1 );
		}
		if ( $identity->phone_verified && '' !== $identity->phone ) {
			$phone = Phone::normalize( $identity->phone );
			if ( Phone::is_valid( $phone ) ) {
				update_user_meta( $user_id, UserManager::META_PHONE, $phone );
				update_user_meta( $user_id, UserManager::META_PHONE_VERIFIED, $now );
			}
		}

		$user = get_userdata( (int) $user_id );
		return $user ?: new WP_Error( 'smart_login_provider_user', __( 'Không thể tải tài khoản vừa tạo.', 'smart-login' ) );
	}

	/**
	 * Take ownership of the provider subject.
	 *
	 * The OAuth exchange already proved control, so the claim is verified before
	 * it reaches the repository — an unproven subject has no route into the
	 * identities table.
	 *
	 * Note what is NOT recorded here: the provider's email. An email address is
	 * an identity in the 'email' channel, not an attribute of a federated one.
	 * Phase 3 decides when a verified provider email earns its own row; until
	 * then it stays in meta_json as forensic context only.
	 */
	private function link( ProviderIdentity $identity, int $user_id, string $linked_by ): bool {
		$record = IdentityRecord::create(
			$user_id,
			VerifiedClaim::from( $this->claim_for( $identity ), VerifiedClaim::PROOF_OAUTH ),
			$linked_by,
			false,
			$identity->claims
		);

		$ok = $this->identities->claim( $record );

		if ( $ok ) {
			AuditLog::record(
				AuditLog::PROVIDER_LINKED,
				'',
				array(
					'provider'  => $identity->provider,
					'linked_by' => $linked_by,
				),
				$user_id
			);
		}

		return $ok;
	}

	private function context( ProviderIdentity $identity, int $user_id, bool $is_new, bool $is_linking ): AuthContext {
		return new AuthContext(
			array(
				'auth_method'      => $identity->provider,
				'provider'         => $identity->provider,
				'provider_subject' => $identity->subject,
				'user_id'          => $user_id,
				'is_new_user'      => $is_new,
				'is_linking'       => $is_linking,
				'email'            => $identity->email,
				'email_verified'   => $identity->email_verified,
				'phone'            => $identity->phone,
				'phone_verified'   => $identity->phone_verified,
			)
		);
	}
}
