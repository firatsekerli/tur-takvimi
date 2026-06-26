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
	if ( ! cfg ) {
		return;
	}

	// Filter the route checkboxes to the city's selected country (and uncheck
	// any that no longer apply). Runs independently of the map below so it works
	// even if Leaflet fails to load.
	( function initRouteFilter() {
		var sel = document.getElementById( 'tt_country_sel' );
		var list = document.querySelector( '.tt-routes__list' );
		var note = document.getElementById( 'tt-routes-empty' );
		if ( ! list ) {
			return;
		}
		function apply() {
			var country = sel && sel.value ? sel.value : '';
			var items = list.querySelectorAll( 'li' );
			var visible = 0;
			Array.prototype.forEach.call( items, function ( li ) {
				var rc = li.getAttribute( 'data-country' ) || '';
				var show = ! country || rc === country;
				li.style.display = show ? '' : 'none';
				if ( show ) {
					visible++;
				} else {
					var cb = li.querySelector( 'input[type="checkbox"]' );
					if ( cb ) {
						cb.checked = false;
					}
				}
			} );
			if ( note ) {
				note.style.display = visible ? 'none' : '';
			}
		}
		if ( sel ) {
			sel.addEventListener( 'change', apply );
		}
		apply();
	}() );

	if ( typeof window.L === 'undefined' ) {
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
			var m = L.marker( [ item.lat, item.lng ], item.approx ? { opacity: 0.55 } : {} ).addTo( map );
			m.bindPopup(
				( item.address || '' ) +
				( item.postcode ? '<br>' + item.postcode : '' ) +
				( item.approx ? '<br><em>' + cfg.i18n.approxHint + '</em>' : '' )
			);
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

		// Column headers above the rows. Mirror every row column (including the
		// trailing "weeks" word + remove link) so the labels line up exactly.
		var head = el( 'div', 'tt-address-head' );
		head.appendChild( el( 'span', 'tt-address-row__pin', '' ) );
		head.appendChild( el( 'span', 'tt-address-row__street', cfg.i18n.street ) );
		head.appendChild( el( 'span', 'tt-address-row__pc', cfg.i18n.postcode ) );
		head.appendChild( el( 'span', 'tt-address-row__time', cfg.i18n.time ) );
		head.appendChild( el( 'span', 'tt-address-row__freq', cfg.i18n.freq ) );
		head.appendChild( el( 'span', 'tt-address-row__freq-label', '' ) );
		head.appendChild( el( 'span', 'tt-address-row__remove', '' ) );
		listEl.appendChild( head );

		state.forEach( function ( item, index ) {
			var row = el( 'div', 'tt-address-row' );

			var isApprox = hasCoords( item ) && item.approx;
			var streetWrap = el( 'span', 'tt-address-row__pin' + ( isApprox ? ' is-approx' : '' ), hasCoords( item ) ? '📍' : '•' );
			if ( isApprox ) {
				streetWrap.title = cfg.i18n.approxHint;
			}
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

			var time = document.createElement( 'input' );
			time.type = 'text';
			time.className = 'tt-address-row__time';
			time.value = item.time || '';
			time.placeholder = cfg.i18n.time;
			time.title = cfg.i18n.timeTitle;
			time.addEventListener( 'input', function () {
				state[ index ].time = time.value;
				sync();
			} );
			row.appendChild( time );

			var freq = document.createElement( 'input' );
			freq.type = 'number';
			freq.min = '0';
			freq.className = 'tt-address-row__freq';
			freq.value = ( item.frequency != null && item.frequency !== '' ) ? item.frequency : cfg.defaultFreq;
			freq.placeholder = cfg.i18n.freq;
			freq.title = cfg.i18n.freqTitle;
			freq.addEventListener( 'input', function () {
				state[ index ].frequency = freq.value === '' ? '' : parseInt( freq.value, 10 );
				sync();
			} );
			row.appendChild( freq );

			var freqLabel = el( 'span', 'tt-address-row__freq-label', cfg.i18n.freq.toLowerCase() );
			row.appendChild( freqLabel );

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
			lng: item.lng,
			frequency: cfg.defaultFreq
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

	// Geocode within the city's selected country so e.g. a Dutch address on an
	// NL city isn't biased toward the site default country.
	function selectedCountry() {
		var sel = document.getElementById( 'tt_country_sel' );
		return sel && sel.value ? sel.value : '';
	}

	function lookup( q ) {
		var url = cfg.rest + '/geocode?q=' + encodeURIComponent( biasedQuery( q ) );
		var country = selectedCountry();
		if ( country ) {
			url += '&country=' + encodeURIComponent( country );
		}
		fetch( url, {
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
			state.push( { address: '', postcode: '', lat: null, lng: null, frequency: cfg.defaultFreq } );
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
