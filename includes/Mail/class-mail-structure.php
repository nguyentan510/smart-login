<?php
/**
 * Structure tokens: the parts of a message that are not prose.
 *
 * An OTP email has one job — show six digits — and until this existed it showed
 * them as running text in the middle of a paragraph:
 *
 *     Mã xác nhận: {{code}}
 *
 * That is not a styling preference. It is the content of the message rendered as
 * though it were an aside.
 *
 * These are tokens rather than layout settings because they are content
 * decisions: whether *this* message leads with a code, or carries a button, is a
 * property of the message, and the message is what the administrator edits. A
 * setting would apply to all six and be wrong for at least two.
 *
 * Both are opt-in. A body using neither renders byte-identically to what it
 * rendered before they existed, which is what keeps this from being a migration.
 *
 * @package OmniWP
 */

namespace OmniWP\Mail;

defined( 'ABSPATH' ) || exit;

final class MailStructure {

	/** Renders the code as what it is: the point of the message. */
	const CODE = '{{code_block}}';

	/** `{{button:https://…|Nhãn}}` */
	const BUTTON_PATTERN = '/\{\{button:([^|}]+)\|([^}]*)\}\}/';

	/**
	 * Expand the structure tokens in an already-rendered body.
	 *
	 * Runs *after* `Placeholders::render()`, so a URL written as
	 * `{{button:{{site_url}}account|Mở tài khoản}}` has had its inner token
	 * substituted before this sees it — the alternative is parsing nested braces,
	 * which is a parser nobody asked for.
	 *
	 * @param string $body    Rendered body.
	 * @param bool   $is_html Whether the message will be sent as HTML.
	 * @param array  $map     The placeholder map, for values the tokens need.
	 */
	public static function expand( string $body, bool $is_html, array $map ): string {
		$body = self::expand_code( $body, $is_html, (string) ( $map['code'] ?? '' ) );

		return self::expand_buttons( $body, $is_html );
	}

	private static function expand_code( string $body, bool $is_html, string $code ): string {
		if ( false === strpos( $body, self::CODE ) ) {
			return $body;
		}

		if ( ! $is_html ) {
			// The bare digits. A text message that carried markup would fail the
			// rule 11.0 landed for exactly this.
			return str_replace( self::CODE, $code, $body );
		}

		/*
		 * `letter-spacing` on the whole string rather than a span per digit.
		 * Splitting the digits up is the prettier markup and it defeats the one
		 * thing this block exists for: a customer on a phone selects the code and
		 * copies it, and per-digit spans copy as "4 8 2 9 1 3".
		 */
		$block = sprintf(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr>'
				. '<td style="padding:16px 24px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;'
				. 'font-family:Consolas,Menlo,monospace;font-size:30px;line-height:1.2;font-weight:700;'
				. 'letter-spacing:6px;color:#1d2327;">%s</td>'
				. '</tr></table>',
			esc_html( $code )
		);

		return str_replace( self::CODE, $block, $body );
	}

	private static function expand_buttons( string $body, bool $is_html ): string {
		return (string) preg_replace_callback(
			self::BUTTON_PATTERN,
			static function ( array $matches ) use ( $is_html ): string {
				$url   = trim( $matches[1] );
				$label = trim( $matches[2] );

				if ( ! $is_html ) {
					// A link the reader cannot click has to be one they can copy.
					return '' === $label ? $url : $label . ': ' . $url;
				}

				/*
				 * A table, not a styled `<a>`. Outlook renders with Word's HTML
				 * engine and ignores padding on inline elements, so the "button"
				 * becomes underlined text — which is the whole reason this is
				 * three nested elements instead of one.
				 */
				return sprintf(
					'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr>'
						. '<td style="border-radius:6px;background:%1$s;">'
						. '<a href="%2$s" style="display:inline-block;padding:12px 24px;font-size:15px;'
						. 'font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">%3$s</a>'
						. '</td></tr></table>',
					esc_attr( MailLayout::accent() ),
					esc_url( $url ),
					esc_html( '' === $label ? $url : $label )
				);
			},
			$body
		);
	}
}
