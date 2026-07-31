<?php
/**
 * Profile completeness notice — the single owner.
 *
 * This block used to exist twice. templates/profile-summary.php rendered a
 * heading, a sentence and an action link; the WooCommerce account template
 * rendered implode() of the labels and nothing else, which on a live page came
 * out as a blue box containing "Địa chỉ, Ngày sinh, Giới tính". The maintained
 * version is the one kept here.
 *
 * Override at yourtheme/smart-login/partials/account/status.php
 *
 * @var array  $sl_status   Output of ProfileCompletionService::status()
 * @var array  $sl_pending  Output of ContactVerificationService::pending()
 * @var bool   $sl_welcome
 * @var string $sl_edit_url Empty on a surface that already edits.
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;

$sl_status   = isset( $sl_status ) && is_array( $sl_status ) ? $sl_status : array();
$sl_pending  = isset( $sl_pending ) && is_array( $sl_pending ) ? $sl_pending : array();
$sl_welcome  = ! empty( $sl_welcome );
$sl_edit_url = isset( $sl_edit_url ) ? (string) $sl_edit_url : '';
$sl_required = $sl_status['required_missing'] ?? array();
$sl_optional = $sl_status['recommended_missing'] ?? array();
?>

<?php if ( $sl_welcome && ! empty( $sl_status['complete'] ) && empty( $sl_optional ) ) : ?>
	<div class="sl-notice sl-notice--success">
		<?php esc_html_e( 'Hồ sơ của bạn đã đầy đủ. Bạn có thể tiếp tục sử dụng hệ thống.', 'smart-login' ); ?>
	</div>
<?php elseif ( $sl_welcome ) : ?>
	<div class="sl-notice sl-notice--success">
		<?php esc_html_e( 'Chào mừng bạn! Hãy bổ sung thông tin để nhận đầy đủ ưu đãi hội viên.', 'smart-login' ); ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $sl_required ) ) : ?>
	<div class="sl-notice sl-notice--warning">
		<strong><?php esc_html_e( 'Thông tin bắt buộc còn thiếu', 'smart-login' ); ?></strong>
		<p><?php echo esc_html( implode( ', ', wp_list_pluck( $sl_required, 'label' ) ) ); ?></p>
		<?php if ( '' !== $sl_edit_url ) : ?>
			<a class="sl-link" href="<?php echo esc_url( $sl_edit_url ); ?>"><?php esc_html_e( 'Cập nhật ngay', 'smart-login' ); ?></a>
		<?php endif; ?>
	</div>
<?php elseif ( ! empty( $sl_optional ) ) : ?>
	<div class="sl-notice sl-notice--info">
		<strong><?php esc_html_e( 'Bạn có thể bổ sung thêm', 'smart-login' ); ?></strong>
		<p><?php echo esc_html( implode( ', ', wp_list_pluck( $sl_optional, 'label' ) ) ); ?></p>
		<?php if ( '' !== $sl_edit_url ) : ?>
			<a class="sl-link" href="<?php echo esc_url( $sl_edit_url ); ?>"><?php esc_html_e( 'Bổ sung thông tin', 'smart-login' ); ?></a>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $sl_pending ) ) : ?>
	<div class="sl-notice sl-notice--info">
		<?php
		if ( '' !== $sl_edit_url ) {
			printf(
				/* translators: 1: contact type, 2: masked destination. */
				esc_html__( 'Đang chờ xác thực %1$s: %2$s. Mở trang chỉnh sửa hồ sơ để nhập mã OTP.', 'smart-login' ),
				'phone' === ( $sl_pending['type'] ?? '' ) ? esc_html__( 'số điện thoại', 'smart-login' ) : esc_html__( 'email', 'smart-login' ),
				esc_html( $sl_pending['masked'] ?? '' )
			);
		} else {
			printf(
				/* translators: 1: contact type, 2: masked destination. */
				esc_html__( 'Đang chờ xác thực %1$s: %2$s. Bạn có thể gửi lại một mã mới bên dưới.', 'smart-login' ),
				'phone' === ( $sl_pending['type'] ?? '' ) ? esc_html__( 'số điện thoại', 'smart-login' ) : esc_html__( 'email', 'smart-login' ),
				esc_html( $sl_pending['masked'] ?? '' )
			);
		}
		?>
	</div>
<?php endif; ?>
