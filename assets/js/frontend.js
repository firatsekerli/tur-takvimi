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

		// Body: distance + next visit.
		var distText = ( typeof data.distance_km === 'number' && data.distance_km > 0 )
			? ( String( data.distance_km ).replace( '.', ',' ) + ' ' + cfg.i18n.kmAway )
			: cfg.i18n.inArea;

		var grid = el( 'div', 'tt-search__grid' );
		grid.appendChild( cell( cfg.i18n.distance, distText, false ) );
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

	function init() {
		var widgets = document.querySelectorAll( '[data-tt-search]' );
		Array.prototype.forEach.call( widgets, bind );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
