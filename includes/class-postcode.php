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
		//    This is what lets nearby villages (postcodes we don't explicitly
		//    list) resolve to their closest covered stop.
		$center = Geocoder::geocode_postcode( $raw, $country );
		if ( $center ) {
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
			if ( null !== $best ) {
				// Outside the configured service radius → genuinely not covered.
				$radius = self::service_radius_km();
				if ( $radius > 0 && $best_dist > $radius ) {
					return null;
				}
				return self::decorate( $best, $best_dist, $best_addr, $best_time );
			}
		}

		// 3) Geocoding unavailable (provider miss/rate-limit) or no located
		//    stops: fall back to the closest covered postcode by shared leading
		//    digits, then numeric proximity — no network needed, so a nearby
		//    village still resolves to its nearest stop.
		return self::nearest_by_prefix( $norm, $country );
	}

	/**
	 * Maximum distance (km) a postcode may be from a stop to count as covered.
	 * 0 means no limit. Settable in Settings and filterable.
	 *
	 * @return float
	 */
	private static function service_radius_km(): float {
		$radius = (float) Settings::get( 'service_radius_km', 0 );
		return (float) apply_filters( 'tur_takvimi_service_radius_km', $radius );
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
	 * Network-free fallback: the covered postcode closest to the typed one by
	 * shared leading digits, then numeric distance. Used when geocoding is
	 * unavailable so a nearby village still resolves to its nearest stop.
	 *
	 * Requires a minimum number of shared leading characters (filterable), so a
	 * genuinely far-away postcode returns null ("not in your area") instead of a
	 * random distant stop. For German PLZ, 2 digits ≈ the same lead region.
	 *
	 * @param string $norm    Normalized typed postcode.
	 * @param string $country ISO-2 country code.
	 * @return array|null
	 */
	private static function nearest_by_prefix( string $norm, string $country ): ?array {
		if ( '' === $norm ) {
			return null;
		}

		/**
		 * Minimum shared leading characters for a proximity match.
		 *
		 * @param int    $min     Default minimum (2).
		 * @param string $country ISO-2 country code.
		 */
		$min_prefix = (int) apply_filters( 'tur_takvimi_postcode_min_prefix', 2, $country );

		$best_id     = 0;
		$best_addr   = '';
		$best_time   = '';
		$best_prefix = -1;
		$best_delta  = PHP_INT_MAX;

		foreach ( self::locations( $country ) as $id ) {
			$id = (int) $id;
			foreach ( self::covered_postcodes( $id, $country ) as $pc => $meta ) {
				$prefix = self::common_prefix_len( $norm, (string) $pc );
				if ( $prefix < $min_prefix ) {
					continue;
				}
				$delta = self::numeric_delta( $norm, (string) $pc );
				if ( $prefix > $best_prefix || ( $prefix === $best_prefix && $delta < $best_delta ) ) {
					$best_prefix = $prefix;
					$best_delta  = $delta;
					$best_id     = $id;
					$best_addr   = $meta['address'];
					$best_time   = $meta['time'];
				}
			}
		}

		return $best_id ? self::decorate( $best_id, 0.0, $best_addr, $best_time ) : null;
	}

	/**
	 * A location's covered postcodes (normalized) mapped to a representative
	 * street/time: the address stops first, then the covered-postcodes summary.
	 *
	 * @param int    $id      Location ID.
	 * @param string $country ISO-2 country code.
	 * @return array<string,array{address:string,time:string}>
	 */
	private static function covered_postcodes( int $id, string $country ): array {
		$out       = array();
		$addresses = json_decode( (string) get_post_meta( $id, '_tt_addresses', true ), true );
		if ( is_array( $addresses ) ) {
			foreach ( $addresses as $a ) {
				$pc = self::normalize( (string) ( $a['postcode'] ?? '' ), $country );
				if ( '' !== $pc && ! isset( $out[ $pc ] ) ) {
					$out[ $pc ] = array(
						'address' => (string) ( $a['address'] ?? '' ),
						'time'    => (string) ( $a['time'] ?? '' ),
					);
				}
			}
		}
		$raw = (string) get_post_meta( $id, '_tt_postcodes', true );
		foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$pc = self::normalize( $token, $country );
			if ( '' !== $pc && ! isset( $out[ $pc ] ) ) {
				$out[ $pc ] = array(
					'address' => '',
					'time'    => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Number of shared leading characters between two strings.
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 * @return int
	 */
	private static function common_prefix_len( string $a, string $b ): int {
		$max = min( strlen( $a ), strlen( $b ) );
		$n   = 0;
		while ( $n < $max && $a[ $n ] === $b[ $n ] ) {
			++$n;
		}
		return $n;
	}

	/**
	 * Absolute numeric difference between two postcodes, or PHP_INT_MAX when
	 * either is non-numeric.
	 *
	 * @param string $a First postcode.
	 * @param string $b Second postcode.
	 * @return int
	 */
	private static function numeric_delta( string $a, string $b ): int {
		if ( ! ctype_digit( $a ) || ! ctype_digit( $b ) ) {
			return PHP_INT_MAX;
		}
		return abs( (int) $a - (int) $b );
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
