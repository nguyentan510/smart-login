<?php
/**
 * All-in-one login and registration box.
 *
 * Override at yourtheme/smart-login/form-auth.php.
 *
 * @var array  $notices
 * @var string $active_tab login|register
 * @var string $terms_url
 *
 * @package SmartLogin
 */

use SmartLogin\Auth\ProviderAuthController;
use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Auth\RegisterHandler;
use SmartLogin\Security\RequestGuard;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

$sl_active_tab        = Flow::STEP_REGISTER === ( $active_tab ?? Flow::STEP_LOGIN )
	? Flow::STEP_REGISTER
	: Flow::STEP_LOGIN;
$sl_redirect          = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$sl_providers         = ( new ProviderRegistry() )->available();
$sl_min_length        = max( 6, Settings::get_int( 'min_password_length', 8 ) );
$sl_login_disabled    = Flow::STEP_LOGIN !== $sl_active_tab;
$sl_register_disabled = Flow::STEP_REGISTER !== $sl_active_tab;
?>
<div class="smart-login smart-login--auth" data-sl-auth data-active-tab="<?php echo esc_attr( $sl_active_tab ); ?>">
	<h2 class="screen-reader-text"><?php esc_html_e( 'Đăng nhập hoặc đăng ký', 'smart-login' ); ?></h2>

	<nav class="sl-auth-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Chọn hình thức tài khoản', 'smart-login' ); ?>">
		<a
			class="sl-auth-tab<?php echo Flow::STEP_LOGIN === $sl_active_tab ? ' is-active' : ''; ?>"
			href="<?php echo esc_url( Flow::url( Flow::STEP_LOGIN ) ); ?>"
			id="sl-tab-login"
			role="tab"
			aria-controls="sl-panel-login"
			aria-selected="<?php echo Flow::STEP_LOGIN === $sl_active_tab ? 'true' : 'false'; ?>"
			data-sl-auth-tab="login"
		>
			<?php esc_html_e( 'Đăng nhập', 'smart-login' ); ?>
		</a>
		<a
			class="sl-auth-tab<?php echo Flow::STEP_REGISTER === $sl_active_tab ? ' is-active' : ''; ?>"
			href="<?php echo esc_url( Flow::url( Flow::STEP_REGISTER ) ); ?>"
			id="sl-tab-register"
			role="tab"
			aria-controls="sl-panel-register"
			aria-selected="<?php echo Flow::STEP_REGISTER === $sl_active_tab ? 'true' : 'false'; ?>"
			data-sl-auth-tab="register"
		>
			<?php esc_html_e( 'Đăng ký', 'smart-login' ); ?>
		</a>
	</nav>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<form method="post" class="sl-form sl-auth-form" data-sl-auth-form novalidate>
		<?php RequestGuard::fields( 'login', 'login_' ); ?>
		<?php RequestGuard::fields( 'register', 'register_' ); ?>
		<input type="hidden" name="smart_login_action" value="<?php echo esc_attr( $sl_active_tab ); ?>" data-sl-auth-action />
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $sl_redirect ); ?>" />

	<section
		class="sl-auth-panel<?php echo Flow::STEP_LOGIN === $sl_active_tab ? ' is-active' : ''; ?>"
		id="sl-panel-login"
		role="tabpanel"
		aria-labelledby="sl-tab-login"
		data-sl-auth-panel="login"
		<?php echo Flow::STEP_LOGIN === $sl_active_tab ? '' : 'hidden'; ?>
	>
			<div class="sl-field">
				<label class="sl-label" for="sl-identity">
					<?php echo esc_html( RegisterHandler::identifier_label() ); ?>
					<span class="sl-required">*</span>
				</label>
				<input
					type="text"
					class="sl-input"
					id="sl-identity"
					name="login_identity"
					value="<?php echo esc_attr( Flow::old( 'identity' ) ); ?>"
					inputmode="<?php echo Settings::phone_enabled() && ! Settings::email_enabled() ? 'tel' : 'text'; ?>"
					autocomplete="username"
					<?php echo $sl_login_disabled ? 'disabled' : ''; ?>
					required
				/>
			</div>

			<?php
			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'         => 'login_password',
					'label'        => __( 'Mật khẩu', 'smart-login' ),
					'id'           => 'sl-password',
					'autocomplete' => 'current-password',
					'disabled'     => $sl_login_disabled,
				)
			);
			?>

			<div class="sl-login-options">
				<label class="sl-remember">
					<input type="checkbox" name="remember" value="1" checked <?php echo $sl_login_disabled ? 'disabled' : ''; ?> />
					<span><?php esc_html_e( 'Ghi nhớ đăng nhập', 'smart-login' ); ?></span>
				</label>
				<a class="sl-link" href="<?php echo esc_url( Flow::url( Flow::STEP_FORGOT ) ); ?>">
					<?php esc_html_e( 'Quên mật khẩu?', 'smart-login' ); ?>
				</a>
			</div>

			<button type="submit" class="sl-btn sl-btn--primary" data-sl-auth-submit="login" <?php echo $sl_login_disabled ? 'disabled' : ''; ?>><?php esc_html_e( 'Đăng nhập', 'smart-login' ); ?></button>
	</section>

	<section
		class="sl-auth-panel<?php echo Flow::STEP_REGISTER === $sl_active_tab ? ' is-active' : ''; ?>"
		id="sl-panel-register"
		role="tabpanel"
		aria-labelledby="sl-tab-register"
		data-sl-auth-panel="register"
		<?php echo Flow::STEP_REGISTER === $sl_active_tab ? '' : 'hidden'; ?>
	>
			<div class="sl-field">
				<label class="sl-label" for="sl-full-name">
					<?php esc_html_e( 'Họ tên', 'smart-login' ); ?>
					<span class="sl-required">*</span>
				</label>
				<input
					type="text"
					class="sl-input"
					id="sl-full-name"
					name="full_name"
					value="<?php echo esc_attr( Flow::old( 'full_name' ) ); ?>"
					autocomplete="name"
					<?php echo $sl_register_disabled ? 'disabled' : ''; ?>
					required
				/>
			</div>

			<div class="sl-field">
				<label class="sl-label" for="sl-reg-identity">
					<?php echo esc_html( RegisterHandler::identifier_label() ); ?>
					<span class="sl-required">*</span>
				</label>
				<input
					type="text"
					class="sl-input"
					id="sl-reg-identity"
					name="register_identity"
					value="<?php echo esc_attr( Flow::old( 'identity' ) ); ?>"
					inputmode="<?php echo Settings::phone_enabled() && ! Settings::email_enabled() ? 'tel' : 'text'; ?>"
					autocomplete="username"
					<?php echo $sl_register_disabled ? 'disabled' : ''; ?>
					required
				/>
			</div>

			<fieldset class="sl-password-block" aria-describedby="sl-password-security">
				<legend class="sl-password-block__title"><?php esc_html_e( 'Thiết lập mật khẩu', 'smart-login' ); ?></legend>

				<?php
				TemplateLoader::output(
					'partials/password-field',
					array(
						'name'         => 'register_password',
						'label'        => __( 'Mật khẩu', 'smart-login' ),
						'id'           => 'sl-reg-password',
						'autocomplete' => 'new-password',
						'minlength'    => $sl_min_length,
						'describedby'  => 'sl-password-guidance',
						'disabled'     => $sl_register_disabled,
					)
				);
				?>
				<p
					class="sl-hint sl-password-guidance"
					id="sl-password-guidance"
					data-sl-password-guidance
					data-minlength="<?php echo esc_attr( $sl_min_length ); ?>"
					hidden
				>
					<?php
					printf(
						/* translators: %d: minimum password length. */
						esc_html__( 'Mật khẩu cần có ít nhất %d ký tự.', 'smart-login' ),
						(int) $sl_min_length
					);
					?>
				</p>

				<?php
				TemplateLoader::output(
					'partials/password-field',
					array(
						'name'         => 'register_password_confirm',
						'label'        => __( 'Nhập lại mật khẩu', 'smart-login' ),
						'id'           => 'sl-reg-password-confirm',
						'autocomplete' => 'new-password',
						'minlength'    => $sl_min_length,
						'describedby'  => 'sl-password-match',
						'disabled'     => $sl_register_disabled,
					)
				);
				?>
				<p class="sl-password-match" id="sl-password-match" role="status" aria-live="polite" hidden></p>

				<p class="sl-password-security" id="sl-password-security">
					<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
						<path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-7-2a2 2 0 0 1 4 0v2h-4V6zm5 8H9v-2h6v2z" fill="currentColor"/>
					</svg>
					<span><?php esc_html_e( 'Mật khẩu của bạn được bảo vệ an toàn.', 'smart-login' ); ?></span>
				</p>
			</fieldset>

			<label class="sl-terms">
				<input type="checkbox" name="terms" value="1" <?php echo $sl_register_disabled ? 'disabled' : ''; ?> required />
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

			<button type="submit" class="sl-btn sl-btn--primary" data-sl-auth-submit="register" <?php echo $sl_register_disabled ? 'disabled' : ''; ?>><?php esc_html_e( 'Đăng ký', 'smart-login' ); ?></button>
	</section>
	</form>

	<?php if ( ! empty( $sl_providers ) ) : ?>
		<div class="sl-divider"><span><?php esc_html_e( 'Hoặc tiếp tục nhanh với', 'smart-login' ); ?></span></div>
		<div class="sl-provider-buttons">
			<?php foreach ( $sl_providers as $sl_provider ) : ?>
				<a
					class="sl-btn sl-btn--provider sl-btn--<?php echo esc_attr( $sl_provider->id() ); ?>"
					href="<?php echo esc_url( ProviderAuthController::start_url( $sl_provider->id(), $sl_redirect ) ); ?>"
					data-sl-provider="<?php echo esc_attr( $sl_provider->id() ); ?>"
					data-sl-provider-mode="login"
				>
					<span class="sl-provider-icon" aria-hidden="true">
						<?php echo esc_html( 'google' === $sl_provider->id() ? 'G' : 'Z' ); ?>
					</span>
					<span><?php echo esc_html( $sl_provider->label() ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
