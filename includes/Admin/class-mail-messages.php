<?php
/**
 * The message list, and one editor open at a time.
 *
 * Phase 11 put twenty fields on this screen — six of them 8-row textareas —
 * which is the wall Phase 10 was created to remove from the delivery tab,
 * rebuilt one phase later somewhere else. Six messages in one column, with no
 * way to see which are customised short of reading all twelve boxes.
 *
 * Showing one panel at a time is done with JavaScript here, which looks like a
 * contradiction of 10.6 and is not. There the panels belonged to *different
 * tabs*, and `Settings::sanitize()` writes only the fields carried by the tab
 * named in the POST — hiding them would have dissolved the boundary that stops
 * one screen saving another's fields. Here every field belongs to one tab and
 * one save, so hiding a panel changes nothing about what is posted.
 *
 * Which is why every panel is rendered even when hidden. Rendering only the open
 * one is the obvious optimisation and it would silently stop five messages being
 * saved, because an absent field reads as "not on this tab" and the stored value
 * is left alone. The mail suite asserts this.
 *
 * @package OmniWP
 */

namespace OmniWP\Admin;

use OmniWP\Mail\MailRegistry;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class MailMessages {

	/**
	 * @param array<string,array> $fields Every registry row on this tab.
	 */
	public function render( array $fields ): void {
		$messages = MailRegistry::by_group();
		$first    = (string) array_key_first( $messages );
		?>
		<div class="sl-mail-surface">
			<table class="widefat striped sl-mail-list">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Email', 'omniwp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Gửi khi nào', 'omniwp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Nội dung', 'omniwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $messages as $id => $row ) : ?>
						<?php $this->row( (string) $id, $row, (string) $id === $first ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php foreach ( $messages as $id => $row ) : ?>
				<?php $this->panel( (string) $id, $row, $fields, (string) $id === $first ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * One line in the list.
	 *
	 * A button rather than a link: a link needs a query argument, a query
	 * argument means the screen reloads, and a reload mid-edit loses whatever was
	 * typed. The simplest thing that cannot do that is to not navigate.
	 */
	private function row( string $id, array $row, bool $is_first ): void {
		$overridden = MailRegistry::is_overridden( $id );
		?>
		<tr data-mail-message="<?php echo esc_attr( $id ); ?>">
			<td>
				<?php if ( ! empty( $row['switchable'] ) ) : ?>
					<?php $this->switch_for( $id, (string) ( $row['label'] ?? $id ) ); ?>
				<?php endif; ?>
				<button
					type="button"
					class="button-link sl-mail-open"
					data-mail-open="<?php echo esc_attr( $id ); ?>"
					aria-expanded="<?php echo $is_first ? 'true' : 'false'; ?>"
				><strong><?php echo esc_html( (string) ( $row['label'] ?? $id ) ); ?></strong></button>
			</td>
			<td class="sl-mail-when"><?php echo esc_html( (string) ( $row['when'] ?? '' ) ); ?></td>
			<td>
				<span class="sl-mail-state <?php echo $overridden ? 'is-custom' : 'is-inherited'; ?>">
					<?php
					echo $overridden
						? esc_html__( 'Đã tuỳ chỉnh', 'omniwp' )
						: esc_html__( 'Đang dùng mẫu chung', 'omniwp' );
					?>
				</span>
			</td>
		</tr>
		<?php
	}

	/**
	 * The on/off switch for an operational alert, in the list row.
	 *
	 * It decides whether the panel matters at all, so it belongs beside the name
	 * rather than inside the thing it can turn off. Drawn here rather than by
	 * FieldRenderer for the reason the provider switch is: that emits a whole
	 * `<tr>`, and the hidden companion input has to travel with the checkbox or an
	 * unticked box posts nothing and the alert can never be switched off.
	 */
	private function switch_for( string $id, string $label ): void {
		$path = MailRegistry::PATH_PREFIX . $id . '.enabled';
		?>
		<label class="sl-mail-switch">
			<input type="hidden" name="<?php echo esc_attr( FieldRenderer::name( $path ) ); ?>" value="0" />
			<input
				type="checkbox"
				name="<?php echo esc_attr( FieldRenderer::name( $path ) ); ?>"
				value="1"
				<?php checked( Settings::is_on( $path ) ); ?>
			/>
			<span class="screen-reader-text">
				<?php
				printf(
					/* translators: %s: message name. */
					esc_html__( 'Gửi email cho: %s', 'omniwp' ),
					esc_html( $label )
				);
				?>
			</span>
		</label>
		<?php
	}

	/**
	 * The editor for one message.
	 *
	 * `hidden` is set only on the panels the script will hide anyway. With
	 * JavaScript off every panel shows, which is a long page — the behaviour this
	 * screen had before — rather than a broken one.
	 *
	 * @param string              $id       Registry row id.
	 * @param array               $row      The registry row.
	 * @param array<string,array> $fields   Every registry row on this tab.
	 * @param bool                $is_first Whether this panel starts open.
	 */
	private function panel( string $id, array $row, array $fields, bool $is_first ): void {
		$own = array();

		foreach ( array( 'subject', 'body' ) as $part ) {
			$path = MailRegistry::PATH_PREFIX . $id . '.' . $part;

			if ( isset( $fields[ $path ] ) ) {
				$own[ $path ] = $fields[ $path ];
			}
		}

		if ( ! $own ) {
			return;
		}
		?>
		<div class="sl-mail-panel" data-mail-panel="<?php echo esc_attr( $id ); ?>" <?php echo $is_first ? '' : 'data-mail-collapsible="1"'; ?>>
			<h3><?php echo esc_html( (string) ( $row['label'] ?? $id ) ); ?></h3>
			<p class="description"><?php echo esc_html( (string) ( $row['when'] ?? '' ) ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				foreach ( $own as $path => $field ) {
					FieldRenderer::render( $path, $field );
				}
				?>
			</table>
		</div>
		<?php
	}
}
