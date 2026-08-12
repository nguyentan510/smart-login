<?php
/**
 * Forgot-password step 3: set a new password.
 * Override at yourtheme/omniwp/form-reset.php
 *
 * @var array  $notices
 * @var string $grant
 *
 * @package OmniWP
 */

use OmniWP\Frontend\Flow;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Security\RequestGuard;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="omniwp omniwp--reset">

	<?php TemplateLoader::output( 'partials/screen-title', array( 'text' => __( 'Đặt lại mật khẩu', 'omniwp' ) ) ); ?>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php if ( '' === $grant ) : ?>

		<p class="sl-lead"><?php esc_html_e( 'Phiên đặt lại mật khẩu đã hết hạn.', 'omniwp' ); ?></p>
		<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( Flow::STEP_FORGOT ) ); ?>">
			<?php esc_html_e( 'Thử lại', 'omniwp' ); ?>
		</a>

	<?php else : ?>

		<p class="sl-lead">
			<?php
			printf(
				/* translators: %d: minimum password length. */
				esc_html__( 'Mật khẩu mới cần tối thiểu %d ký tự.', 'omniwp' ),
				esc_html( max( 6, Settings::get_int( 'signup.min_password_length', 8 ) ) )
			);
			?>
		</p>

		<form method="post" class="sl-form" novalidate>
			<?php RequestGuard::fields( 'reset' ); ?>
			<input type="hidden" name="OMNIWP_action" value="reset_password" />
			<input type="hidden" name="grant" value="<?php echo esc_attr( $grant ); ?>" />

			<?php
			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'  => 'password',
					'label' => __( 'Mật khẩu mới', 'omniwp' ),
					'id'    => 'sl-new-password',
				)
			);

			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'  => 'password_confirm',
					'label' => __( 'Nhập lại mật khẩu mới', 'omniwp' ),
					'id'    => 'sl-new-password-confirm',
				)
			);
			?>

			<button type="submit" class="sl-btn sl-btn--primary"><?php esc_html_e( 'Xác nhận', 'omniwp' ); ?></button>
		</form>

	<?php endif; ?>
</div>
