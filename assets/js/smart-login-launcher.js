/**
 * Smart Login — the launcher.
 *
 * This file loads on every page of the site, so it does one job: recognise that
 * somebody wants to sign in, and fetch the rest. The stylesheet and the flow
 * script arrive on the first open and never before — the alternative is loading
 * the whole sign-in surface on every page against the possibility of a click.
 *
 * Four ways in, one vocabulary:
 *
 *   ?smart_login_step=identify   canonical; the only form the server can see
 *   #login                       an alias, resolved to the canonical form
 *   [data-smart-login="<step>"]  for elements that are not links
 *   window.SmartLogin.open(step) for themes and other plugins
 *
 * The step allowlist is not in this file. It is localized from PHP, where it
 * has lived since a step could first be asked for by URL. A second copy here is
 * how the two drift.
 */
( function () {
	'use strict';

	var data = window.SmartLoginDialog || {};
	var steps = data.steps || [];
	var aliases = data.aliases || {};
	var loading = null;

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	/**
	 * Resolve any spelling to a step the server allows, or ''.
	 *
	 * An unknown value returns '' and the caller leaves the page alone. The
	 * theme may own that fragment, and claiming every hash on the site would be
	 * a plugin deciding what somebody else's anchors mean.
	 */
	function resolve( value ) {
		var name = String( value || '' ).replace( /^#/, '' ).toLowerCase();

		if ( ! name ) {
			return '';
		}

		if ( Object.prototype.hasOwnProperty.call( aliases, name ) ) {
			return aliases[ name ];
		}

		return steps.indexOf( name ) !== -1 ? name : '';
	}

	/** The step asked for by the current URL, by either spelling. */
	function requestedStep() {
		var params = new URLSearchParams( window.location.search );
		var queried = resolve( params.get( data.param || 'smart_login_step' ) );

		return queried || resolve( window.location.hash );
	}

	function loadAsset( tag, attrs ) {
		return new Promise( function ( resolve, reject ) {
			var node = document.createElement( tag );

			Object.keys( attrs ).forEach( function ( key ) {
				node[ key ] = attrs[ key ];
			} );

			node.onload = resolve;
			node.onerror = reject;
			document.head.appendChild( node );
		} );
	}

	/**
	 * Pull in the sign-in surface, once.
	 *
	 * The promise is cached rather than a boolean flag: two clicks in the same
	 * second must wait on the same load, not start two.
	 */
	function loadDialog() {
		if ( loading ) {
			return loading;
		}

		var assets = data.assets || {};

		loading = Promise.all( [
			loadAsset( 'link', { rel: 'stylesheet', href: data.dialogCss } ),
			loadAsset( 'link', { rel: 'stylesheet', href: assets.css } ),
			loadAsset( 'script', { src: assets.js, async: false } )
		] ).then( function () {
			// After the shared script, which it builds on.
			return loadAsset( 'script', { src: data.dialogJs, async: false } );
		} );

		return loading;
	}

	function open( step ) {
		var wanted = resolve( step ) || resolve( 'login' );

		if ( ! wanted ) {
			return Promise.resolve();
		}

		return loadDialog().then( function () {
			if ( window.SmartLoginDialogApi ) {
				window.SmartLoginDialogApi.open( wanted );
			}
		} );
	}

	function close() {
		if ( window.SmartLoginDialogApi ) {
			window.SmartLoginDialogApi.close();
		}
	}

	// ---------------------------------------------------------------
	// Triggers
	// ---------------------------------------------------------------

	/**
	 * One delegated listener, so markup added after load works too.
	 *
	 * **`href` is never touched.** Interception is what makes capture safe: with
	 * this script blocked, removed, or simply not loaded yet, every link on the
	 * page is the link its author wrote. A plugin that rewrote them would own a
	 * failure mode where its own script is the only thing keeping the site's
	 * sign-in working.
	 */
	function onClick( event ) {
		// A modified click is the visitor asking for the page, not the dialog.
		if ( event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var trigger = event.target.closest( '[data-smart-login]' );

		if ( trigger ) {
			var asked = resolve( trigger.getAttribute( 'data-smart-login' ) || 'login' );

			if ( asked ) {
				event.preventDefault();
				open( asked );
			}

			return;
		}

		var link = event.target.closest( 'a[href]' );

		if ( ! link || link.closest( '[data-no-smart-login]' ) ) {
			return;
		}

		var hash = link.getAttribute( 'href' );

		if ( hash && hash.charAt( 0 ) === '#' ) {
			var step = resolve( hash );

			if ( step ) {
				event.preventDefault();
				open( step );
			}
		}
	}

	function onHashChange() {
		var step = resolve( window.location.hash );

		if ( step ) {
			open( step );
		}
	}

	/**
	 * A visitor coming back from a provider as a brand-new member.
	 *
	 * The hand-off to Google is a full-page navigation, so the dialog closed
	 * when they left. They return to the page they started on carrying
	 * `smartlogin_welcome=1`, which is the flag a finished registration has
	 * always put in a URL — not a new spelling invented for this.
	 *
	 * Skipped when the page already renders the welcome screen itself. A page
	 * hosting `[smart_login]` draws it from the same flag, and opening a dialog
	 * on top of it would ask the same questions twice.
	 */
	function welcomeStep() {
		var welcome = data.welcome || {};

		if ( ! welcome.flag ) {
			return '';
		}

		var params = new URLSearchParams( window.location.search );

		if ( ! params.get( welcome.flag ) ) {
			return '';
		}

		return document.querySelector( '[data-sl-onboarding]' ) ? '' : welcome.step;
	}

	function forgetWelcome() {
		var welcome = data.welcome || {};
		var url = new URL( window.location.href );

		url.searchParams.delete( welcome.flag );
		window.history.replaceState( window.history.state, '', url.toString() );
	}

	ready( function () {
		if ( ! data.endpoint ) {
			return;
		}

		document.addEventListener( 'click', onClick );
		window.addEventListener( 'hashchange', onHashChange );

		var welcome = welcomeStep();

		if ( welcome ) {
			// Stripped straight away, so a reload does not ask a member who has
			// already answered to answer again.
			forgetWelcome();

			loadDialog().then( function () {
				if ( window.SmartLoginDialogApi ) {
					window.SmartLoginDialogApi.open( welcome );
				}
			} );

			return;
		}

		var initial = requestedStep();

		if ( initial ) {
			open( initial );
		}
	} );

	window.SmartLogin = {
		open: open,
		close: close,
		resolve: resolve
	};
} )();
