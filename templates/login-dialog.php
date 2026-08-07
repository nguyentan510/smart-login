<?php
/**
 * The dialog shell: a container, and deliberately nothing else.
 *
 * **No form, no nonce, no `RequestGuard::fields()`.** That is the property rule
 * 4 asserts and the reason the shell is safe for a full-page cache to serve:
 * everything with a lifetime arrives in the fetched fragment, minted for the
 * request that asked for it.
 *
 * **Emitted on `wp_footer`, after every form on the page has closed.** HTML
 * forbids a `<form>` inside a `<form>`, and browsers do not merely ignore the
 * inner one — the parser drops its start tag, so the inner `</form>` closes the
 * *outer* form. `class-deferred-forms.php` exists because this plugin already
 * shipped that defect once: the account page's "Lưu thay đổi" button had no form
 * to submit and pressing it did nothing, silently. A dialog rendered inline
 * inside a checkout template would reproduce it exactly.
 *
 * Native `<dialog>` rather than a div: `showModal()` supplies the focus trap,
 * `Esc`, the inert background and the top-layer stacking that would otherwise be
 * four hand-written behaviours for Phase 18's rules to measure.
 *
 * Override at yourtheme/smart-login/login-dialog.php.
 *
 * @var string $title Initial accessible name; replaced per step by the script.
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;
?>
<dialog class="sl-dialog" id="sl-dialog" aria-labelledby="sl-dialog-title" data-sl-dialog>
	<div class="sl-dialog__panel">
		<div class="sl-dialog__bar">
			<h2 class="sl-dialog__title" id="sl-dialog-title" data-sl-dialog-title>
				<?php echo esc_html( $title ); ?>
			</h2>
			<button
				type="button"
				class="sl-dialog__close"
				data-sl-dialog-close
				aria-label="<?php esc_attr_e( 'Đóng', 'smart-login' ); ?>"
			>
				<span aria-hidden="true">&times;</span>
			</button>
		</div>

		<?php
		/*
		 * `aria-live` on the region rather than on a status line: the fragment
		 * that lands here *is* the announcement, and a step that arrives with an
		 * error notice at the top has to be read out. `polite`, not `assertive` —
		 * the visitor caused this by pressing a button.
		 */
		?>
		<div class="sl-dialog__body" data-sl-dialog-body aria-live="polite" aria-busy="true">
			<p class="sl-dialog__loading"><?php esc_html_e( 'Đang tải…', 'smart-login' ); ?></p>
		</div>
	</div>
</dialog>
