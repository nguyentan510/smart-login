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

use SmartLogin\Frontend\IconSet;

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
			<?php echo IconSet::get( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconSet returns fixed markup from a closed set; nothing here comes from input. ?>
		</button>
	</div>
</div>
