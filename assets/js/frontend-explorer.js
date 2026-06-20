/**
 * Delivery-regions explorer.
 *
 * Enhances the server-rendered stop list with a Leaflet map and instant,
 * fully client-side filtering (country, region/route, week, city search).
 * Markers are slaved to list-item visibility so map and list never diverge.
 */
( function () {
	'use strict';

	var cfg = window.TurTakvimiMap || { i18n: { stops: '%d' } };

	function buildMarkers( root, map ) {
		var holder = root.querySelector( '[data-tt-explorer-points]' );
		var markers = {};
		if ( ! holder ) {
			return markers;
		}
		var points;
		try {
			points = JSON.parse( holder.textContent );
		} catch ( e ) {
			return markers;
		}
		points.forEach( function ( p ) {
			var marker = window.L.marker( [ p.lat, p.lng ] );
			if ( p.label ) {
				marker.bindPopup( p.label );
			}
			markers[ p.id ] = marker;
		} );
		return markers;
	}

	function bind( root ) {
		if ( ! window.L || root.dataset.ttReady ) {
			return;
		}
		var mapEl = root.querySelector( '[data-tt-explorer-map]' );
		if ( ! mapEl ) {
			return;
		}
		root.dataset.ttReady = '1';

		var map = window.L.map( mapEl, { scrollWheelZoom: false } );
		window.L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '© OpenStreetMap',
			maxZoom: 19
		} ).addTo( map );

		var markers = buildMarkers( root, map );
		var items = Array.prototype.slice.call( root.querySelectorAll( '[data-tt-stop]' ) );
		var countEl = root.querySelector( '[data-tt-stop-count]' );
		var searchEl = root.querySelector( '[data-tt-stop-search]' );

		var state = { country: '', region: '', week: '', q: '' };

		function matches( it ) {
			if ( state.country && it.dataset.country !== state.country ) {
				return false;
			}
			if ( state.region && ( ',' + ( it.dataset.regions || '' ) + ',' ).indexOf( ',' + state.region + ',' ) < 0 ) {
				return false;
			}
			if ( state.week && it.dataset.week !== state.week ) {
				return false;
			}
			if ( state.q && ( it.dataset.title || '' ).indexOf( state.q ) < 0 ) {
				return false;
			}
			return true;
		}

		function apply() {
			var bounds = [];
			var visible = 0;
			items.forEach( function ( it ) {
				var ok = matches( it );
				it.parentNode.style.display = ok ? '' : 'none';
				var m = markers[ it.dataset.id ];
				if ( ok ) {
					visible++;
					if ( m ) {
						m.addTo( map );
						bounds.push( m.getLatLng() );
					}
				} else if ( m ) {
					map.removeLayer( m );
				}
			} );

			if ( countEl ) {
				countEl.textContent = cfg.i18n.stops.replace( '%d', visible );
			}
			if ( bounds.length === 1 ) {
				map.setView( bounds[ 0 ], 11 );
			} else if ( bounds.length > 1 ) {
				map.fitBounds( bounds, { padding: [ 30, 30 ] } );
			}
		}

		var countrySelect = root.querySelector( '[data-tt-country]' );
		var regionSelect = root.querySelector( '[data-tt-region]' );
		var weekSelect = root.querySelector( '[data-tt-week]' );

		// Limit the region dropdown to the chosen country and drop a now-invalid
		// selection. Updates state.region.
		function syncRegionOptions( country ) {
			if ( ! regionSelect ) {
				return;
			}
			var opts = regionSelect.options;
			for ( var i = 0; i < opts.length; i++ ) {
				var o = opts[ i ];
				if ( ! o.value ) {
					continue;
				}
				var oc = o.getAttribute( 'data-country' ) || '';
				var show = ! country || ! oc || ( ',' + oc + ',' ).indexOf( ',' + country + ',' ) >= 0;
				o.hidden = ! show;
				o.disabled = ! show;
			}
			var current = regionSelect.options[ regionSelect.selectedIndex ];
			if ( current && current.hidden ) {
				regionSelect.value = '';
			}
			state.region = regionSelect.value;
		}

		if ( countrySelect ) {
			countrySelect.addEventListener( 'change', function () {
				state.country = countrySelect.value;
				syncRegionOptions( state.country );
				apply();
			} );
		}

		if ( regionSelect ) {
			regionSelect.addEventListener( 'change', function () {
				state.region = regionSelect.value;
				apply();
			} );
		}

		if ( weekSelect ) {
			weekSelect.addEventListener( 'change', function () {
				state.week = weekSelect.value;
				apply();
			} );
		}

		if ( searchEl ) {
			searchEl.addEventListener( 'input', function () {
				state.q = searchEl.value.trim().toLowerCase();
				apply();
			} );
		}

		// Initial fit to all located stops.
		var initial = [];
		Object.keys( markers ).forEach( function ( id ) {
			markers[ id ].addTo( map );
			initial.push( markers[ id ].getLatLng() );
		} );
		if ( initial.length === 1 ) {
			map.setView( initial[ 0 ], 11 );
		} else if ( initial.length > 1 ) {
			map.fitBounds( initial, { padding: [ 30, 30 ] } );
		} else {
			map.setView( [ 51.2, 7.0 ], 7 );
		}

		// The container is often laid out (or resized) after init inside iframes
		// and page-builder canvases; recompute tiles once things settle.
		setTimeout( function () {
			map.invalidateSize();
		}, 300 );
		window.addEventListener( 'resize', function () {
			map.invalidateSize();
		} );
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function scan() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-tt-explorer]' ), bind );
	}

	ready( scan );

	// Builders (Breakdance, Elementor…) inject or replace shortcode markup after
	// load and Leaflet may arrive late; re-scan on load and on DOM changes
	// (bind() is idempotent, so this only ever wires up new, unbound widgets).
	window.addEventListener( 'load', scan );
	if ( window.MutationObserver ) {
		var rescan;
		var observer = new window.MutationObserver( function () {
			clearTimeout( rescan );
			rescan = setTimeout( scan, 200 );
		} );
		ready( function () {
			observer.observe( document.body, { childList: true, subtree: true } );
		} );
	}
}() );
