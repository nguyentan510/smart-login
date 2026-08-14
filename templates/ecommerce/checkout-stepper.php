<?php
/**
 * Template partial for OmniWP Unified Checkout Stepper (3-step progress bar).
 *
 * @package OmniWP
 * @var int $active_step Active step index (1: Cart, 2: Checkout, 3: Order Received). Default 1.
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$ow_step      = isset( $active_step ) ? (int) $active_step : 1;
$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' );
$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' );
?>
<div class="sl-checkout-stepper-wrap sl-no-print">
	<div class="sl-checkout-stepper">
		
		<!-- Step 1: Cart -->
		<?php if ( $ow_step > 1 ) : ?>
			<a href="<?php echo esc_url( $cart_url ); ?>" class="sl-stepper-item is-completed sl-stepper-link" title="<?php esc_attr_e( 'Quay lại Giỏ hàng', 'omniwp' ); ?>">
				<div class="sl-stepper-icon">
					<?php echo IconSet::get( 'check-simple' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<span class="sl-stepper-title"><?php esc_html_e( 'Giỏ hàng', 'omniwp' ); ?></span>
			</a>
		<?php else : ?>
			<div class="sl-stepper-item <?php echo 1 === $ow_step ? 'is-active' : ''; ?>">
				<div class="sl-stepper-icon">
					<?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<span class="sl-stepper-title"><?php esc_html_e( 'Giỏ hàng', 'omniwp' ); ?></span>
			</div>
		<?php endif; ?>

		<div class="sl-stepper-line <?php echo $ow_step >= 2 ? 'is-active' : ''; ?>"></div>

		<!-- Step 2: Checkout -->
		<?php if ( $ow_step > 2 ) : ?>
			<a href="<?php echo esc_url( $checkout_url ); ?>" class="sl-stepper-item is-completed sl-stepper-link" title="<?php esc_attr_e( 'Quay lại Thanh toán', 'omniwp' ); ?>">
				<div class="sl-stepper-icon">
					<?php echo IconSet::get( 'check-simple' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<span class="sl-stepper-title"><?php esc_html_e( 'Thanh toán', 'omniwp' ); ?></span>
			</a>
		<?php else : ?>
			<div class="sl-stepper-item <?php echo 2 === $ow_step ? 'is-active' : ''; ?>">
				<div class="sl-stepper-icon">
					<?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<span class="sl-stepper-title"><?php esc_html_e( 'Thanh toán', 'omniwp' ); ?></span>
			</div>
		<?php endif; ?>

		<div class="sl-stepper-line <?php echo $ow_step >= 3 ? 'is-active' : ''; ?>"></div>

		<!-- Step 3: Thank You / Complete -->
		<div class="sl-stepper-item <?php echo 3 === $ow_step ? 'is-active is-completed' : ''; ?>">
			<div class="sl-stepper-icon">
				<?php echo IconSet::get( 3 === $ow_step ? 'check-simple' : 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<span class="sl-stepper-title"><?php esc_html_e( 'Hoàn tất', 'omniwp' ); ?></span>
		</div>

	</div>
</div>
