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
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

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
			'user'      => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
			),
			'lock'      => array(
				'attrs' => self::OUTLINE,
				'body'  => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
			),
			'map-pin'   => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
			),
			'shield'    => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
			),
			'box'       => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="m21 8-9-5-9 5v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 21v-8"/>',
			),
			'file-text' => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
			),
			'calendar'  => array(
				'attrs' => self::OUTLINE,
				'body'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M3 11h18"/>',
			),
			'pill'      => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M10.5 3.5a5 5 0 0 1 7 7l-7 7a5 5 0 0 1-7-7z"/><path d="m7 7 7 7"/>',
			),
			'heart'     => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M20.8 6.6a5 5 0 0 0-7.1 0L12 8.3l-1.7-1.7a5 5 0 1 0-7.1 7.1L12 22l8.8-8.3a5 5 0 0 0 0-7.1z"/>',
			),
			'log-out'   => array(
				'attrs' => self::OUTLINE,
				'body'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
			),

			// --- control glyphs, not offered in the picker -------------------
			// The congratulations badge on the welcome screen. 28px and solid
			// because that is what it has always been; `aria-hidden` sits on the
			// wrapping span rather than here.
			'check'     => array(
				'attrs' => 'viewBox="0 0 24 24" width="28" height="28" focusable="false"',
				'body'  => '<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" fill="currentColor"/>',
			),
			// The show-password toggle. Its 20px is control geometry: the field
			// reserves 46px of padding for this button, and shrinking the glyph
			// would leave the reservation describing nothing.
			'eye'       => array(
				'attrs' => 'viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"',
				'body'  => '<path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-2.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" fill="currentColor"/>',
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
			'user'      => __( 'Người dùng', 'smart-login' ),
			'lock'      => __( 'Khoá', 'smart-login' ),
			'map-pin'   => __( 'Địa chỉ', 'smart-login' ),
			'shield'    => __( 'Bảo mật', 'smart-login' ),
			'box'       => __( 'Đơn hàng', 'smart-login' ),
			'file-text' => __( 'Tài liệu', 'smart-login' ),
			'calendar'  => __( 'Lịch hẹn', 'smart-login' ),
			'pill'      => __( 'Thuốc', 'smart-login' ),
			'heart'     => __( 'Yêu thích', 'smart-login' ),
			'log-out'   => __( 'Đăng xuất', 'smart-login' ),
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
