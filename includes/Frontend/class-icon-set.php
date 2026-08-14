<?php
/**
 * Every UI glyph this plugin draws, declared exactly once.
 *
 * Before 21.2 there were three producers. `AccountForm::sections_meta()` built
 * four stroked marks from an inline closure; `templates/onboarding.php` drew a
 * solid checkmark; `templates/partials/password-field.php` drew an eye. None of
 * them knew about the others, and 21.0's rule 2 found the second and third — the
 * spec had counted only the first.
 *
 * Two things live here and they are not the same thing:
 *
 *   names()  the **pickable vocabulary**. What a settings row may choose from,
 *            and what a menu entry may name. Ten entries, one visual style.
 *   get()    markup for **any** glyph, pickable or not.
 *
 * `check` and `eye` are in the second and deliberately not the first. They are
 * fixed parts of one control each — the eye is the geometry
 * `.sl-field--password`'s `padding-right: 46px` reserves room for — and nobody
 * will ever choose them from a menu. Offering them in a picker would mean a
 * 28px solid checkmark could turn up beside a row of 18px outlines.
 *
 * That distinction is the same one 21.1 drew for `--sl-dlg-*`: a thing that
 * never crosses the boundary is local to the side it lives on. The difference is
 * that a glyph's *markup* still benefits from one home, so these live here while
 * staying out of the vocabulary.
 *
 * **Geometry is per-icon, not per-set.** The four stroked marks are 18×18 with
 * `fill="none"`; `check` is 28×28 solid; `eye` is 20×20 solid. Flattening them
 * to one wrapper would have changed how three shipped surfaces look, inside a
 * commit whose whole claim is that nothing looks different. The attribute
 * strings below are reproduced exactly as each call site had them, attribute
 * order included, so every render is byte-identical to what it replaced.
 *
 * **Provider brand marks are not here.** `ProviderMark::icon_svg()` returns a
 * trademark in its owner's colours; folding it into a `currentColor` UI set
 * would be unification past the point where it means anything.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

defined( 'ABSPATH' ) || exit;

final class IconSet {

	/**
	 * What an unknown name resolves to.
	 *
	 * There is no "no icon" case: every caller is drawing a slot that exists, so
	 * an empty string would leave a hole rather than report a problem. Settings
	 * sanitising folds an unknown name to this too, which is what makes an icon
	 * outside the set unrepresentable rather than merely rejected.
	 */
	const FALLBACK = 'user';

	/**
	 * The shared wrapper for the outline family.
	 *
	 * Lifted verbatim from the closure in `AccountForm::sections_meta()`, which
	 * is why the attribute order looks arbitrary — it is the order that file
	 * already had, and changing it would change six shipped renders.
	 */
	private const OUTLINE = 'width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
		. ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
		. ' focusable="false" aria-hidden="true"';

	/**
	 * name => [ attrs, body ].
	 *
	 * @return array<string,array{attrs:string,body:string}>
	 */
	private static function glyphs(): array {
		return array(
			// --- the outline vocabulary -------------------------------------
			'user'          => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
			),
			'lock'          => array(
				'attrs' => self::OUTLINE,
				'body'  => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
			),
			'map-pin'       => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
			),
			'shield'        => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
			),
			'box'           => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="m21 8-9-5-9 5v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 21v-8"/>',
			),
			'file-text'     => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
			),
			'calendar'      => array(
				'attrs' => self::OUTLINE,
				'body'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M3 11h18"/>',
			),
			'pill'          => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M10.5 3.5a5 5 0 0 1 7 7l-7 7a5 5 0 0 1-7-7z"/><path d="m7 7 7 7"/>',
			),
			'heart'         => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M20.8 6.6a5 5 0 0 0-7.1 0L12 8.3l-1.7-1.7a5 5 0 1 0-7.1 7.1L12 22l8.8-8.3a5 5 0 0 0 0-7.1z"/>',
			),
			'ticket'        => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
			),
			'log-out'       => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
			),
			'settings'      => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
			),
			'cart'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
			),
			'close'         => array(
				'attrs' => 'viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
			),
			'trash'         => array(
				'attrs' => 'viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
			),
			'check-simple'  => array(
				'attrs' => 'viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<polyline points="20 6 9 17 4 12"/>',
			),
			'home'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
			),
			'briefcase'     => array(
				'attrs' => 'viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
			),

			// --- control glyphs, not offered in the picker -------------------
			// The congratulations badge on the welcome screen. 28px and solid
			// because that is what it has always been; `aria-hidden` sits on the
			// wrapping span rather than here.
			'check'         => array(
				'attrs' => 'viewBox="0 0 24 24" width="28" height="28" focusable="false"',
				'body'  => '<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" fill="currentColor"/>',
			),
			// The show-password toggle. Its 20px is control geometry: the field
			// reserves 46px of padding for this button, and shrinking the glyph
			// would leave the reservation describing nothing.
			'eye'           => array(
				'attrs' => 'viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-2.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" fill="currentColor"/>',
			),
			'star'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="currentColor"/>',
			),
			'edit'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" stroke="currentColor" stroke-width="2" fill="none"/>',
			),
			'save'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" fill="none" stroke="currentColor" stroke-width="2"/><polyline points="17 21 17 13 7 13 7 21" fill="none" stroke="currentColor" stroke-width="2"/><polyline points="7 3 7 8 15 8" fill="none" stroke="currentColor" stroke-width="2"/>',
			),
			'search'        => array(
				'attrs' => 'viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"',
				'body'  => '<circle cx="11" cy="11" r="8" fill="none" stroke="currentColor" stroke-width="2"/><path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2"/>',
			),
			'phone'         => array(
				'attrs' => 'viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			),
			'mail'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="22,6 12,13 2,6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			),
			'rotate-ccw'    => array(
				'attrs' => 'viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
			),
			'copy'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
			),
			'percent'       => array(
				'attrs' => 'viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
			),
			'truck'         => array(
				'attrs' => 'viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
			),
			'info'          => array(
				'attrs' => 'viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
			),
			'chevron-down'  => array(
				'attrs' => 'viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<polyline points="6 9 12 15 18 9"/>',
			),
			'chevron-right' => array(
				'attrs' => 'viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<polyline points="9 18 15 12 9 6"/>',
			),
			'chevron-left'  => array(
				'attrs' => 'viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"',
				'body'  => '<polyline points="15 18 9 12 15 6"/>',
			),
		);
	}

	/**
	 * The pickable vocabulary: name => label for a settings control.
	 *
	 * The settings `<select>` is built from this, and so is the rule that an
	 * unknown icon cannot be stored — one list, so a name the picker offers is a
	 * name the renderer has by construction.
	 *
	 * @return array<string,string>
	 */
	public static function names(): array {
		return array(
			'user'      => __( 'Người dùng', 'omniwp' ),
			'lock'      => __( 'Khoá', 'omniwp' ),
			'map-pin'   => __( 'Địa chỉ', 'omniwp' ),
			'shield'    => __( 'Bảo mật', 'omniwp' ),
			'box'       => __( 'Đơn hàng', 'omniwp' ),
			'ticket'    => __( 'Mã giảm giá', 'omniwp' ),
			'file-text' => __( 'Tài liệu', 'omniwp' ),
			'calendar'  => __( 'Lịch hẹn', 'omniwp' ),
			'pill'      => __( 'Thuốc', 'omniwp' ),
			'heart'     => __( 'Yêu thích', 'omniwp' ),
			'log-out'   => __( 'Đăng xuất', 'omniwp' ),
		);
	}

	/**
	 * Whether a name may be stored by a settings row.
	 *
	 * Private: `sanitize()` is the only caller and the only thing a caller
	 * actually wants. A public predicate here would be a promise nobody has
	 * asked for, and the answer it gives is already implied by what `sanitize()`
	 * hands back.
	 */
	private static function is_pickable( string $name ): bool {
		return array_key_exists( $name, self::names() );
	}

	/**
	 * A pickable name, or the fallback.
	 *
	 * The input never survives: this is what makes an icon outside the set
	 * unrepresentable rather than rejected-and-remembered.
	 */
	public static function sanitize( string $name ): string {
		return self::is_pickable( $name ) ? $name : self::FALLBACK;
	}

	/**
	 * Markup for a glyph, pickable or not.
	 *
	 * Returns the fallback's markup for an unknown name — never the name itself,
	 * which is the half of this that matters: no value that reached the plugin
	 * from a settings form can arrive in the DOM as markup.
	 */
	public static function get( string $name ): string {
		$glyphs = self::glyphs();
		$glyph  = $glyphs[ $name ] ?? $glyphs[ self::FALLBACK ];

		return '<svg ' . $glyph['attrs'] . '>' . $glyph['body'] . '</svg>';
	}
}
