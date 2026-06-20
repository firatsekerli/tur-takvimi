<?php
/**
 * Postcode → nearest stop search.
 *
 * Resolves a typed postcode to the nearest delivery stop. Exact matches use
 * the postcodes attached to each location's addresses; otherwise the postcode
 * is geocoded on demand (cached) and the nearest address/city is returned.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Postcode normalization and nearest-stop resolution.
 */
class Postcode {

	/**
	 * Normalize a typed postcode to a comparable key for the given country.
	 *
	 * @param string $raw     User input.
	 * @param string $country ISO-2 country code.
	 * @return string Normalized key, or '' if invalid.
	 */
	public static function normalize( string $raw, string $country = 'DE' ): string {
		$raw     = strtoupper( trim( $raw ) );
		$country = strtoupper( $country );

		if ( 'DE' === $country ) {
			// German postcode: exactly 5 digits.
			return preg_match( '/\b(\d{5})\b/', $raw, $m ) ? $m[1] : '';
		}
		if ( 'NL' === $country ) {
			// Dutch postcode: 4 digits (+ optional 2 letters).
			return preg_match( '/^(\d{4})\s*[A-Z]{0,2}$/', $raw, $m ) ? $m[1] : '';
		}

		// Generic: keep alphanumerics.
		return preg_replace( '/[^A-Z0-9]/', '', $raw );
	}

	/**
	 * Resolve a typed postcode to the nearest location and its next visit.
	 *
	 * @param string $raw     Typed postcode.
	 * @param string $country Optional ISO-2 override; otherwise auto-detected
	 *                        from the postcode format, then the default.
	 * @return array|null {location_id, title, url, distance_km, next_date} or null.
	 */
	public static function nearest( string $raw, string $country = '' ): ?array {
		$country = strtoupper( $country );
		if ( '' === $country ) {
			$country = Country::detect( $raw );
		}
		if ( '' === $country ) {
			$country = Country::default_code();
		}
		$norm = self::normalize( $raw, $country );

		// 1) Exact coverage: a location's address carries this postcode.
		if ( '' !== $norm ) {
			$exact = self::exact_coverage( $norm, $country );
			if ( $exact ) {
				return self::decorate( $exact['id'], 0.0, $exact['address'], $exact['time'] );
			}
		}

		// 2) Geocode the typed postcode, then find the nearest stop by distance.
		$center = Geocoder::geocode_postcode( $raw, $country );
		if ( ! $center ) {
			return null;
		}

		$best      = null;
		$best_dist = PHP_FLOAT_MAX;
		$best_addr = '';
		$best_time = '';
		foreach ( self::located_stops( $country ) as $stop ) {
			$dist = self::haversine( $center['lat'], $center['lng'], $stop['lat'], $stop['lng'] );
			if ( $dist < $best_dist ) {
				$best_dist = $dist;
				$best      = $stop['location_id'];
				$best_addr = $stop['address'];
				$best_time = $stop['time'];
			}
		}

		return null === $best ? null : self::decorate( $best, $best_dist, $best_addr, $best_time );
	}

	/**
	 * Find a location whose addresses cover the normalized postcode, returning
	 * the matched street where available.
	 *
	 * @param string $norm    Normalized postcode.
	 * @param string $country ISO-2 country code.
	 * @return array{id:int,address:string}|null
	 */
	private static function exact_coverage( string $norm, string $country ): ?array {
		foreach ( self::locations( $country ) as $id ) {
			$id        = (int) $id;
			$addresses = json_decode( (string) get_post_meta( $id, '_tt_addresses', true ), true );
			if ( is_array( $addresses ) ) {
				foreach ( $addresses as $a ) {
					if ( self::normalize( (string) ( $a['postcode'] ?? '' ), $country ) === $norm ) {
						return array(
							'id'      => $id,
							'address' => (string) ( $a['address'] ?? '' ),
							'time'    => (string) ( $a['time'] ?? '' ),
						);
					}
				}
			}

			// Fall back to the covered-postcodes summary (no specific street).
			$raw = (string) get_post_meta( $id, '_tt_postcodes', true );
			foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
				if ( self::normalize( $token, $country ) === $norm ) {
					return array(
						'id'      => $id,
						'address' => '',
						'time'    => '',
					);
				}
			}
		}
		return null;
	}

	/**
	 * Every stop with coordinates: each address point, plus the city centroid
	 * as a fallback for locations whose addresses lack coordinates.
	 *
	 * @param string $country Optional ISO-2 restriction.
	 * @return array<int,array{location_id:int,lat:float,lng:float}>
	 */
	private static function located_stops( string $country = '' ): array {
		$stops = array();
		foreach ( self::locations( $country ) as $id ) {
			$id        = (int) $id;
			$addresses = json_decode( (string) get_post_meta( $id, '_tt_addresses', true ), true );
			$has_addr  = false;
			if ( is_array( $addresses ) ) {
				foreach ( $addresses as $a ) {
					if ( isset( $a['lat'], $a['lng'] ) && is_numeric( $a['lat'] ) && is_numeric( $a['lng'] ) ) {
						$stops[]  = array(
							'location_id' => $id,
							'lat'         => (float) $a['lat'],
							'lng'         => (float) $a['lng'],
							'address'     => (string) ( $a['address'] ?? '' ),
							'time'        => (string) ( $a['time'] ?? '' ),
						);
						$has_addr = true;
					}
				}
			}
			if ( ! $has_addr ) {
				$lat = get_post_meta( $id, '_tt_lat', true );
				$lng = get_post_meta( $id, '_tt_lng', true );
				if ( '' !== $lat && '' !== $lng ) {
					$stops[] = array(
						'location_id' => $id,
						'lat'         => (float) $lat,
						'lng'         => (float) $lng,
						'address'     => '',
						'time'        => '',
					);
				}
			}
		}
		return $stops;
	}

	/**
	 * Published location IDs, optionally restricted to a country.
	 *
	 * @param string $country Optional ISO-2 code.
	 * @return int[]
	 */
	private static function locations( string $country = '' ): array {
		$args = array(
			'post_type'      => Post_Types::LOCATION,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		if ( '' !== $country ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_tt_country',
					'value' => strtoupper( $country ),
				),
			);
		}
		return get_posts( $args );
	}

	/**
	 * Build the response payload for a resolved location.
	 *
	 * @param int    $location_id Location ID.
	 * @param float  $distance_km Distance in km.
	 * @param string $address     Nearest stop street, when known.
	 * @param string $time        Delivery time/hour for the nearest stop.
	 * @return array
	 */
	private static function decorate( int $location_id, float $distance_km, string $address = '', string $time = '' ): array {
		$schedule = new Schedule();
		return array(
			'location_id' => $location_id,
			'title'       => get_the_title( $location_id ),
			'address'     => $address,
			'url'         => (string) get_permalink( $location_id ),
			'distance_km' => round( $distance_km, 1 ),
			'time'        => $time,
			'next_date'   => $schedule->next_tour_for_location( $location_id ),
		);
	}

	/**
	 * Great-circle distance between two points in kilometers.
	 */
	private static function haversine( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r    = 6371.0;
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlng = deg2rad( $lng2 - $lng1 );
		$a    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
		return $r * 2 * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * Postcode coordinates are now resolved on demand via the Geocoder and
	 * cached in transients, so no bundled dataset needs seeding.
	 */
	public static function maybe_seed(): void {
		// Intentionally a no-op; retained for activation-hook compatibility.
	}
}
