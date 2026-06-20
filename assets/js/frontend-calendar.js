/**
 * Month-calendar navigation.
 *
 * Progressive enhancement: the prev/next links work as plain page loads, but
 * when this script and the REST endpoint are available the grid is swapped in
 * place (no reload) and the URL's ?tt_month is updated silently.
 */
( function () {
	'use strict';

	var cfg = window.TurTakvimiCal || {};

	function addMonths( ym, delta ) {
		var parts = ym.split( '-' );
		var year = parseInt( parts[ 0 ], 10 );
		var month = parseInt( parts[ 1 ], 10 ) - 1 + delta;
		year += Math.floor( month / 12 );
		month = ( ( month % 12 ) + 12 ) % 12;
		return year + '-' + ( '0' + ( month + 1 ) ).slice( -2 );
	}

	function urlForMonth( month ) {
		try {
			var u = new URL( window.location.href );
			u.searchParams.set( 'tt_month', month );
			return u;
		} catch ( e ) {
			return null;
		}
	}

	function bind( root ) {
		if ( root.dataset.ttCalBound ) {
			return;
		}
		root.dataset.ttCalBound = '1';

		var grids = root.querySelector( '[data-tt-month-grids]' );
		var prev = root.querySelector( '[data-tt-month-prev]' );
		var next = root.querySelector( '[data-tt-month-next]' );
		if ( ! grids || ! cfg.rest ) {
			return; // No JS nav: the links fall back to normal page loads.
		}

		function syncNav() {
			var current = root.dataset.month;
			var p = urlForMonth( addMonths( current, -1 ) );
			var n = urlForMonth( addMonths( current, 1 ) );
			if ( prev && p ) {
				prev.setAttribute( 'href', p.pathname + p.search );
			}
			if ( next && n ) {
				next.setAttribute( 'href', n.pathname + n.search );
			}
		}

		function load( month ) {
			var url = cfg.rest + '/calendar-month?month=' + encodeURIComponent( month ) +
				'&country=' + encodeURIComponent( root.dataset.country || '' ) +
				'&location=' + encodeURIComponent( root.dataset.location || '0' ) +
				'&months=' + encodeURIComponent( root.dataset.months || '1' );

			grids.classList.add( 'is-loading' );
			fetch( url )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( data && typeof data.html === 'string' ) {
						grids.innerHTML = data.html;
						root.dataset.month = data.month || month;
						syncNav();
						var u = urlForMonth( root.dataset.month );
						if ( u ) {
							window.history.replaceState( {}, '', u.toString() );
						}
					}
					grids.classList.remove( 'is-loading' );
				} )
				.catch( function () {
					grids.classList.remove( 'is-loading' );
				} );
		}

		if ( prev ) {
			prev.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				load( addMonths( root.dataset.month, -1 ) );
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				load( addMonths( root.dataset.month, 1 ) );
			} );
		}
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-tt-month-root]' ), bind );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Builders inject shortcode markup after load; re-scan on DOM changes.
	window.addEventListener( 'load', init );
	if ( window.MutationObserver ) {
		var rescan;
		var observer = new window.MutationObserver( function () {
			clearTimeout( rescan );
			rescan = setTimeout( init, 200 );
		} );
		if ( document.body ) {
			observer.observe( document.body, { childList: true, subtree: true } );
		}
	}
}() );
