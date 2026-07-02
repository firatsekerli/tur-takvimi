/**
 * Checkout delivery picker: ülke → bölge → şehir cascade.
 *
 * The selects are fully server-rendered (so the form works without JS); this
 * script narrows the region/city options to the chosen country/region and
 * fills the delivery-details box for the picked city.
 */
( function () {
	'use strict';

	var cfg = window.TurTakvimiCommerce;

	function init() {
		var root = document.querySelector( '[data-tt-checkout]' );
		if ( ! cfg || ! root ) {
			return;
		}
		var country = root.querySelector( 'select[name="tt_checkout_country"]' );
		var region = root.querySelector( 'select[name="tt_checkout_region"]' );
		var city = root.querySelector( 'select[name="tt_checkout_city"]' );
		var info = root.querySelector( '[data-tt-info]' );
		if ( ! city ) {
			return;
		}

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
				var opt = new Option( r.name, r.slug, false, r.slug === keep );
				region.add( opt );
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
				var opt = new Option( c.name, String( c.id ), false, String( c.id ) === keep );
				city.add( opt );
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

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
