/**
 * Tur Takvimi — location editor: Leaflet map + PDOK address autocomplete.
 *
 * Lets an admin search a Dutch address (postcode + coordinates filled
 * automatically) and click/drag a map pin to set the city coordinates.
 */
( function () {
	'use strict';

	var cfg = window.TurTakvimiAdmin;
	if ( ! cfg || typeof window.L === 'undefined' ) {
		return;
	}

	var latInput = document.getElementById( 'tt_lat' );
	var lngInput = document.getElementById( 'tt_lng' );
	var addresses = document.getElementById( 'tt_addresses' );
	var mapEl = document.getElementById( 'tt-map' );
	var search = document.getElementById( 'tt-geocode-search' );
	var results = document.getElementById( 'tt-geocode-results' );

	if ( ! mapEl ) {
		return;
	}

	var startLat = parseFloat( latInput && latInput.value ) || cfg.center.lat;
	var startLng = parseFloat( lngInput && lngInput.value ) || cfg.center.lng;
	var hasCoords = !! ( latInput && latInput.value && lngInput && lngInput.value );

	var map = L.map( mapEl ).setView( [ startLat, startLng ], hasCoords ? 13 : 7 );
	L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 19,
		attribution: '© OpenStreetMap'
	} ).addTo( map );

	var marker = L.marker( [ startLat, startLng ], { draggable: true } );
	if ( hasCoords ) {
		marker.addTo( map );
	}

	function setCoords( lat, lng, recenter ) {
		if ( latInput ) {
			latInput.value = lat.toFixed( 6 );
		}
		if ( lngInput ) {
			lngInput.value = lng.toFixed( 6 );
		}
		marker.setLatLng( [ lat, lng ] );
		if ( ! map.hasLayer( marker ) ) {
			marker.addTo( map );
		}
		if ( recenter ) {
			map.setView( [ lat, lng ], 14 );
		}
	}

	map.on( 'click', function ( e ) {
		setCoords( e.latlng.lat, e.latlng.lng, false );
	} );
	marker.on( 'dragend', function () {
		var p = marker.getLatLng();
		setCoords( p.lat, p.lng, false );
	} );

	// Fix Leaflet sizing inside a meta box that may render hidden/narrow.
	setTimeout( function () {
		map.invalidateSize();
	}, 200 );

	if ( ! search || ! results ) {
		return;
	}

	var timer = null;

	function clearResults() {
		results.innerHTML = '';
		results.style.display = 'none';
	}

	function appendAddress( street, postcode ) {
		if ( ! addresses ) {
			return;
		}
		var line = postcode ? street + ' ; ' + postcode : street;
		var val = addresses.value.replace( /\s+$/, '' );
		addresses.value = val ? val + '\n' + line : line;
	}

	function choose( item ) {
		appendAddress( item.street || item.label, item.postcode );
		// Use the first geocoded address to seed the city coordinates.
		if ( ! latInput.value || ! lngInput.value ) {
			setCoords( item.lat, item.lng, true );
		}
		L.marker( [ item.lat, item.lng ] ).addTo( map );
		search.value = '';
		clearResults();
	}

	function render( items ) {
		results.innerHTML = '';
		if ( ! items.length ) {
			clearResults();
			return;
		}
		items.forEach( function ( item ) {
			var li = document.createElement( 'li' );
			li.className = 'tt-geocode__item';
			li.textContent = item.label;
			li.addEventListener( 'click', function () {
				choose( item );
			} );
			results.appendChild( li );
		} );
		results.style.display = 'block';
	}

	function lookup( q ) {
		var url = cfg.rest + '/geocode?q=' + encodeURIComponent( q );
		fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				render( ( data && data.results ) || [] );
			} )
			.catch( clearResults );
	}

	search.addEventListener( 'input', function () {
		var q = search.value.trim();
		clearTimeout( timer );
		if ( q.length < 3 ) {
			clearResults();
			return;
		}
		timer = setTimeout( function () {
			lookup( q );
		}, 300 );
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( e.target !== search && ! results.contains( e.target ) ) {
			clearResults();
		}
	} );
} )();
