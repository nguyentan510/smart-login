<?php
/**
 * The shared HTML mail layout.
 *
 * This markup is ugly on purpose and must stay that way.
 *
 * Mail clients are not browsers. Outlook renders with Word's HTML engine and
 * ignores `<style>` blocks entirely; Gmail strips `<head>`; several strip
 * `class` attributes. So: tables for layout, inline styles on every element, no
 * stylesheet, no flexbox, no shorthand anyone might "tidy up". A cleaner version
 * of this file renders as a wall of unstyled text in the one client a Vietnamese
 * shop's customers are most likely to be using.
 *
 * Override it from a theme at `smart-login/mail/layout.php` — that escape hatch
 * is what keeps the three settings defensible instead of the ten somebody will
 * eventually ask for.
 *
 * On dark mode: the `color-scheme` meta tags below are honoured by Apple Mail
 * and recent Outlook, and ignored by Gmail's web client, which applies its own
 * inversion regardless. There is no fixing that from here. What can be done is
 * choosing colours whose inverted rendering is still legible — near-white
 * surfaces and near-black text invert to something readable, while a mid-grey
 * card does not — so the palette is deliberately high-contrast rather than
 * tasteful.
 *
 * @var string $body      Rendered body HTML.
 * @var string $subject   Message subject, used as the title.
 * @var string $preheader Inbox preview line; may be empty.
 * @var string $accent    Validated hex colour.
 * @var string $logo      Logo URL, may be empty.
 * @var string $footer    Footer text, may be empty.
 * @var string $site      Site name.
 * @var string $marker    Comment that marks this message as already wrapped.
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;

$preheader = isset( $preheader ) ? (string) $preheader : '';

echo $marker; // phpcs:ignore WordPress.Security.EscapeOutput -- a fixed comment constant.
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="color-scheme" content="light dark" />
	<meta name="supported-color-schemes" content="light dark" />
	<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f1f1f1;">
<?php if ( '' !== $preheader ) : ?>
	<?php
	/*
	 * The grey line an inbox shows after the subject. Hidden in the message
	 * itself; without it clients pull the first words of the body, so every
	 * message previewed as "Xin chào,".
	 *
	 * The zero-width characters after it are padding: without them a client
	 * keeps reading into the body and appends it to the preview anyway.
	 */
	?>
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
		<?php echo esc_html( $preheader ); ?>
		<?php echo str_repeat( '&#847;&zwnj;&nbsp;', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed entities. ?>
	</div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f1f1;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:8px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
				<tr>
					<td style="background:<?php echo esc_attr( $accent ); ?>;padding:20px 28px;">
						<?php if ( '' !== $logo ) : ?>
							<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site ); ?>" height="36" style="height:36px;display:block;border:0;" />
						<?php else : ?>
							<span style="color:#ffffff;font-size:18px;font-weight:600;"><?php echo esc_html( $site ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:32px 28px;color:#1d2327;font-size:16px;line-height:1.7;">
						<?php echo wp_kses_post( $body ); ?>
					</td>
				</tr>
				<?php if ( '' !== $footer ) : ?>
					<tr>
						<td style="padding:16px 28px 24px;border-top:1px solid #e5e5e5;color:#646970;font-size:12px;line-height:1.6;">
							<?php echo esc_html( $footer ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
