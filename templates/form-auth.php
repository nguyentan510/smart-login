<?php
/**
 * Step 1 of everything: one identifier.
 *
 * There is no login/register choice here by design. A visitor rarely knows
 * which one they need — the two-tab box that lived here made them guess, and
 * guessing wrong cost them a retyped form and an error message. The server
 * resolves the identifier and picks the branch.
 *
 * Override at yourtheme/smart-login/form-auth.php.
 *
 * @var array  $notices
 * @var string $mode      login|register — wording only, never which form shows.
 * @var string $terms_url
 *
 * @package SmartLogin
 */

use SmartLogin\Auth\ProviderAuthController;
use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Auth\RegisterHandler;
use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Security\RequestGuard;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

$sl_redirect  = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$sl_providers = ( new ProviderRegistry() )->available();
$sl_register  = 'register' === ( $mode ?? 'login' );
$sl_phone     = Settings::phone_enabled();
$sl_email     = Settings::email_enabled();
?>
<div class="smart-login smart-login--identify">

	<h2 class="sl-title">
		<?php
		echo $sl_register
			? esc_html__( 'Tạo tài khoản', 'smart-login' )
			: esc_html__( 'Đăng nhập hoặc đăng ký', 'smart-login' );
		?>
	</h2>

	<?php
	/*
	 * One sentence, and it is an instruction. The second sentence this used to
	 * carry — "Chúng tôi sẽ tự nhận ra bạn đã có tài khoản hay chưa" — described
	 * what the server does with the answer, which is not something the visitor
	 * can act on, and it sat between the heading and the only input on the page.
	 * The reassurance is not lost: "Đăng nhập hoặc đăng ký" above says the same
	 * thing in four words, which is the whole reason there is no login/register
	 * choice on this screen.
	 */
	?>
	<p class="sl-lead">
		<?php
		if ( $sl_phone && ! $sl_email ) {
			esc_html_e( 'Nhập số điện thoại để tiếp tục.', 'smart-login' );
		} elseif ( $sl_email && ! $sl_phone ) {
			esc_html_e( 'Nhập email để tiếp tục.', 'smart-login' );
		} else {
			esc_html_e( 'Nhập số điện thoại hoặc email để tiếp tục.', 'smart-login' );
		}
		?>
	</p>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<form method="post" class="sl-form sl-form--identify">
		<?php RequestGuard::fields( 'identify' ); ?>
		<?php echo \SmartLogin\Security\Captcha::field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
		<input type="hidden" name="smart_login_action" value="identify" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $sl_redirect ); ?>" />

		<div class="sl-field">
			<label class="sl-label" for="sl-identity">
				<?php echo esc_html( RegisterHandler::identifier_label() ); ?>
				<span class="sl-required">*</span>
			</label>
			<input
				<?php if ( $sl_email && ! $sl_phone ) : ?>
					type="email"
					inputmode="email"
				<?php elseif ( $sl_phone && ! $sl_email ) : ?>
					type="tel"
					inputmode="tel"
				<?php else : ?>
					type="text"
					inputmode="text"
				<?php endif; ?>
				class="sl-input sl-input--lg"
				id="sl-identity"
				name="identity"
				value="<?php echo esc_attr( Flow::old( 'identity' ) ); ?>"
				placeholder="<?php echo $sl_phone ? esc_attr__( '0969 789 475', 'smart-login' ) : esc_attr__( 'ban@example.com', 'smart-login' ); ?>"
				autocomplete="username"
				autocapitalize="none"
				autocorrect="off"
				spellcheck="false"
				required
				autofocus
			/>
		</div>

		<button type="submit" class="sl-btn sl-btn--primary sl-btn--block">
			<?php esc_html_e( 'Tiếp tục', 'smart-login' ); ?>
		</button>
	</form>

	<?php if ( ! empty( $sl_providers ) ) : ?>
		<?php
		/*
		 * Just "Hoặc". The divider used to read "Hoặc tiếp tục nhanh với" directly
		 * above two buttons that each say "Tiếp tục với …", so the same three words
		 * appeared three times in forty pixels. The button copy is the half that
		 * cannot move — "Continue with Google" is one of the strings Google's
		 * branding guidelines permit, and inventing a shorter one is not an option.
		 */
		?>
		<div class="sl-divider"><span><?php esc_html_e( 'Hoặc', 'smart-login' ); ?></span></div>
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

	<?php if ( '' !== (string) ( $terms_url ?? '' ) ) : ?>
		<p class="sl-hint sl-terms-note">
			<?php
			printf(
				/* translators: %s: linked terms and conditions label. */
				wp_kses_post( __( 'Khi tiếp tục, bạn đồng ý với %s.', 'smart-login' ) ),
				'<a class="sl-link" href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'các điều khoản áp dụng', 'smart-login' ) . '</a>'
			);
			?>
		</p>
	<?php endif; ?>
</div>
