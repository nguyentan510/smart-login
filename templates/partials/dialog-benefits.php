<?php
/**
 * The reasons to sign in, shown between the lead and the first field.
 *
 * An empty list hides the entire row — no placeholders, no empty boxes,
 * no "1, 2, 3" defaults with nothing in them. Every theme will want a
 * different three reasons and half of all sites will want none at all.
 *
 * Override at yourtheme/omniwp/partials/dialog-benefits.php or populate via
 * `add_filter( 'omniwp_dialog_benefits', ... )`.
 *
 * @package OmniWP
 */

defined( 'ABSPATH' ) || exit;

/*
 * A caller may pass the list directly; otherwise the filter decides.
 */
if ( isset( $benefits ) && is_array( $benefits ) ) {
	$ow_benefits = $benefits;
} else {
	/**
	 * Reasons to sign in, shown as a row of badges in the dialog.
	 *
	 * Each entry: `icon` (emoji, text or SVG) and `label` (one short line, or
	 * two). Return `array()` to hide.
	 *
	 * @param array<int,array{icon:string,label:string}> $benefits Default empty.
	 */
	$ow_benefits = (array) apply_filters( 'omniwp_dialog_benefits', array() );
}

if ( array() === $ow_benefits ) {
	return;
}

// Three is what the row is built for; more wraps, which is worse than fewer.
$ow_benefits = array_slice( $ow_benefits, 0, 3 );
?>
<ul class="sl-benefits" role="list">
	<?php foreach ( $ow_benefits as $ow_benefit ) : ?>
		<li class="sl-benefit">
			<span class="sl-benefit__mark" aria-hidden="true">
				<?php echo wp_kses_post( (string) ( $ow_benefit['icon'] ?? '' ) ); ?>
			</span>
			<span class="sl-benefit__label">
				<?php echo esc_html( (string) ( $ow_benefit['label'] ?? '' ) ); ?>
			</span>
		</li>
	<?php endforeach; ?>
</ul>
