<?php
/**
 * The mega panel, and the mobile category sheet. One markup, two shapes.
 *
 * F1 is a rail of buttons; each F1 entry owns a pane holding its F2 columns, and
 * each column lists its F3. On a wide screen the rail and the active pane sit
 * side by side inside a dropdown; on a narrow one the same two elements fill the
 * screen. Nothing here knows which is happening — see omniwp-navigation.css.
 *
 * @package OmniWP
 * @var \OmniWP\Navigation\Tree $tree     The branch this panel shows.
 * @var string                  $panel_id DOM id, referenced by the toggle's aria-controls.
 * @var string                  $label    The menu item's own label.
 */

use OmniWP\Navigation\MegaPanel;

defined( 'ABSPATH' ) || exit;

if ( ! isset( $tree ) || $tree->is_empty() ) {
	return;
}

$ow_roots = $tree->roots();

?>
<div class="ow-mega" id="<?php echo esc_attr( $panel_id ); ?>" data-ow-mega="1" hidden>
	<div class="ow-mega__inner">
		<div class="ow-mega__rail" role="tablist" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php foreach ( $ow_roots as $ow_index => $ow_root ) : ?>
				<?php
				$ow_pane_id  = $panel_id . '-pane-' . $ow_index;
				$ow_rail_cls = 'ow-mega__rail-item';
				$ow_device   = $ow_root->device_class();

				if ( '' !== $ow_device ) {
					$ow_rail_cls .= ' ' . $ow_device;
				}
				?>
				<button type="button"
					class="<?php echo esc_attr( $ow_rail_cls ); ?><?php echo 0 === $ow_index ? ' is-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo 0 === $ow_index ? 'true' : 'false'; ?>"
					aria-controls="<?php echo esc_attr( $ow_pane_id ); ?>"
					data-ow-mega-rail="<?php echo esc_attr( $ow_pane_id ); ?>">
					<?php
					$ow_icon = (string) ( $ow_root->visual()['image'] ?? '' );
					if ( '' !== $ow_icon ) :
						?>
						<span class="ow-mega__rail-thumb"><img src="<?php echo esc_url( $ow_icon ); ?>" alt="" loading="lazy" decoding="async" /></span>
					<?php endif; ?>
					<span class="ow-mega__rail-text"><?php echo esc_html( $ow_root->label() ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="ow-mega__panes">
			<?php foreach ( $ow_roots as $ow_index => $ow_root ) : ?>
				<?php $ow_pane_id = $panel_id . '-pane-' . $ow_index; ?>
				<div class="ow-mega__pane<?php echo 0 === $ow_index ? ' is-active' : ''; ?>"
					id="<?php echo esc_attr( $ow_pane_id ); ?>"
					role="tabpanel"
					<?php echo 0 === $ow_index ? '' : 'hidden'; ?>>

					<div class="ow-mega__columns">
						<?php foreach ( $ow_root->children() as $ow_child ) : ?>
							<div class="ow-mega__column">
								<?php
								/*
								 * A `group` node is a heading that owns its children and is
								 * not itself a destination. Everything else at this level is,
								 * so it stays a link even when it has children under it —
								 * "Sữa Mỹ" is a category page as well as a column header.
								 */
								if ( 'group' === $ow_child->type() ) :
									?>
									<p class="ow-mega__heading"><?php echo esc_html( $ow_child->label() ); ?></p>
								<?php else : ?>
									<p class="ow-mega__heading"><?php echo MegaPanel::link( $ow_child, 'f2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
								<?php endif; ?>

								<?php if ( $ow_child->has_children() ) : ?>
									<ul class="ow-mega__list">
										<?php foreach ( $ow_child->children() as $ow_leaf ) : ?>
											<li class="ow-mega__list-item">
												<?php echo MegaPanel::link( $ow_leaf, 'f3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>

					<?php if ( '' !== $ow_root->url() ) : ?>
						<?php
						/*
						 * Not decoration. A panel never holds a whole catalog, and every
						 * reference store puts this link at the foot of the branch rather
						 * than adding a fourth level nobody scrolls to.
						 */
						?>
						<a class="ow-mega__all" href="<?php echo esc_url( $ow_root->url() ); ?>">
							<?php
							printf(
								/* translators: %s: category name. */
								esc_html__( 'Xem tất cả %s', 'omniwp' ),
								esc_html( $ow_root->label() )
							);
							?>
						</a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
