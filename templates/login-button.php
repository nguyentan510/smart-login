<?php
/**
 * The signed-out header button.
 *
 * An **anchor**, not a button, and its `href` is the real sign-in page. With the
 * script blocked the visitor gets a link that works; the launcher intercepts the
 * click and never touches the href. When no page hosts the flow,
 * `Flow::login_url()` is '' and the fragment stands in.
 *
 * Override at yourtheme/smart-login/login-button.php
 *
 * @var string $label
 * @var string $step
 * @var string $href
 * @var string $class
 * @var bool   $collapse Hide the text below the breakpoint, leaving the icon.
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$sl_classes = 'sl-account-btn';

if ( ! empty( $collapse ) ) {
	$sl_classes .= ' sl-account-btn--collapse';
}

if ( '' !== (string) $class ) {
	$sl_classes .= ' ' . $class;
}
?>
<a
	class="<?php echo esc_attr( $sl_classes ); ?>"
	href="<?php echo esc_url( $href ); ?>"
	data-smart-login="<?php echo esc_attr( $step ); ?>"
>
	<span class="sl-account-btn__icon" aria-hidden="true">
		<?php echo IconSet::get( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup from a closed set. ?>
	</span>
	<span class="sl-account-btn__text"><?php echo esc_html( $label ); ?></span>
</a>
