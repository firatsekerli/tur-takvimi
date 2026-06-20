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

		// Recompute tiles after late layout (iframes / builder canvases).
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
		Array.prototype.forEach.call( document.querySelectorAll( '[data-tt-citymap]' ), initMap );
	}

	ready( scan );

	// Builders inject/replace shortcode markup after load and Leaflet may load
	// late; re-scan on load and on DOM changes (initMap is idempotent).
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
