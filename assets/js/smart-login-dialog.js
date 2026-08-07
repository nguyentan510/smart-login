/**
 * Smart Login — the dialog.
 *
 * Loaded on the first open, never before. It fetches a step as HTML and posts
 * the form back to the same endpoint, replacing one fragment with the next.
 * Nothing here renders a form: the server does that, from the templates the
 * shortcode uses, so the two cannot drift.
 *
 * `showModal()` supplies the focus trap, Esc and the inert background. Three
 * things it does not supply are written here: the scroll lock, focus returned
 * to whatever opened it, and a refusal to close mid-request.
 */
( function () {
	'use strict';

	var data = window.SmartLoginDialog || {};
	var i18n = data.i18n || {};
	var dialog = document.querySelector( '[data-sl-dialog]' );
	var body = dialog && dialog.querySelector( '[data-sl-dialog-body]' );
	var titleEl = dialog && dialog.querySelector( '[data-sl-dialog-title]' );
	var opener = null;
	var busy = false;

	if ( ! dialog || ! body ) {
		return;
	}

	function here() {
		// Without the trigger, so a fragment never offers to send the visitor
		// back to a URL that reopens the dialog on arrival.
		var url = new URL( window.location.href );

		url.searchParams.delete( data.param || 'smart_login_step' );
		url.hash = '';

		return url.toString();
	}

	function setBusy( state ) {
		busy = state;
		body.setAttribute( 'aria-busy', state ? 'true' : 'false' );
		dialog.classList.toggle( 'is-busy', state );
	}

	/**
	 * Put a fragment on screen and hand it to the shared enhancements.
	 *
	 * `smart-login.js` binds the OTP boxes, the countdown and the password
	 * toggle on DOMContentLoaded, which has long since fired. It exposes
	 * `SmartLoginEnhance` for exactly this — markup that arrives later.
	 */
	function paint( payload ) {
		body.innerHTML = payload.html || '';

		if ( titleEl && payload.title ) {
			titleEl.textContent = payload.title;
		}

		if ( window.SmartLoginEnhance ) {
			window.SmartLoginEnhance( body );
		}

		holdSubmit();
		focusFirst();
	}

	/**
	 * Keep submit disabled until the fragment is old enough to be accepted.
	 *
	 * `RequestGuard::MIN_FILL_SECONDS` rejects a form submitted within two
	 * seconds of being minted. That floor was written for a form that loads with
	 * the page, where two seconds is unreachable; a dialog filled by a password
	 * manager reaches it easily. The guard is right and stays untouched — what
	 * changes is that the visitor sees a button enable itself rather than an
	 * error accusing them of being a bot.
	 */
	function holdSubmit() {
		var wait = ( data.minFill || 0 ) * 1000;

		if ( wait <= 0 ) {
			return;
		}

		var buttons = body.querySelectorAll( 'button[type="submit"]' );

		buttons.forEach( function ( button ) {
			button.disabled = true;
		} );

		window.setTimeout( function () {
			buttons.forEach( function ( button ) {
				button.disabled = false;
			} );
		}, wait );
	}

	function focusFirst() {
		var target = body.querySelector( '[autofocus], input:not([type="hidden"]):not([tabindex="-1"]), button' );

		if ( target ) {
			target.focus();
		}
	}

	function request( options ) {
		setBusy( true );

		return window
			.fetch( options.url, options.init )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var payload = ( json && json.data ) || {};

				if ( payload.redirect ) {
					window.location.assign( payload.redirect );
					return;
				}

				paint( payload );
			} )
			.catch( function () {
				body.innerHTML = '<p class="sl-notice sl-notice--error">' + ( i18n.failed || '' ) + '</p>';
			} )
			.then( function () {
				setBusy( false );
			} );
	}

	function load( step ) {
		var url = new URL( data.endpoint );

		url.searchParams.set( 'step', step );
		url.searchParams.set( 'page', here() );
		url.searchParams.set( 'redirect_to', here() );

		return request( { url: url.toString(), init: { credentials: 'same-origin' } } );
	}

	/**
	 * Submit the fragment's form through the endpoint instead of the page.
	 *
	 * The body is the form's own fields — nonce, signed timestamp and honeypot
	 * included — so the server checks it exactly as it checks a page submit.
	 */
	function onSubmit( event ) {
		var form = event.target.closest( 'form' );

		if ( ! form || ! body.contains( form ) ) {
			return;
		}

		event.preventDefault();

		if ( busy ) {
			return;
		}

		var payload = new FormData( form );

		// A submit button's own name/value is not in FormData unless it is named
		// as the submitter. "Để sau" on the welcome screen is distinguishable
		// only by that, and losing it would turn skipping into saving.
		if ( event.submitter && event.submitter.name ) {
			payload.append( event.submitter.name, event.submitter.value );
		}

		payload.append( 'page', here() );

		// Only when the form does not carry one. Several steps render their own
		// `redirect_to` — onboarding's is where "Hoàn tất" and "Để sau" both
		// lead — and appending a second entry would silently win, because PHP
		// keeps the last value for a repeated key.
		if ( ! payload.get( 'redirect_to' ) ) {
			payload.append( 'redirect_to', here() );
		}

		request( {
			url: data.endpoint,
			init: { method: 'POST', body: payload, credentials: 'same-origin' }
		} );
	}

	function open( step ) {
		if ( dialog.open ) {
			return;
		}

		opener = document.activeElement;
		document.documentElement.style.overflow = 'hidden';

		if ( dialog.showModal ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', 'open' );
		}

		load( step );
	}

	/**
	 * Take the trigger back out of the URL.
	 *
	 * `replaceState`, not `pushState`: opening pushed no entry, so closing must
	 * not add one. Without this, a visitor who opened the dialog from
	 * `?smart_login_step=identify` and closed it would reopen it on refresh, and
	 * the back button would walk them through a sequence of dialogs.
	 */
	function forget() {
		var url = new URL( window.location.href );

		url.searchParams.delete( data.param || 'smart_login_step' );
		url.hash = '';

		window.history.replaceState( window.history.state, '', url.toString() );
	}

	function close() {
		// Mid-request means a code may already have been sent. Closing here
		// would leave the visitor with an SMS and no box to type it into.
		if ( busy ) {
			return;
		}

		forget();
		document.documentElement.style.overflow = '';

		if ( dialog.close ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}

		if ( opener && opener.focus ) {
			opener.focus();
		}
	}

	dialog.addEventListener( 'submit', onSubmit );

	dialog.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '[data-sl-dialog-close]' ) ) {
			close();
			return;
		}

		// The backdrop is the dialog element itself; the panel sits inside it.
		if ( event.target === dialog ) {
			close();
		}
	} );

	// Esc is `showModal()`'s, and it bypasses close(). Cancel it while busy for
	// the same reason the close button is inert then.
	dialog.addEventListener( 'cancel', function ( event ) {
		if ( busy ) {
			event.preventDefault();
			return;
		}

		forget();
		document.documentElement.style.overflow = '';
	} );

	window.SmartLoginDialogApi = {
		open: open,
		close: close
	};
} )();
