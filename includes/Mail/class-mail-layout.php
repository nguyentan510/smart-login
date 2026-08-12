<?php
/**
 * Puts an HTML message inside something.
 *
 * Until this, `email.is_html` meant the body *was* the document: whatever the
 * administrator typed went straight into the message with no `<html>`, no
 * width, no footer. "Designing the template" therefore meant writing a complete
 * HTML file into a textarea, which is why nobody did.
 *
 * One layout for every HTML message, three settings, and a theme override for
 * anyone who wants more. Three settings is the deliberate ceiling: a mail
 * template editor is a product, and this is a plugin.
 *
 * @package OmniWP
 */

namespace OmniWP\Mail;

use OmniWP\Frontend\TemplateLoader;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class MailLayout {

	/**
	 * How a wrapped message is recognised.
	 *
	 * An administrator pasting a complete document into the body is the obvious
	 * way double-wrapping happens, and the answer is to detect and pass through
	 * rather than to nest. Kept as a comment rather than a class or id so that
	 * stripping it from a theme override is a deliberate act.
	 */
	const MARKER = '<!-- omniwp-mail -->';

	/**
	 * Wrap rendered body HTML in the shared layout.
	 *
	 * @param string $body    Already-rendered message body.
	 * @param string $subject Used as the preheader and title.
	 */
	public static function wrap( string $body, string $subject = '', string $preheader = '' ): string {
		if ( false !== strpos( $body, self::MARKER ) ) {
			return $body;
		}

		if ( false !== stripos( $body, '<html' ) ) {
			// A complete document that predates the marker. Passing it through is
			// the same decision for the same reason.
			return $body;
		}

		$html = TemplateLoader::render(
			'mail/layout',
			array(
				'body'      => self::paragraphs( $body ),
				'subject'   => $subject,
				'preheader' => $preheader,
				'accent'    => self::accent(),
				'logo'      => trim( (string) Settings::get( 'email.logo_url', '' ) ),
				'footer'    => trim( (string) Settings::get( 'email.footer_text', '' ) ),
				'site'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'marker'    => self::MARKER,
			)
		);

		// A theme override that returns nothing must not silently send an empty
		// mail; the unwrapped body is worse-looking and correct.
		return '' !== trim( $html ) ? $html : $body;
	}

	/**
	 * A colour safe to interpolate into a style attribute.
	 *
	 * Validated rather than escaped, because the value goes inside CSS where
	 * escaping is not enough: `esc_attr()` would happily pass
	 * `red;background:url(…)`.
	 */
	public static function accent(): string {
		$accent = trim( (string) Settings::get( 'email.accent_color', '' ) );

		return preg_match( '/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $accent ) ? $accent : '#2271b1';
	}

	/**
	 * Turn the blank-line-separated body into paragraphs.
	 *
	 * The shipped defaults are written for plain text, so their line breaks are
	 * the only structure they have. Handing them to a layout unconverted renders
	 * every message as one long run-on paragraph — which is what "turn HTML on"
	 * used to do, and the reason it was reasonable to leave it off.
	 *
	 * A body that already contains block markup is left alone.
	 */
	private static function paragraphs( string $body ): string {
		/*
		 * Decided per block, not for the whole body.
		 *
		 * This used to bail out entirely the moment the body contained any block
		 * tag, which was right when the only way one got there was an
		 * administrator pasting markup. 13.3 broke that assumption: a body using
		 * `{{code_block}}` now contains a `<table>`, so every message with a code
		 * block would have had all of its prose run together in one line.
		 *
		 * Caught by 11.2's own assertion, which counts paragraphs rather than
		 * checking that wrapping happened.
		 */
		$blocks = preg_split( '/\n\s*\n/', trim( $body ) ) ?: array();
		$out    = '';

		foreach ( $blocks as $block ) {
			$block = trim( $block );

			if ( '' === $block ) {
				continue;
			}

			// A block that is already block-level is emitted as it is; only prose
			// gets a paragraph around it.
			if ( preg_match( '/^<(p|div|table|ul|ol|h[1-6])\b/i', $block ) ) {
				$out .= $block . "\n";
				continue;
			}

			$out .= '<p style="margin:0 0 16px;">' . nl2br( $block ) . "</p>\n";
		}

		return $out;
	}
}
