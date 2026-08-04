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
 * @var string $body    Rendered body HTML.
 * @var string $subject Message subject, used as the title.
 * @var string $accent  Validated hex colour.
 * @var string $logo    Logo URL, may be empty.
 * @var string $footer  Footer text, may be empty.
 * @var string $site    Site name.
 * @var string $marker  Comment that marks this message as already wrapped.
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;

echo $marker; // phpcs:ignore WordPress.Security.EscapeOutput -- a fixed comment constant.
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f1f1f1;">
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
					<td style="padding:28px;color:#1d2327;font-size:15px;line-height:1.65;">
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
