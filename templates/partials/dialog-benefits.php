<?php
/**
 * The reasons to sign in, shown between the lead and the first field.
 *
 * **Empty by default, and that is the decision.** The layout comes from a
 * reference where the three badges read "Miễn phí vận chuyển", "Số 1 thuốc kê
 * đơn", "Giao nhanh trong 1 giờ" — claims belonging to one pharmacy. A plugin
 * that shipped those would be putting somebody else's marketing on every site
 * that installed it, and a plugin that invented its own would be making
 * promises it has no way to keep.
 *
 * So this renders nothing until a site says what its own reasons are:
 *
 *     add_filter( 'OMNIWP_dialog_benefits', function () {
 *         return array(
 *             array( 'icon' => '🚚', 'label' => 'Miễn phí vận chuyển' ),
 *             array( 'icon' => '⭐', 'label' => 'Ưu đãi riêng cho thành viên' ),
 *             array( 'icon' => '⚡', 'label' => 'Giao nhanh trong 1 giờ' ),
 *         );
 *     } );
 *
 * `icon` may be an emoji, a short string, or SVG markup — it is passed through
 * `wp_kses_post()`, so a filter can supply a real icon without the partial
 * having to know about images.
 *
 * Override at yourtheme/omniwp/partials/dialog-benefits.php.
 *
 * @package OmniWP
 */

defined( 'ABSPATH' ) || exit;

/*
 * A caller may pass the list directly; otherwise the filter decides. Both,
 * rather than only the filter, because a theme overriding this partial should
 * be able to hand it a list without registering a hook — and because the smoke
 * test renders every template from a fixture, and a template that can only ever
 * be driven by a filter is a template that suite can only ever see empty.
 */
if ( isset( $benefits ) && is_array( $benefits ) && array() !== $benefits ) {
	$ow_benefits = $benefits;
} else {
	/**
	 * Reasons to sign in, shown as a row of badges in the dialog.
	 *
	 * Each entry: `icon` (emoji, text or SVG) and `label` (one short line, or
	 * two). Return `array()` — the default — to show nothing.
	 *
	 * @param array<int,array{icon:string,label:string}> $benefits
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
