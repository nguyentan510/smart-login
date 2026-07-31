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
			var token = '';

			if ( ! valueInput || ! startButton || ! verifyButton || ! confirm || ! status ) {
				return;
			}

			function message( text, kind ) {
				status.textContent = text || '';
				status.classList.toggle( 'is-error', kind === 'error' );
				status.classList.toggle( 'is-success', kind === 'success' );
			}

			function request( path, payload ) {
				return window.fetch( String( data.restUrl || '' ) + path, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': String( data.nonce || '' )
					},
					body: JSON.stringify( payload )
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

			verifyButton.addEventListener( 'click', function () {
				message( i18n.contactWait || 'Please wait…' );
				busy( verifyButton, true );
				request( 'contact/verify', { type: type, token: token, code: codeInput ? codeInput.value : '' } )
					.then( function () {
						message( i18n.contactDone || 'Contact verified.', 'success' );
						window.setTimeout( function () {
							window.location.reload();
						}, 700 );
					} )
					.catch( function ( error ) {
						message( error.message, 'error' );
						busy( verifyButton, false );
					} );
			} );

			if ( resendButton ) {
				resendButton.addEventListener( 'click', function () {
					if ( ! token ) {
						startButton.click();
						return;
					}
					message( i18n.contactWait || 'Please wait…' );
					busy( resendButton, true );
					request( 'contact/resend', { token: token } )
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
			initOtpBoxes( root );
			initCountdown( root );
			initResend( root );
			initDobMask( root );
			initContactVerification( root );
		} );
	} );
} )();
