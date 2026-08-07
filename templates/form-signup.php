<?php
/**
 * Step 3: name and password, against an identifier the OTP has already proven.
 *
 * Asking for these *after* verification rather than before is the whole point of
 * the reordering: by the time this screen appears the visitor has already put
 * work into the flow, so the completion rate on it is far higher than on the
 * same fields shown to a stranger. There is one password box, not two — the
 * show/hide toggle does what a confirmation field was there to do.
 *
 * Override at yourtheme/smart-login/form-signup.php
 *
 * @var array  $notices
 * @var string $grant
 * @var string $terms_url
 * @var int    $min_password
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;

$sl_grant = (string) ( $grant ?? '' );
$sl_min   = max( 6, (int) ( $min_password ?? 8 ) );
?>
<div class="smart-login smart-login--signup">

	<?php
	TemplateLoader::output(
		'partials/steps',
		array(
			'current' => 3,
			'labels'  => array(
				__( 'Số điện thoại', 'smart-login' ),
				__( 'Xác thực', 'smart-login' ),
				__( 'Thông tin', 'smart-login' ),
			),
		)
	);
	?>

	<h2 class="sl-title"><?php esc_html_e( 'Gần xong rồi!', 'smart-login' ); ?></h2>
	<p class="sl-lead"><?php esc_html_e( 'Chúng tôi đã xác thực được bạn. Chỉ còn hai thông tin nữa là tài khoản sẵn sàng.', 'smart-login' ); ?></p>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php if ( '' === $sl_grant ) : ?>

		<p class="sl-lead"><?php esc_html_e( 'Phiên đăng ký đã hết hạn. Vui lòng thực hiện lại.', 'smart-login' ); ?></p>
		<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( Flow::STEP_IDENTIFY ) ); ?>">
			<?php esc_html_e( 'Bắt đầu lại', 'smart-login' ); ?>
		</a>

	<?php else : ?>

		<form method="post" class="sl-form sl-form--signup">
			<?php RequestGuard::fields( 'signup' ); ?>
			<input type="hidden" name="smart_login_action" value="signup" />
			<input type="hidden" name="grant" value="<?php echo esc_attr( $sl_grant ); ?>" />

			<div class="sl-field">
				<label class="sl-label" for="sl-full-name">
					<?php esc_html_e( 'Họ và tên', 'smart-login' ); ?>
					<span class="sl-required">*</span>
				</label>
				<input
					type="text"
					class="sl-input"
					id="sl-full-name"
					name="full_name"
					value="<?php echo esc_attr( Flow::old( 'full_name' ) ); ?>"
					autocomplete="name"
					required
					autofocus
				/>
			</div>

			<?php
			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'         => 'password',
					'label'        => __( 'Mật khẩu', 'smart-login' ),
					'id'           => 'sl-reg-password',
					'autocomplete' => 'new-password',
					'minlength'    => $sl_min,
					'describedby'  => 'sl-password-guidance',
				)
			);
			?>
			<p class="sl-hint" id="sl-password-guidance">
				<?php
				printf(
					/* translators: %d: minimum password length. */
					esc_html__( 'Ít nhất %d ký tự. Bấm vào biểu tượng con mắt để kiểm tra lại.', 'smart-login' ),
					(int) $sl_min
				);
				?>
			</p>

			<label class="sl-terms">
				<input type="checkbox" name="terms" value="1" required />
				<span>
					<?php if ( ! empty( $terms_url ) ) : ?>
						<?php
						printf(
							/* translators: %s: linked terms and conditions label. */
							wp_kses_post( __( 'Tôi đồng ý với %s.', 'smart-login' ) ),
							'<a class="sl-link" href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'các điều khoản áp dụng', 'smart-login' ) . '</a>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Tôi đồng ý với các điều khoản áp dụng.', 'smart-login' ); ?>
					<?php endif; ?>
				</span>
			</label>

			<button type="submit" class="sl-btn sl-btn--primary sl-btn--block">
				<?php esc_html_e( 'Hoàn tất đăng ký', 'smart-login' ); ?>
			</button>
		</form>

	<?php endif; ?>
</div>
