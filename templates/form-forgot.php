<?php
/**
 * Forgot-password step 1. Override at yourtheme/smart-login/form-forgot.php
 *
 * @var array $notices
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Auth\RegisterHandler;
use SmartLogin\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;
?>
<div class="smart-login smart-login--forgot">

	<?php TemplateLoader::output( 'partials/screen-title', array( 'text' => __( 'Quên mật khẩu', 'smart-login' ) ) ); ?>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<p class="sl-lead">
		<?php esc_html_e( 'Nhập thông tin tài khoản của bạn. Chúng tôi sẽ gửi mã xác thực để bạn đặt lại mật khẩu.', 'smart-login' ); ?>
	</p>

	<form method="post" class="sl-form" novalidate>
		<?php RequestGuard::fields( 'forgot' ); ?>
		<?php echo \SmartLogin\Security\Captcha::field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
		<input type="hidden" name="smart_login_action" value="forgot" />

		<div class="sl-field">
			<label class="sl-label" for="sl-forgot-identity">
				<?php echo esc_html( RegisterHandler::identifier_label() ); ?>
				<span class="sl-required">*</span>
			</label>
			<input
				type="text"
				class="sl-input"
				id="sl-forgot-identity"
				name="identity"
				value="<?php echo esc_attr( Flow::old( 'identity' ) ); ?>"
				autocomplete="username"
				required
			/>
		</div>

		<button type="submit" class="sl-btn sl-btn--primary"><?php esc_html_e( 'Gửi mã xác thực', 'smart-login' ); ?></button>
	</form>

	<div class="sl-divider"><span><?php esc_html_e( 'Hoặc', 'smart-login' ); ?></span></div>

	<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( Flow::STEP_LOGIN ) ); ?>">
		<?php esc_html_e( 'Quay lại đăng nhập', 'smart-login' ); ?>
	</a>
</div>
