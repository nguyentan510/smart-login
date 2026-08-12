/**
 * Smart Login — the account menu's manners.
 *
 * Everything the menu *does* is already done by `<details>`: it opens, it
 * closes, it takes keyboard focus, and a screen reader announces it. This file
 * adds only what the element does not do, and **removes nothing**. If it is
 * blocked, fails to parse, or has not arrived yet, the member still has a
 * working account menu — which is the whole reason 21.5 shipped the markup a
 * sub-phase before this script existed.
 *
 * Three additions:
 *
 *   1. an outside click closes the menu
 *   2. Escape closes it and puts focus back on the button
 *   3. `aria-expanded` is kept in step with the open state
 *
 * The third is not cosmetic. `<details>` does not maintain `aria-expanded`, and
 * a screen reader announcing a collapsed menu as expanded is worse than one
 * announcing nothing at all — so the attribute is written by the same event
 * that changes the state, never inferred somewhere else.
 *
 * One delegated listener per event, on the document, matching the launcher's
 * shape: markup added after load works too, and there is one listener rather
 * than one per button.
 */
( function () {
	'use strict';

	var SELECTOR = '[data-sl-account]';

	function summaryOf( details ) {
		return details.querySelector( 'summary' );
	}

	/** Mirror the element's own state onto the attribute a reader consults. */
	function syncExpanded( details ) {
		var summary = summaryOf( details );

		if ( summary ) {
			summary.setAttribute( 'aria-expanded', details.open ? 'true' : 'false' );
		}
	}

	function closeAll( except ) {
		var open = document.querySelectorAll( SELECTOR + '[open]' );

		Array.prototype.forEach.call( open, function ( details ) {
			if ( details !== except ) {
				details.open = false;
				syncExpanded( details );
			}
		} );
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		/*
		 * The initial pass. A menu could already be open on arrival — a browser
		 * restoring scroll and form state after a back navigation will reopen a
		 * <details> it saw open — so the attribute is set from the element rather
		 * than assumed to start closed.
		 */
		Array.prototype.forEach.call( document.querySelectorAll( SELECTOR ), syncExpanded );

		/*
		 * `toggle` fires however the state changed: a click, a keypress, a
		 * browser restoring it, other script setting `.open`. Listening here
		 * rather than on click is what keeps the attribute honest in the cases
		 * nobody thought of.
		 *
		 * Captured, because `toggle` does not bubble.
		 */
		document.addEventListener(
			'toggle',
			function ( event ) {
				var details = event.target;

				if ( ! details || ! details.matches || ! details.matches( SELECTOR ) ) {
					return;
				}

				syncExpanded( details );

				// Two menus open at once is a state nobody asked for.
				if ( details.open ) {
					closeAll( details );
				}
			},
			true
		);

		document.addEventListener( 'click', function ( event ) {
			var inside = event.target.closest ? event.target.closest( SELECTOR ) : null;

			// A click inside the menu is the visitor using it — including the
			// summary, whose own default toggle must not be interfered with.
			closeAll( inside );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' && event.key !== 'Esc' ) {
				return;
			}

			var open = document.querySelector( SELECTOR + '[open]' );

			if ( ! open ) {
				return;
			}

			open.open = false;
			syncExpanded( open );

			// Focus goes back to the control that opened it. Closing a menu and
			// leaving focus on a node that is now hidden strands a keyboard user
			// at the top of the document.
			var summary = summaryOf( open );

			if ( summary ) {
				summary.focus();
			}
		} );
	} );
} )();
