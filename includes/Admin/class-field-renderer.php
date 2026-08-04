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

use SmartLogin\GatewayPresets;
use SmartLogin\Mail\MailRegistry;
use SmartLogin\OTP\Placeholders;
use SmartLogin\Security\AuditLog;
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

		if ( ! empty( $field['readonly'] ) ) {
			self::derived( $path, $field );
			return;
		}

		if ( 'headers' === $type ) {
			self::headers( $path, $field );
			return;
		}

		if ( 'credentials' === $type ) {
			self::credentials( $path );
			return;
		}

		if ( 'checkbox' === $type ) {
			self::checkbox( $path, $field );
			return;
		}

		if ( 'checkboxes' === $type ) {
			self::checkboxes( $path, $field );
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

					case 'page':
						self::page( $path );
						break;

					case 'secret':
						self::secret( $path, $field );
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

	/**
	 * A list of independent switches sharing one stored array.
	 *
	 * The empty hidden input is what makes "none ticked" expressible. Without it
	 * an unticked list is simply absent from $_POST, which sanitize() would read
	 * as "field not on this tab" and leave the stored value alone — the user
	 * would be unable to turn the last one off.
	 *
	 * @param string $path  Dot path.
	 * @param array  $field Registry row; `choices` may name a generated source.
	 */
	private static function checkboxes( string $path, array $field ): void {
		$chosen  = (array) Settings::get( $path, array() );
		$choices = self::choices_for( $field );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $field['label'] ?? $path ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( self::name( $path ) ); ?>[]" value="" />
				<fieldset class="sl-checkboxes">
					<?php foreach ( $choices as $value => $label ) : ?>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::name( $path ) ); ?>[]"
								value="<?php echo esc_attr( (string) $value ); ?>"
								<?php checked( in_array( (string) $value, array_map( 'strval', $chosen ), true ) ); ?>
							/>
							<code><?php echo esc_html( (string) $label ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php self::help( $field ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A choices list that is either declared inline or generated.
	 *
	 * The audit events are generated from the constants, so a constant added
	 * later becomes subscribable without anyone remembering to edit a second
	 * list. That is the same argument the registry itself makes.
	 *
	 * @param array $field Registry row.
	 * @return array<string,string>
	 */
	private static function choices_for( array $field ): array {
		$choices = $field['choices'] ?? array();

		if ( 'audit_events' === $choices ) {
			return array_combine( AuditLog::events(), AuditLog::events() );
		}

		return (array) $choices;
	}

	/**
	 * Write-only: a stored secret is never rendered back into the form.
	 *
	 * `value` is unconditionally empty, so a blank submission cannot be
	 * distinguished from "unchanged" any other way — which is exactly the
	 * convention Settings::sanitize() already applies to the provider secrets, and
	 * why the clear checkbox exists rather than "empty the box to remove it".
	 */
	private static function secret( string $path, array $field ): void {
		$stored = '' !== ( $field['stored'] ?? '' );

		printf(
			'<input type="password" class="regular-text" id="%1$s" name="%2$s" value="" autocomplete="new-password" placeholder="%3$s" />',
			esc_attr( self::id( $path ) ),
			esc_attr( self::name( $path ) ),
			esc_attr( $stored ? __( 'Đã lưu — để trống nếu không đổi', 'smart-login' ) : __( 'Chưa có', 'smart-login' ) )
		);

		if ( $stored ) {
			// A flat input name, not a nested one: the value never belongs in the
			// settings array, and Settings::sanitize() strips it before storing.
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
				esc_attr( 'sl_clear_' . str_replace( '.', '_', $path ) ),
				esc_html__( 'Xoá giá trị đã lưu', 'smart-login' )
			);
		}
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
			placeholder="<?php echo esc_attr( self::inherited( $field ) ); ?>"
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

	/**
	 * Pick a published page instead of typing a URL.
	 *
	 * The value stored is still a permalink, so nothing downstream changes and a
	 * URL set by a filter or by wp-config keeps working — it simply appears as an
	 * extra option so it is visible rather than silently overwritten.
	 */
	private static function page( string $path ): void {
		$current = (string) Settings::get( $path, '' );
		$pages   = get_pages( array( 'sort_column' => 'post_title' ) ) ?: array();
		$known   = array();
		?>
		<select id="<?php echo esc_attr( self::id( $path ) ); ?>" name="<?php echo esc_attr( self::name( $path ) ); ?>">
			<option value=""><?php esc_html_e( '— Mặc định —', 'smart-login' ); ?></option>
			<?php
			foreach ( $pages as $page ) {
				$permalink = (string) get_permalink( $page->ID );
				$known[]   = $permalink;
				?>
				<option value="<?php echo esc_attr( $permalink ); ?>" <?php selected( $current, $permalink ); ?>>
					<?php echo esc_html( $page->post_title ); ?>
				</option>
				<?php
			}

			if ( '' !== $current && ! in_array( $current, $known, true ) ) :
				?>
				<option value="<?php echo esc_attr( $current ); ?>" selected>
					<?php echo esc_html( $current ); ?>
				</option>
			<?php endif; ?>
		</select>
		<?php
	}

	private static function textarea( string $path, array $field ): void {
		?>
		<textarea
			id="<?php echo esc_attr( self::id( $path ) ); ?>"
			name="<?php echo esc_attr( self::name( $path ) ); ?>"
			rows="<?php echo (int) ( $field['rows'] ?? 6 ); ?>"
			placeholder="<?php echo esc_attr( self::inherited( $field ) ); ?>"
			class="large-text code"
		><?php echo esc_textarea( (string) Settings::get( $path, '' ) ); ?></textarea>
		<?php
		self::message_tokens( $field );
	}

	/**
	 * What an empty template box will actually send.
	 *
	 * Shown as the placeholder, so a blank field reads as "inheriting this"
	 * rather than as "this email has no subject" — which is what a mail screen
	 * full of empty boxes otherwise looks like, and the reason an administrator
	 * would paste the default into all eight of them and lose the inheritance
	 * the registry exists to provide.
	 */
	private static function inherited( array $field ): string {
		$message = (string) ( $field['message'] ?? '' );
		$part    = (string) ( $field['part'] ?? '' );

		if ( '' === $message || '' === $part ) {
			return '';
		}

		return (string) ( MailRegistry::resolve( $message )[ $part ] ?? '' );
	}

	/**
	 * The tokens this message understands, collapsed.
	 *
	 * Only this message's set. The global table under the SMS section stays
	 * where it is and keeps showing everything, because there it is right — here
	 * it would offer an operational token beside an OTP body, which renders as a
	 * silent empty string and is the whole reason the sets are declared per
	 * message.
	 */
	private static function message_tokens( array $field ): void {
		$message = (string) ( $field['message'] ?? '' );

		if ( '' === $message || 'body' !== ( $field['part'] ?? '' ) ) {
			return;
		}

		$tokens = Placeholders::available_tokens( $message );

		if ( ! $tokens ) {
			return;
		}
		?>
		<details class="sl-derived sl-message-tokens">
			<summary><?php esc_html_e( 'Các thẻ dùng được trong mẫu này', 'smart-login' ); ?></summary>
			<table class="widefat striped sl-tokens">
				<tbody>
				<?php foreach ( $tokens as $token => $description ) : ?>
					<tr>
						<td style="width:180px"><code><?php echo esc_html( $token ); ?></code></td>
						<td><?php echo esc_html( $description ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
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

	/**
	 * The only inputs a preset-configured gateway asks for.
	 *
	 * Which inputs exist depends on the gateway chosen, so this reads the preset
	 * rather than the registry. A secret is write-only, exactly like the provider
	 * secrets: never echoed, blank means unchanged.
	 */
	private static function credentials( string $path ): void {
		$slug  = (string) Settings::get( 'sms.preset', GatewayPresets::CUSTOM );
		$specs = GatewayPresets::credentials( $slug );

		if ( ! $specs ) {
			return;
		}

		$stored = (array) Settings::get( $path, array() );

		foreach ( $specs as $name => $spec ) {
			$secret = ! empty( $spec['secret'] );
			$id     = self::id( $path ) . '-' . $name;
			?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $spec['label'] ); ?></label>
				</th>
				<td>
					<input
						type="<?php echo $secret ? 'password' : 'text'; ?>"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( self::name( $path ) . '[' . $name . ']' ); ?>"
						value="<?php echo $secret ? '' : esc_attr( (string) ( $stored[ $name ] ?? '' ) ); ?>"
						class="regular-text<?php echo $secret ? '' : ' code'; ?>"
						autocomplete="off"
						<?php if ( $secret ) : ?>
							placeholder="
							<?php
							echo '' !== (string) ( $stored[ $name ] ?? '' )
								? esc_attr__( 'Đã lưu — để trống để giữ nguyên', 'smart-login' )
								: esc_attr__( 'Nhập giá trị', 'smart-login' );
							?>
								"
						<?php endif; ?>
					/>
					<?php if ( $secret ) : ?>
						<p class="description"><?php esc_html_e( 'Không bao giờ được hiển thị lại sau khi lưu.', 'smart-login' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}
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

	/**
	 * A value the preset produced, shown so it can be checked but not edited.
	 *
	 * Still a real input carrying the real name, so the field is present in the
	 * form like any other. Whatever it posts is discarded: Settings re-derives
	 * these from the preset on every save of this tab.
	 */
	private static function derived( string $path, array $field ): void {
		$value = Settings::get( $path, '' );
		$value = is_array( $value ) ? self::flatten_headers( $value ) : (string) $value;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( self::id( $path ) ); ?>"><?php echo esc_html( $field['label'] ?? $path ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="<?php echo esc_attr( self::id( $path ) ); ?>"
					name="<?php echo esc_attr( self::name( $path ) ); ?>"
					value="<?php echo esc_attr( self::mask_secrets( $value ) ); ?>"
					class="large-text code"
					readonly
				/>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<int,array{key:string,value:string}> $rows
	 */
	private static function flatten_headers( array $rows ): string {
		$parts = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['key'] ) ) {
				$parts[] = $row['key'] . ': ' . ( $row['value'] ?? '' );
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Blank out any secret credential that appears inside a derived value.
	 *
	 * The derived body legitimately contains the gateway's secret key, and this
	 * screen exists so the administrator can check the request shape — not so the
	 * secret ends up in a screenshot or a support ticket.
	 */
	private static function mask_secrets( string $value ): string {
		$slug        = (string) Settings::get( 'sms.preset', GatewayPresets::CUSTOM );
		$credentials = (array) Settings::get( 'sms.credentials', array() );

		foreach ( GatewayPresets::credentials( $slug ) as $name => $spec ) {
			$secret = (string) ( $credentials[ $name ] ?? '' );

			if ( empty( $spec['secret'] ) || '' === $secret ) {
				continue;
			}

			$value = str_replace( $secret, '••••••••', $value );
		}

		return $value;
	}

	private static function help( array $field ): void {
		if ( empty( $field['help'] ) ) {
			return;
		}

		printf( '<p class="description">%s</p>', wp_kses_post( $field['help'] ) );
	}
}
