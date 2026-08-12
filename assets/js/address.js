/**
 * Smart Login — two-level address picker.
 *
 * Progressive enhancement over plain <select> elements: the selects stay in the
 * form and remain what actually gets submitted, so removing this file degrades
 * to a working (if plainer) form rather than a broken one.
 */
( function () {
	'use strict';

	var cfg = window.OmniWPAddress || {};
	var i18n = cfg.i18n || {};

	/** Ward lists already fetched, keyed by province code. */
	var wardCache = {};

	// -----------------------------------------------------------------
	// Vietnamese-aware text helpers
	// -----------------------------------------------------------------

	/**
	 * NFD decomposition strips the tone marks, but `đ` is its own letter and
	 * never decomposes — it has to be replaced explicitly.
	 */
	var COMBINING = new RegExp( '[\\u0300-\\u036f]', 'g' );

	function unaccent( text ) {
		return String( text )
			.normalize( 'NFD' )
			.replace( COMBINING, '' )
			.replace( /đ/g, 'd' )
			.replace( /Đ/g, 'D' );
	}

	function slug( text ) {
		return unaccent( text )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, ' ' )
			.trim();
	}

	var PREFIXES = [ 'thanh pho ', 'thi xa ', 'thi tran ', 'quan ', 'huyen ', 'phuong ', 'dac khu ', 'tinh ', 'xa ' ];

	function stripPrefix( value ) {
		for ( var i = 0; i < PREFIXES.length; i++ ) {
			if ( value.indexOf( PREFIXES[ i ] ) === 0 ) {
				return value.slice( PREFIXES[ i ].length ).trim();
			}
		}

		return value;
	}

	function debounce( fn, wait ) {
		var timer;

		return function () {
			var args = arguments;
			var self = this;

			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};
	}

	// -----------------------------------------------------------------
	// Combobox: a searchable overlay on a native <select>
	// -----------------------------------------------------------------

	function Combo( select, options ) {
		this.select = select;
		this.options = options || {};
		this.items = [];
		this.active = -1;
		this.open = false;

		this.build();
		this.bind();
		this.syncFromSelect();
	}

	Combo.prototype.build = function () {
		var wrap = document.createElement( 'div' );
		wrap.className = 'sl-combo';

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'sl-input sl-combo__input';
		input.autocomplete = 'off';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.placeholder = this.options.placeholder || '';

		var list = document.createElement( 'ul' );
		list.className = 'sl-combo__list';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;
		list.id = ( this.select.id || 'sl-combo' ) + '-list';

		input.setAttribute( 'aria-controls', list.id );

		if ( this.select.id ) {
			var label = document.querySelector( 'label[for="' + this.select.id + '"]' );
			if ( label ) {
				input.setAttribute( 'aria-label', label.textContent.trim() );
			}
		}

		// The select still submits, but a display:none control cannot receive
		// browser validation focus — so the requirement moves to the input.
		if ( this.select.hasAttribute( 'required' ) ) {
			this.select.removeAttribute( 'required' );
			input.required = true;
		}

		this.select.classList.add( 'sl-combo__native' );
		this.select.parentNode.insertBefore( wrap, this.select );
		wrap.appendChild( input );
		wrap.appendChild( list );
		wrap.appendChild( this.select );

		this.wrap = wrap;
		this.input = input;
		this.list = list;
	};

	Combo.prototype.bind = function () {
		var self = this;

		this.input.addEventListener( 'focus', function () {
			self.filter( '' );
		} );

		this.input.addEventListener( 'input', function () {
			self.filter( self.input.value );
		} );

		this.input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				if ( ! self.open ) {
					self.filter( '' );
				}
				self.move( 1 );
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				self.move( -1 );
			} else if ( event.key === 'Enter' ) {
				if ( self.open && self.active >= 0 ) {
					event.preventDefault();
					self.choose( self.items[ self.active ] );
				}
			} else if ( event.key === 'Escape' ) {
				self.close();
			} else if ( event.key === 'Tab' ) {
				self.close();
			}
		} );

		this.input.addEventListener( 'blur', function () {
			// Delay so a click on an option lands before the list closes.
			window.setTimeout( function () {
				self.close();
				self.syncFromSelect();
			}, 150 );
		} );

		this.list.addEventListener( 'mousedown', function ( event ) {
			var li = event.target.closest( 'li[data-value]' );

			if ( ! li ) {
				return;
			}

			event.preventDefault();
			self.choose( { value: li.getAttribute( 'data-value' ), label: li.textContent } );
		} );
	};

	/** Read the current options out of the select. */
	Combo.prototype.pool = function () {
		var out = [];

		for ( var i = 0; i < this.select.options.length; i++ ) {
			var option = this.select.options[ i ];

			if ( ! option.value ) {
				continue;
			}

			out.push( {
				value: option.value,
				label: option.textContent.trim(),
				key: slug( option.textContent )
			} );
		}

		return out;
	};

	Combo.prototype.filter = function ( query ) {
		var needle = slug( query );
		var pool = this.pool();
		var matches;

		if ( ! needle ) {
			matches = pool.slice( 0, 100 );
		} else {
			var starts = [];
			var loose = [];

			pool.forEach( function ( item ) {
				var bare = stripPrefix( item.key );

				if ( item.key.indexOf( needle ) === 0 || bare.indexOf( needle ) === 0 ) {
					starts.push( item );
				} else if ( item.key.indexOf( needle ) !== -1 ) {
					loose.push( item );
				}
			} );

			matches = starts.concat( loose ).slice( 0, 100 );
		}

		this.render( matches );
	};

	Combo.prototype.render = function ( items ) {
		this.items = items;
		this.active = -1;
		this.list.innerHTML = '';

		if ( ! items.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'sl-combo__empty';
			empty.textContent = i18n.noResults || 'Không tìm thấy';
			this.list.appendChild( empty );
		} else {
			var fragment = document.createDocumentFragment();

			items.forEach( function ( item, index ) {
				var li = document.createElement( 'li' );
				li.className = 'sl-combo__option';
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'data-value', item.value );
				li.setAttribute( 'data-index', index );
				li.textContent = item.label;
				fragment.appendChild( li );
			} );

			this.list.appendChild( fragment );
		}

		this.list.hidden = false;
		this.input.setAttribute( 'aria-expanded', 'true' );
		this.open = true;
	};

	Combo.prototype.move = function ( delta ) {
		if ( ! this.items.length ) {
			return;
		}

		this.active = ( this.active + delta + this.items.length ) % this.items.length;

		var nodes = this.list.querySelectorAll( '.sl-combo__option' );

		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].classList.toggle( 'is-active', i === this.active );
		}

		if ( nodes[ this.active ] ) {
			nodes[ this.active ].scrollIntoView( { block: 'nearest' } );
		}
	};

	Combo.prototype.choose = function ( item ) {
		if ( ! item ) {
			return;
		}

		this.select.value = item.value;
		this.input.value = item.label;
		this.close();

		this.select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	};

	Combo.prototype.close = function () {
		this.list.hidden = true;
		this.input.setAttribute( 'aria-expanded', 'false' );
		this.open = false;
	};

	/** Mirror the select's current selection into the visible input. */
	Combo.prototype.syncFromSelect = function () {
		var option = this.select.options[ this.select.selectedIndex ];

		this.input.value = option && option.value ? option.textContent.trim() : '';
		this.input.disabled = this.select.disabled;
	};

	Combo.prototype.setDisabled = function ( disabled ) {
		this.select.disabled = disabled;
		this.input.disabled = disabled;
	};

	// -----------------------------------------------------------------
	// Ward loading
	// -----------------------------------------------------------------

	function fetchWards( provinceCode ) {
		if ( wardCache[ provinceCode ] ) {
			return Promise.resolve( wardCache[ provinceCode ] );
		}

		return window
			.fetch( cfg.restUrl + 'address/wards/' + encodeURIComponent( provinceCode ), {
				credentials: 'same-origin'
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( wards ) {
				wardCache[ provinceCode ] = Array.isArray( wards ) ? wards : [];
				return wardCache[ provinceCode ];
			} )
			.catch( function () {
				return [];
			} );
	}

	function fillWardSelect( select, wards, selected ) {
		select.innerHTML = '';

		var placeholder = document.createElement( 'option' );
		placeholder.value = '';
		placeholder.textContent = i18n.chooseWard || '— Chọn Phường/Xã —';
		select.appendChild( placeholder );

		var fragment = document.createDocumentFragment();

		wards.forEach( function ( ward ) {
			var option = document.createElement( 'option' );
			option.value = ward.code;
			option.textContent = ward.name;

			if ( selected && String( selected ) === String( ward.code ) ) {
				option.selected = true;
			}

			fragment.appendChild( option );
		} );

		select.appendChild( fragment );
	}

	/**
	 * Wire a province select to a ward select.
	 *
	 * @param {HTMLSelectElement} provinceSelect
	 * @param {HTMLSelectElement} wardSelect
	 * @param {Combo|null}        wardCombo
	 */
	function link( provinceSelect, wardSelect, wardCombo ) {
		// The server renders this when it hands over an empty ward list, so the
		// grey select explains itself before any script runs. Once the list
		// arrives the explanation is wrong, so it goes with it.
		var wardHint = ( wardSelect.closest( '.sl-field' ) || document ).querySelector( '[data-sl-ward-hint]' );

		function showHint( waiting ) {
			if ( wardHint ) {
				wardHint.hidden = ! waiting;
			}
		}

		function load( preserve ) {
			var code = provinceSelect.value;

			if ( ! code ) {
				fillWardSelect( wardSelect, [], '' );
				wardSelect.disabled = true;
				showHint( true );
				if ( wardCombo ) {
					wardCombo.setDisabled( true );
					wardCombo.syncFromSelect();
				}
				return Promise.resolve();
			}

			if ( wardCombo ) {
				wardCombo.input.placeholder = i18n.loading || 'Đang tải…';
			}

			return fetchWards( code ).then( function ( wards ) {
				fillWardSelect( wardSelect, wards, preserve );
				wardSelect.disabled = wards.length === 0;
				showHint( wards.length === 0 );

				if ( wardCombo ) {
					wardCombo.setDisabled( wards.length === 0 );
					wardCombo.input.placeholder = i18n.chooseWard || '';
					wardCombo.syncFromSelect();
				}

				wardSelect.dispatchEvent( new Event( 'sl:wards-loaded', { bubbles: true } ) );
			} );
		}

		provinceSelect.addEventListener( 'change', function () {
			// Changing province invalidates the previous ward entirely.
			load( '' );
		} );

		return load;
	}


	// -----------------------------------------------------------------
	// Entry points
	// -----------------------------------------------------------------

	/** Forms rendered by the plugin: both selects are ours. */
	function initPluginForm( root ) {
		if ( root.hasAttribute( 'data-sl-address-ready' ) ) {
			return;
		}

		root.setAttribute( 'data-sl-address-ready', '1' );

		var provinceSelect = root.querySelector( '[data-sl-province]' );
		var wardSelect = root.querySelector( '[data-sl-ward]' );

		if ( ! provinceSelect || ! wardSelect ) {
			return;
		}

		var provinceCombo = new Combo( provinceSelect, { placeholder: i18n.chooseProvince || '' } );
		var wardCombo = new Combo( wardSelect, { placeholder: i18n.chooseWard || '' } );

		link( provinceSelect, wardSelect, wardCombo );
	}

	/**
	 * WooCommerce checkout and address book: Woo owns the province field, so it
	 * is left completely alone and only the ward select is enhanced.
	 */
	function initWooForm() {
		[ 'billing', 'shipping' ].forEach( function ( prefix ) {
			var provinceSelect = document.getElementById( prefix + '_state' );
			var wardSelect = document.getElementById( prefix + '_city' );

			if ( ! provinceSelect || ! wardSelect || wardSelect.tagName !== 'SELECT' ) {
				return;
			}

			if ( wardSelect.hasAttribute( 'data-sl-address-ready' ) ) {
				return;
			}

			wardSelect.setAttribute( 'data-sl-address-ready', '1' );

			var wardCombo = new Combo( wardSelect, { placeholder: i18n.chooseWard || '' } );
			var load = link( provinceSelect, wardSelect, wardCombo );

			// country_to_state_changed is a jQuery-only event: jQuery.trigger()
			// does not dispatch a real DOM event for custom names, so a native
			// addEventListener here would never fire.
			if ( window.jQuery ) {
				window.jQuery( document.body ).on( 'country_to_state_changed', function () {
					load( wardSelect.value );
				} );
			}

			// Populate on first paint when a province is already selected but the
			// server could not know the ward list (fresh checkout).
			if ( provinceSelect.value && wardSelect.options.length <= 1 ) {
				load( wardSelect.getAttribute( 'data-selected' ) || '' );
			}

		} );
	}

	/**
	 * Upgrade every picker inside a container.
	 *
	 * Scoped, and exposed below, because the dialog's markup arrives long after
	 * DOMContentLoaded — the same reason `omniwp.js` exposes
	 * `OmniWPEnhance`, which this file simply never joined. Without it the
	 * welcome screen rendered two dead selects: a province list that changed
	 * nothing and a ward list that stayed disabled.
	 *
	 * Every caller passes no argument or a container; none passes an event.
	 * `initPluginForm()` marks what it has done, so calling this twice over the
	 * same markup is free.
	 *
	 * @param {ParentNode} [scope]
	 */
	function initAll( scope ) {
		( scope || document ).querySelectorAll( '[data-sl-address]' ).forEach( initPluginForm );

		// WooCommerce owns its own markup and it is never inside a fragment, so
		// the checkout half only runs against the document.
		if ( ! scope || scope === document ) {
			initWooForm();
		}
	}

	if ( document.readyState !== 'loading' ) {
		initAll();
	} else {
		// Wrapped: a listener receives the event as its first argument, and this
		// function now reads its first argument as a container.
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	}

	// Woo rebuilds the checkout markup after every AJAX refresh. Same jQuery-only
	// event caveat as country_to_state_changed above, and the same wrapping.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', function () {
			initAll();
		} );
	}

	window.OmniWPAddressEnhance = initAll;
} )();
