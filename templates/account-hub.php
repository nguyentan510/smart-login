<?php
/**
 * Main Account Hub Template — 2-column Customer Portal.
 *
 * Integrates native OmniWP AccountForm partials (status, profile, contact,
 * address, password, providers, savebar, and deferred forms).
 *
 * @var \WP_User                         $user
 * @var \OmniWP\Frontend\AccountForm $ow_form
 * @var array                            $notices
 * @var array                            $tabs
 * @var string                           $active_tab
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

<div class="omniwp omniwp--account sl-hub" data-sl-hub data-rest-url="<?php echo esc_url( rest_url( 'omniwp/v1/' ) ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">

	<!-- Sidebar (Left Column) -->
	<aside class="sl-hub-sidebar">
		<?php
		TemplateLoader::output(
			'account-hub/sidebar',
			array(
				'user'       => $user,
				'tabs'       => $tabs,
				'active_tab' => $active_tab,
			)
		);
		?>
	</aside>

	<!-- Main Content Area (Right Column) -->
	<main class="sl-hub-content">
		<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

		<!-- Progress / Completion Nudge Banner (Hoàn thiện 1/4) -->
		<?php $ow_form->output_status(); ?>

		<!-- Account Details Form -->
		<form class="sl-form" method="post" action="">
			<?php foreach ( $tabs as $tab_key => $hub_tab ) : ?>
				<?php if ( empty( $hub_tab['is_logout'] ) ) : ?>
					<div class="sl-hub-panel" data-sl-hub-panel="<?php echo esc_attr( $tab_key ); ?>" style="<?php echo $tab_key === $active_tab ? '' : 'display:none;'; ?>">
						<?php
						if ( ! empty( $hub_tab['template'] ) ) {
							TemplateLoader::output(
								$hub_tab['template'],
								array(
									'user'    => $user,
									'ow_form' => $ow_form,
									'tab'     => $hub_tab,
								)
							);
						}
						?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>

			<!-- Floating Savebar -->
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
		 * Flush deferred forms (e.g. unlinking social providers).
		 */
		DeferredForms::flush();
		?>
	</main>

	<!-- Address Form Modal Popup (Outside main form to prevent nesting) -->
	<?php TemplateLoader::output( 'account-hub/address-modal', array( 'user' => $user ) ); ?>

	<!-- Order Detail Modal Popup -->
	<?php TemplateLoader::output( 'account-hub/order-modal', array( 'user' => $user ) ); ?>

	<!-- Voucher Detail Modal Popup -->
	<?php TemplateLoader::output( 'account-hub/voucher-modal', array( 'user' => $user ) ); ?>

	<!-- Logout Confirmation Modal -->
	<?php TemplateLoader::output( 'account-hub/logout-modal', array( 'user' => $user ) ); ?>

</div>
