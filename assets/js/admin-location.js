/**
 * Tur Takvimi — location editor.
 *
 * Search an address (geocoded via the REST proxy) to add a delivery stop with
 * its postcode and coordinates. Each stop is shown as a pin on the map and as
 * an editable row. State is serialized to a hidden input for saving.
 */
( function () {
	'use strict';

	var cfg = window.TurTakvimiAdmin;
	if ( ! cfg || typeof window.L === 'undefined' ) {
		return;
	}

	var mapEl = document.getElementById( 'tt-map' );
	var listEl = document.getElementById( 'tt-address-list' );
	var hidden = document.getElementById( 'tt_addresses_json' );
	var search = document.getElementById( 'tt-geocode-search' );
	var results = document.getElementById( 'tt-geocode-results' );
	var addBtn = document.getElementById( 'tt-add-address' );

	if ( ! mapEl || ! listEl || ! hidden ) {
		return;
	}

	var state = Array.isArray( cfg.addresses ) ? cfg.addresses.slice() : [];
	var markers = [];

	var map = L.map( mapEl ).setView( [ cfg.center.lat, cfg.center.lng ], 6 );
	L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 19,
		attribution: '© OpenStreetMap'
	} ).addTo( map );

	function hasCoords( item ) {
		return item && item.lat != null && item.lng != null && ! isNaN( item.lat ) && ! isNaN( item.lng );
	}

	function sync() {
		hidden.value = JSON.stringify( state );
	}

	function drawMarkers() {
		markers.forEach( function ( m ) {
			map.removeLayer( m );
		} );
		markers = [];

		var pts = [];
		state.forEach( function ( item ) {
			if ( ! hasCoords( item ) ) {
				return;
			}
			var m = L.marker( [ item.lat, item.lng ] ).addTo( map );
			m.bindPopup( ( item.address || '' ) + ( item.postcode ? '<br>' + item.postcode : '' ) );
			markers.push( m );
			pts.push( [ item.lat, item.lng ] );
		} );

		if ( pts.length === 1 ) {
			map.setView( pts[ 0 ], 14 );
		} else if ( pts.length > 1 ) {
			map.fitBounds( pts, { padding: [ 30, 30 ], maxZoom: 15 } );
		}
	}

	function el( tag, className, text ) {
		var n = document.createElement( tag );
		if ( className ) {
			n.className = className;
		}
		if ( text != null ) {
			n.textContent = text;
		}
		return n;
	}

	function renderList() {
		listEl.innerHTML = '';

		if ( ! state.length ) {
			listEl.appendChild( el( 'p', 'tt-address-list__empty', cfg.i18n.empty ) );
			return;
		}

		state.forEach( function ( item, index ) {
			var row = el( 'div', 'tt-address-row' );

			var streetWrap = el( 'span', 'tt-address-row__pin', hasCoords( item ) ? '📍' : '•' );
			row.appendChild( streetWrap );

			var street = document.createElement( 'input' );
			street.type = 'text';
			street.className = 'tt-address-row__street';
			street.value = item.address || '';
			street.placeholder = cfg.i18n.street;
			street.addEventListener( 'input', function () {
				state[ index ].address = street.value;
				sync();
			} );
			row.appendChild( street );

			var pc = document.createElement( 'input' );
			pc.type = 'text';
			pc.className = 'tt-address-row__pc';
			pc.value = item.postcode || '';
			pc.placeholder = cfg.i18n.postcode;
			pc.addEventListener( 'input', function () {
				state[ index ].postcode = pc.value;
				sync();
			} );
			row.appendChild( pc );

			var rm = el( 'button', 'button-link tt-address-row__remove', cfg.i18n.remove );
			rm.type = 'button';
			rm.addEventListener( 'click', function () {
				state.splice( index, 1 );
				sync();
				renderList();
				drawMarkers();
			} );
			row.appendChild( rm );

			listEl.appendChild( row );
		} );
	}

	function refresh() {
		sync();
		renderList();
		drawMarkers();
	}

	/* ---- Geocode autocomplete ---- */

	var timer = null;

	function clearResults() {
		results.innerHTML = '';
		results.style.display = 'none';
	}

	function choose( item ) {
		state.push( {
			address: item.street || item.label,
			postcode: item.postcode || '',
			lat: item.lat,
			lng: item.lng
		} );
		search.value = '';
		clearResults();
		refresh();
	}

	function renderResults( items ) {
		results.innerHTML = '';
		if ( ! items.length ) {
			clearResults();
			return;
		}
		items.forEach( function ( item ) {
			var li = el( 'li', 'tt-geocode__item', item.label );
			li.addEventListener( 'click', function () {
				choose( item );
			} );
			results.appendChild( li );
		} );
		results.style.display = 'block';
	}

	// Read the city name from the live title field, falling back to the saved
	// title, so address results are biased to the city being edited.
	function cityHint() {
		var node = document.querySelector( '.editor-post-title__input, #title' );
		var live = node ? ( node.value || node.textContent || '' ).trim() : '';
		return live || cfg.city || '';
	}

	function biasedQuery( q ) {
		var city = cityHint();
		if ( city && q.toLowerCase().indexOf( city.toLowerCase() ) === -1 ) {
			return q + ' ' + city;
		}
		return q;
	}

	function lookup( q ) {
		fetch( cfg.rest + '/geocode?q=' + encodeURIComponent( biasedQuery( q ) ), {
			headers: { 'X-WP-Nonce': cfg.nonce }
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				renderResults( ( data && data.results ) || [] );
			} )
			.catch( clearResults );
	}

	if ( search && results ) {
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
	}

	if ( addBtn ) {
		addBtn.addEventListener( 'click', function () {
			state.push( { address: '', postcode: '', lat: null, lng: null } );
			refresh();
		} );
	}

	// Initial paint.
	renderList();
	drawMarkers();
	setTimeout( function () {
		map.invalidateSize();
	}, 200 );
} )();
