/**
 * Read-only city map: renders every delivery stop as a Leaflet pin.
 * Reads its points from a [data-tt-citymap] JSON attribute.
 */
( function () {
	'use strict';

	function initMap( el ) {
		if ( ! window.L || el.dataset.ttMapReady ) {
			return;
		}
		var cfg;
		try {
			cfg = JSON.parse( el.getAttribute( 'data-tt-citymap' ) );
		} catch ( e ) {
			return;
		}
		if ( ! cfg || ! cfg.points || ! cfg.points.length ) {
			return;
		}
		el.dataset.ttMapReady = '1';

		var map = window.L.map( el, { scrollWheelZoom: false } );
		window.L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '© OpenStreetMap',
			maxZoom: 19
		} ).addTo( map );

		var latlngs = [];
		cfg.points.forEach( function ( p ) {
			var marker = window.L.marker( [ p.lat, p.lng ] );
			if ( p.label ) {
				marker.bindPopup( p.label );
			}
			marker.addTo( map );
			latlngs.push( [ p.lat, p.lng ] );
		} );

		if ( latlngs.length === 1 ) {
			map.setView( latlngs[ 0 ], 14 );
		} else {
			map.fitBounds( latlngs, { padding: [ 30, 30 ] } );
		}
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var maps = document.querySelectorAll( '[data-tt-citymap]' );
		Array.prototype.forEach.call( maps, initMap );
	} );
}() );
