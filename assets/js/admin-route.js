/**
 * Route editor: order the "Locations served" list.
 *
 * - Drag a row to set a manual visit order.
 * - "Optimize by distance" reorders cities by geographic proximity
 *   (nearest-neighbour) from a chosen start city, using their map pins.
 *
 * The resulting order is mirrored into a hidden input as a JSON array of
 * location IDs and persisted when the route is saved.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $list  = $( '#tt-route-order' );
		var $field = $( '#tt_location_order' );
		if ( ! $list.length || ! $field.length ) {
			return;
		}

		function sync() {
			var ids = $list.children( '.tt-route-order__item' ).map( function () {
				return parseInt( $( this ).attr( 'data-id' ), 10 );
			} ).get();
			$field.val( JSON.stringify( ids ) );
		}

		$list.sortable( {
			handle: '.tt-route-order__handle',
			axis: 'y',
			placeholder: 'tt-route-order__placeholder',
			forcePlaceholderSize: true,
			update: sync
		} );

		// Great-circle distance between two {lat,lng} points, in km.
		function haversine( a, b ) {
			var r = 6371;
			var dLat = ( b.lat - a.lat ) * Math.PI / 180;
			var dLng = ( b.lng - a.lng ) * Math.PI / 180;
			var s = Math.sin( dLat / 2 ) * Math.sin( dLat / 2 ) +
				Math.cos( a.lat * Math.PI / 180 ) * Math.cos( b.lat * Math.PI / 180 ) *
				Math.sin( dLng / 2 ) * Math.sin( dLng / 2 );
			return r * 2 * Math.asin( Math.min( 1, Math.sqrt( s ) ) );
		}

		// Total length of an open path through the points (no return to start).
		function pathLength( route ) {
			var d = 0;
			for ( var i = 0; i < route.length - 1; i++ ) {
				d += haversine( route[ i ], route[ i + 1 ] );
			}
			return d;
		}

		// 2-opt: repeatedly reverse a segment when it shortens the path,
		// keeping the start city fixed at index 0. N is small (≤ ~20 cities).
		function twoOpt( route ) {
			var improved = true;
			while ( improved ) {
				improved = false;
				for ( var i = 1; i < route.length - 1; i++ ) {
					for ( var k = i + 1; k < route.length; k++ ) {
						var candidate = route.slice( 0, i )
							.concat( route.slice( i, k + 1 ).reverse() )
							.concat( route.slice( k + 1 ) );
						if ( pathLength( candidate ) < pathLength( route ) - 1e-9 ) {
							route = candidate;
							improved = true;
						}
					}
				}
			}
			return route;
		}

		function optimize() {
			var $items = $list.children( '.tt-route-order__item' );
			var byId   = {};
			var located = [];
			var unlocated = []; // Keep their relative order; append at the end.

			$items.each( function () {
				var id  = parseInt( $( this ).attr( 'data-id' ), 10 );
				var lat = parseFloat( $( this ).attr( 'data-lat' ) );
				var lng = parseFloat( $( this ).attr( 'data-lng' ) );
				byId[ id ] = this;
				if ( isFinite( lat ) && isFinite( lng ) ) {
					located.push( { id: id, lat: lat, lng: lng } );
				} else {
					unlocated.push( id );
				}
			} );

			if ( located.length < 2 ) {
				window.alert( ttRouteI18n.needCoords );
				return;
			}

			var startId = parseInt( $( '#tt-route-start' ).val(), 10 );
			var remaining = located.slice();
			var order = [];

			// Seed with the chosen start (fall back to the first located city).
			var startIdx = 0;
			for ( var i = 0; i < remaining.length; i++ ) {
				if ( remaining[ i ].id === startId ) {
					startIdx = i;
					break;
				}
			}
			var current = remaining.splice( startIdx, 1 )[ 0 ];
			order.push( current );

			// Repeatedly hop to the nearest unvisited city.
			while ( remaining.length ) {
				var best = 0;
				var bestDist = Infinity;
				for ( var j = 0; j < remaining.length; j++ ) {
					var d = haversine( current, remaining[ j ] );
					if ( d < bestDist ) {
						bestDist = d;
						best = j;
					}
				}
				current = remaining.splice( best, 1 )[ 0 ];
				order.push( current );
			}

			// Tighten the nearest-neighbour result by un-crossing legs.
			order = twoOpt( order );

			// Re-append the DOM nodes in the new order, then unlocated cities.
			order.forEach( function ( p ) {
				$list.append( byId[ p.id ] );
			} );
			unlocated.forEach( function ( id ) {
				$list.append( byId[ id ] );
			} );

			sync();
		}

		$( '#tt-route-optimize' ).on( 'click', optimize );
	} );
}( jQuery ) );
