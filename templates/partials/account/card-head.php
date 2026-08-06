<?php
/**
 * The heading row of one account card: its mark and its name.
 *
 * The only place `sl-card__icon` is written. Until 17.8 each of the four
 * partials carried its own copy, all four containing the same `&#9679;` — four
 * identical marks, which distinguish nothing, in four places nobody would think
 * to keep in step.
 *
 * Both values come from `AccountForm::sections_meta()`, which declares them
 * together, so a section cannot end up named in one file and marked in another.
 *
 * The `<h3>` is a real heading in the document outline; the mark is
 * `aria-hidden` because it repeats what the heading already says.
 *
 * Override at yourtheme/smart-login/partials/account/card-head.php
 *
 * @var string $sl_section One of profile, contact, address, password.
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\AccountForm;

defined( 'ABSPATH' ) || exit;

$sl_meta = AccountForm::sections_meta()[ (string) ( $sl_section ?? '' ) ] ?? null;

if ( null === $sl_meta ) {
	return;
}
?>
<h3 class="sl-card__title">
	<?php
	// Markup by definition, and it comes from plugin code — never from a request.
	?>
	<span class="sl-card__icon" aria-hidden="true"><?php echo $sl_meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<?php echo esc_html( $sl_meta['label'] ); ?>
</h3>
