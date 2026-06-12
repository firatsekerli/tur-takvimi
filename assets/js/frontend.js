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

	function renderResult( box, data ) {
		box.innerHTML = '';

		if ( ! data || ! data.found ) {
			box.appendChild( el( 'p', 'tt-search__error', cfg.i18n.noResult ) );
			return;
		}

		var card = el( 'div', 'tt-search__card' );

		var label = el( 'span', 'tt-search__nearest', cfg.i18n.nearest );
		card.appendChild( label );

		var name = el( 'strong', 'tt-search__city', data.title );
		card.appendChild( name );

		if ( typeof data.distance_km === 'number' && data.distance_km > 0 ) {
			card.appendChild( el( 'span', 'tt-search__dist', '~' + data.distance_km + ' km' ) );
		}

		var date = el(
			'p',
			'tt-search__next',
			cfg.i18n.next + ': ' + ( data.next_date_label || cfg.i18n.noDate )
		);
		card.appendChild( date );

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

			var url = cfg.rest + '/search?postcode=' + encodeURIComponent( value );
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
