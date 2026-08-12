<?php
/**
 * The account surface without WooCommerce.
 *
 * Same renderer and the same six partials as
 * templates/woocommerce/form-edit-account.php — only the wrapper differs,
 * because the two surfaces are saved by different things. Here FormController
 * owns the save; on the Woo page WC_Form_Handler does, and must.
 *
 * Rendered by the [smart_account] shortcode.
 *
 * Override at yourtheme/omniwp/account.php
 *
 * @var \OmniWP\Frontend\AccountForm $ow_form
 * @var array                            $notices
 *
 * @package OmniWP
 */

use OmniWP\Frontend\DeferredForms;
use OmniWP\Frontend\FormController;
use OmniWP\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

if ( ! $ow_form->user() ) {
	return;
}
?>
<div class="omniwp omniwp--account">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php $ow_form->output_status(); ?>

	<form class="sl-form" method="post" action="">

		<?php
		foreach ( $ow_form->sections() as $ow_section ) {
			$ow_form->output_section( $ow_section );
		}
		?>

		<div class="sl-savebar" data-sl-savebar>
			<p class="sl-savebar__state" data-sl-savebar-state role="status" aria-live="polite" hidden>
				<span class="sl-savebar__warn" aria-hidden="true">!</span>
				<span data-sl-savebar-text></span>
			</p>
			<?php wp_nonce_field( 'OMNIWP_save_profile' ); ?>
			<input type="hidden" name="<?php echo esc_attr( FormController::ACTION_FIELD ); ?>" value="save_profile" />
			<input type="hidden" name="_redirect" value="<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>" />
			<button type="reset" class="sl-btn sl-btn--ghost sl-btn--inline" data-sl-savebar-cancel><?php esc_html_e( 'Huỷ', 'omniwp' ); ?></button>
			<button type="submit" class="sl-btn sl-btn--primary sl-btn--inline"><?php esc_html_e( 'Lưu thay đổi', 'omniwp' ); ?></button>
		</div>
	</form>
	<?php
	/*
	 * Forms that could not be emitted where they are used, because HTML forbids
	 * a form inside a form. Their controls carry `form="…"` and live inside the
	 * cards above; the elements themselves land here, after the account form has
	 * closed. See DeferredForms for the measurement that made this necessary.
	 */
	DeferredForms::flush();
	?>

</div>
