<?php
/**
 * The mobile bottom dock.
 *
 * @package OmniWP
 * @var array<int,array<string,mixed>> $slots Resolved slots, in order.
 */

use OmniWP\Navigation\Dock;

defined( 'ABSPATH' ) || exit;

if ( empty( $slots ) ) {
	return;
}

$ow_current = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
?>
<nav class="ow-dock" aria-label="<?php esc_attr_e( 'Điều hướng nhanh', 'omniwp' ); ?>" data-ow-dock="1">
	<ul class="ow-dock__list">
		<?php foreach ( $slots as $ow_slot ) : ?>
			<?php
			$ow_url    = is_callable( $ow_slot['url'] ) ? (string) call_user_func( $ow_slot['url'] ) : '';
			$ow_name   = (string) ( $ow_slot['name'] ?? '' );
			$ow_active = '' !== $ow_url && '' !== $ow_current && untrailingslashit( wp_parse_url( $ow_url, PHP_URL_PATH ) ?? '' ) === untrailingslashit( wp_parse_url( $ow_current, PHP_URL_PATH ) ?? '' );
			?>
			<li class="ow-dock__item ow-dock__item--<?php echo esc_attr( $ow_name ); ?>">
				<a class="ow-dock__link<?php echo $ow_active ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $ow_url ); ?>"
					<?php echo $ow_active ? ' aria-current="page"' : ''; ?>>
					<span class="ow-dock__icon">
						<?php
						// IconSet returns a fixed SVG for a name it knows and the fallback for one it does not.
						echo Dock::icon( (string) ( $ow_slot['icon'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<?php
						if ( ! empty( $ow_slot['badge'] ) ) {
							/*
							 * Empty on purpose. This markup goes into a page that may be
							 * cached; WooCommerce's fragment refresh replaces it with the
							 * visitor's own count. A number printed here would be the
							 * previous visitor's — docs/navigation.md §1.3.
							 */
							echo Dock::badge_markup( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</span>
					<span class="ow-dock__label"><?php echo esc_html( (string) ( $ow_slot['label'] ?? '' ) ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
