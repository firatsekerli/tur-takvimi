/**
 * Tur Takvimi front-end widgets.
 *
 * Progressive enhancement: the calendar is already server-rendered; this
 * file powers the interactive postcode search against the REST API.
 */
( function () {
	'use strict';

	if ( typeof window.TurTakvimi === 'undefined' ) {
		return;
	}

	var cfg = window.TurTakvimi;

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	var PIN_SVG = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>';

	function cell( labelText, valueText, accent ) {
		var c = el( 'div', 'tt-search__cell' );
		c.appendChild( el( 'span', 'tt-search__cell-label', labelText ) );
		c.appendChild( el( 'strong', 'tt-search__cell-value' + ( accent ? ' tt-search__cell-value--accent' : '' ), valueText ) );
		return c;
	}

	function renderResult( box, data ) {
		box.innerHTML = '';

		if ( ! data || ! data.found ) {
			box.appendChild( el( 'p', 'tt-search__error', cfg.i18n.noResult ) );
			return;
		}

		var card = el( 'div', 'tt-search__card' );

		// Header: green badge + city + nearest address.
		var head = el( 'div', 'tt-search__head' );
		var badge = el( 'span', 'tt-search__badge' );
		badge.innerHTML = PIN_SVG;
		head.appendChild( badge );

		var headText = el( 'div', 'tt-search__headtext' );
		headText.appendChild( el( 'strong', 'tt-search__city', data.title ) );
		if ( data.address ) {
			headText.appendChild( el( 'span', 'tt-search__addr', data.address ) );
		}
		head.appendChild( headText );
		card.appendChild( head );

		// Body: delivery hour + next visit.
		var grid = el( 'div', 'tt-search__grid' );
		grid.appendChild( cell( cfg.i18n.time, data.time || '—', false ) );
		grid.appendChild( cell( cfg.i18n.next, data.next_date_label || cfg.i18n.noDate, true ) );
		card.appendChild( grid );

		if ( data.url ) {
			var link = el( 'a', 'tt-search__link', cfg.i18n.details );
			link.href = data.url;
			card.appendChild( link );
		}

		box.appendChild( card );
	}

	function bind( root ) {
		var form = root.querySelector( '.tt-search__form' );
		var input = root.querySelector( '[data-tt-input]' );
		var box = root.querySelector( '[data-tt-result]' );

		if ( ! form || ! input || ! box ) {
			return;
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var value = input.value.trim();
			if ( ! value ) {
				return;
			}

			box.innerHTML = '';
			box.appendChild( el( 'p', 'tt-search__loading', cfg.i18n.searching ) );

			var picker = root.querySelector( '[data-tt-country]' );
			var country = ( picker && picker.value ) || root.getAttribute( 'data-country' ) || '';
			var url = cfg.rest + '/search?postcode=' + encodeURIComponent( value );
			if ( country ) {
				url += '&country=' + encodeURIComponent( country );
			}
			fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce } } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					renderResult( box, data );
				} )
				.catch( function () {
					box.innerHTML = '';
					box.appendChild( el( 'p', 'tt-search__error', cfg.i18n.noResult ) );
				} );
		} );
	}

	// Client-side country filter for the server-rendered calendar: show/hide
	// chips by country and collapse days that end up with no visible stops.
	function bindCalendar( section ) {
		var buttons = section.querySelectorAll( '[data-tt-cal-filter]' );
		if ( ! buttons.length ) {
			return;
		}
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				var country = btn.getAttribute( 'data-country' ) || '';

				Array.prototype.forEach.call( buttons, function ( b ) {
					b.classList.toggle( 'is-active', b === btn );
				} );

				Array.prototype.forEach.call( section.querySelectorAll( '.tt-chip' ), function ( chip ) {
					var cc = chip.getAttribute( 'data-country' ) || '';
					chip.style.display = ( ! country || cc === country ) ? '' : 'none';
				} );

				Array.prototype.forEach.call( section.querySelectorAll( '.tt-day' ), function ( day ) {
					var visible = false;
					Array.prototype.forEach.call( day.querySelectorAll( '.tt-chip' ), function ( ch ) {
						if ( 'none' !== ch.style.display ) {
							visible = true;
						}
					} );
					day.style.display = visible ? '' : 'none';
				} );
			} );
		} );
	}

	// Signup form: POSTs to /subscribe and shows the server's message.
	function bindSignup( root ) {
		var form = root.querySelector( '.tt-signup__form' );
		var box = root.querySelector( '[data-tt-su-result]' );
		if ( ! form || ! box ) {
			return;
		}

		function field( name ) {
			var node = root.querySelector( '[data-tt-su-' + name + ']' );
			return node ? node.value.trim() : '';
		}

		function show( ok, message ) {
			box.innerHTML = '';
			box.appendChild( el( 'p', ok ? 'tt-signup__ok' : 'tt-signup__error', message ) );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var optinBox = root.querySelector( '[data-tt-su-optin]' );
			var payload = {
				name: field( 'name' ),
				email: field( 'email' ),
				phone: field( 'phone' ),
				postcode: field( 'postcode' ),
				country: root.getAttribute( 'data-country' ) || '',
				optin: !! ( optinBox && optinBox.checked ),
				website: field( 'hp' )
			};

			if ( ! payload.name || ! payload.email || ! payload.postcode ) {
				show( false, cfg.i18n.signupMissing );
				return;
			}

			show( true, cfg.i18n.sending );
			fetch( cfg.rest + '/subscribe', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( payload )
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					show( !! ( data && data.ok ), ( data && data.message ) || cfg.i18n.signupError );
					if ( data && data.ok ) {
						form.reset();
					}
				} )
				.catch( function () {
					show( false, cfg.i18n.signupError );
				} );
		} );
	}

	function init() {
		var widgets = document.querySelectorAll( '[data-tt-search]' );
		Array.prototype.forEach.call( widgets, bind );

		var calendars = document.querySelectorAll( '[data-tt-calendar]' );
		Array.prototype.forEach.call( calendars, bindCalendar );

		var signups = document.querySelectorAll( '[data-tt-signup]' );
		Array.prototype.forEach.call( signups, bindSignup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

/**
 * City-page delivery-address filter. Standalone (no REST config needed): hides
 * stop rows whose postcode/address don't match the typed query.
 */
( function () {
	'use strict';

	function bindStops( root ) {
		var input = root.querySelector( '[data-tt-stops-filter]' );
		var rows = root.querySelectorAll( '[data-tt-stop-row]' );
		var empty = root.querySelector( '[data-tt-stops-empty]' );
		if ( ! input || ! rows.length ) {
			return;
		}
		input.addEventListener( 'input', function () {
			var q = input.value.trim().toLowerCase();
			var shown = 0;
			Array.prototype.forEach.call( rows, function ( row ) {
				var hay = ( row.getAttribute( 'data-search' ) || '' );
				var match = ! q || hay.indexOf( q ) >= 0;
				row.style.display = match ? '' : 'none';
				if ( match ) {
					shown++;
				}
			} );
			if ( empty ) {
				empty.style.display = shown ? 'none' : '';
			}
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-tt-stops]' ), bindStops );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
