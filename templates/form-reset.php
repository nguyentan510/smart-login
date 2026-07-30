<?php
/**
 * Forgot-password step 3: set a new password.
 * Override at yourtheme/smart-login/form-reset.php
 *
 * @var array  $notices
 * @var string $grant
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Security\RequestGuard;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="smart-login smart-login--reset">

	<h2 class="sl-title"><?php esc_html_e( 'Đặt lại mật khẩu', 'smart-login' ); ?></h2>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php if ( '' === $grant ) : ?>

		<p class="sl-lead"><?php esc_html_e( 'Phiên đặt lại mật khẩu đã hết hạn.', 'smart-login' ); ?></p>
		<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( Flow::STEP_FORGOT ) ); ?>">
			<?php esc_html_e( 'Thử lại', 'smart-login' ); ?>
		</a>

	<?php else : ?>

		<p class="sl-lead">
			<?php
			printf(
				/* translators: %d: minimum password length. */
				esc_html__( 'Mật khẩu mới cần tối thiểu %d ký tự.', 'smart-login' ),
				esc_html( max( 6, Settings::get_int( 'min_password_length', 8 ) ) )
			);
			?>
		</p>

		<form method="post" class="sl-form" novalidate>
			<?php RequestGuard::fields( 'reset' ); ?>
			<input type="hidden" name="smart_login_action" value="reset_password" />
			<input type="hidden" name="grant" value="<?php echo esc_attr( $grant ); ?>" />

			<?php
			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'  => 'password',
					'label' => __( 'Mật khẩu mới', 'smart-login' ),
					'id'    => 'sl-new-password',
				)
			);

			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'  => 'password_confirm',
					'label' => __( 'Nhập lại mật khẩu mới', 'smart-login' ),
					'id'    => 'sl-new-password-confirm',
				)
			);
			?>

			<button type="submit" class="sl-btn sl-btn--primary"><?php esc_html_e( 'Xác nhận', 'smart-login' ); ?></button>
		</form>

	<?php endif; ?>
</div>
