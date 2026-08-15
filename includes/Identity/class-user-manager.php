<?php
/**
 * Account creation and profile meta handling.
 *
 * @package OmniWP
 */

namespace OmniWP\Identity;

use OmniWP\Identity\Channels\MailChannel;
use OmniWP\Identity\Channels\PhoneChannel;
use OmniWP\Security\SecurityMeta;
use OmniWP\Settings;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class UserManager {

	const META_PHONE          = 'OmniWP_phone';
	const META_PHONE_VERIFIED = 'OmniWP_phone_verified_at';
	const META_EMAIL_VERIFIED = 'OmniWP_email_verified_at';
	const META_DOB            = 'OmniWP_dob';
	const META_GENDER         = 'OmniWP_gender';
	const META_SYNTHETIC      = 'OmniWP_synthetic_email';

	/**
	 * Build the placeholder address used when an account has no real inbox.
	 *
	 * `.invalid` is reserved by RFC 2606 and can never resolve, which is the
	 * point: nothing should ever try to deliver mail here.
	 *
	 * The local part is the account's opaque login, NOT its phone number. Two
	 * reasons, both of which cost nothing here and matter a lot:
	 *
	 *  1. WordPress core resolves user_email at `authenticate` priority 20, so a
	 *     derivable placeholder is a typeable identifier that bypasses
	 *     IdentityDirectory entirely.
	 *  2. It never has to change. A phone-derived placeholder goes stale the
	 *     moment the user changes their number, leaving the retired number
	 *     reachable through the email path — the same defect in a new place.
	 *
	 * @param string $opaque_token Output of OpaqueLogin::generate().
	 */
	public static function synthetic_email( string $opaque_token, string $phone = '' ): string {
		$domain = (string) Settings::get( 'identity.synthetic_domain', 'phone.invalid' );
		$prefix = $opaque_token;

		if ( '' !== $phone ) {
			$clean_phone = Phone::normalize( $phone );
			if ( '' !== $clean_phone ) {
				$prefix = $clean_phone;
			}
		}

		$email = $prefix . '@' . $domain;

		/**
		 * Filter the generated placeholder email.
		 *
		 * @param string $email
		 * @param string $opaque_token
		 * @param string $phone
		 */
		return (string) apply_filters( 'OMNIWP_synthetic_email', $email, $opaque_token, $phone );
	}

	/**
	 * Is this address a placeholder rather than something a human reads?
	 */
	public static function is_synthetic_email( string $email ): bool {
		$domain = (string) Settings::get( 'identity.synthetic_domain', 'phone.invalid' );

		$is = ( '' !== $domain && str_ends_with( strtolower( $email ), '@' . strtolower( $domain ) ) );

		/**
		 * @param bool   $is
		 * @param string $email
		 */
		return (bool) apply_filters( 'OMNIWP_is_synthetic_email', $is, $email );
	}

	public static function user_has_synthetic_email( int $user_id ): bool {
		$user = get_userdata( $user_id );

		return $user ? self::is_synthetic_email( $user->user_email ) : false;
	}

	/**
	 * Create a fully verified account from a pending registration payload.
	 *
	 * The password arrives pre-hashed (see RegisterHandler) so the plaintext
	 * never touches the database or the OTP row. wp_insert_user() insists on
	 * hashing whatever it is given, so we write a throwaway password first and
	 * swap in the real hash immediately afterwards.
	 *
	 * @param VerifiedClaim $claim The proven identity this account is built on.
	 * @param array         $data {
	 *     @type string $pass_hash Output of wp_hash_password().
	 *     @type string $full_name
	 *     @type string $dob
	 *     @type string $gender
	 * }
	 * @return int|WP_Error New user ID.
	 */
	public static function create_verified_user( VerifiedClaim $claim, array $data = array(), ?IdentityDirectory $directory = null ) {
		$directory = $directory ?? new IdentityDirectory();
		$channel   = $claim->channel();
		$subject   = $claim->subject();

		if ( '' === $subject ) {
			return new WP_Error( 'OMNIWP_no_identity', __( 'Thiếu thông tin định danh.', 'omniwp' ) );
		}

		// Ownership is the directory's question, not a uniqueness check here.
		if ( $directory->resolve( $claim->claim() )->has_owner() ) {
			return new WP_Error( 'OMNIWP_exists', __( 'Tài khoản đã tồn tại.', 'omniwp' ) );
		}

		// One opaque token serves as both the login and the placeholder mailbox,
		// so neither is derivable from anything the user typed.
		$login    = OpaqueLogin::generate();
		$is_email = MailChannel::ID === $channel;
		$mail     = $is_email ? $subject : self::synthetic_email( $login, PhoneChannel::ID === $channel ? $subject : '' );

		if ( ! $is_email && email_exists( $mail ) ) {
			// Fall back to opaque login token if phone-derived placeholder exists.
			$mail = self::synthetic_email( $login );
		}

		if ( email_exists( $mail ) ) {
			return new WP_Error( 'OMNIWP_exists', __( 'Tài khoản đã tồn tại.', 'omniwp' ) );
		}

		$full_name = trim( (string) ( $data['full_name'] ?? '' ) );
		$names     = self::split_name( $full_name );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $mail,
				'user_pass'    => wp_generate_password( 64, true, true ), // Replaced below.
				'display_name' => '' !== $full_name ? $full_name : $login,
				'first_name'   => $names['first'],
				'last_name'    => $names['last'],
				// Hard-coded: never trust a posted role.
				'role'         => self::default_role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// The identity row is the source of truth, so it has to succeed. Losing a
		// race here means another request claimed the subject in between; roll the
		// half-built account back rather than leave it stranded without identity.
		if ( ! $directory->link( (int) $user_id, $claim, IdentityRecord::BY_REGISTRATION, true ) ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( (int) $user_id );

			return new WP_Error( 'OMNIWP_exists', __( 'Tài khoản đã tồn tại.', 'omniwp' ) );
		}

		self::apply_password_hash( (int) $user_id, (string) ( $data['pass_hash'] ?? '' ) );

		$now = current_time( 'mysql', true );

		// Everything below is a DERIVED MIRROR of the identity row. Nothing reads it to
		// answer "who owns this subject" — see Invariant 1.
		//
		// This used to say the keys were documented in README as a public contract for
		// other plugins. They are not: README names OmniWP_ward_code and nothing
		// else of this family. Found in 14.2, corrected in 15.4 — a comment claiming a
		// contract that does not exist is the same defect as a README doing it.
		if ( PhoneChannel::ID === $channel ) {
			update_user_meta( $user_id, self::META_PHONE, $subject );
			update_user_meta( $user_id, self::META_PHONE_VERIFIED, $now );

			if ( Settings::is_on( 'woo.sync_billing_phone' ) ) {
				ProfileSeeder::seed_if_empty( (int) $user_id, 'billing_phone', Phone::to_local( $subject ) );
			}
		}

		if ( self::is_synthetic_email( $mail ) ) {
			update_user_meta( $user_id, self::META_SYNTHETIC, 1 );
		} elseif ( $is_email ) {
			update_user_meta( $user_id, self::META_EMAIL_VERIFIED, $now );
			ProfileSeeder::seed_if_empty( (int) $user_id, 'billing_email', $mail );
		}

		if ( ! empty( $data['dob'] ) ) {
			update_user_meta( $user_id, self::META_DOB, sanitize_text_field( $data['dob'] ) );
		}

		if ( ! empty( $data['gender'] ) ) {
			update_user_meta( $user_id, self::META_GENDER, sanitize_key( $data['gender'] ) );
		}

		if ( '' !== $names['first'] ) {
			ProfileSeeder::seed_many(
				(int) $user_id,
				array(
					'billing_first_name'  => $names['first'],
					'billing_last_name'   => $names['last'],
					'shipping_first_name' => $names['first'],
					'shipping_last_name'  => $names['last'],
				)
			);
		}

		return (int) $user_id;
	}

	/**
	 * The only writer of "this account owns this address".
	 *
	 * Five things say that, and before this method existed three call sites each
	 * wrote a different subset of them: the contact-verification flow wrote all
	 * five, `AccountProvisioner` wrote two and skipped the identity row — which is
	 * the whole of Phase 14's defect — and `WooIntegration` wrote two as
	 * housekeeping. One function decides now, so writing three fifths of the fact
	 * is not something a caller can do by accident.
	 *
	 * The order is load-bearing and asserted. The directory write is the one that
	 * can lose a race, so it happens first: a subject claimed by somebody else in
	 * between must fail before `user_email` has moved. Doing it the other way round
	 * leaves an account whose address disagrees with its identity, which is the
	 * state this phase exists to remove.
	 *
	 * A `VerifiedClaim` rather than a string plus a proof constant: the type is the
	 * gate. Only the PROVE layer can produce one, so there is no signature here that
	 * an unproven address fits through.
	 *
	 * @return true|WP_Error
	 */
	public static function adopt_verified_email( int $user_id, VerifiedClaim $claim, string $linked_by = IdentityRecord::BY_OTP, ?IdentityDirectory $directory = null ) {
		if ( MailChannel::ID !== $claim->channel() ) {
			return new WP_Error( 'OMNIWP_not_an_email', __( 'Kênh không phải email.', 'omniwp' ) );
		}

		$address   = $claim->subject();
		$directory = $directory ?? new IdentityDirectory();

		if ( ! $directory->replace_in_channel( $user_id, $claim, $linked_by ) ) {
			return new WP_Error( 'OMNIWP_contact_exists', __( 'Không thể cập nhật thông tin liên hệ.', 'omniwp' ) );
		}

		$updated = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $address,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// Derived mirrors below; see create_verified_user() for why they exist at
		// all. Nothing resolves ownership from them.
		update_user_meta( $user_id, self::META_EMAIL_VERIFIED, current_time( 'mysql', true ) );
		delete_user_meta( $user_id, self::META_SYNTHETIC );
		ProfileSeeder::seed_if_empty( $user_id, 'billing_email', $address );

		return true;
	}

	/**
	 * Write a pre-computed password hash straight into the users table.
	 */
	private static function apply_password_hash( int $user_id, string $hash ): void {
		if ( '' === $hash ) {
			return;
		}

		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->users,
			array( 'user_pass' => $hash ),
			array( 'ID' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_user_cache( $user_id );

		/*
		 * The hash reaching here came from a password its owner typed on the
		 * signup form, so this is the moment they chose one — and it is the
		 * writer no WordPress hook can see, because the row was written straight
		 * through $wpdb. See SecurityMeta for why the rule is over the writers
		 * rather than over an event.
		 */
		SecurityMeta::record_password_change( $user_id );
	}

	/**
	 * `customer` when WooCommerce is present, otherwise the site default.
	 */
	public static function default_role(): string {
		$role = get_role( 'customer' ) ? 'customer' : (string) get_option( 'default_role', 'subscriber' );

		/**
		 * @param string $role
		 */
		return (string) apply_filters( 'OMNIWP_default_role', $role );
	}

	/**
	 * Vietnamese names put the family name first, so the last token is the
	 * given name — which is what people expect to be greeted by.
	 *
	 * @return array{first:string,last:string}
	 */
	public static function split_name( string $full_name ): array {
		$full_name = preg_replace( '/\s+/u', ' ', trim( $full_name ) );

		if ( '' === $full_name ) {
			return array(
				'first' => '',
				'last'  => '',
			);
		}

		$parts = explode( ' ', $full_name );

		if ( count( $parts ) === 1 ) {
			return array(
				'first' => $parts[0],
				'last'  => '',
			);
		}

		$given = array_pop( $parts );

		return array(
			'first' => $given,
			'last'  => implode( ' ', $parts ),
		);
	}

	/**
	 * Fields the profile screen should nag the user about.
	 *
	 * @return string[] Human-readable labels.
	 */
	public static function missing_profile_fields( int $user_id ): array {
		$status  = ( new \OmniWP\Auth\ProfileCompletionService() )->status( $user_id );
		$missing = array_map(
			static function ( array $item ): string {
				return (string) $item['label'];
			},
			array_merge( $status['required_missing'] ?? array(), $status['recommended_missing'] ?? array() )
		);

		/**
		 * @param string[] $missing
		 * @param int      $user_id
		 */
		return (array) apply_filters( 'OMNIWP_missing_profile_fields', $missing, $user_id );
	}
}
