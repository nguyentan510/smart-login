<?php
/**
 * Step 1 of everything: one identifier.
 *
 * There is no login/register choice here by design. A visitor rarely knows
 * which one they need — the two-tab box that lived here made them guess, and
 * guessing wrong cost them a retyped form and an error message. The server
 * resolves the identifier and picks the branch.
 *
 * Override at yourtheme/omniwp/form-auth.php.
 *
 * @var array  $notices
 * @var string $mode      login|register — wording only, never which form shows.
 * @var string $terms_url
 *
 * @package OmniWP
 */

use OmniWP\Auth\ProviderAuthController;
use OmniWP\Auth\Providers\ProviderRegistry;
use OmniWP\Auth\RegisterHandler;
use OmniWP\Frontend\Flow;
use OmniWP\Frontend\ProviderMark;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Security\RequestGuard;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

/*
 * Not `$_GET` directly, since 19.2. This template renders in two places now: a
 * page, where the query string is the visitor's, and a REST fragment fetched by
 * the dialog, where the query string belongs to the API request. `Flow` knows
 * which, and validates either way.
 */
$ow_redirect  = Flow::redirect_to();
$ow_providers = ( new ProviderRegistry() )->available();
$ow_register  = 'register' === ( $mode ?? 'login' );
$ow_phone     = Settings::phone_enabled();
$ow_email     = Settings::email_enabled();

/*
 * Scoped, not literal — since 19.3.
 *
 * This template used to hard-code `id="sl-identity"`. That is fine on a page
 * that renders it once and wrong the moment a page renders it twice, which is
 * exactly what the dialog makes possible: a sign-in page carrying the shortcode
 * *and* the shell has two elements with one id, and a `<label for>` that
 * resolves to whichever the parser met first.
 *
 * `autofocus` went the same way. Two controls claiming focus is one too many,
 * and the dialog applies focus on open — which is the only place that knows
 * whether the visitor is looking at this form or at the page behind it.
 */
$ow_identity_id = wp_unique_id( 'sl-identity-' );

/*
 * Whether a dialog is rendering this, which the provider round trip has to
 * know: Google is a full-page navigation either way, so the return has to
 * remember to come back here and reopen. See ProviderAuthController's marker.
 */
$ow_in_dialog = '' !== Flow::base();
?>
<div class="omniwp omniwp--identify<?php echo $ow_in_dialog ? ' omniwp--in-dialog' : ''; ?>">

	<?php
	/*
	 * The heading belongs to whichever surface is the outer one, and
	 * `partials/screen-title` is the single place that knows which. Inside the
	 * dialog the shell has already drawn one — it has to, because that element
	 * is the dialog's accessible name via `aria-labelledby` — and drawing a
	 * second put the same four words on screen twice, forty pixels apart.
	 */
	TemplateLoader::output(
		'partials/screen-title',
		array(
			'text' => $ow_register
				? __( 'Tạo tài khoản', 'omniwp' )
				: __( 'Đăng nhập hoặc đăng ký', 'omniwp' ),
		)
	);
	?>

	<?php
	/*
	 * One sentence, and on a page it is an instruction. The second sentence this
	 * used to carry — "Chúng tôi sẽ tự nhận ra bạn đã có tài khoản hay chưa" —
	 * described what the server does with the answer, which is not something the
	 * visitor can act on, and it sat between the heading and the only input on
	 * the page.
	 *
	 * In the dialog it says something else, and that is a decision rather than
	 * an oversight. The instruction is already carried by the field's own label
	 * — "Số điện thoại hoặc Email" is directly below it — so repeating it there
	 * spends the one line under the title restating the next line. A visitor who
	 * has just been interrupted mid-shopping is owed a reason instead.
	 */
	?>
	<p class="sl-lead">
		<?php
		if ( $ow_in_dialog ) {
			esc_html_e( 'Vui lòng đăng nhập để hưởng những đặc quyền dành cho thành viên.', 'omniwp' );
		} elseif ( $ow_phone && ! $ow_email ) {
			esc_html_e( 'Nhập số điện thoại để tiếp tục.', 'omniwp' );
		} elseif ( $ow_email && ! $ow_phone ) {
			esc_html_e( 'Nhập email để tiếp tục.', 'omniwp' );
		} else {
			esc_html_e( 'Nhập số điện thoại hoặc email để tiếp tục.', 'omniwp' );
		}
		?>
	</p>

	<?php
	// Only in the dialog: on a page the surrounding content is the site's own
	// argument for signing up, and repeating it inside the form would be the
	// plugin talking over the page it was placed on.
	if ( $ow_in_dialog ) {
		TemplateLoader::output( 'partials/dialog-benefits' );
	}
	?>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<form method="post" class="sl-form sl-form--identify">
		<?php RequestGuard::fields( 'identify' ); ?>
		<?php echo \OmniWP\Security\Captcha::field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
		<input type="hidden" name="OMNIWP_action" value="identify" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $ow_redirect ); ?>" />

		<div class="sl-field">
			<?php
			/*
			 * The label stays in the DOM inside the dialog and only stops being
			 * visible. 18.2 removed a placeholder-only input from this plugin
			 * because "a placeholder is not a name — it is a hint that
			 * disappears the moment somebody types". That objection is to having
			 * no accessible name at all, and a visually hidden label has one.
			 *
			 * Visible on a page, where there is room and no title above it doing
			 * the same job.
			 */
			?>
			<label class="sl-label<?php echo $ow_in_dialog ? ' sl-label--sr' : ''; ?>" for="<?php echo esc_attr( $ow_identity_id ); ?>">
				<?php echo esc_html( RegisterHandler::identifier_label() ); ?>
				<span class="sl-required">*</span>
			</label>
			<input
				<?php if ( $ow_email && ! $ow_phone ) : ?>
					type="email"
					inputmode="email"
				<?php elseif ( $ow_phone && ! $ow_email ) : ?>
					type="tel"
					inputmode="tel"
				<?php else : ?>
					type="text"
					inputmode="text"
				<?php endif; ?>
				class="sl-input sl-input--lg"
				id="<?php echo esc_attr( $ow_identity_id ); ?>"
				name="identity"
				value="<?php echo esc_attr( Flow::old( 'identity' ) ); ?>"
				<?php
				$ow_placeholder = $ow_phone ? __( '0969 789 475', 'omniwp' ) : __( 'ban@example.com', 'omniwp' );

				if ( $ow_in_dialog ) {
					// The hidden label's words, so the field is never unlabelled
					// on screen even though the <label> is only for the reader.
					$ow_placeholder = sprintf(
						/* translators: %s: identifier label, e.g. "Số điện thoại hoặc Email". */
						__( 'Nhập %s', 'omniwp' ),
						mb_strtolower( RegisterHandler::identifier_label() )
					);
				}
				?>
				placeholder="<?php echo esc_attr( $ow_placeholder ); ?>"
				autocomplete="username"
				autocapitalize="none"
				autocorrect="off"
				spellcheck="false"
				required
			/>
		</div>

		<button type="submit" class="sl-btn sl-btn--primary sl-btn--block">
			<?php esc_html_e( 'Tiếp tục', 'omniwp' ); ?>
		</button>
	</form>

	<?php if ( ! empty( $ow_providers ) ) : ?>
		<?php
		/*
		 * Just "Hoặc". The divider used to read "Hoặc tiếp tục nhanh với" directly
		 * above two buttons that each say "Tiếp tục với …", so the same three words
		 * appeared three times in forty pixels. The button copy is the half that
		 * cannot move — "Continue with Google" is one of the strings Google's
		 * branding guidelines permit, and inventing a shorter one is not an option.
		 */
		?>
		<div class="sl-divider"><span><?php esc_html_e( 'Hoặc', 'omniwp' ); ?></span></div>
		<div class="sl-provider-buttons">
			<?php foreach ( $ow_providers as $ow_provider ) : ?>
				<a
					class="sl-btn sl-btn--provider sl-btn--<?php echo esc_attr( $ow_provider->id() ); ?>"
					href="<?php echo esc_url( ProviderAuthController::start_url( $ow_provider->id(), $ow_redirect, false, $ow_in_dialog ) ); ?>"
					data-sl-provider="<?php echo esc_attr( $ow_provider->id() ); ?>"
					data-sl-provider-mode="login"
				>
					<?php
					/*
					 * The provider owns its mark; ProviderMark only places it. The box,
					 * the filter and the escaping decision moved there in 17.1, when the
					 * account card became the second caller — this was the only place
					 * that applied `omniwp_provider_icon_svg`, so a site filtering
					 * it would have got a mark on one screen and not the other.
					 */
					ProviderMark::output_for_provider( $ow_provider );
					?>
					<span><?php echo esc_html( $ow_provider->label() ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== (string) ( $terms_url ?? '' ) ) : ?>
		<p class="sl-hint sl-terms-note">
			<?php
			printf(
				/* translators: %s: linked terms and conditions label. */
				wp_kses_post( __( 'Khi tiếp tục, bạn đồng ý với %s.', 'omniwp' ) ),
				'<a class="sl-link" href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'các điều khoản áp dụng', 'omniwp' ) . '</a>'
			);
			?>
		</p>
	<?php endif; ?>
</div>
