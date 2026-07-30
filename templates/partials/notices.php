<?php
/**
 * Notice list.
 *
 * @var array $notices
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $notices ) ) {
	return;
}
?>
<div class="sl-notices" role="status" aria-live="polite">
	<?php foreach ( $notices as $notice ) : ?>
		<div class="sl-notice sl-notice--<?php echo esc_attr( $notice['type'] ); ?>">
			<?php echo esc_html( $notice['message'] ); ?>
		</div>
	<?php endforeach; ?>
</div>
