<?php
/**
 * Three sensible OTP security profiles, so the six numbers become one choice.
 *
 * Code length, lifetime, wrong-attempt ceiling, resend cooldown and the two
 * hourly rate limits are not six independent decisions — they are one posture
 * expressed six times, and an administrator asked to set them individually is
 * being asked to derive the posture themselves. The numbers stay editable under
 * "Tuỳ chỉnh" for the site that genuinely needs a seventh combination.
 *
 * @package OmniWP
 */

namespace OmniWP;

defined( 'ABSPATH' ) || exit;

final class OtpPresets {

	const CUSTOM = 'custom';

	/**
	 * @return array<string,array>
	 */
	public static function all(): array {
		$presets = array(
			'balanced'   => array(
				'label'  => __( 'Cân bằng — khuyến nghị', 'omniwp' ),
				'detail' => __( 'Mã 6 số, hiệu lực 5 phút, tối đa 5 mã mỗi số mỗi giờ.', 'omniwp' ),
				'values' => array(
					'otp.length'                   => 6,
					'otp.ttl'                      => 300,
					'otp.max_attempts'             => 5,
					'otp.resend_cooldown'          => 60,
					'otp.max_per_destination_hour' => 5,
					'otp.max_per_ip_hour'          => 10,
				),
			),
			'strict'     => array(
				'label'  => __( 'Chặt — ưu tiên an toàn', 'omniwp' ),
				'detail' => __( 'Mã hết hạn sau 2 phút và giới hạn gửi thấp hơn. Giảm chi phí SMS, nhưng người dùng chậm tay sẽ phải xin mã mới.', 'omniwp' ),
				'values' => array(
					'otp.length'                   => 6,
					'otp.ttl'                      => 120,
					'otp.max_attempts'             => 3,
					'otp.resend_cooldown'          => 120,
					'otp.max_per_destination_hour' => 3,
					'otp.max_per_ip_hour'          => 6,
				),
			),
			'relaxed'    => array(
				'label'  => __( 'Thoáng — ưu tiên thuận tiện', 'omniwp' ),
				'detail' => __( 'Mã sống 10 phút và cho gửi lại nhiều hơn. Dễ dùng hơn ở nơi sóng yếu, đổi lại hoá đơn SMS cao hơn.', 'omniwp' ),
				'values' => array(
					'otp.length'                   => 6,
					'otp.ttl'                      => 600,
					'otp.max_attempts'             => 8,
					'otp.resend_cooldown'          => 30,
					'otp.max_per_destination_hour' => 8,
					'otp.max_per_ip_hour'          => 20,
				),
			),
			self::CUSTOM => array(
				'label'  => __( 'Tuỳ chỉnh', 'omniwp' ),
				'detail' => __( 'Tự đặt từng giá trị bên dưới.', 'omniwp' ),
				'values' => array(),
			),
		);

		/**
		 * Register another OTP security profile.
		 *
		 * @param array $presets
		 */
		return (array) apply_filters( 'omniwp_otp_presets', $presets );
	}

	/**
	 * @return array<string,string>
	 */
	public static function choices(): array {
		return array_map(
			static fn( array $preset ): string => (string) $preset['label'],
			self::all()
		);
	}

	public static function is_custom( string $slug ): bool {
		return self::CUSTOM === $slug || ! isset( self::all()[ $slug ] );
	}

	public static function detail( string $slug ): string {
		return (string) ( self::all()[ $slug ]['detail'] ?? '' );
	}

	/**
	 * @return array<string,mixed> Dot path => value; empty for the custom profile.
	 */
	public static function resolve( string $slug ): array {
		if ( self::is_custom( $slug ) ) {
			return array();
		}

		return (array) ( self::all()[ $slug ]['values'] ?? array() );
	}
}
