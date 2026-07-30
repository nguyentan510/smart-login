<?php
/**
 * One source of truth for onboarding and profile completeness.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Address\AddressFields;
use SmartLogin\Identity\UserManager;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class ProfileCompletionService {

	const META_SEEN      = '_smartlogin_onboarding_seen_at';
	const META_SOURCE    = '_smartlogin_onboarding_source';
	const META_GATE      = '_smartlogin_profile_gate';
	const META_NOTICE    = '_smartlogin_profile_notice_version';
	const NOTICE_VERSION = '1';

	public function status( int $user_id ): array {
		$user        = get_userdata( $user_id );
		$required    = array();
		$recommended = array();

		if ( ! $user ) {
			return array(
				'complete'            => false,
				'required_missing'    => $required,
				'recommended_missing' => $recommended,
			);
		}

		if ( '' === trim( (string) $user->display_name ) || (string) $user->user_login === (string) $user->display_name ) {
			$required[] = $this->item( 'full_name', __( 'Họ tên', 'smart-login' ), false );
		}

		if ( ! Settings::is_on( 'field_email_optional' ) && UserManager::is_synthetic_email( (string) $user->user_email ) ) {
			$required[] = $this->item( 'email', __( 'Email', 'smart-login' ), true );
		}

		if ( Settings::is_on( 'address_required_in_profile' ) ) {
			if ( ! AddressFields::is_complete( $user_id ) || ! get_user_meta( $user_id, 'billing_address_1', true ) ) {
				$required[] = $this->item( 'address', __( 'Địa chỉ', 'smart-login' ), false );
			}
		} elseif ( Settings::is_on( 'address_enabled' ) && ( ! AddressFields::is_complete( $user_id ) || ! get_user_meta( $user_id, 'billing_address_1', true ) ) ) {
			$recommended[] = $this->item( 'address', __( 'Địa chỉ', 'smart-login' ), false );
		}

		if ( Settings::is_on( 'field_dob' ) && ! get_user_meta( $user_id, UserManager::META_DOB, true ) ) {
			$recommended[] = $this->item( 'dob', __( 'Ngày sinh', 'smart-login' ), false );
		}

		if ( Settings::is_on( 'field_gender' ) && ! get_user_meta( $user_id, UserManager::META_GENDER, true ) ) {
			$recommended[] = $this->item( 'gender', __( 'Giới tính', 'smart-login' ), false );
		}

		$status = array(
			'complete'            => empty( $required ),
			'required_missing'    => $required,
			'recommended_missing' => $recommended,
		);

		return (array) apply_filters( 'smart_login_profile_status', $status, $user_id );
	}

	public function needs_gate( int $user_id, bool $is_new_user ): bool {
		$missing = ! empty( $this->status( $user_id )['required_missing'] );
		if ( $is_new_user && $missing ) {
			update_user_meta( $user_id, self::META_GATE, 1 );
		}
		if ( ! $missing ) {
			delete_user_meta( $user_id, self::META_GATE );
			return false;
		}
		return $is_new_user || (bool) get_user_meta( $user_id, self::META_GATE, true );
	}

	public function has_seen( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_SEEN, true );
	}

	public function mark_seen( int $user_id, string $source ): void {
		update_user_meta( $user_id, self::META_SEEN, current_time( 'mysql', true ) );
		update_user_meta( $user_id, self::META_SOURCE, sanitize_key( $source ) );
		update_user_meta( $user_id, self::META_NOTICE, self::NOTICE_VERSION );
	}

	private function item( string $key, string $label, bool $verification_required ): array {
		return array(
			'key'                   => $key,
			'label'                 => $label,
			'verification_required' => $verification_required,
		);
	}
}
