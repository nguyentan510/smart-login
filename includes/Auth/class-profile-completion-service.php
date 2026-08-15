<?php
/**
 * One source of truth for onboarding and profile completeness.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth;

use OmniWP\Address\AddressFields;
use OmniWP\Identity\UserManager;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class ProfileCompletionService {

	const META_SEEN      = '_OmniWP_onboarding_seen_at';
	const META_SOURCE    = '_OmniWP_onboarding_source';
	const META_NOTICE    = '_OmniWP_profile_notice_version';
	const NOTICE_VERSION = '1';

	/**
	 * How many things onboarding may ask for at once.
	 *
	 * A first screen that lists everything missing is a form, not a welcome. Three
	 * is the point where it still reads as a short ask.
	 */
	const ONBOARDING_LIMIT = 6;

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
			'full_name' => __( 'Để xưng hô chuẩn xác hơn', 'omniwp' ),
			'phone'     => __( 'Để shipper liên hệ khi giao hàng', 'omniwp' ),
			'email'     => __( 'Để nhận hóa đơn & ưu đãi độc quyền', 'omniwp' ),
			'address'   => __( 'Để giao hàng chính xác & không cần nhập lại', 'omniwp' ),
			'dob'       => __( 'Để nhận quà tặng dịp sinh nhật', 'omniwp' ),
			'gender'    => __( 'Để gợi ý ưu đãi phù hợp nhất', 'omniwp' ),
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
				'total'               => 0,
				'done'                => 0,
			);
		}

		$total = 0;
		$done  = 0;

		foreach ( $this->fields_in_scope( $user_id, $user ) as $field ) {
			++$total;

			if ( ! $field['missing'] ) {
				++$done;
				continue;
			}

			$item = $this->item( $field['key'], $field['label'], $field['verification_required'] );

			if ( $field['required'] ) {
				$required[] = $item;
			} else {
				$recommended[] = $item;
			}
		}

		$status = array(
			'complete'            => empty( $required ),
			'required_missing'    => $required,
			'recommended_missing' => $recommended,
			'total'               => $total,
			'done'                => $done,
		);

		return (array) apply_filters( 'OMNIWP_profile_status', $status, $user_id );
	}

	/**
	 * Every field this account is asked for, whether it holds one or not.
	 *
	 * Split out in 17.7, and the split is what makes the fraction possible: the
	 * old shape only ever built the list of what was *missing*, so "4 of 6" had
	 * no six to count. Deriving the six anywhere else would mean re-implementing
	 * these five settings lookups in a template, and the settings are exactly why
	 * the denominator moves.
	 *
	 * One array decides whether a field is in scope, whether it is required, and
	 * whether it holds a value — the FieldRegistry shape, applied to the one
	 * other place in this plugin that had a rule spread across five branches.
	 *
	 * @param int      $user_id
	 * @param \WP_User $user
	 * @return array<int,array{key:string,label:string,required:bool,verification_required:bool,missing:bool}>
	 */
	private function fields_in_scope( int $user_id, $user ): array {
		$fields = array();

		$fields[] = array(
			'key'                   => 'full_name',
			'label'                 => __( 'Họ và tên', 'omniwp' ),
			'required'              => true,
			'verification_required' => false,
			'missing'               => '' === trim( (string) $user->display_name )
				|| (string) $user->user_login === (string) $user->display_name,
		);

		$has_phone = '' !== (string) get_user_meta( $user_id, 'shipping_phone', true )
			|| '' !== (string) get_user_meta( $user_id, 'billing_phone', true )
			|| '' !== (string) get_user_meta( $user_id, UserManager::META_PHONE, true );

		$fields[] = array(
			'key'                   => 'phone',
			'label'                 => __( 'Số điện thoại', 'omniwp' ),
			'required'              => true,
			'verification_required' => false,
			'missing'               => ! $has_phone,
		);

		if ( ! Settings::is_on( 'profile.email_optional' ) ) {
			$fields[] = array(
				'key'                   => 'email',
				'label'                 => __( 'Email', 'omniwp' ),
				'required'              => true,
				'verification_required' => true,
				'missing'               => UserManager::is_synthetic_email( (string) $user->user_email ),
			);
		}

		/*
		 * The same words the card that fixes it is headed with. 17.4 found this
		 * concept carrying three names — "Địa chỉ giao hàng" as a heading, "Địa
		 * chỉ" here, "địa chỉ giao hàng mặc định" in the note — so a member was
		 * told to complete one thing and shown another.
		 *
		 * `required_in_profile` puts the field in scope on its own, without
		 * `enabled`. That is the behaviour that was here and it is preserved
		 * rather than tidied: an admin who has marked the address required has
		 * said something more specific than an admin who has merely enabled it.
		 */
		if ( Settings::is_on( 'address.required_in_profile' ) || Settings::is_on( 'address.enabled' ) ) {
			$fields[] = array(
				'key'                   => 'address',
				'label'                 => __( 'Địa chỉ nhận hàng', 'omniwp' ),
				'required'              => Settings::is_on( 'address.required_in_profile' ),
				'verification_required' => false,
				'missing'               => ! AddressFields::is_complete( $user_id )
					|| ( ! get_user_meta( $user_id, 'shipping_address_1', true ) && ! get_user_meta( $user_id, 'billing_address_1', true ) ),
			);
		}

		if ( Settings::is_on( 'profile.dob' ) ) {
			$fields[] = array(
				'key'                   => 'dob',
				'label'                 => __( 'Ngày sinh', 'omniwp' ),
				'required'              => false,
				'verification_required' => false,
				'missing'               => ! get_user_meta( $user_id, UserManager::META_DOB, true ),
			);
		}

		if ( Settings::is_on( 'profile.gender' ) ) {
			$fields[] = array(
				'key'                   => 'gender',
				'label'                 => __( 'Giới tính', 'omniwp' ),
				'required'              => false,
				'verification_required' => false,
				'missing'               => ! get_user_meta( $user_id, UserManager::META_GENDER, true ),
			);
		}

		return $fields;
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
		$fields = (array) apply_filters( 'OMNIWP_onboarding_fields', array_values( $fields ), $user_id );

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
