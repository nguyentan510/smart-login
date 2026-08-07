<?php
/**
 * The heading of one step — drawn only when this template is the outer surface.
 *
 * Every step used to print its own `<h2 class="sl-title">`, which is right on a
 * page and wrong inside the dialog: the shell has already drawn a heading, and
 * it has to, because that element is the dialog's accessible name through
 * `aria-labelledby`. Rendered in the dialog, each step therefore said its name
 * twice, forty pixels apart.
 *
 * The obvious fix is a condition in each of the six templates, which is six
 * copies of one rule and five chances to forget it — the shape of drift this
 * project has been bitten by often enough to have a section about. One owner
 * instead, and rule 14 asserts no step draws its own.
 *
 * `FragmentRenderer::title()` is the other half: it decides what the shell's
 * bar says per step, so the sentence exists once either way.
 *
 * Override at yourtheme/smart-login/partials/screen-title.php.
 *
 * @var string $text
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;

defined( 'ABSPATH' ) || exit;

// A fragment render is the only case where something else owns the heading, and
// Flow knows because it was told which page it is rendering for.
if ( '' !== Flow::base() ) {
	return;
}
?>
<h2 class="sl-title"><?php echo esc_html( $text ); ?></h2>
