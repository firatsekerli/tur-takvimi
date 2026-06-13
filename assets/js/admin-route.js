/**
 * Route editor: drag the "Locations served" list to set a manual visit order.
 * The order is mirrored into a hidden input as a JSON array of location IDs.
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
	} );
}( jQuery ) );
