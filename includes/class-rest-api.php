<?php
/**
 * REST API endpoints powering the front-end widgets.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Registers public read endpoints for the calendar and postcode search.
 */
class Rest_Api {

	const NS = 'tur-takvimi/v1';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		register_rest_route(
			self::NS,
			'/calendar',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'calendar' ),
				'args'                => array(
					'weeks'  => array(
						'type'    => 'integer',
						'default' => (int) Settings::get( 'calendar_weeks', 3 ),
					),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'search' ),
				'args'                => array(
					'postcode' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// Admin-only geocoding proxy (Dutch PDOK Locatieserver).
		register_rest_route(
			self::NS,
			'/geocode',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => array( $this, 'geocode' ),
				'args'                => array(
					'q' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Proxy an address lookup to the geocoder (server-side avoids CORS).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function geocode( \WP_REST_Request $req ): \WP_REST_Response {
		$query   = (string) $req->get_param( 'q' );
		$country = (string) Settings::get( 'country', 'DE' );
		$results = Geocoder::search( $query, 8, $country );
		return new \WP_REST_Response( array( 'results' => $results ) );
	}

	/**
	 * Calendar payload: days grouped by date within the requested window.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function calendar( \WP_REST_Request $req ): \WP_REST_Response {
		$weeks  = max( 1, min( 12, (int) $req->get_param( 'weeks' ) ) );
		$offset = max( 0, (int) $req->get_param( 'offset' ) );

		$start = ( new \DateTimeImmutable( current_time( 'Y-m-d' ) ) )->modify( '+' . ( $offset * $weeks ) . ' weeks' );
		$end   = $start->modify( '+' . $weeks . ' weeks -1 day' );

		$schedule = new Schedule();
		$tours    = $schedule->get_tours_between( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );

		$days = array();
		foreach ( $tours as $tour ) {
			$days[ $tour['tour_date'] ][] = $tour;
		}

		$out = array();
		foreach ( $days as $date => $items ) {
			$out[] = array(
				'date'     => $date,
				'is_today' => current_time( 'Y-m-d' ) === $date,
				'label'    => self::format_day( $date ),
				'tours'    => $items,
			);
		}

		return new \WP_REST_Response(
			array(
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
				'days'  => $out,
			)
		);
	}

	/**
	 * Postcode search payload.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function search( \WP_REST_Request $req ): \WP_REST_Response {
		$postcode = (string) $req->get_param( 'postcode' );
		$result   = Postcode::nearest( $postcode );

		if ( null === $result ) {
			return new \WP_REST_Response(
				array(
					'found'   => false,
					'message' => __( 'No stop found for this postcode. Please check and try again.', 'tur-takvimi' ),
				),
				200
			);
		}

		if ( ! empty( $result['next_date'] ) ) {
			$result['next_date_label'] = self::format_day( $result['next_date'] );
		}
		$result['found'] = true;

		return new \WP_REST_Response( $result );
	}

	/**
	 * Localized "Weekday D Month" label for a Y-m-d date.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public static function format_day( string $date ): string {
		$ts = strtotime( $date . ' 12:00:00' );
		return wp_date( 'l, j F Y', $ts );
	}
}
