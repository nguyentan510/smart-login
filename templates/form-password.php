<?php
/**
 * Step 2a: the identifier is registered, so ask for the password.
 *
 * Override at yourtheme/smart-login/form-password.php
 *
 * @var array  $notices
 * @var string $identity Exactly what the visitor typed on step 1.
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;

$sl_identity = (string) ( $identity ?? '' );
$sl_redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="smart-login smart-login--password">

	<h2 class="sl-title"><?php esc_html_e( 'Nhập mật khẩu', 'smart-login' ); ?></h2>

	<p class="sl-identity-chip">
		<span class="sl-identity-chip__value"><?php echo esc_html( $sl_identity ); ?></span>
		<a class="sl-link" href="<?php echo esc_url( Flow::url( Flow::STEP_IDENTIFY ) ); ?>">
			<?php esc_html_e( 'Đổi', 'smart-login' ); ?>
		</a>
	</p>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<form method="post" class="sl-form sl-form--password">
		<?php RequestGuard::fields( 'login', 'login_' ); ?>
		<input type="hidden" name="smart_login_action" value="login" />
		<input type="hidden" name="identity" value="<?php echo esc_attr( $sl_identity ); ?>" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $sl_redirect ); ?>" />
		<?php // Tells the controller a rejected password belongs back on this screen. ?>
		<input type="hidden" name="sl_from_password" value="1" />

		<?php
		TemplateLoader::output(
			'partials/password-field',
			array(
				'name'         => 'password',
				'label'        => __( 'Mật khẩu', 'smart-login' ),
				'id'           => 'sl-password',
				'autocomplete' => 'current-password',
				'autofocus'    => true,
			)
		);
		?>

		<div class="sl-login-options">
			<label class="sl-remember">
				<input type="checkbox" name="remember" value="1" checked />
				<span><?php esc_html_e( 'Ghi nhớ đăng nhập', 'smart-login' ); ?></span>
			</label>
			<a class="sl-link" href="<?php echo esc_url( Flow::url( Flow::STEP_FORGOT ) ); ?>">
				<?php esc_html_e( 'Quên mật khẩu?', 'smart-login' ); ?>
			</a>
		</div>

		<button type="submit" class="sl-btn sl-btn--primary sl-btn--block">
			<?php esc_html_e( 'Đăng nhập', 'smart-login' ); ?>
		</button>
	</form>
</div>
