<?php
/**
 * Template for OmniWP Floating Cart Bubble.
 *
 * @package OmniWP
 * @var array $cart Cart payload data.
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$item_count = (int) ( $cart['item_count'] ?? 0 );
?>
<div id="sl-floating-cart" class="sl-floating-cart <?php echo $item_count > 0 ? 'sl-floating-cart--has-items' : ''; ?>" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Mở giỏ hàng', 'omniwp' ); ?>">
	<div class="sl-floating-cart__icon-wrap">
		<?php echo IconSet::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span class="sl-floating-cart__badge" id="sl-floating-cart-badge"><?php echo esc_html( (string) $item_count ); ?></span>
	</div>
	<div class="sl-floating-cart__details">
		<span class="sl-floating-cart__label"><?php esc_html_e( 'Giỏ hàng', 'omniwp' ); ?></span>
		<strong class="sl-floating-cart__total" id="sl-floating-cart-total"><?php echo wp_kses_post( $cart['subtotal_html'] ?? '' ); ?></strong>
	</div>
</div>
