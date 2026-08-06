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
 * 17.5 gives it the *reason* each item is worth filling in. Those sentences have
 * been written and translated since Phase 8 and one screen read them:
 * `ProfileCompletionService::onboarding_reasons()`. They are looked up here and
 * never copied — 8.4 removed a second source of truth from this exact block, and
 * pasting the strings back in is how it would return.
 *
 * The two branches collapsed into one in 17.5 as well. They differed in a class,
 * a heading and the wording of a link, and had a duplicated list, a duplicated
 * link and a duplicated conditional between them.
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

use SmartLogin\Auth\ProfileCompletionService;

defined( 'ABSPATH' ) || exit;

$sl_status   = isset( $sl_status ) && is_array( $sl_status ) ? $sl_status : array();
$sl_pending  = isset( $sl_pending ) && is_array( $sl_pending ) ? $sl_pending : array();
$sl_welcome  = ! empty( $sl_welcome );
$sl_edit_url = isset( $sl_edit_url ) ? (string) $sl_edit_url : '';
$sl_required = $sl_status['required_missing'] ?? array();
$sl_optional = $sl_status['recommended_missing'] ?? array();

$sl_reasons = ProfileCompletionService::onboarding_reasons();

/*
 * Counted in the service, never here. The denominator moves with five settings
 * — profile.email_optional, address.required_in_profile, address.enabled,
 * profile.dob, profile.gender — and re-deriving those in a template is a second
 * implementation of the rule that decides what the form asks for.
 */
$sl_total = (int) ( $sl_status['total'] ?? 0 );
$sl_done  = (int) ( $sl_status['done'] ?? 0 );

/*
 * Required outranks recommended: a member with both is shown the half they
 * cannot proceed without, not a list of six things at one weight.
 */
if ( ! empty( $sl_required ) ) {
	$sl_missing = $sl_required;
	$sl_kind    = 'warning';
	$sl_heading = __( 'Thông tin bắt buộc còn thiếu', 'smart-login' );
	$sl_action  = __( 'Cập nhật ngay', 'smart-login' );
} elseif ( ! empty( $sl_optional ) ) {
	$sl_missing = $sl_optional;
	$sl_kind    = 'info';
	$sl_heading = __( 'Bạn có thể bổ sung thêm', 'smart-login' );
	$sl_action  = __( 'Bổ sung thông tin', 'smart-login' );
} else {
	$sl_missing = array();
	$sl_kind    = '';
	$sl_heading = '';
	$sl_action  = '';
}
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

<?php if ( $sl_total > 0 ) : ?>
	<?php
	/*
	 * The meter carries its value in two ways. The bar is the visual answer; the
	 * progressbar role and its two attributes are the answer for anything that
	 * is not looking at pixels, and the same numbers are printed in words beside
	 * it — a bar with no number is a shape, not a statement.
	 */
	?>
	<div class="sl-progress">
		<div
			class="sl-progress__track"
			role="progressbar"
			aria-valuemin="0"
			aria-valuemax="<?php echo esc_attr( (string) $sl_total ); ?>"
			aria-valuenow="<?php echo esc_attr( (string) $sl_done ); ?>"
			aria-label="<?php esc_attr_e( 'Mức hoàn thiện hồ sơ', 'smart-login' ); ?>"
		>
			<span class="sl-progress__fill" style="width: <?php echo esc_attr( (string) round( ( $sl_done / $sl_total ) * 100 ) ); ?>%"></span>
		</div>
		<span class="sl-progress__count">
			<?php
			/* translators: 1: fields completed, 2: fields asked for. */
			printf( esc_html__( 'Hoàn thiện %1$d/%2$d', 'smart-login' ), (int) $sl_done, (int) $sl_total );
			?>
		</span>
	</div>
<?php endif; ?>

<?php if ( ! empty( $sl_missing ) ) : ?>
	<div class="sl-notice sl-notice--<?php echo esc_attr( $sl_kind ); ?>">
		<strong><?php echo esc_html( $sl_heading ); ?></strong>

		<ul class="sl-missing">
			<?php foreach ( $sl_missing as $sl_item ) : ?>
				<?php $sl_reason = (string) ( $sl_reasons[ (string) ( $sl_item['key'] ?? '' ) ] ?? '' ); ?>
				<li class="sl-missing__item">
					<span class="sl-missing__label"><?php echo esc_html( (string) ( $sl_item['label'] ?? '' ) ); ?></span>
					<?php
					/*
					 * An item with no reason renders its label alone. `email` is the
					 * one that has none, deliberately — changing it needs its own OTP
					 * round trip and does not belong on a welcome screen — and
					 * inventing a sentence here would be the copy drifting away from
					 * the place it lives.
					 */
					?>
					<?php if ( '' !== $sl_reason ) : ?>
						<span class="sl-missing__reason"><?php echo esc_html( $sl_reason ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( '' !== $sl_edit_url ) : ?>
			<a class="sl-link" href="<?php echo esc_url( $sl_edit_url ); ?>"><?php echo esc_html( $sl_action ); ?></a>
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
