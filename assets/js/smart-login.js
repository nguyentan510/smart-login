/**
 * Smart Login — progressive enhancement.
 *
 * Every form works without this file; JS only adds the niceties: the OTP
 * digit boxes, the countdown, the resend cooldown and the password toggle.
 */
( function () {
	'use strict';

	var data = window.SmartLoginData || {};
	var i18n = data.i18n || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	// ---------------------------------------------------------------
	// Password visibility
	// ---------------------------------------------------------------

	function initPasswordToggles( root ) {
		root.querySelectorAll( '.sl-toggle-password' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var input = document.getElementById( button.getAttribute( 'data-target' ) );

				if ( ! input ) {
					return;
				}

				var reveal = input.type === 'password';

				input.type = reveal ? 'text' : 'password';
				button.setAttribute( 'aria-label', reveal ? ( i18n.hidePass || 'Hide' ) : ( i18n.showPass || 'Show' ) );
				button.classList.toggle( 'is-visible', reveal );
			} );
		} );
	}

	// ---------------------------------------------------------------
	// Submit feedback
	//
	// Step 1 sends an SMS, so a double submit costs the site money and the
	// visitor a wasted code. Disabling on submit is the cheapest guard, and it
	// doubles as the "something is happening" signal on a slow network.
	// ---------------------------------------------------------------

	function initSubmitGuard( root ) {
		root.querySelectorAll( 'form.sl-form' ).forEach( function ( form ) {
			var submitted = false;

			form.addEventListener( 'submit', function ( event ) {
				// Let the browser's own required-field checks win first.
				if ( form.checkValidity && ! form.checkValidity() ) {
					return;
				}

				if ( submitted ) {
					event.preventDefault();
					return;
				}

				submitted = true;
				form.classList.add( 'is-submitting' );

				// Deliberately not `disabled`: the onboarding form carries two
				// submit buttons and the one that was pressed has to keep its
				// name in the payload for "Để sau" to be distinguishable.
				form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( button ) {
					button.setAttribute( 'aria-busy', 'true' );
				} );
			} );
		} );
	}

	// ---------------------------------------------------------------
	// Unsaved-changes guard
	//
	// The account form is long, and three of the things on it navigate away:
	// the provider link buttons are plain <a> elements, and the browser's own
	// back button is always there. Losing a screen of typing to a misclick is
	// the kind of defect people do not report, they just stop editing.
	//
	// Contact inputs are excluded on purpose. They carry no name, so submitting
	// never saves them; their own flow persists over REST and clears them.
	// ---------------------------------------------------------------

	var dirtyForms = [];

	function isTracked( field ) {
		return ! field.hasAttribute( 'data-sl-contact-value' ) && ! field.hasAttribute( 'data-sl-contact-code' );
	}

	function initDirtyGuard( root ) {
		root.querySelectorAll( 'form.sl-form' ).forEach( function ( form ) {
			var state = { form: form, dirty: false };

			form.addEventListener( 'input', function ( event ) {
				if ( isTracked( event.target ) ) {
					state.dirty = true;
				}
			} );

			form.addEventListener( 'change', function ( event ) {
				if ( isTracked( event.target ) ) {
					state.dirty = true;
				}
			} );

			// Submitting is how the edits stop being unsaved.
			form.addEventListener( 'submit', function () {
				state.dirty = false;
			} );

			dirtyForms.push( state );
		} );
	}

	function anythingDirty() {
		return dirtyForms.some( function ( state ) {
			return state.dirty;
		} );
	}

	// ---------------------------------------------------------------
	// Save bar
	//
	// Reflects the same dirty state the unload guard uses, so the page never
	// warns about unsaved changes it has not shown the visitor. Sections that
	// persist through their own request are excluded by isTracked(), which is
	// why the contact card carries a badge saying so instead.
	// ---------------------------------------------------------------

	function initSaveBar( root ) {
		root.querySelectorAll( '[data-sl-savebar]' ).forEach( function ( bar ) {
			var state = bar.querySelector( '[data-sl-savebar-state]' );
			// The text node inside the warning, so the "!" mark beside it survives
			// being repainted. Older markup had no inner span; fall back to the
			// element itself rather than silently painting nothing.
			var text = bar.querySelector( '[data-sl-savebar-text]' ) || state;
			var form = bar.closest( 'form' );

			if ( ! form ) {
				return;
			}

			function show( dirty ) {
				bar.classList.toggle( 'is-dirty', dirty );

				if ( ! state ) {
					return;
				}

				// `hidden`, not an empty string. An aria-live region that is present
				// and empty is a region a screen reader has already announced; one
				// that appears is an announcement. It also lets CSS give the warning
				// a shape without it reserving space when there is nothing to warn
				// about.
				state.hidden = ! dirty;

				if ( text ) {
					text.textContent = dirty ? ( i18n.unsaved || '' ) : '';
				}
			}

			function paint() {
				show(
					dirtyForms.some( function ( entry ) {
						return entry.form === form && entry.dirty;
					} )
				);
			}

			form.addEventListener( 'input', paint );
			form.addEventListener( 'change', paint );
			form.addEventListener( 'submit', function () {
				show( false );
			} );

			/*
			 * "Huỷ" is a native <button type="reset">, so it works with JavaScript
			 * off — the browser puts every field back to the value the server
			 * rendered, which is exactly what cancelling an edit means.
			 *
			 * The event fires *before* the fields are restored, so the dirty
			 * recount has to wait a tick. Without that, cancelling leaves the bar
			 * warning about changes that no longer exist.
			 */
			form.addEventListener( 'reset', function () {
				window.setTimeout( function () {
					dirtyForms.forEach( function ( entry ) {
						if ( entry.form === form ) {
							entry.dirty = false;
						}
					} );

					show( false );
				}, 0 );
			} );

			paint();
		} );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! anythingDirty() ) {
			return;
		}

		// Browsers show their own wording and ignore ours; both lines are still
		// required for the prompt to appear at all.
		event.preventDefault();
		event.returnValue = '';
	} );

	// ---------------------------------------------------------------
	// OTP digit boxes
	// ---------------------------------------------------------------

	function initOtpBoxes( root ) {
		var wrap = root.querySelector( '.sl-otp-boxes' );

		if ( ! wrap ) {
			return;
		}

		var digits = Array.prototype.slice.call( wrap.querySelectorAll( '.sl-otp-digit' ) );
		var hidden = document.getElementById( 'sl-otp-code' );
		var form = document.getElementById( 'sl-otp-form' );
		var submit = document.getElementById( 'sl-otp-submit' );

		if ( ! digits.length ) {
			return;
		}

		function collect() {
			return digits
				.map( function ( input ) {
					return input.value.replace( /\D/g, '' );
				} )
				.join( '' );
		}

		function sync() {
			var code = collect();

			if ( hidden ) {
				hidden.value = code;
			}

			if ( submit ) {
				submit.disabled = code.length !== digits.length;
			}

			return code;
		}

		digits.forEach( function ( input, index ) {
			input.addEventListener( 'input', function () {
				input.value = input.value.replace( /\D/g, '' ).slice( 0, 1 );

				if ( input.value && index < digits.length - 1 ) {
					digits[ index + 1 ].focus();
				}

				var code = sync();

				// Auto-submit the moment the code is complete.
				if ( code.length === digits.length && form ) {
					form.requestSubmit ? form.requestSubmit() : form.submit();
				}
			} );

			input.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Backspace' && ! input.value && index > 0 ) {
					digits[ index - 1 ].focus();
					digits[ index - 1 ].value = '';
					sync();
					event.preventDefault();
				}

				if ( event.key === 'ArrowLeft' && index > 0 ) {
					digits[ index - 1 ].focus();
					event.preventDefault();
				}

				if ( event.key === 'ArrowRight' && index < digits.length - 1 ) {
					digits[ index + 1 ].focus();
					event.preventDefault();
				}
			} );

			// Pasting the whole code into any box should fill them all.
			input.addEventListener( 'paste', function ( event ) {
				var text = ( event.clipboardData || window.clipboardData ).getData( 'text' ) || '';
				var chars = text.replace( /\D/g, '' ).split( '' );

				if ( ! chars.length ) {
					return;
				}

				event.preventDefault();

				digits.forEach( function ( target, offset ) {
					if ( offset >= index ) {
						target.value = chars[ offset - index ] || '';
					}
				} );

				var code = sync();
				var next = Math.min( index + chars.length, digits.length - 1 );

				digits[ next ].focus();

				if ( code.length === digits.length && form ) {
					form.requestSubmit ? form.requestSubmit() : form.submit();
				}
			} );
		} );

		sync();
	}

	// ---------------------------------------------------------------
	// Countdown
	// ---------------------------------------------------------------

	function initCountdown( root ) {
		var el = root.querySelector( '.sl-countdown__value' );

		if ( ! el ) {
			return;
		}

		var remaining = parseInt( el.getAttribute( 'data-expires-in' ), 10 );

		if ( isNaN( remaining ) ) {
			return;
		}

		function pad( n ) {
			return n < 10 ? '0' + n : String( n );
		}

		function tick() {
			if ( remaining <= 0 ) {
				el.textContent = i18n.expired || '00:00';
				el.classList.add( 'is-expired' );
				return;
			}

			el.textContent = pad( Math.floor( remaining / 60 ) ) + ':' + pad( remaining % 60 );
			remaining -= 1;
			window.setTimeout( tick, 1000 );
		}

		tick();
	}

	// ---------------------------------------------------------------
	// Resend cooldown
	// ---------------------------------------------------------------

	function initResend( root ) {
		var button = root.querySelector( '#sl-resend-button' );

		if ( ! button ) {
			return;
		}

		var wait = parseInt( button.getAttribute( 'data-resend-after' ), 10 );

		if ( isNaN( wait ) || wait <= 0 ) {
			return;
		}

		var label = button.textContent;

		function tick() {
			if ( wait <= 0 ) {
				button.disabled = false;
				button.textContent = label;
				return;
			}

			button.disabled = true;
			button.textContent = ( i18n.resendIn || 'Resend in %ds' ).replace( '%d', wait );
			wait -= 1;
			window.setTimeout( tick, 1000 );
		}

		tick();
	}

	// ---------------------------------------------------------------
	// Date of birth: auto-insert the slashes as the user types.
	// ---------------------------------------------------------------

	function initDobMask( root ) {
		var input = root.querySelector( '#sl-dob' );

		if ( ! input ) {
			return;
		}

		input.addEventListener( 'input', function () {
			var digits = input.value.replace( /\D/g, '' ).slice( 0, 8 );
			var parts = [];

			if ( digits.length > 0 ) {
				parts.push( digits.slice( 0, 2 ) );
			}
			if ( digits.length > 2 ) {
				parts.push( digits.slice( 2, 4 ) );
			}
			if ( digits.length > 4 ) {
				parts.push( digits.slice( 4, 8 ) );
			}

			input.value = parts.join( '/' );
		} );
	}

	// ---------------------------------------------------------------
	// Authenticated phone/email changes.
	// ---------------------------------------------------------------

	function initContactVerification( root ) {
		root.querySelectorAll( '[data-sl-contact]' ).forEach( function ( panel ) {
			var type = panel.getAttribute( 'data-sl-contact' );
			var valueInput = panel.querySelector( '[data-sl-contact-value]' );
			var codeInput = panel.querySelector( '[data-sl-contact-code]' );
			var startButton = panel.querySelector( '[data-sl-contact-start]' );
			var verifyButton = panel.querySelector( '[data-sl-contact-verify]' );
			var resendButton = panel.querySelector( '[data-sl-contact-resend]' );
			var confirm = panel.querySelector( '[data-sl-contact-confirm]' );
			var masked = panel.querySelector( '[data-sl-contact-masked]' );
			var status = panel.querySelector( '[data-sl-contact-status]' );
			var targetSelector = panel.getAttribute( 'data-sl-contact-target' );
			var target = targetSelector ? document.querySelector( targetSelector ) : null;
			var pendingMask = panel.getAttribute( 'data-sl-contact-pending' );
			var toggle = panel.querySelector( '[data-sl-contact-toggle]' );
			var edit = panel.querySelector( '[data-sl-contact-edit]' );
			var verifiedBadge = panel.querySelector( '[data-sl-contact-verified]' );
			var token = '';

			// One "Đổi" per row, opening the exchange next to the value it
			// changes. The screen this replaces showed both OTP panels to
			// everybody, all the time, including people with nothing to change.
			function openEditor( open ) {
				if ( ! edit || ! toggle ) {
					return;
				}

				edit.hidden = ! open;
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

				if ( open && valueInput ) {
					valueInput.focus();
				}
			}

			if ( toggle && edit ) {
				toggle.addEventListener( 'click', function () {
					openEditor( edit.hidden );
				} );
			}

			if ( ! valueInput || ! startButton || ! verifyButton || ! confirm || ! status ) {
				return;
			}

			// A code was already in flight when this page loaded. Its token went
			// with the previous page, so the panel reopens without one and the
			// server resolves the flow by type instead.
			if ( null !== pendingMask ) {
				openEditor( true );
				confirm.hidden = false;

				if ( masked ) {
					masked.textContent = ( i18n.contactSent || 'OTP sent to %s.' ).replace( '%s', pendingMask );
				}
			}

			function message( text, kind ) {
				status.textContent = text || '';
				status.classList.toggle( 'is-error', kind === 'error' );
				status.classList.toggle( 'is-success', kind === 'success' );
			}

			function request( path, payload ) {
				// The honeypot and the signed timestamp the HTML forms carry.
				// Without them RequestGuard::verify_rest() has nothing to inspect,
				// so the guard was inert on this path rather than merely weak.
				var body = Object.assign( {}, payload || {} );

				body.smart_login_ts = String( data.stamp || '' );
				body.smart_login_website = '';

				return window.fetch( String( data.restUrl || '' ) + path, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': String( data.nonce || '' )
					},
					body: JSON.stringify( body )
				} ).then( function ( response ) {
					return response.json().catch( function () {
						return {};
					} ).then( function ( body ) {
						if ( ! response.ok || ! body.success ) {
							throw new Error( body.message || 'Request failed.' );
						}
						return body.data || {};
					} );
				} );
			}

			function busy( button, active ) {
				button.disabled = active;
				if ( active ) {
					button.setAttribute( 'aria-busy', 'true' );
				} else {
					button.removeAttribute( 'aria-busy' );
				}
			}

			startButton.addEventListener( 'click', function () {
				message( i18n.contactWait || 'Please wait…' );
				busy( startButton, true );
				request( 'contact/start', { type: type, value: valueInput.value } )
					.then( function ( result ) {
						token = String( result.token || '' );
						confirm.hidden = false;
						if ( masked ) {
							masked.textContent = ( i18n.contactSent || 'OTP sent to %s.' ).replace( '%s', result.masked || '' );
						}
						message( '' );
						if ( codeInput ) {
							codeInput.focus();
						}
					} )
					.catch( function ( error ) {
						message( error.message, 'error' );
					} )
					.then( function () {
						busy( startButton, false );
					} );
			} );

			// Enter is the reflex in a single-field row. Without these handlers the
			// browser submits the account form instead: these inputs carry no
			// name, so the typed value is thrown away and everything else saves
			// as a side effect of asking for a code.
			valueInput.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					startButton.click();
				}
			} );

			if ( codeInput ) {
				codeInput.addEventListener( 'keydown', function ( event ) {
					if ( 'Enter' === event.key ) {
						event.preventDefault();
						verifyButton.click();
					}
				} );
			}

			verifyButton.addEventListener( 'click', function () {
				message( i18n.contactWait || 'Please wait…' );
				busy( verifyButton, true );
				request( 'contact/verify', { type: type, token: token, code: codeInput ? codeInput.value : '' } )
					.then( function ( result ) {
						// The server's formatting, not the raw input: a phone is
						// stored E.164 and displayed local.
						var accepted = ( result && result.display ) ? result.display : valueInput.value;

						message( i18n.contactDone || 'Contact verified.', 'success' );

						// This used to reload the page, which discarded every
						// other edit in progress. Writing the accepted value back
						// is not cosmetic either: the form posts the field it is
						// showing, so leaving the previous address in place makes
						// the next save fail block_unverified_email_change().
						if ( target ) {
							// The row shows text now, not an input value.
							if ( 'value' in target && target.tagName !== 'SPAN' ) {
								target.value = accepted;
							} else {
								target.textContent = accepted;
							}
						}

						if ( verifiedBadge ) {
							verifiedBadge.hidden = false;
						}

						token = '';
						valueInput.value = '';

						if ( codeInput ) {
							codeInput.value = '';
						}

						confirm.hidden = true;
						openEditor( false );
						panel.removeAttribute( 'data-sl-contact-pending' );
						busy( verifyButton, false );
					} )
					.catch( function ( error ) {
						message( error.message, 'error' );
						busy( verifyButton, false );
					} );
			} );

			if ( resendButton ) {
				resendButton.addEventListener( 'click', function () {
					message( i18n.contactWait || 'Please wait…' );
					busy( resendButton, true );

					// No token means this page did not start the flow — a reload,
					// almost always. The pending row is still on the server, so
					// ask by type and take the fresh token from the reply.
					request( 'contact/resend', token ? { token: token } : { type: type } )
						.then( function ( result ) {
							token = String( result.token || token );
							if ( masked ) {
								masked.textContent = ( i18n.contactSent || 'OTP sent to %s.' ).replace( '%s', result.masked || '' );
							}
							message( '' );
						} )
						.catch( function ( error ) {
							message( error.message, 'error' );
						} )
						.then( function () {
							busy( resendButton, false );
						} );
				} );
			}
		} );
	}

	// ---------------------------------------------------------------

	ready( function () {
		document.querySelectorAll( '.smart-login' ).forEach( function ( root ) {
			initPasswordToggles( root );
			initSubmitGuard( root );
			initDirtyGuard( root );
			initOtpBoxes( root );
			initCountdown( root );
			initResend( root );
			initDobMask( root );
			initContactVerification( root );
			initSaveBar( root );
		} );
	} );
} )();
