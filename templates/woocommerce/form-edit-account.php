<?php
/**
 * Replaces WooCommerce's myaccount/form-edit-account.php.
 *
 * An adapter, not a page. Everything visible comes from
 * templates/partials/account/*, assembled by Frontend\AccountForm, so this file
 * owns only what belongs to WooCommerce: its nonce, its `save_account_details`
 * field, and the four hook points third-party plugins inject their own fields
 * into.
 *
 * WooCommerce keeps the save. WC_Form_Handler::save_account_details() still runs,
 * because other plugins hook woocommerce_save_account_details and
 * woocommerce_save_account_details_errors; taking that over would stop them
 * writing with no error to show for it. WooIntegration::prepare_account_post()
 * translates this form's field names into the ones Woo expects.
 *
 * Override at yourtheme/woocommerce/myaccount/form-edit-account.php
 *
 * @package OmniWP
 */

use OmniWP\Frontend\AccountForm;
use OmniWP\Frontend\DeferredForms;

defined( 'ABSPATH' ) || exit;

$ow_form = new AccountForm( get_current_user_id(), AccountForm::CONTEXT_WOOCOMMERCE );

if ( ! $ow_form->user() ) {
	return;
}

do_action( 'woocommerce_before_edit_account_form' );
?>

<div class="omniwp omniwp--account">

	<?php $ow_form->output_status(); ?>

	<form class="woocommerce-EditAccountForm edit-account sl-form" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?> >

		<?php do_action( 'woocommerce_edit_account_form_start' ); ?>

		<?php
		foreach ( $ow_form->sections() as $ow_section ) {
			$ow_form->output_section( $ow_section );
		}
		?>

		<?php do_action( 'woocommerce_edit_account_form' ); ?>

		<div class="sl-savebar" data-sl-savebar>
			<p class="sl-savebar__state" data-sl-savebar-state role="status" aria-live="polite" hidden>
				<span class="sl-savebar__warn" aria-hidden="true">!</span>
				<span data-sl-savebar-text></span>
			</p>
			<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
			<button type="reset" class="sl-btn sl-btn--ghost sl-btn--inline" data-sl-savebar-cancel><?php esc_html_e( 'Huỷ', 'omniwp' ); ?></button>
			<button type="submit" class="sl-btn sl-btn--primary sl-btn--inline woocommerce-Button button" name="save_account_details" value="<?php esc_attr_e( 'Lưu thay đổi', 'omniwp' ); ?>">
				<?php esc_html_e( 'Lưu thay đổi', 'omniwp' ); ?>
			</button>
			<input type="hidden" name="action" value="save_account_details" />
		</div>

		<?php do_action( 'woocommerce_edit_account_form_end' ); ?>
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

<?php do_action( 'woocommerce_after_edit_account_form' ); ?>
