<?php
/**
 * Postcode → nearest stop search.
 *
 * Bundles a per-country postcode centroid dataset (Netherlands first) and
 * resolves a typed postcode to the nearest location via haversine distance.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Postcode normalization, seeding and nearest-stop resolution.
 */
class Postcode {

	/**
	 * Table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tt_postcodes';
	}

	/**
	 * Normalize a typed postcode to its country-specific lookup key.
	 *
	 * For the Netherlands, "1234 AB" / "1234ab" → PC4 "1234".
	 *
	 * @param string $raw     User input.
	 * @param string $country ISO country code.
	 * @return string Normalized key, or '' if invalid.
	 */
	public static function normalize( string $raw, string $country = 'NL' ): string {
		$raw = strtoupper( trim( $raw ) );

		if ( 'NL' === $country ) {
			// Dutch postcode: 4 digits optionally followed by 2 letters.
			if ( preg_match( '/^(\d{4})\s*[A-Z]{0,2}$/', $raw, $m ) ) {
				return $m[1];
			}
			return '';
		}

		// Generic fallback: strip to leading alphanumerics.
		return preg_replace( '/[^A-Z0-9]/', '', $raw );
	}

	/**
	 * Look up the lat/lng centroid for a normalized postcode.
	 *
	 * @param string $pc4     Normalized postcode key.
	 * @param string $country ISO country code.
	 * @return array{lat:float,lng:float,city:string}|null
	 */
	public static function centroid( string $pc4, string $country = 'NL' ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lat, lng, city FROM " . self::table() . " WHERE country = %s AND pc4 = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$country,
				$pc4
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}
		return array(
			'lat'  => (float) $row['lat'],
			'lng'  => (float) $row['lng'],
			'city' => (string) $row['city'],
		);
	}

	/**
	 * Resolve a typed postcode to the nearest location and its next visit.
	 *
	 * @param string $raw Typed postcode.
	 * @return array|null {location_id, title, url, city, distance_km, next_date} or null.
	 */
	public static function nearest( string $raw ): ?array {
		$country = (string) Settings::get( 'country', 'NL' );
		$pc4     = self::normalize( $raw, $country );
		if ( '' === $pc4 ) {
			return null;
		}

		// 1) Exact coverage: a location explicitly lists this postcode.
		$exact = self::exact_coverage( $pc4 );
		if ( $exact ) {
			return self::decorate( $exact, 0.0 );
		}

		// 2) Nearest by distance using the postcode centroid.
		$center = self::centroid( $pc4, $country );
		if ( ! $center ) {
			return null;
		}

		$best      = null;
		$best_dist = PHP_FLOAT_MAX;
		foreach ( self::located_locations() as $loc ) {
			$dist = self::haversine( $center['lat'], $center['lng'], $loc['lat'], $loc['lng'] );
			if ( $dist < $best_dist ) {
				$best_dist = $dist;
				$best      = $loc['id'];
			}
		}

		if ( null === $best ) {
			return null;
		}
		return self::decorate( $best, $best_dist );
	}

	/**
	 * Find a location that explicitly covers the given postcode.
	 *
	 * @param string $pc4 Normalized postcode.
	 * @return int|null Location ID.
	 */
	private static function exact_coverage( string $pc4 ): ?int {
		$locations = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $locations as $id ) {
			$raw = (string) get_post_meta( $id, '_tt_postcodes', true );
			if ( '' === $raw ) {
				continue;
			}
			// Match the 4-digit prefix of any listed postcode.
			if ( preg_match_all( '/\d{4}/', $raw, $m ) && in_array( $pc4, $m[0], true ) ) {
				return (int) $id;
			}
		}
		return null;
	}

	/**
	 * All published locations that have coordinates.
	 *
	 * @return array<int,array{id:int,lat:float,lng:float}>
	 */
	private static function located_locations(): array {
		$locations = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $locations as $id ) {
			$lat = get_post_meta( $id, '_tt_lat', true );
			$lng = get_post_meta( $id, '_tt_lng', true );
			if ( '' === $lat || '' === $lng ) {
				continue;
			}
			$out[] = array(
				'id'  => (int) $id,
				'lat' => (float) $lat,
				'lng' => (float) $lng,
			);
		}
		return $out;
	}

	/**
	 * Build the response payload for a resolved location.
	 *
	 * @param int   $location_id Location ID.
	 * @param float $distance_km Distance in km.
	 * @return array
	 */
	private static function decorate( int $location_id, float $distance_km ): array {
		$schedule = new Schedule();
		return array(
			'location_id' => $location_id,
			'title'       => get_the_title( $location_id ),
			'url'         => (string) get_permalink( $location_id ),
			'distance_km' => round( $distance_km, 1 ),
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
	 * Seed the postcode table from the bundled dataset if empty.
	 */
	public static function maybe_seed(): void {
		global $wpdb;

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB
		if ( $count > 0 ) {
			return;
		}

		$file = TURTAKVIMI_DIR . 'assets/data/nl-postcodes.csv';
		if ( ! is_readable( $file ) ) {
			return;
		}

		$handle = fopen( $file, 'r' );
		if ( ! $handle ) {
			return;
		}

		$header = fgetcsv( $handle ); // country,pc4,city,lat,lng
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) < 5 ) {
				continue;
			}
			$wpdb->insert(
				self::table(),
				array(
					'country' => strtoupper( substr( trim( $row[0] ), 0, 2 ) ),
					'pc4'     => trim( $row[1] ),
					'city'    => trim( $row[2] ),
					'lat'     => (float) $row[3],
					'lng'     => (float) $row[4],
				),
				array( '%s', '%s', '%s', '%f', '%f' )
			);
		}
		fclose( $handle );
	}
}
