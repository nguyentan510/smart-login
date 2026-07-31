<?php
/**
 * Draws one registry row as one settings-table row.
 *
 * Every control the settings screen shows comes through here, so the input name,
 * the id, the escaping and the min/max attributes are decided once per type
 * rather than once per field. The old screen had four near-identical helpers
 * (text/checkbox/select/textarea) and every field had to pick the right one by
 * hand; picking wrong, or forgetting to call one at all, was invisible.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin;

use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class FieldRenderer {

	/**
	 * `otp.ttl` becomes `smart_login_settings[otp][ttl]`, which is the shape
	 * Settings::sanitize() digs back out by the same dot path.
	 */
	public static function name( string $path ): string {
		return Settings::OPTION . '[' . implode( '][', explode( '.', $path ) ) . ']';
	}

	public static function id( string $path ): string {
		return 'sl-' . str_replace( '.', '-', $path );
	}

	/**
	 * @param string $path  Dot path.
	 * @param array  $field Registry row.
	 */
	public static function render( string $path, array $field ): void {
		$type = $field['type'] ?? 'text';

		if ( 'headers' === $type ) {
			self::headers( $path, $field );
			return;
		}

		if ( 'checkbox' === $type ) {
			self::checkbox( $path, $field );
			return;
		}
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( self::id( $path ) ); ?>"><?php echo esc_html( $field['label'] ?? $path ); ?></label>
			</th>
			<td>
				<?php
				switch ( $type ) {
					case 'select':
						self::select( $path, $field );
						break;

					case 'textarea':
						self::textarea( $path, $field );
						break;

					default:
						self::input( $path, $field );
				}

				self::help( $field );
				?>
			</td>
		</tr>
		<?php
	}

	private static function input( string $path, array $field ): void {
		$type  = $field['type'] ?? 'text';
		$html  = in_array( $type, array( 'number', 'url', 'email' ), true ) ? $type : 'text';
		$extra = '';

		// The same min/max drives the browser hint and the server-side clamp in
		// Settings::sanitize_field(), so the two cannot describe different rules.
		foreach ( array( 'min', 'max' ) as $bound ) {
			if ( isset( $field[ $bound ] ) ) {
				$extra .= sprintf( ' %s="%s"', $bound, esc_attr( (string) $field[ $bound ] ) );
			}
		}
		?>
		<input
			type="<?php echo esc_attr( $html ); ?>"
			id="<?php echo esc_attr( self::id( $path ) ); ?>"
			name="<?php echo esc_attr( self::name( $path ) ); ?>"
			value="<?php echo esc_attr( (string) Settings::get( $path, '' ) ); ?>"
			class="regular-text"
			<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts. ?>
		/>
		<?php
	}

	private static function select( string $path, array $field ): void {
		$current = (string) Settings::get( $path, '' );
		?>
		<select id="<?php echo esc_attr( self::id( $path ) ); ?>" name="<?php echo esc_attr( self::name( $path ) ); ?>">
			<?php foreach ( (array) ( $field['choices'] ?? array() ) as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, (string) $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function textarea( string $path, array $field ): void {
		?>
		<textarea
			id="<?php echo esc_attr( self::id( $path ) ); ?>"
			name="<?php echo esc_attr( self::name( $path ) ); ?>"
			rows="<?php echo (int) ( $field['rows'] ?? 6 ); ?>"
			class="large-text code"
		><?php echo esc_textarea( (string) Settings::get( $path, '' ) ); ?></textarea>
		<?php
	}

	/**
	 * The hidden companion input is what makes "absent means unchecked" safe:
	 * an unticked box posts `0` rather than nothing at all.
	 */
	private static function checkbox( string $path, array $field ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $field['label'] ?? $path ); ?></th>
			<td>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::name( $path ) ); ?>" value="0" />
					<input
						type="checkbox"
						id="<?php echo esc_attr( self::id( $path ) ); ?>"
						name="<?php echo esc_attr( self::name( $path ) ); ?>"
						value="1"
						<?php checked( Settings::is_on( $path ) ); ?>
					/>
					<?php echo wp_kses_post( $field['help'] ?? ( $field['label'] ?? '' ) ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	private static function headers( string $path, array $field ): void {
		$rows_in   = (array) Settings::get( $path, array() );
		$row_count = max( 3, count( $rows_in ) + 1 );
		$name      = self::name( $path );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $field['label'] ?? $path ); ?></th>
			<td>
				<table class="widefat sl-headers-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tên', 'smart-login' ); ?></th>
							<th><?php esc_html_e( 'Giá trị', 'smart-login' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php for ( $i = 0; $i < $row_count; $i++ ) : ?>
							<tr>
								<td>
									<input
										type="text"
										name="<?php echo esc_attr( $name . '[' . $i . '][key]' ); ?>"
										value="<?php echo esc_attr( $rows_in[ $i ]['key'] ?? '' ); ?>"
										placeholder="Authorization"
										class="regular-text"
									/>
								</td>
								<td>
									<input
										type="text"
										name="<?php echo esc_attr( $name . '[' . $i . '][value]' ); ?>"
										value="<?php echo esc_attr( $rows_in[ $i ]['value'] ?? '' ); ?>"
										placeholder="Bearer xxxxx"
										class="large-text"
									/>
								</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Bỏ trống dòng không dùng. Giá trị chứa từ khoá bảo mật sẽ được che khi hiển thị kết quả gửi thử.', 'smart-login' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function help( array $field ): void {
		if ( empty( $field['help'] ) ) {
			return;
		}

		printf( '<p class="description">%s</p>', wp_kses_post( $field['help'] ) );
	}
}
