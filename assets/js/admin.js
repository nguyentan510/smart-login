/**
 * Smart Login — admin: the channel test button.
 */
( function () {
	'use strict';

	var config = window.SmartLoginAdmin || {};
	var i18n = config.i18n || {};

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function block( title, content ) {
		if ( ! content ) {
			return '';
		}

		return '<h4>' + escapeHtml( title ) + '</h4><pre>' + escapeHtml( content ) + '</pre>';
	}

	function renderRequest( request ) {
		if ( ! request || ! request.url ) {
			return '';
		}

		var lines = [ ( request.method || 'POST' ) + ' ' + request.url ];

		Object.keys( request.headers || {} ).forEach( function ( key ) {
			lines.push( key + ': ' + request.headers[ key ] );
		} );

		if ( request.body ) {
			lines.push( '' );
			lines.push( request.body );
		}

		return block( 'Request', lines.join( '\n' ) );
	}

	function render( box, payload, ok ) {
		var html = '';

		if ( payload.status || payload.duration_ms ) {
			html += '<p class="sl-test-meta">HTTP ' + escapeHtml( payload.status || 0 ) +
				' &middot; ' + escapeHtml( payload.duration_ms || 0 ) + 'ms</p>';
		}

		html += '<p><strong>' + escapeHtml( payload.message || '' ) + '</strong></p>';
		html += renderRequest( payload.request );
		html += block( 'Response', payload.response );

		box.innerHTML = html;
		box.classList.toggle( 'is-ok', ok );
		box.classList.toggle( 'is-error', ! ok );
		box.hidden = false;
	}

	function init( tester ) {
		var button = tester.querySelector( '.sl-test-button' );
		var input = tester.querySelector( '.sl-test-destination' );
		var box = tester.querySelector( '.sl-test-result' );
		var channel = tester.getAttribute( 'data-channel' ) || 'sms';

		if ( ! button || ! input || ! box ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var destination = input.value.trim();

			if ( ! destination ) {
				input.focus();
				render( box, { message: i18n.prompt || '' }, false );
				return;
			}

			var label = button.textContent;

			button.disabled = true;
			button.textContent = i18n.sending || 'Sending…';
			box.hidden = true;

			var body = new URLSearchParams();
			body.append( 'action', 'smart_login_test_channel' );
			body.append( 'nonce', config.nonce || '' );
			body.append( 'channel', channel );
			body.append( 'destination', destination );

			window
				.fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					render( box, json.data || {}, !! json.success );
				} )
				.catch( function () {
					render( box, { message: i18n.failed || 'Request failed' }, false );
				} )
				.finally( function () {
					button.disabled = false;
					button.textContent = label;
				} );
		} );
	}

	function initProviderCard( card ) {
		var tabs = card.querySelectorAll( '[data-provider-tab]' );
		var panels = card.querySelectorAll( '[data-provider-panel]' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = tab.getAttribute( 'data-provider-tab' );

				tabs.forEach( function ( item ) {
					var active = item === tab;
					item.classList.toggle( 'is-active', active );
					item.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );

				panels.forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-provider-panel' ) !== target;
				} );
			} );
		} );
	}

	/**
	 * The mail message list: one editor open at a time.
	 *
	 * Hiding is done here rather than in the markup deliberately. Every panel is
	 * rendered and visible by default, so with scripts off this screen is the
	 * long page it was before — not a page where five of six messages cannot be
	 * reached. The script takes away; it never adds the only way in.
	 */
	function initMailMessages( surface ) {
		var panels = surface.querySelectorAll( '[data-mail-panel]' );
		var rows = surface.querySelectorAll( '[data-mail-message]' );
		var buttons = surface.querySelectorAll( '[data-mail-open]' );

		if ( ! panels.length ) {
			return;
		}

		function open( id ) {
			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-mail-panel' ) !== id;
			} );

			rows.forEach( function ( row ) {
				row.classList.toggle( 'is-open', row.getAttribute( 'data-mail-message' ) === id );
			} );

			buttons.forEach( function ( button ) {
				button.setAttribute( 'aria-expanded', button.getAttribute( 'data-mail-open' ) === id ? 'true' : 'false' );
			} );
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				open( button.getAttribute( 'data-mail-open' ) );
			} );
		} );

		open( panels[ 0 ].getAttribute( 'data-mail-panel' ) );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.sl-tester' ).forEach( init );
		document.querySelectorAll( '[data-provider-card]' ).forEach( initProviderCard );
		document.querySelectorAll( '.sl-mail-surface' ).forEach( initMailMessages );
	} );
} )();
