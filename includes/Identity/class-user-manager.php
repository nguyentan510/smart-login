<?php
/**
 * Account creation and profile meta handling.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Settings;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class UserManager {

	const META_PHONE          = 'smartlogin_phone';
	const META_PHONE_VERIFIED = 'smartlogin_phone_verified_at';
	const META_EMAIL_VERIFIED = 'smartlogin_email_verified_at';
	const META_DOB            = 'smartlogin_dob';
	const META_GENDER         = 'smartlogin_gender';
	const META_REFERRAL       = 'smartlogin_referral_code';
	const META_SYNTHETIC      = 'smartlogin_synthetic_email';

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
	public static function synthetic_email( string $opaque_token ): string {
		$domain = (string) Settings::get( 'identity.synthetic_domain', 'phone.invalid' );
		$email  = $opaque_token . '@' . $domain;

		/**
		 * Filter the generated placeholder email.
		 *
		 * @param string $email
		 * @param string $opaque_token
		 */
		return (string) apply_filters( 'smart_login_synthetic_email', $email, $opaque_token );
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
		return (bool) apply_filters( 'smart_login_is_synthetic_email', $is, $email );
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
	 *     @type string $referral_code
	 * }
	 * @return int|WP_Error New user ID.
	 */
	public static function create_verified_user( VerifiedClaim $claim, array $data = array(), ?IdentityDirectory $directory = null ) {
		$directory = $directory ?? new IdentityDirectory();
		$channel   = $claim->channel();
		$subject   = $claim->subject();

		if ( '' === $subject ) {
			return new WP_Error( 'smart_login_no_identity', __( 'Thiếu thông tin định danh.', 'smart-login' ) );
		}

		// Ownership is the directory's question, not a uniqueness check here.
		if ( $directory->resolve( $claim->claim() )->has_owner() ) {
			return new WP_Error( 'smart_login_exists', __( 'Tài khoản đã tồn tại.', 'smart-login' ) );
		}

		// One opaque token serves as both the login and the placeholder mailbox,
		// so neither is derivable from anything the user typed.
		$login    = OpaqueLogin::generate();
		$is_email = MailChannel::ID === $channel;
		$mail     = $is_email ? $subject : self::synthetic_email( $login );

		if ( email_exists( $mail ) ) {
			return new WP_Error( 'smart_login_exists', __( 'Tài khoản đã tồn tại.', 'smart-login' ) );
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

			return new WP_Error( 'smart_login_exists', __( 'Tài khoản đã tồn tại.', 'smart-login' ) );
		}

		self::apply_password_hash( (int) $user_id, (string) ( $data['pass_hash'] ?? '' ) );

		$now = current_time( 'mysql', true );

		// Everything below is a DERIVED MIRROR of the identity row, kept because
		// README documents these meta keys as a public contract for other plugins.
		// Nothing reads them to answer "who owns this subject" — see Invariant 1.
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

		if ( ! empty( $data['referral_code'] ) ) {
			update_user_meta( $user_id, self::META_REFERRAL, sanitize_text_field( $data['referral_code'] ) );
		}

		if ( '' !== $names['first'] ) {
			ProfileSeeder::seed_many(
				(int) $user_id,
				array(
					'billing_first_name' => $names['first'],
					'billing_last_name'  => $names['last'],
				)
			);
		}

		return (int) $user_id;
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
	}

	/**
	 * `customer` when WooCommerce is present, otherwise the site default.
	 */
	public static function default_role(): string {
		$role = get_role( 'customer' ) ? 'customer' : (string) get_option( 'default_role', 'subscriber' );

		/**
		 * @param string $role
		 */
		return (string) apply_filters( 'smart_login_default_role', $role );
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
		$status  = ( new \SmartLogin\Auth\ProfileCompletionService() )->status( $user_id );
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
		return (array) apply_filters( 'smart_login_missing_profile_fields', $missing, $user_id );
	}
}
