<?php
/**
 * The signed-in header button and its menu.
 *
 * `<details>` on purpose. It opens, closes, takes keyboard focus and announces
 * itself with **no JavaScript at all** — 21.6 adds outside-click, Escape and
 * `aria-expanded` on top, and if that file never loads the member still has a
 * working account menu. A plugin must not own a failure mode in which its own
 * script is the only thing keeping basic navigation alive.
 *
 * There is deliberately no `data-omniwp` anywhere below: a signed-in
 * visitor has nothing to sign in to, and the launcher is not even enqueued for
 * them.
 *
 * Override at yourtheme/omniwp/account-button.php
 *
 * @var string $label    What to call the member — see Shortcodes::account_label().
 * @var array  $items    AccountMenu::items(); each entry is key/label/icon/url.
 * @var string $class
 * @var bool   $collapse Hide the text below the breakpoint, leaving the icon.
 *
 * @package OmniWP
 */

use OmniWP\Frontend\AccountMenu;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$ow_classes = 'sl-account-btn';

if ( ! empty( $collapse ) ) {
	$ow_classes .= ' sl-account-btn--collapse';
}

if ( '' !== (string) $class ) {
	$ow_classes .= ' ' . $class;
}
?>
<details class="sl-account" data-sl-account>
	<summary class="<?php echo esc_attr( $ow_classes ); ?>">
		<span class="sl-account-btn__icon" aria-hidden="true">
			<?php echo IconSet::get( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup from a closed set. ?>
		</span>
		<span class="sl-account-btn__text"><?php echo esc_html( $label ); ?></span>
	</summary>

	<nav class="sl-account__menu" aria-label="<?php esc_attr_e( 'Tài khoản', 'omniwp' ); ?>">
		<ul class="sl-account__list">
			<?php foreach ( (array) $items as $ow_item ) : ?>
				<?php
				// The tail is separated rather than merely last. A design
				// constant, not a setting: leaving is not one more destination.
				$ow_row = AccountMenu::KEY_LOGOUT === $ow_item['key']
					? 'sl-account__item sl-account__item--logout'
					: 'sl-account__item';
				?>
				<li class="<?php echo esc_attr( $ow_row ); ?>">
					<a class="sl-account__link" href="<?php echo esc_url( $ow_item['url'] ); ?>">
						<span class="sl-account__icon" aria-hidden="true">
							<?php echo IconSet::get( $ow_item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconSet resolves a closed set; an unknown name folds to the fallback. ?>
						</span>
						<?php echo esc_html( $ow_item['label'] ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
</details>
