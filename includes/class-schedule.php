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
	public function get_tours_between( string $start, string $end ): array {
		global $wpdb;

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
	 * Route IDs that include a given location.
	 *
	 * @param int $location_id Location post ID.
	 * @return int[]
	 */
	public function routes_for_location( int $location_id ): array {
		$routes = get_posts(
			array(
				'post_type'      => Post_Types::ROUTE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$matches = array();
		foreach ( $routes as $route_id ) {
			if ( in_array( $location_id, $this->location_ids( (int) $route_id ), true ) ) {
				$matches[] = (int) $route_id;
			}
		}
		return $matches;
	}

	/**
	 * Ordered location IDs for a route.
	 *
	 * @param int $route_id Route post ID.
	 * @return int[]
	 */
	private function location_ids( int $route_id ): array {
		$ids = json_decode( (string) get_post_meta( $route_id, '_tt_location_ids', true ), true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
