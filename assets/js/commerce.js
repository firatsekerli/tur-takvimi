/**
 * Checkout delivery picker: ülke → bölge → şehir cascade.
 *
 * The selects are fully server-rendered (so the form works without JS); this
 * script narrows the region/city options to the chosen country/region and
 * fills the delivery-details box for the picked city.
 *
 * Themes sometimes render the checkout form twice (or replace parts of it via
 * Woo's AJAX), so every [data-tt-checkout] instance is bound, and binding is
 * re-run on Woo's updated_checkout event.
 */
( function () {
	'use strict';

	function bind( root ) {
		var cfg = window.TurTakvimiCommerce;
		if ( ! cfg || root.dataset.ttBound ) {
			return;
		}
		var country = root.querySelector( 'select[name="tt_checkout_country"]' );
		var region = root.querySelector( 'select[name="tt_checkout_region"]' );
		var city = root.querySelector( 'select[name="tt_checkout_city"]' );
		var info = root.querySelector( '[data-tt-info]' );
		if ( ! city ) {
			return;
		}
		root.dataset.ttBound = '1';

		function currentCountry() {
			return country ? country.value : '';
		}

		function currentRegion() {
			return region ? region.value : '';
		}

		function rebuildRegions() {
			if ( ! region ) {
				return;
			}
			var keep = region.value;
			var co = currentCountry();
			region.length = 1; // Keep the "all" placeholder.
			cfg.regions.forEach( function ( r ) {
				if ( co && r.countries.indexOf( co ) === -1 ) {
					return;
				}
				region.add( new Option( r.name, r.slug, false, r.slug === keep ) );
			} );
		}

		function rebuildCities() {
			var keep = city.value;
			var co = currentCountry();
			var reg = currentRegion();
			city.length = 1; // Keep the "select a city" placeholder.
			cfg.cities.forEach( function ( c ) {
				if ( co && c.country !== co ) {
					return;
				}
				if ( reg && c.regions.indexOf( reg ) === -1 ) {
					return;
				}
				city.add( new Option( c.name, String( c.id ), false, String( c.id ) === keep ) );
			} );
			updateInfo();
		}

		function setRow( key, value ) {
			var row = info.querySelector( '[data-tt-row="' + key + '"]' );
			if ( ! row ) {
				return;
			}
			row.style.display = value ? '' : 'none';
			var v = row.querySelector( '[data-tt-value]' );
			if ( v ) {
				v.textContent = value || '';
			}
		}

		function updateInfo() {
			if ( ! info ) {
				return;
			}
			var picked = null;
			cfg.cities.forEach( function ( c ) {
				if ( String( c.id ) === city.value ) {
					picked = c;
				}
			} );
			if ( ! picked ) {
				info.style.display = 'none';
				return;
			}
			setRow( 'date', picked.dateLabel );
			setRow( 'time', picked.time );
			setRow( 'address', picked.address );
			info.style.display = '';
		}

		if ( country ) {
			country.addEventListener( 'change', function () {
				rebuildRegions();
				rebuildCities();
			} );
		}
		if ( region ) {
			region.addEventListener( 'change', rebuildCities );
		}
		city.addEventListener( 'change', updateInfo );

		rebuildRegions();
		rebuildCities();
	}

	function initAll() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-tt-checkout]' ), bind );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	// WooCommerce re-renders checkout fragments; re-bind any fresh copies of
	// the section (updated_checkout is a jQuery event, so guard for jQuery).
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', initAll );
	}
}() );
