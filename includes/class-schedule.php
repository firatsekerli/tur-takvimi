<?php
/**
 * Schedule / recurrence engine.
 *
 * Frequency lives on each stop (address) inside a location. A route supplies
 * the start date and vehicle; every stop recurs from that start date at its
 * own interval. A location is "visited" on any date one of its stops is due.
 * Occurrences are materialized per (route, location, date) for fast lookups.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and queries materialized tour dates.
 */
class Schedule {

	/**
	 * How many weeks ahead to materialize occurrences.
	 */
	const HORIZON_WEEKS = 52;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'save_post_' . Post_Types::ROUTE, array( $this, 'on_route_save' ), 20 );
		add_action( 'save_post_' . Post_Types::LOCATION, array( $this, 'on_location_save' ), 20 );
		add_action( 'tur_takvimi_daily_regen', array( $this, 'regenerate_all' ) );

		add_action( 'wp_trash_post', array( $this, 'on_route_remove' ) );
		add_action( 'before_delete_post', array( $this, 'on_route_remove' ) );

		if ( ! wp_next_scheduled( 'tur_takvimi_daily_regen' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tur_takvimi_daily_regen' );
		}
	}

	/**
	 * Schedule table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tt_schedule';
	}

	/**
	 * Default per-stop frequency (weeks) when a stop has none set.
	 */
	private function default_frequency(): int {
		return max( 1, (int) Settings::get( 'default_frequency_weeks', 4 ) );
	}

	/**
	 * Regenerate occurrences when a route is saved.
	 *
	 * @param int $route_id Route post ID.
	 */
	public function on_route_save( $route_id ): void {
		if ( wp_is_post_revision( $route_id ) || wp_is_post_autosave( $route_id ) ) {
			return;
		}
		$this->regenerate_route( (int) $route_id );
	}

	/**
	 * When a location changes, regenerate every route that includes it (its
	 * stop frequencies feed those routes' schedules).
	 *
	 * @param int $location_id Location post ID.
	 */
	public function on_location_save( $location_id ): void {
		if ( wp_is_post_revision( $location_id ) || wp_is_post_autosave( $location_id ) ) {
			return;
		}

		// Drop this location's future rows everywhere first, so routes it was
		// just removed from don't keep stale occurrences, then rebuild current.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE location_id = %d AND tour_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $location_id,
				current_time( 'Y-m-d' )
			)
		);

		foreach ( $this->routes_for_location( (int) $location_id ) as $route_id ) {
			$this->regenerate_route( $route_id );
		}
	}

	/**
	 * Delete all schedule rows for a route when it is trashed or deleted.
	 *
	 * @param int $post_id Post being trashed/deleted.
	 */
	public function on_route_remove( $post_id ): void {
		if ( Post_Types::ROUTE !== get_post_type( $post_id ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'route_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Regenerate the schedule for every published route.
	 */
	public function regenerate_all(): void {
		$routes = get_posts(
			array(
				'post_type'      => Post_Types::ROUTE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $routes as $route_id ) {
			$this->regenerate_route( (int) $route_id );
		}
	}

	/**
	 * Materialize future occurrences for a single route.
	 *
	 * @param int $route_id Route post ID.
	 */
	public function regenerate_route( int $route_id ): void {
		global $wpdb;

		// Keep the route's derived summary (postcodes, start/end city, count) in
		// sync with its members (membership can change from a route or location).
		$this->refresh_route_summary( $route_id );

		$anchor  = (string) get_post_meta( $route_id, '_tt_anchor_date', true );
		$vehicle = (string) get_post_meta( $route_id, '_tt_vehicle', true );
		$today   = current_time( 'Y-m-d' );

		// Clear future rows; keep past ones as history.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE route_id = %d AND tour_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$route_id,
				$today
			)
		);

		if ( empty( $anchor ) ) {
			return;
		}

		try {
			$anchor_obj  = new \DateTimeImmutable( $anchor );
			$today_obj   = new \DateTimeImmutable( $today );
			$horizon_obj = $today_obj->modify( '+' . self::HORIZON_WEEKS . ' weeks' );
		} catch ( \Exception $e ) {
			return;
		}

		foreach ( $this->location_ids( $route_id ) as $location_id ) {
			$dates = $this->location_due_dates( $location_id, $anchor_obj, $today_obj, $horizon_obj );
			foreach ( $dates as $date ) {
				$wpdb->replace(
					$this->table(),
					array(
						'route_id'    => $route_id,
						'location_id' => $location_id,
						'tour_date'   => $date,
						'weekday'     => (int) ( new \DateTimeImmutable( $date ) )->format( 'w' ),
						'vehicle'     => $vehicle,
						'status'      => 'scheduled',
					),
					array( '%d', '%d', '%s', '%d', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * The set of dates a location is due, given a route's anchor — the union of
	 * its stops' recurrences (every stop.frequency weeks from the anchor).
	 *
	 * @param int                $location_id Location ID.
	 * @param \DateTimeImmutable $anchor      Route start date.
	 * @param \DateTimeImmutable $from        Earliest date to keep (today).
	 * @param \DateTimeImmutable $to          Horizon.
	 * @return string[] Sorted unique Y-m-d dates.
	 */
	private function location_due_dates( int $location_id, \DateTimeImmutable $anchor, \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$dates = array();
		foreach ( $this->stop_frequencies( $location_id ) as $weeks ) {
			$date = $anchor;
			while ( $date < $from ) {
				$date = $date->modify( '+' . $weeks . ' weeks' );
			}
			while ( $date <= $to ) {
				$dates[ $date->format( 'Y-m-d' ) ] = true;
				$date = $date->modify( '+' . $weeks . ' weeks' );
			}
		}
		$out = array_keys( $dates );
		sort( $out );
		return $out;
	}

	/**
	 * Distinct positive visit frequencies (weeks) among a location's stops.
	 * On-demand stops (frequency 0) are excluded. Falls back to the default
	 * when stops have no frequency set.
	 *
	 * @param int $location_id Location ID.
	 * @return int[]
	 */
	private function stop_frequencies( int $location_id ): array {
		$addresses = json_decode( (string) get_post_meta( $location_id, '_tt_addresses', true ), true );
		$freqs     = array();
		$has_stop  = false;

		if ( is_array( $addresses ) ) {
			foreach ( $addresses as $a ) {
				$has_stop = true;
				if ( ! array_key_exists( 'frequency', $a ) || '' === $a['frequency'] ) {
					$freqs[ $this->default_frequency() ] = true;
					continue;
				}
				$weeks = (int) $a['frequency'];
				if ( $weeks > 0 ) {
					$freqs[ $weeks ] = true;
				}
			}
		}

		// A location with no stops at all still gets a default cadence so the
		// route shows on the calendar.
		if ( ! $has_stop ) {
			$freqs[ $this->default_frequency() ] = true;
		}

		return array_keys( $freqs );
	}

	/**
	 * Tour occurrences between two dates, grouped per (date, route).
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_tours_between( string $start, string $end, string $country = '' ): array {
		global $wpdb;
		$country = strtoupper( $country );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT route_id, location_id, tour_date, weekday, vehicle FROM {$this->table()}
				 WHERE tour_date BETWEEN %s AND %s
				 ORDER BY tour_date ASC, vehicle ASC, route_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$start,
				$end
			),
			ARRAY_A
		);

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$location_id = (int) $row['location_id'];
			if ( 'publish' !== get_post_status( $location_id ) ) {
				continue;
			}
			if ( '' !== $country && Country::of_post( $location_id ) !== $country ) {
				continue;
			}
			$key = $row['tour_date'] . '|' . $row['route_id'];
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'route_id'  => (int) $row['route_id'],
					'tour_date' => $row['tour_date'],
					'weekday'   => (int) $row['weekday'],
					'vehicle'   => $row['vehicle'],
					'route'     => get_the_title( (int) $row['route_id'] ),
					'locations' => array(),
				);
			}
			$grouped[ $key ]['locations'][] = array(
				'id'    => $location_id,
				'title' => get_the_title( $location_id ),
				'url'   => (string) get_permalink( $location_id ),
			);
		}

		return array_values( $grouped );
	}

	/**
	 * The next upcoming visit date for a location, or null.
	 *
	 * @param int    $location_id Location post ID.
	 * @param string $from        Y-m-d (defaults to today).
	 * @return string|null
	 */
	public function next_tour_for_location( int $location_id, string $from = '' ): ?string {
		global $wpdb;
		$from = $from ?: current_time( 'Y-m-d' );

		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(tour_date) FROM {$this->table()} WHERE location_id = %d AND tour_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$location_id,
				$from
			)
		);
		return $date ?: null;
	}

	/**
	 * The next upcoming visit date for every location, in one query.
	 *
	 * @param string $from Y-m-d (defaults to today).
	 * @return array<int,string> location_id => Y-m-d.
	 */
	public function next_tour_dates( string $from = '' ): array {
		global $wpdb;
		$from = $from ?: current_time( 'Y-m-d' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT location_id, MIN(tour_date) AS next_date FROM {$this->table()} WHERE tour_date >= %s GROUP BY location_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$from
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['location_id'] ] = (string) $row['next_date'];
		}
		return $out;
	}

	/**
	 * The next upcoming visit dates for a location (distinct, ascending).
	 *
	 * @param int    $location_id Location post ID.
	 * @param int    $limit       Maximum number of dates to return.
	 * @param string $from        Y-m-d (defaults to today).
	 * @return string[] Y-m-d dates.
	 */
	public function upcoming_tours_for_location( int $location_id, int $limit = 4, string $from = '' ): array {
		global $wpdb;
		$from  = $from ?: current_time( 'Y-m-d' );
		$limit = max( 1, $limit );

		$dates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tour_date FROM {$this->table()} WHERE location_id = %d AND tour_date >= %s ORDER BY tour_date ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$location_id,
				$from,
				$limit
			)
		);
		return array_map( 'strval', (array) $dates );
	}

	/**
	 * Route IDs a location belongs to (many-to-many; stored on the location).
	 *
	 * @param int $location_id Location post ID.
	 * @return int[]
	 */
	public function routes_for_location( int $location_id ): array {
		$ids = get_post_meta( $location_id, '_tt_route_id', false );
		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Published locations assigned to a route (unordered).
	 *
	 * @param int $route_id Route post ID.
	 * @return int[]
	 */
	public function member_location_ids( int $route_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_tt_route_id',
						'value' => $route_id,
					),
				),
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Member locations of a route in visit order: manual override first
	 * (where set), then any remaining sorted by ascending postcode.
	 *
	 * @param int $route_id Route post ID.
	 * @return int[]
	 */
	public function ordered_location_ids( int $route_id ): array {
		$members = $this->member_location_ids( $route_id );
		if ( empty( $members ) ) {
			return array();
		}

		$order   = json_decode( (string) get_post_meta( $route_id, '_tt_location_order', true ), true );
		$order   = is_array( $order ) ? array_map( 'intval', $order ) : array();
		$ordered = array_values( array_intersect( $order, $members ) );

		$remaining = array_values( array_diff( $members, $ordered ) );
		$remaining = $this->sort_by_postcode( $remaining );

		return array_merge( $ordered, $remaining );
	}

	/**
	 * Sort location IDs by their lowest postcode (ascending).
	 *
	 * @param int[] $ids Location IDs.
	 * @return int[]
	 */
	private function sort_by_postcode( array $ids ): array {
		$pairs = array();
		foreach ( $ids as $id ) {
			$pairs[] = array(
				'id' => (int) $id,
				'pc' => $this->min_postcode( (int) $id ),
			);
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				return $a['pc'] <=> $b['pc'];
			}
		);
		return array_map( static fn( $p ) => $p['id'], $pairs );
	}

	/**
	 * Lowest numeric postcode among a location's covered postcodes.
	 *
	 * @param int $location_id Location ID.
	 * @return int PHP_INT_MAX when none.
	 */
	private function min_postcode( int $location_id ): int {
		$raw = (string) get_post_meta( $location_id, '_tt_postcodes', true );
		$min = PHP_INT_MAX;
		foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( preg_match( '/\d+/', $token, $m ) ) {
				$min = min( $min, (int) $m[0] );
			}
		}
		return $min;
	}

	/**
	 * Ordered location IDs for a route (alias used during regeneration).
	 *
	 * @param int $route_id Route post ID.
	 * @return int[]
	 */
	private function location_ids( int $route_id ): array {
		return $this->ordered_location_ids( $route_id );
	}

	/**
	 * Recompute a route's derived summary fields (display only): covered
	 * postcodes, start/end city (first/last stop in visit order) and the
	 * number of cities served.
	 *
	 * @param int $route_id Route post ID.
	 */
	private function refresh_route_summary( int $route_id ): void {
		$ordered = $this->ordered_location_ids( $route_id );

		// Covered postcodes (unique, sorted).
		$postcodes = array();
		foreach ( $ordered as $loc_id ) {
			$list      = preg_split( '/[\s,]+/', (string) get_post_meta( $loc_id, '_tt_postcodes', true ), -1, PREG_SPLIT_NO_EMPTY );
			$postcodes = array_merge( $postcodes, (array) $list );
		}
		$postcodes = array_values( array_unique( $postcodes ) );
		sort( $postcodes );
		update_post_meta( $route_id, '_tt_plz_range', implode( ', ', $postcodes ) );

		// Start city = first stop, end city = last stop, count = city total.
		$start = $ordered ? get_the_title( (int) $ordered[0] ) : '';
		$end   = $ordered ? get_the_title( (int) end( $ordered ) ) : '';
		update_post_meta( $route_id, '_tt_start_city', $start );
		update_post_meta( $route_id, '_tt_end_city', $end );
		update_post_meta( $route_id, '_tt_city_count', count( $ordered ) );
	}
}
