<?php
/**
 * Verifies a newly supplied contact before changing canonical user data.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\IdentityResolver;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactVerificationService {

	const META_PENDING = '_smartlogin_pending_contact';

	private OtpService $otp;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp ?? new OtpService();
	}

	/** @return array|WP_Error */
	public function start( int $user_id, string $type, string $value ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
		}

		$contact = $this->normalise( $type, $value );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$destination = $contact['value'];

		if ( 'phone' === $type ) {
			$owner = IdentityResolver::user_by_phone( $destination );
			if ( $owner && (int) $owner->ID !== $user_id ) {
				return new WP_Error( 'smart_login_contact_exists', __( 'Số điện thoại này đã thuộc về tài khoản khác.', 'smart-login' ) );
			}
			$purpose = OtpService::PURPOSE_CHANGE_PHONE;
		} else {
			$owner = get_user_by( 'email', $destination );
			if ( $owner && (int) $owner->ID !== $user_id ) {
				return new WP_Error( 'smart_login_contact_exists', __( 'Email này đã thuộc về tài khoản khác.', 'smart-login' ) );
			}
			$purpose = OtpService::PURPOSE_CHANGE_EMAIL;
		}

		$result = $this->otp->issue(
			$destination,
			$purpose,
			array( 'user_id' => $user_id, 'contact_type' => $type ),
			array( 'user_name' => $user->display_name )
		);
		if ( ! is_wp_error( $result ) ) {
			update_user_meta(
				$user_id,
				self::META_PENDING,
				array(
					'type'       => $type,
					'masked'     => (string) $result['masked'],
					'expires_at' => time() + (int) $result['expires_in'],
				)
			);
			AuditLog::record( AuditLog::CONTACT_PENDING, RateLimiter::mask_identity( $destination ), array( 'type' => $type ), $user_id );
		}
		return $result;
	}

	/** @return array|WP_Error */
	public function verify( int $user_id, string $token, string $code, string $type ) {
		if ( ! in_array( $type, array( 'phone', 'email' ), true ) ) {
			return new WP_Error( 'smart_login_bad_contact', __( 'Thông tin liên hệ không hợp lệ.', 'smart-login' ) );
		}
		$purpose = 'phone' === $type ? OtpService::PURPOSE_CHANGE_PHONE : OtpService::PURPOSE_CHANGE_EMAIL;
		$row = $this->otp->verify( $token, $code, $purpose );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( (int) ( $row['payload']['user_id'] ?? 0 ) !== $user_id || ( $row['payload']['contact_type'] ?? '' ) !== $type ) {
			return new WP_Error( 'smart_login_contact_session', __( 'Phiên xác thực thông tin liên hệ không hợp lệ.', 'smart-login' ) );
		}

		$destination = (string) $row['destination'];
		$now = current_time( 'mysql', true );
		if ( 'phone' === $type ) {
			$owner = IdentityResolver::user_by_phone( $destination );
			if ( $owner && (int) $owner->ID !== $user_id ) {
				return new WP_Error( 'smart_login_contact_exists', __( 'Số điện thoại này đã thuộc về tài khoản khác.', 'smart-login' ) );
			}
			update_user_meta( $user_id, UserManager::META_PHONE, $destination );
			update_user_meta( $user_id, UserManager::META_PHONE_VERIFIED, $now );
			if ( Settings::is_on( 'woo_sync_billing_phone' ) ) {
				update_user_meta( $user_id, 'billing_phone', Phone::to_local( $destination ) );
			}
		} else {
			$owner = get_user_by( 'email', $destination );
			if ( $owner && (int) $owner->ID !== $user_id ) {
				return new WP_Error( 'smart_login_contact_exists', __( 'Email này đã thuộc về tài khoản khác.', 'smart-login' ) );
			}
			$updated = wp_update_user( array( 'ID' => $user_id, 'user_email' => $destination ) );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			update_user_meta( $user_id, UserManager::META_EMAIL_VERIFIED, $now );
			update_user_meta( $user_id, 'billing_email', $destination );
			delete_user_meta( $user_id, UserManager::META_SYNTHETIC );
		}

		delete_user_meta( $user_id, self::META_PENDING );
		AuditLog::record( AuditLog::CONTACT_VERIFIED, RateLimiter::mask_identity( $destination ), array( 'type' => $type ), $user_id );
		return array( 'type' => $type, 'value' => $destination );
	}

	public function pending( int $user_id ): array {
		$pending = get_user_meta( $user_id, self::META_PENDING, true );
		if ( ! is_array( $pending ) || (int) ( $pending['expires_at'] ?? 0 ) <= time() ) {
			delete_user_meta( $user_id, self::META_PENDING );
			return array();
		}
		return array(
			'type'       => sanitize_key( (string) ( $pending['type'] ?? '' ) ),
			'masked'     => sanitize_text_field( (string) ( $pending['masked'] ?? '' ) ),
			'expires_at' => (int) $pending['expires_at'],
		);
	}

	/** @return array|WP_Error */
	private function normalise( string $type, string $value ) {
		if ( 'phone' === $type ) {
			$phone = Phone::normalize( $value );
			if ( ! Phone::is_valid( $phone ) ) {
				return new WP_Error( 'smart_login_bad_phone', __( 'Số điện thoại không hợp lệ.', 'smart-login' ) );
			}
			return array( 'value' => $phone );
		}
		if ( 'email' === $type && is_email( $value ) ) {
			return array( 'value' => strtolower( sanitize_email( $value ) ) );
		}
		return new WP_Error( 'smart_login_bad_contact', __( 'Thông tin liên hệ không hợp lệ.', 'smart-login' ) );
	}
}
