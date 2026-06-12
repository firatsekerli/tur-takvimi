<?php
/**
 * Schedule / recurrence engine.
 *
 * Materializes recurring route visits (every N weeks from an anchor date)
 * into the wp_tt_schedule table so the calendar and "next visit" lookups
 * are fast, indexed queries rather than per-request computation.
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
		add_action( 'tur_takvimi_daily_regen', array( $this, 'regenerate_all' ) );

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

		$anchor    = (string) get_post_meta( $route_id, '_tt_anchor_date', true );
		$frequency = (int) get_post_meta( $route_id, '_tt_frequency_weeks', true );
		$vehicle   = (string) get_post_meta( $route_id, '_tt_vehicle', true );

		// Clear future rows; keep past ones as history.
		$today = current_time( 'Y-m-d' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE route_id = %d AND tour_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$route_id,
				$today
			)
		);

		if ( empty( $anchor ) || $frequency < 1 ) {
			return;
		}

		try {
			$date    = new \DateTimeImmutable( $anchor );
			$horizon = ( new \DateTimeImmutable( $today ) )->modify( '+' . self::HORIZON_WEEKS . ' weeks' );
		} catch ( \Exception $e ) {
			return;
		}

		// Advance the anchor forward to the first occurrence that is >= today.
		$todayObj = new \DateTimeImmutable( $today );
		while ( $date < $todayObj ) {
			$date = $date->modify( '+' . $frequency . ' weeks' );
		}

		while ( $date <= $horizon ) {
			$wpdb->replace(
				$this->table(),
				array(
					'route_id'  => $route_id,
					'tour_date' => $date->format( 'Y-m-d' ),
					'weekday'   => (int) $date->format( 'w' ),
					'vehicle'   => $vehicle,
					'status'    => 'scheduled',
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
			$date = $date->modify( '+' . $frequency . ' weeks' );
		}
	}

	/**
	 * Get tour occurrences between two dates (inclusive), with route data.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array<int,array<string,mixed>> Rows keyed numerically.
	 */
	public function get_tours_between( string $start, string $end ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT route_id, tour_date, weekday, vehicle FROM {$this->table()}
				 WHERE tour_date BETWEEN %s AND %s
				 ORDER BY tour_date ASC, vehicle ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$start,
				$end
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$route_id = (int) $row['route_id'];
			$out[]    = array(
				'route_id'  => $route_id,
				'tour_date' => $row['tour_date'],
				'weekday'   => (int) $row['weekday'],
				'vehicle'   => $row['vehicle'],
				'route'     => get_the_title( $route_id ),
				'locations' => $this->route_location_links( $route_id ),
			);
		}
		return $out;
	}

	/**
	 * The next upcoming tour date for a given location, or null.
	 *
	 * @param int    $location_id Location post ID.
	 * @param string $from        Y-m-d (defaults to today).
	 * @return string|null Y-m-d of the next visit.
	 */
	public function next_tour_for_location( int $location_id, string $from = '' ): ?string {
		global $wpdb;

		$from       = $from ?: current_time( 'Y-m-d' );
		$route_ids  = $this->routes_for_location( $location_id );
		if ( empty( $route_ids ) ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $route_ids ), '%d' ) );
		$params       = array_merge( $route_ids, array( $from ) );

		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(tour_date) FROM {$this->table()}
				 WHERE route_id IN ($placeholders) AND tour_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				...$params
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
			$ids = json_decode( (string) get_post_meta( $route_id, '_tt_location_ids', true ), true );
			if ( is_array( $ids ) && in_array( $location_id, array_map( 'intval', $ids ), true ) ) {
				$matches[] = (int) $route_id;
			}
		}
		return $matches;
	}

	/**
	 * Ordered location title/permalink pairs for a route.
	 *
	 * @param int $route_id Route post ID.
	 * @return array<int,array{id:int,title:string,url:string}>
	 */
	private function route_location_links( int $route_id ): array {
		$ids = json_decode( (string) get_post_meta( $route_id, '_tt_location_ids', true ), true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
				'url'   => (string) get_permalink( $id ),
			);
		}
		return $out;
	}
}
