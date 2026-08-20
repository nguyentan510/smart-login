/**
 * Navigation surfaces: the mega panel, the mobile category sheet, and the dock's
 * Danh mục tab.
 *
 * There is no pixel width anywhere in this file, and that is a rule rather than
 * a preference: the stylesheet decides what narrow means and publishes the
 * answer as --ow-layout. A second copy of the breakpoint here is a second copy
 * that will disagree, and nothing would notice when it did. Enforced by rule 3
 * in tests/navigation/run-navigation-tests.php.
 *
 * @package OmniWP
 */

( function () {
	'use strict';

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

	var openPanel = null;
	var opener = null;

	/** What the stylesheet says the layout currently is. */
	function isNarrow() {
		return 'narrow' === window.getComputedStyle( document.documentElement )
			.getPropertyValue( '--ow-layout' ).trim();
	}

	function toggleFor( panel ) {
		return document.querySelector( '[data-ow-mega-toggle="' + panel.id + '"]' );
	}

	function open( panel, trigger ) {
		if ( openPanel && openPanel !== panel ) {
			close();
		}

		panel.removeAttribute( 'hidden' );

		var toggle = toggleFor( panel );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
		}

		openPanel = panel;
		opener = trigger || toggle || null;

		/*
		 * The sheet covers the page on a narrow screen, so the page behind it must
		 * not scroll. On a wide screen the panel is a dropdown and the page behind
		 * it is still the page — locking it there would be a bug, not a nicety.
		 */
		if ( isNarrow() ) {
			document.body.style.overflow = 'hidden';

			var first = panel.querySelector( FOCUSABLE );

			if ( first ) {
				first.focus();
			}
		}
	}

	function close() {
		if ( ! openPanel ) {
			return;
		}

		var toggle = toggleFor( openPanel );

		openPanel.setAttribute( 'hidden', '' );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
		}

		document.body.style.overflow = '';

		if ( opener && typeof opener.focus === 'function' ) {
			opener.focus();
		}

		openPanel = null;
		opener = null;
	}

	/** Move the rail's selection, and the pane it controls, together. */
	function selectRail( button ) {
		var panel = button.closest( '[data-ow-mega]' );

		if ( ! panel ) {
			return;
		}

		var paneId = button.getAttribute( 'data-ow-mega-rail' );

		Array.prototype.forEach.call( panel.querySelectorAll( '[data-ow-mega-rail]' ), function ( item ) {
			var active = item === button;

			item.classList.toggle( 'is-active', active );
			item.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );

		Array.prototype.forEach.call( panel.querySelectorAll( '.ow-mega__pane' ), function ( pane ) {
			var active = pane.id === paneId;

			pane.classList.toggle( 'is-active', active );

			if ( active ) {
				pane.removeAttribute( 'hidden' );
			} else {
				pane.setAttribute( 'hidden', '' );
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest( '[data-ow-mega-toggle]' );

		if ( toggle ) {
			event.preventDefault();

			var panel = document.getElementById( toggle.getAttribute( 'data-ow-mega-toggle' ) );

			if ( panel ) {
				if ( panel === openPanel ) {
					close();
				} else {
					open( panel, toggle );
				}
			}

			return;
		}

		var rail = event.target.closest( '[data-ow-mega-rail]' );

		if ( rail ) {
			event.preventDefault();
			selectRail( rail );

			return;
		}

		/*
		 * The dock's Danh mục tab opens the first panel on the page when there is
		 * one, and otherwise follows its own href to the shop archive. The link is
		 * the fallback rather than the afterthought: it is what the tab does with
		 * this script absent, and what it did before any panel existed.
		 */
		var dockCategories = event.target.closest( '.ow-dock__item--categories .ow-dock__link' );

		if ( dockCategories ) {
			var sheet = document.querySelector( '[data-ow-mega]' );

			if ( sheet ) {
				event.preventDefault();
				open( sheet, dockCategories );
			}

			return;
		}

		if ( openPanel && ! event.target.closest( '[data-ow-mega]' ) ) {
			close();
		}
	} );

	/*
	 * Hover opens the panel only where hovering is a thing. A touch device fires
	 * a synthetic mouseenter on tap, which would open the panel and then let the
	 * click close it again.
	 */
	document.addEventListener( 'pointerenter', function ( event ) {
		if ( 'mouse' !== event.pointerType || isNarrow() ) {
			return;
		}

		var item = event.target.closest ? event.target.closest( '.ow-has-mega' ) : null;

		if ( ! item ) {
			return;
		}

		var panel = item.querySelector( '[data-ow-mega]' );

		if ( panel && panel !== openPanel ) {
			open( panel, null );
		}
	}, true );

	document.addEventListener( 'pointerleave', function ( event ) {
		if ( 'mouse' !== event.pointerType || isNarrow() || ! openPanel ) {
			return;
		}

		var item = event.target.closest ? event.target.closest( '.ow-has-mega' ) : null;

		if ( item && item.contains( openPanel ) ) {
			close();
		}
	}, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! openPanel ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			close();

			return;
		}

		if ( 'Tab' !== event.key || ! isNarrow() ) {
			return;
		}

		/*
		 * Trapped only while the sheet covers the screen. A dropdown that refused
		 * to let the keyboard leave would be a worse control than the theme's own
		 * menu, which tabs straight through.
		 */
		var stops = openPanel.querySelectorAll( FOCUSABLE );

		if ( ! stops.length ) {
			return;
		}

		var first = stops[ 0 ];
		var last = stops[ stops.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );
}() );
