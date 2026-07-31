<?php
/**
 * Password input with a show/hide toggle.
 *
 * @var string $name
 * @var string $label
 * @var string $id
 * @var bool   $required
 * @var string $autocomplete
 * @var int    $minlength
 * @var string $describedby
 * @var bool   $disabled
 * @var bool   $autofocus
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;

$sl_id           = $id ?? 'sl-' . $name;
$sl_required     = $required ?? true;
$sl_autocomplete = $autocomplete ?? ( 'password' === $name ? 'current-password' : 'new-password' );
$sl_minlength    = isset( $minlength ) ? max( 0, (int) $minlength ) : 0;
$sl_describedby  = isset( $describedby ) ? trim( (string) $describedby ) : '';
$sl_disabled     = ! empty( $disabled );
$sl_autofocus    = ! empty( $autofocus );
?>
<div class="sl-field sl-field--password">
	<label class="sl-label" for="<?php echo esc_attr( $sl_id ); ?>">
		<?php echo esc_html( $label ); ?>
		<?php
		if ( $sl_required ) :
			?>
			<span class="sl-required">*</span><?php endif; ?>
	</label>
	<div class="sl-input-wrap">
		<input
			type="password"
			class="sl-input"
			id="<?php echo esc_attr( $sl_id ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			autocomplete="<?php echo esc_attr( $sl_autocomplete ); ?>"
			<?php
			if ( $sl_minlength > 0 ) :
				?>
				minlength="<?php echo esc_attr( $sl_minlength ); ?>"<?php endif; ?>
			<?php
			if ( '' !== $sl_describedby ) :
				?>
				aria-describedby="<?php echo esc_attr( $sl_describedby ); ?>"<?php endif; ?>
			<?php echo $sl_disabled ? 'disabled' : ''; ?>
			<?php echo $sl_required ? 'required' : ''; ?>
			<?php echo $sl_autofocus ? 'autofocus' : ''; ?>
		/>
		<button type="button" class="sl-toggle-password" aria-label="<?php esc_attr_e( 'Hiện mật khẩu', 'smart-login' ); ?>" data-target="<?php echo esc_attr( $sl_id ); ?>">
			<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
				<path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-2.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" fill="currentColor"/>
			</svg>
		</button>
	</div>
</div>
