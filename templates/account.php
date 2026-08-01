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
 * Override at yourtheme/smart-login/account.php
 *
 * @var \SmartLogin\Frontend\AccountForm $sl_form
 * @var array                            $notices
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\FormController;
use SmartLogin\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

if ( ! $sl_form->user() ) {
	return;
}
?>
<div class="smart-login smart-login--account">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php $sl_form->output_status(); ?>

	<form class="sl-form" method="post" action="">

		<?php
		foreach ( $sl_form->sections() as $sl_section ) {
			$sl_form->output_section( $sl_section );
		}
		?>

		<div class="sl-savebar" data-sl-savebar>
			<span class="sl-savebar__state" data-sl-savebar-state aria-live="polite"></span>
			<?php wp_nonce_field( 'smart_login_save_profile' ); ?>
			<input type="hidden" name="<?php echo esc_attr( FormController::ACTION_FIELD ); ?>" value="save_profile" />
			<input type="hidden" name="_redirect" value="<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>" />
			<button type="submit" class="sl-btn sl-btn--primary"><?php esc_html_e( 'Cập nhật', 'smart-login' ); ?></button>
		</div>
	</form>
</div>
