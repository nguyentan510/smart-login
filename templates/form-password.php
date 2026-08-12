<?php
/**
 * Step 2a: the identifier is registered, so ask for the password.
 *
 * Override at yourtheme/omniwp/form-password.php
 *
 * @var array  $notices
 * @var string $identity Exactly what the visitor typed on step 1.
 *
 * @package OmniWP
 */

use OmniWP\Frontend\Flow;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;

$ow_identity = (string) ( $identity ?? '' );
$ow_redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="omniwp omniwp--password">

	<?php TemplateLoader::output( 'partials/screen-title', array( 'text' => __( 'Nhập mật khẩu', 'omniwp' ) ) ); ?>

	<p class="sl-identity-chip">
		<span class="sl-identity-chip__value"><?php echo esc_html( $ow_identity ); ?></span>
		<a class="sl-link" href="<?php echo esc_url( Flow::url( Flow::STEP_IDENTIFY ) ); ?>">
			<?php esc_html_e( 'Đổi', 'omniwp' ); ?>
		</a>
	</p>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<form method="post" class="sl-form sl-form--password">
		<?php RequestGuard::fields( 'login', 'login_' ); ?>
		<input type="hidden" name="OMNIWP_action" value="login" />
		<input type="hidden" name="identity" value="<?php echo esc_attr( $ow_identity ); ?>" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $ow_redirect ); ?>" />
		<?php // Tells the controller a rejected password belongs back on this screen. ?>
		<input type="hidden" name="ow_from_password" value="1" />

		<?php
		TemplateLoader::output(
			'partials/password-field',
			array(
				'name'         => 'password',
				'label'        => __( 'Mật khẩu', 'omniwp' ),
				'id'           => 'sl-password',
				'autocomplete' => 'current-password',
				'autofocus'    => true,
			)
		);
		?>

		<div class="sl-login-options">
			<label class="sl-remember">
				<input type="checkbox" name="remember" value="1" checked />
				<span><?php esc_html_e( 'Ghi nhớ đăng nhập', 'omniwp' ); ?></span>
			</label>
		</div>

		<button type="submit" class="sl-btn sl-btn--primary sl-btn--block">
			<?php esc_html_e( 'Đăng nhập', 'omniwp' ); ?>
		</button>
	</form>

	<?php
	/*
	 * A way forward for somebody with no password to type.
	 *
	 * An account provisioned by a provider holds a 64-character random password its
	 * owner has never seen, so this box cannot be filled — and the old link sent them
	 * to a screen that asked them to type the identifier they had just typed. This
	 * posts the identifier already held in the flow to the same `forgot` action, so
	 * there is no new intent, no new grant and no second entry point to an OTP send:
	 * it is the door that already existed, moved to where the visitor is standing.
	 *
	 * A separate form because a submit button inside the login form above would send
	 * the login form. The guard fields carry no prefix here; the login form's are
	 * prefixed, which is what that mechanism is for.
	 */
	?>
	<form method="post" class="sl-form sl-form--recover">
		<?php RequestGuard::fields( 'forgot' ); ?>
		<?php echo \OmniWP\Security\Captcha::field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
		<input type="hidden" name="OMNIWP_action" value="forgot" />
		<input type="hidden" name="identity" value="<?php echo esc_attr( $ow_identity ); ?>" />

		<p class="sl-hint"><?php esc_html_e( 'Chưa có mật khẩu, hoặc không nhớ?', 'omniwp' ); ?></p>

		<button type="submit" class="sl-btn sl-btn--ghost">
			<?php esc_html_e( 'Gửi mã xác thực', 'omniwp' ); ?>
		</button>
	</form>
</div>
