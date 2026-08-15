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

<div class="omniwp omniwp--account sl-hub" data-sl-hub data-rest-url="<?php echo esc_url( rest_url( 'omniwp/v1/' ) ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"<?php echo ! empty( $order_id ) ? ' data-sl-initial-order="' . esc_attr( (string) $order_id ) . '"' : ''; ?>>

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

		<!-- Account Panels -->
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

		<?php
		/*
		 * Flush deferred forms (e.g. unlinking social providers).
		 */
		DeferredForms::flush();
		?>
	</main>

	<!-- Order Detail Modal Popup -->
	<?php TemplateLoader::output( 'account-hub/order-modal', array( 'user' => $user ) ); ?>

	<!-- Voucher Detail Modal Popup -->
	<?php TemplateLoader::output( 'account-hub/voucher-modal', array( 'user' => $user ) ); ?>

	<!-- Logout Confirmation Modal -->
	<?php TemplateLoader::output( 'account-hub/logout-modal', array( 'user' => $user ) ); ?>

	<!-- Settings Bottom Sheet (Mobile) -->
	<?php TemplateLoader::output( 'account-hub/settings-sheet', array( 'user' => $user ) ); ?>

</div>
