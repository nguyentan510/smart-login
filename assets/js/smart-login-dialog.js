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

		enhanceAddress();
		holdSubmit();
		focusFirst();
	}

	/**
	 * The address picker: a third stage, fetched by the fragment that needs one.
	 *
	 * `address.js` is not in the dialog's bundle, and until 19.12 it was not
	 * anywhere else on this path either — the template asks for it through
	 * `Assets::enqueue_address()`, and inside the REST request that renders a
	 * fragment there is no `wp_enqueue_scripts` listening. The welcome screen drew
	 * two selects that did nothing.
	 *
	 * Loading it with the dialog would put the whole picker on every identify and
	 * OTP open, none of which contains an address field. So it is fetched here,
	 * once, and only when a painted fragment actually has one.
	 */
	var addressLoading = null;

	function loadAddressPicker() {
		if ( addressLoading ) {
			return addressLoading;
		}

		// Already on the page: a dialog opened over `[smart_profile]` has it
		// enqueued the ordinary way, and a second copy would rebind nothing.
		if ( window.SmartLoginAddressEnhance || ! data.addressJs ) {
			return Promise.resolve();
		}

		// Before the script, which reads this global as it runs. It comes from
		// PHP the same way everything else here does — `wp_localize_script()`
		// cannot reach a script the page injects itself.
		window.SmartLoginAddress = window.SmartLoginAddress || data.address || {};

		addressLoading = new Promise( function ( resolve ) {
			var node = document.createElement( 'script' );

			node.src = data.addressJs;
			node.async = false;

			// Resolved either way. A picker that failed to load is the plain
			// pair of selects the server already rendered, which is the state
			// this whole file degrades to; rejecting would add an unhandled
			// rejection on top of it and change nothing on screen.
			node.onload = resolve;
			node.onerror = resolve;

			document.head.appendChild( node );
		} );

		return addressLoading;
	}

	function enhanceAddress() {
		if ( ! body.querySelector( '[data-sl-address]' ) ) {
			return;
		}

		loadAddressPicker().then( function () {
			if ( window.SmartLoginAddressEnhance ) {
				window.SmartLoginAddressEnhance( body );
			}
		} );
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

	/**
	 * Where the visitor should end up once they are signed in.
	 *
	 * Defaults to the page they are on, which is the whole point of the dialog.
	 * A captured link may override it — "sign in and come back to the cart" has
	 * to keep meaning that — and the server validates either way, so an off-site
	 * value cannot survive.
	 */
	var destination = '';

	function load( step ) {
		var url = new URL( data.endpoint );

		url.searchParams.set( 'step', step );
		url.searchParams.set( 'page', here() );
		url.searchParams.set( 'redirect_to', destination || here() );

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
			payload.append( 'redirect_to', destination || here() );
		}

		request( {
			url: data.endpoint,
			init: { method: 'POST', body: payload, credentials: 'same-origin' }
		} );
	}

	function open( step, redirectTo ) {
		if ( dialog.open ) {
			return;
		}

		destination = redirectTo || '';

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
