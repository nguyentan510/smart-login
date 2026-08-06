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
	const META_NOTICE    = '_smartlogin_profile_notice_version';
	const NOTICE_VERSION = '1';

	/**
	 * How many things onboarding may ask for at once.
	 *
	 * A first screen that lists everything missing is a form, not a welcome. Three
	 * is the point where it still reads as a short ask.
	 */
	const ONBOARDING_LIMIT = 3;

	/**
	 * Order the onboarding screen asks in, and why each field is worth giving.
	 *
	 * The reason is not decoration: a field labelled "Ngày sinh" is a demand, and
	 * the same field labelled "để nhận quà sinh nhật" is an offer. Only fields
	 * listed here can appear in onboarding — `email` deliberately does not, since
	 * changing it needs its own OTP round-trip and that does not belong on a
	 * welcome screen.
	 *
	 * @return array<string,string>
	 */
	public static function onboarding_reasons(): array {
		return array(
			'full_name' => __( 'Để chúng tôi biết xưng hô với bạn thế nào', 'smart-login' ),
			'address'   => __( 'Để đơn hàng được giao đúng nơi, không phải nhập lại mỗi lần', 'smart-login' ),
			'dob'       => __( 'Để nhận ưu đãi vào dịp sinh nhật', 'smart-login' ),
			'gender'    => __( 'Để gợi ý sản phẩm hợp với bạn hơn', 'smart-login' ),
		);
	}

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

		if ( ! Settings::is_on( 'profile.email_optional' ) && UserManager::is_synthetic_email( (string) $user->user_email ) ) {
			$required[] = $this->item( 'email', __( 'Email', 'smart-login' ), true );
		}

		/*
		 * The same words the card that fixes it is headed with. 17.4 found this
		 * concept carrying three names — "Địa chỉ giao hàng" as a heading, "Địa
		 * chỉ" here, "địa chỉ giao hàng mặc định" in the note — so a member was
		 * told to complete one thing and shown another.
		 */
		$address_label   = __( 'Địa chỉ nhận hàng', 'smart-login' );
		$address_missing = ! AddressFields::is_complete( $user_id ) || ! get_user_meta( $user_id, 'billing_address_1', true );

		if ( Settings::is_on( 'address.required_in_profile' ) ) {
			if ( $address_missing ) {
				$required[] = $this->item( 'address', $address_label, false );
			}
		} elseif ( Settings::is_on( 'address.enabled' ) && $address_missing ) {
			$recommended[] = $this->item( 'address', $address_label, false );
		}

		if ( Settings::is_on( 'profile.dob' ) && ! get_user_meta( $user_id, UserManager::META_DOB, true ) ) {
			$recommended[] = $this->item( 'dob', __( 'Ngày sinh', 'smart-login' ), false );
		}

		if ( Settings::is_on( 'profile.gender' ) && ! get_user_meta( $user_id, UserManager::META_GENDER, true ) ) {
			$recommended[] = $this->item( 'gender', __( 'Giới tính', 'smart-login' ), false );
		}

		$status = array(
			'complete'            => empty( $required ),
			'required_missing'    => $required,
			'recommended_missing' => $recommended,
		);

		return (array) apply_filters( 'smart_login_profile_status', $status, $user_id );
	}

	/**
	 * What the onboarding screen should actually ask for, in priority order.
	 *
	 * Required gaps come first, then recommended ones, capped at ONBOARDING_LIMIT.
	 * Anything with no input on the onboarding form — email, which needs its own
	 * verification round-trip — is filtered out here rather than in the template,
	 * so "there is nothing to ask" is a question the caller can answer before
	 * deciding to render at all.
	 *
	 * @return array<int,array{key:string,label:string,reason:string}>
	 */
	public function onboarding_fields( int $user_id ): array {
		$status  = $this->status( $user_id );
		$reasons = self::onboarding_reasons();
		$fields  = array();

		foreach ( array_merge( $status['required_missing'], $status['recommended_missing'] ) as $item ) {
			$key = (string) ( $item['key'] ?? '' );

			if ( ! isset( $reasons[ $key ] ) || isset( $fields[ $key ] ) ) {
				continue;
			}

			$fields[ $key ] = array(
				'key'    => $key,
				'label'  => (string) $item['label'],
				'reason' => $reasons[ $key ],
			);
		}

		/**
		 * Adjust what a new account is asked for on the welcome screen.
		 *
		 * @param array $fields
		 * @param int   $user_id
		 */
		$fields = (array) apply_filters( 'smart_login_onboarding_fields', array_values( $fields ), $user_id );

		return array_slice( $fields, 0, self::ONBOARDING_LIMIT );
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
