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
	// All-in-one authentication tabs
	// ---------------------------------------------------------------

	function initAuthTabs( root ) {
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[data-sl-auth-tab]' ) );
		var panels = Array.prototype.slice.call( root.querySelectorAll( '[data-sl-auth-panel]' ) );
		var form = root.querySelector( '[data-sl-auth-form]' );
		var action = root.querySelector( '[data-sl-auth-action]' );

		if ( ! tabs.length || ! panels.length ) {
			return;
		}

		function activate( name, focus ) {
			tabs.forEach( function ( tab ) {
				var selected = tab.getAttribute( 'data-sl-auth-tab' ) === name;

				tab.classList.toggle( 'is-active', selected );
				tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', selected ? '0' : '-1' );

				if ( selected && focus ) {
					tab.focus();
				}
			} );

			panels.forEach( function ( panel ) {
				var selected = panel.getAttribute( 'data-sl-auth-panel' ) === name;

				panel.hidden = ! selected;
				panel.classList.toggle( 'is-active', selected );

				panel.querySelectorAll( 'input, button, select, textarea' ).forEach( function ( control ) {
					control.disabled = ! selected;
				} );
			} );

			if ( action ) {
				action.value = name;
			}

			if ( form ) {
				form.querySelectorAll( '[data-sl-auth-submit]' ).forEach( function ( submit ) {
					submit.disabled = submit.getAttribute( 'data-sl-auth-submit' ) !== name;
				} );
			}

			root.setAttribute( 'data-active-tab', name );
		}

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( tab.getAttribute( 'data-sl-auth-tab' ), false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var next;

				if ( event.key === 'ArrowRight' ) {
					next = ( index + 1 ) % tabs.length;
				} else if ( event.key === 'ArrowLeft' ) {
					next = ( index - 1 + tabs.length ) % tabs.length;
				} else {
					return;
				}

				event.preventDefault();
				activate( tabs[ next ].getAttribute( 'data-sl-auth-tab' ), true );
			} );
		} );

		activate( root.getAttribute( 'data-active-tab' ) || 'login', false );
	}

	// ---------------------------------------------------------------
	// Registration password confirmation
	// ---------------------------------------------------------------

	function initPasswordConfirmation( root ) {
		var form = root.querySelector( '[data-sl-auth-form]' );
		var action = root.querySelector( '[data-sl-auth-action]' );

		if ( ! form ) {
			return;
		}

		var password = form.querySelector( '#sl-reg-password' );
		var confirmation = form.querySelector( '#sl-reg-password-confirm' );
		var status = form.querySelector( '#sl-password-match' );

		if ( ! password || ! confirmation || ! status ) {
			return;
		}

		function validate( showEmpty ) {
			var hasConfirmation = confirmation.value.length > 0;
			var matches = hasConfirmation && password.value === confirmation.value;

			status.classList.remove( 'is-error', 'is-success' );
			confirmation.removeAttribute( 'aria-invalid' );

			if ( ! hasConfirmation ) {
				confirmation.setCustomValidity( showEmpty ? ( i18n.passConfirm || 'Please confirm your password.' ) : '' );
				status.textContent = showEmpty ? ( i18n.passConfirm || 'Please confirm your password.' ) : '';
				status.hidden = ! showEmpty;

				if ( showEmpty ) {
					status.classList.add( 'is-error' );
					confirmation.setAttribute( 'aria-invalid', 'true' );
				}

				return false;
			}

			if ( ! matches ) {
				confirmation.setCustomValidity( i18n.passMismatch || 'Passwords do not match.' );
				confirmation.setAttribute( 'aria-invalid', 'true' );
				status.textContent = i18n.passMismatch || 'Passwords do not match.';
				status.classList.add( 'is-error' );
				status.hidden = false;
				return false;
			}

			confirmation.setCustomValidity( '' );
			status.textContent = i18n.passMatch || 'Passwords match.';
			status.classList.add( 'is-success' );
			status.hidden = false;
			return true;
		}

		password.addEventListener( 'input', function () {
			if ( confirmation.value ) {
				validate( false );
			}
		} );

		confirmation.addEventListener( 'input', function () {
			validate( false );
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( action && action.value !== 'register' ) {
				return;
			}

			if ( ! validate( true ) ) {
				event.preventDefault();
				confirmation.focus();
			}
		} );
	}

	// ---------------------------------------------------------------
	// Password length guidance
	// ---------------------------------------------------------------

	function initPasswordLengthGuidance( root ) {
		root.querySelectorAll( '[data-sl-password-guidance]' ).forEach( function ( guidance ) {
			var inputId = guidance.getAttribute( 'aria-controls' ) || 'sl-reg-password';
			var input = document.getElementById( inputId );
			var minimum = parseInt( guidance.getAttribute( 'data-minlength' ), 10 );

			if ( ! input || isNaN( minimum ) ) {
				return;
			}

			function update() {
				guidance.hidden = input.value.length > 0 && input.value.length < minimum ? false : true;
			}

			input.setAttribute( 'aria-describedby', guidance.id );
			input.addEventListener( 'input', update );
			update();
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
			initAuthTabs( root );
			initPasswordConfirmation( root );
			initPasswordLengthGuidance( root );
			initOtpBoxes( root );
			initCountdown( root );
			initResend( root );
			initDobMask( root );
			initContactVerification( root );
		} );
	} );
} )();
