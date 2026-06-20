<?php
/**
 * Month-grid delivery calendar + "add to calendar" (iCalendar) feed.
 *
 * Two builder-agnostic pieces:
 *   - [tur_takvimi_calendar_month]: a real month grid that marks delivery days
 *     and links each city, with an "Add to calendar" toolbar (subscribe /
 *     download) and per-day Google Calendar links.
 *   - An iCalendar feed at /?tt_ics=1 (optionally scoped by location / route /
 *     country) so visitors can subscribe (webcal, auto-updating) or download a
 *     one-off .ics that any calendar app can import.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * The month calendar shortcode and the .ics feed.
 */
class Calendar {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_shortcode( 'tur_takvimi_calendar_month', array( $this, 'month' ) );
		add_action( 'template_redirect', array( $this, 'maybe_output_ics' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
	}

	/**
	 * Register the calendar navigation script.
	 */
	public function register_assets(): void {
		wp_register_script(
			'tur-takvimi-calendar',
			TURTAKVIMI_URL . 'assets/js/frontend-calendar.js',
			array(),
			Plugin::asset_ver( 'assets/js/frontend-calendar.js' ),
			true
		);
		wp_localize_script(
			'tur-takvimi-calendar',
			'TurTakvimiCal',
			array( 'rest' => esc_url_raw( rest_url( Rest_Api::NS ) ) )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Month grid shortcode
	 * --------------------------------------------------------------------- */

	/**
	 * Render a month-grid calendar.
	 *
	 * @param array $atts Shortcode attributes (country, id, months).
	 * @return string
	 */
	public function month( $atts ): string {
		$atts = shortcode_atts(
			array(
				'country' => '',
				'id'      => 0,
				'months'  => 1,
			),
			$atts,
			'tur_takvimi_calendar_month'
		);

		wp_enqueue_style( 'tur-takvimi' );
		wp_enqueue_script( 'tur-takvimi-calendar' );

		$country = strtoupper( (string) $atts['country'] );
		$loc_id  = (int) $atts['id'];
		// On a single city page, default to that city's schedule.
		if ( ! $loc_id && is_singular( Post_Types::LOCATION ) ) {
			$loc_id = (int) get_queried_object_id();
		}
		$months = max( 1, min( 3, (int) $atts['months'] ) );

		// Which month to show first (Y-m), navigable via ?tt_month / AJAX.
		$cursor = $this->current_cursor();

		$subscribe = $this->feed_url( $country, $loc_id, false, '', true );
		$download  = $this->feed_url( $country, $loc_id, true );

		ob_start();
		?>
		<section class="tt-month" data-tt-month-root
			data-country="<?php echo esc_attr( $country ); ?>"
			data-location="<?php echo esc_attr( (string) $loc_id ); ?>"
			data-months="<?php echo esc_attr( (string) $months ); ?>"
			data-month="<?php echo esc_attr( $cursor->format( 'Y-m' ) ); ?>"
			aria-label="<?php esc_attr_e( 'Delivery calendar', 'tur-takvimi' ); ?>">
			<div class="tt-month__bar">
				<div class="tt-month__nav">
					<a class="tt-month__navbtn" data-tt-month-prev href="<?php echo esc_url( $this->month_url( $cursor->modify( '-1 month' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'tur-takvimi' ); ?>" rel="nofollow">‹</a>
					<a class="tt-month__navbtn" data-tt-month-next href="<?php echo esc_url( $this->month_url( $cursor->modify( '+1 month' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'tur-takvimi' ); ?>" rel="nofollow">›</a>
				</div>
				<div class="tt-month__addcal">
					<span class="tt-month__addcal-label"><?php esc_html_e( 'Add to calendar', 'tur-takvimi' ); ?></span>
					<a class="tt-month__btn tt-month__btn--primary" href="<?php echo esc_url( $subscribe ); ?>" rel="nofollow">
						<?php esc_html_e( 'Subscribe (auto-updates)', 'tur-takvimi' ); ?>
					</a>
					<a class="tt-month__btn" href="<?php echo esc_url( $download ); ?>" rel="nofollow" download>
						<?php esc_html_e( 'Download (.ics)', 'tur-takvimi' ); ?>
					</a>
				</div>
			</div>

			<div class="tt-month__grids" data-tt-month-grids>
				<?php echo $this->render_months_html( $cursor, $country, $loc_id, $months ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the grid(s) for a span of months (the part swapped on navigation).
	 *
	 * @param \DateTimeImmutable $cursor  First month to render.
	 * @param string             $country ISO-2 filter.
	 * @param int                $loc_id  Optional location scope.
	 * @param int                $months  Number of months (1–3).
	 * @return string
	 */
	private function render_months_html( \DateTimeImmutable $cursor, string $country, int $loc_id, int $months ): string {
		$months = max( 1, min( 3, $months ) );

		$range_start = $cursor->format( 'Y-m-d' );
		$range_end   = $cursor->modify( '+' . $months . ' months -1 day' )->format( 'Y-m-d' );

		$schedule = new Schedule();
		$tours    = $schedule->get_tours_between( $range_start, $range_end, $country );

		// Index delivery cities by date.
		$by_date = array();
		foreach ( $tours as $tour ) {
			foreach ( $tour['locations'] as $loc ) {
				if ( $loc_id && (int) $loc['id'] !== $loc_id ) {
					continue;
				}
				$by_date[ $tour['tour_date'] ][] = array(
					'title' => $loc['title'],
					'url'   => $loc['url'],
					'route' => $tour['route'],
				);
			}
		}

		$today    = current_time( 'Y-m-d' );
		$weekdays = $this->weekday_labels();

		$html = '';
		$grid = $cursor;
		for ( $m = 0; $m < $months; $m++ ) {
			$html .= $this->render_grid( $grid, $by_date, $today, $weekdays, $country, $loc_id );
			$grid  = $grid->modify( '+1 month' );
		}
		return $html;
	}

	/* --------------------------------------------------------------------- *
	 * REST: month grid HTML (powers AJAX navigation)
	 * --------------------------------------------------------------------- */

	/**
	 * Register the month-grid REST route.
	 */
	public function rest_routes(): void {
		register_rest_route(
			Rest_Api::NS,
			'/calendar-month',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_month' ),
				'args'                => array(
					'month'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'country'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'location' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'months'   => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);
	}

	/**
	 * Return the grid HTML for a requested month (JSON: { month, html }).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function rest_month( \WP_REST_Request $req ): \WP_REST_Response {
		$month = (string) $req->get_param( 'month' );
		try {
			$cursor = preg_match( '/^\d{4}-\d{2}$/', $month )
				? new \DateTimeImmutable( $month . '-01' )
				: new \DateTimeImmutable( current_time( 'Y-m-01' ) );
		} catch ( \Exception $e ) {
			$cursor = new \DateTimeImmutable( current_time( 'Y-m-01' ) );
		}

		$country  = strtoupper( (string) $req->get_param( 'country' ) );
		$location = max( 0, (int) $req->get_param( 'location' ) );
		$months   = max( 1, min( 3, (int) $req->get_param( 'months' ) ) );

		return new \WP_REST_Response(
			array(
				'month' => $cursor->format( 'Y-m' ),
				'html'  => $this->render_months_html( $cursor, $country, $location, $months ),
			)
		);
	}

	/**
	 * Render a single month grid.
	 *
	 * @param \DateTimeImmutable $first    First day of the month.
	 * @param array              $by_date  Deliveries indexed by Y-m-d.
	 * @param string             $today    Today's Y-m-d.
	 * @param string[]           $weekdays Localized Mon..Sun labels.
	 * @param string             $country  ISO-2 filter (for per-day .ics scope).
	 * @param int                $loc_id   Location scope (for per-day .ics scope).
	 * @return string
	 */
	private function render_grid( \DateTimeImmutable $first, array $by_date, string $today, array $weekdays, string $country = '', int $loc_id = 0 ): string {
		$days_in_month = (int) $first->format( 't' );
		// Leading blanks: ISO weekday (Mon=1..Sun=7) minus 1.
		$lead = (int) $first->format( 'N' ) - 1;

		ob_start();
		?>
		<div class="tt-month__cal">
			<div class="tt-month__title"><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?></div>
			<div class="tt-month__grid">
				<?php foreach ( $weekdays as $wd ) : ?>
					<span class="tt-month__wd"><?php echo esc_html( $wd ); ?></span>
				<?php endforeach; ?>

				<?php for ( $i = 0; $i < $lead; $i++ ) : ?>
					<span class="tt-month__cell tt-month__cell--empty"></span>
				<?php endfor; ?>

				<?php
				for ( $d = 1; $d <= $days_in_month; $d++ ) :
					$date  = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $d );
					$ymd   = $date->format( 'Y-m-d' );
					$stops = $by_date[ $ymd ] ?? array();
					$class = 'tt-month__cell';
					if ( $ymd === $today ) {
						$class .= ' is-today';
					}
					if ( $stops ) {
						$class .= ' has-stops';
					}
					?>
					<div class="<?php echo esc_attr( $class ); ?>">
						<span class="tt-month__num"><?php echo esc_html( (string) $d ); ?></span>
						<?php if ( $stops ) : ?>
							<?php
							$cap     = 6;
							$visible = array_slice( $stops, 0, $cap );
							$extra   = array_slice( $stops, $cap );
							?>
							<div class="tt-month__stops">
								<?php foreach ( $visible as $s ) : ?>
									<a class="tt-month__stop" href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['title'] ); ?></a>
								<?php endforeach; ?>
							</div>
							<?php if ( $extra ) : ?>
								<details class="tt-month__more">
									<summary class="tt-month__more-toggle"><?php echo esc_html( sprintf( /* translators: %d: number of additional cities. */ __( '+%d more', 'tur-takvimi' ), count( $extra ) ) ); ?></summary>
									<div class="tt-month__stops">
										<?php foreach ( $extra as $s ) : ?>
											<a class="tt-month__stop" href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['title'] ); ?></a>
										<?php endforeach; ?>
									</div>
								</details>
							<?php endif; ?>
							<details class="tt-month__add">
								<summary class="tt-month__addbtn" title="<?php esc_attr_e( 'Add to calendar', 'tur-takvimi' ); ?>" aria-label="<?php esc_attr_e( 'Add to calendar', 'tur-takvimi' ); ?>">＋</summary>
								<div class="tt-month__addmenu">
									<a class="tt-month__addmenu-item" href="<?php echo esc_url( $this->feed_url( $country, $loc_id, false, $ymd ) ); ?>"><?php esc_html_e( 'Apple / Outlook (.ics)', 'tur-takvimi' ); ?></a>
									<a class="tt-month__addmenu-item" href="<?php echo esc_url( $this->google_url( $date, $stops ) ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'Google Calendar', 'tur-takvimi' ); ?></a>
								</div>
							</details>
						<?php endif; ?>
					</div>
				<?php endfor; ?>

				<?php
				// Pad to a fixed 6 rows (42 cells) so the height never shifts
				// between months.
				$trailing = 42 - $lead - $days_in_month;
				for ( $i = 0; $i < $trailing; $i++ ) :
					?>
					<span class="tt-month__cell tt-month__cell--empty"></span>
				<?php endfor; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* --------------------------------------------------------------------- *
	 * iCalendar feed
	 * --------------------------------------------------------------------- */

	/**
	 * Stream an .ics feed when ?tt_ics is present, then exit.
	 */
	public function maybe_output_ics(): void {
		if ( ! isset( $_GET['tt_ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$location = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0;
		$route    = isset( $_GET['route'] ) ? absint( $_GET['route'] ) : 0;
		$country  = isset( $_GET['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['country'] ) ) ) : '';
		$date     = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$download = isset( $_GET['download'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ics = $this->build_ics( $country, $location, $route, $date );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		$fname = 'tur-takvimi' . ( $location ? '-' . $location : '' ) . '.ics';
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . $fname . '"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Build an iCalendar document for upcoming deliveries.
	 *
	 * @param string $country  Optional ISO-2 filter.
	 * @param int    $location Optional location scope.
	 * @param int    $route    Optional route scope.
	 * @param string $date     Optional single day (Y-m-d) to scope to.
	 * @return string
	 */
	private function build_ics( string $country, int $location, int $route, string $date = '' ): string {
		// Cache the generated feed briefly: subscriptions poll repeatedly and the
		// schedule changes rarely. Keyed by scope, not by download vs. inline
		// (those only differ in the HTTP header, not the body).
		$cache_key = 'tt_ics_' . md5( implode( '|', array( $country, $location, $route, $date ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$start = $date;
			$end   = $date;
		} else {
			$start = current_time( 'Y-m-d' );
			try {
				$end = ( new \DateTimeImmutable( $start ) )->modify( '+' . Schedule::HORIZON_WEEKS . ' weeks' )->format( 'Y-m-d' );
			} catch ( \Exception $e ) {
				$end = $start;
			}
		}

		$schedule = new Schedule();
		$tours    = $schedule->get_tours_between( $start, $end, $country );

		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$stamp = gmdate( 'Ymd\THis\Z' );
		$brand = (string) Settings::get( 'brand_name', 'Tur Takvimi' );

		$calname = $brand . ' — ' . __( 'Delivery calendar', 'tur-takvimi' );
		if ( $location ) {
			$calname = $brand . ' — ' . get_the_title( $location );
		}

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Tur Takvimi//Delivery Calendar//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::ics_escape( $calname ),
			'X-WR-TIMEZONE:' . self::ics_escape( (string) wp_timezone_string() ),
		);

		// A location-scoped feed expands to per-address, time-of-day events (a
		// customer cares about when delivery reaches their address). The broad
		// feeds stay as lightweight all-day, per-city events.
		$events  = $location
			? self::vevents_per_address( $tours, $location, $route, $host, $stamp )
			: self::vevents( $tours, $location, $route, $host, $stamp );
		$lines   = array_merge( $lines, $events );
		$lines[] = 'END:VCALENDAR';

		$folded = array_map( array( self::class, 'ics_fold' ), $lines );
		$ics    = implode( "\r\n", $folded ) . "\r\n";

		set_transient( $cache_key, $ics, HOUR_IN_SECONDS );
		return $ics;
	}

	/**
	 * Build VEVENT lines for the given tours — one all-day event per delivered
	 * city per date.
	 *
	 * @param array  $tours    Tours grouped per (date, route).
	 * @param int    $location Optional location filter.
	 * @param int    $route    Optional route filter.
	 * @param string $host     Host for the UID domain.
	 * @param string $stamp    DTSTAMP value.
	 * @return string[]
	 */
	private static function vevents( array $tours, int $location, int $route, string $host, string $stamp ): array {
		$lines = array();
		foreach ( $tours as $tour ) {
			if ( $route && (int) $tour['route_id'] !== $route ) {
				continue;
			}
			foreach ( $tour['locations'] as $loc ) {
				if ( $location && (int) $loc['id'] !== $location ) {
					continue;
				}
				try {
					$d = new \DateTimeImmutable( $tour['tour_date'] );
				} catch ( \Exception $e ) {
					continue;
				}
				$dstart  = $d->format( 'Ymd' );
				$dend    = $d->modify( '+1 day' )->format( 'Ymd' );
				$summary = sprintf( /* translators: %s: city name. */ __( 'Delivery — %s', 'tur-takvimi' ), $loc['title'] );
				$desc    = ! empty( $tour['route'] ) ? sprintf( /* translators: %s: route name. */ __( 'Route: %s', 'tur-takvimi' ), $tour['route'] ) : '';

				$lines[] = 'BEGIN:VEVENT';
				$lines[] = 'UID:tt-' . (int) $loc['id'] . '-' . $dstart . '@' . $host;
				$lines[] = 'DTSTAMP:' . $stamp;
				$lines[] = 'DTSTART;VALUE=DATE:' . $dstart;
				$lines[] = 'DTEND;VALUE=DATE:' . $dend;
				$lines[] = 'SUMMARY:' . self::ics_escape( $summary );
				if ( '' !== $desc ) {
					$lines[] = 'DESCRIPTION:' . self::ics_escape( $desc );
				}
				if ( ! empty( $loc['url'] ) ) {
					$lines[] = 'URL:' . self::ics_escape( (string) $loc['url'] );
				}
				$lines[] = 'LOCATION:' . self::ics_escape( (string) $loc['title'] );
				$lines[] = 'END:VEVENT';
			}
		}
		return $lines;
	}

	/**
	 * Build per-address, time-of-day VEVENTs for a single location: each address
	 * that is actually due on a date (by its own frequency, from the route's
	 * anchor) becomes its own event, timed at the address's delivery hour.
	 *
	 * Computed on demand — no extra rows are stored — and only ever for one
	 * location at a time, so the work is small.
	 *
	 * @param array  $tours    Tours grouped per (date, route).
	 * @param int    $location Location scope.
	 * @param int    $route    Optional route filter.
	 * @param string $host     Host for the UID domain.
	 * @param string $stamp    DTSTAMP value.
	 * @return string[]
	 */
	private static function vevents_per_address( array $tours, int $location, int $route, string $host, string $stamp ): array {
		$addresses = json_decode( (string) get_post_meta( $location, '_tt_addresses', true ), true );
		if ( ! is_array( $addresses ) || ! $addresses ) {
			// No address detail: fall back to the city-level all-day event.
			return self::vevents( $tours, $location, $route, $host, $stamp );
		}

		$default_freq = max( 1, (int) Settings::get( 'default_frequency_weeks', 4 ) );
		$city         = (string) get_the_title( $location );
		$url          = (string) get_permalink( $location );

		$anchors = array(); // route_id => \DateTimeImmutable|false.
		$seen    = array();
		$lines   = array();

		foreach ( $tours as $tour ) {
			if ( $route && (int) $tour['route_id'] !== $route ) {
				continue;
			}
			// Only tours that include this location.
			$in = false;
			foreach ( $tour['locations'] as $loc ) {
				if ( (int) $loc['id'] === $location ) {
					$in = true;
					break;
				}
			}
			if ( ! $in ) {
				continue;
			}

			$rid = (int) $tour['route_id'];
			if ( ! array_key_exists( $rid, $anchors ) ) {
				$a = (string) get_post_meta( $rid, '_tt_anchor_date', true );
				try {
					$anchors[ $rid ] = '' !== $a ? new \DateTimeImmutable( $a ) : false;
				} catch ( \Exception $e ) {
					$anchors[ $rid ] = false;
				}
			}
			$anchor = $anchors[ $rid ];

			try {
				$day = new \DateTimeImmutable( $tour['tour_date'] );
			} catch ( \Exception $e ) {
				continue;
			}
			$dstart = $day->format( 'Ymd' );

			foreach ( $addresses as $i => $a ) {
				$freq = ( isset( $a['frequency'] ) && '' !== $a['frequency'] ) ? (int) $a['frequency'] : $default_freq;
				if ( $freq <= 0 ) {
					continue; // On-demand stop: never scheduled.
				}

				// Is this address due on this date (anchor + k·freq weeks)?
				if ( $anchor instanceof \DateTimeImmutable ) {
					if ( $day < $anchor ) {
						continue;
					}
					if ( 0 !== (int) $anchor->diff( $day )->days % ( $freq * 7 ) ) {
						continue;
					}
				}

				$uid = 'tt-' . $location . '-a' . (int) $i . '-' . $dstart . '@' . $host;
				if ( isset( $seen[ $uid ] ) ) {
					continue;
				}
				$seen[ $uid ] = true;

				$street  = (string) ( $a['address'] ?? '' );
				$pc      = (string) ( $a['postcode'] ?? '' );
				$label   = '' !== $street ? $street : $city;
				$summary = sprintf( /* translators: %s: address or city. */ __( 'Delivery — %s', 'tur-takvimi' ), $label );
				$where   = trim( $street . ( '' !== $pc ? ', ' . $pc : '' ) . ' ' . $city );
				$desc    = ! empty( $tour['route'] ) ? sprintf( /* translators: %s: route name. */ __( 'Route: %s', 'tur-takvimi' ), $tour['route'] ) : $city;
				$time    = self::parse_time( (string) ( $a['time'] ?? '' ) );

				$lines[] = 'BEGIN:VEVENT';
				$lines[] = 'UID:' . $uid;
				$lines[] = 'DTSTAMP:' . $stamp;
				if ( $time ) {
					// Floating local time: shown in the delivery region's clock.
					$lines[] = 'DTSTART:' . $dstart . 'T' . $time['start'];
					$lines[] = 'DTEND:' . $dstart . 'T' . $time['end'];
				} else {
					$lines[] = 'DTSTART;VALUE=DATE:' . $dstart;
					$lines[] = 'DTEND;VALUE=DATE:' . $day->modify( '+1 day' )->format( 'Ymd' );
				}
				$lines[] = 'SUMMARY:' . self::ics_escape( $summary );
				$lines[] = 'DESCRIPTION:' . self::ics_escape( $desc );
				if ( '' !== $url ) {
					$lines[] = 'URL:' . self::ics_escape( $url );
				}
				$lines[] = 'LOCATION:' . self::ics_escape( $where );
				$lines[] = 'END:VEVENT';
			}
		}
		return $lines;
	}

	/**
	 * Parse a free-text delivery time ("09:00", "9.00", "09:00–12:00") into
	 * iCalendar HHMMSS start/end. Returns null when no time can be read (the
	 * caller then emits an all-day event).
	 *
	 * @param string $raw Stored time string.
	 * @return array{start:string,end:string}|null
	 */
	private static function parse_time( string $raw ): ?array {
		if ( ! preg_match_all( '/(\d{1,2})[:.hH](\d{2})/', $raw, $matches, PREG_SET_ORDER ) ) {
			return null;
		}
		$to_hms = static function ( $h, $m ) {
			$h = (int) $h;
			$m = (int) $m;
			return ( $h > 23 || $m > 59 ) ? null : sprintf( '%02d%02d00', $h, $m );
		};

		$start = $to_hms( $matches[0][1], $matches[0][2] );
		if ( null === $start ) {
			return null;
		}

		$end = isset( $matches[1] ) ? $to_hms( $matches[1][1], $matches[1][2] ) : null;
		if ( null === $end ) {
			// No explicit end: default to a one-hour window (clamped to the day).
			$h   = (int) substr( $start, 0, 2 );
			$end = $h >= 23 ? '235900' : sprintf( '%02d%02d00', $h + 1, (int) substr( $start, 2, 2 ) );
		}
		if ( $end <= $start ) {
			$end = '235900';
		}
		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * The first day of the month being displayed (from ?tt_month, else now).
	 *
	 * @return \DateTimeImmutable
	 */
	private function current_cursor(): \DateTimeImmutable {
		$param = isset( $_GET['tt_month'] ) ? sanitize_text_field( wp_unslash( $_GET['tt_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		try {
			if ( $param && preg_match( '/^\d{4}-\d{2}$/', $param ) ) {
				return new \DateTimeImmutable( $param . '-01' );
			}
			return new \DateTimeImmutable( current_time( 'Y-m-01' ) );
		} catch ( \Exception $e ) {
			return new \DateTimeImmutable( current_time( 'Y-m-01' ) );
		}
	}

	/**
	 * URL of the current page showing a given month.
	 *
	 * @param \DateTimeImmutable $month Month to link to.
	 * @return string
	 */
	private function month_url( \DateTimeImmutable $month ): string {
		return add_query_arg( 'tt_month', $month->format( 'Y-m' ) );
	}

	/**
	 * Build the .ics feed URL (webcal for subscribe, https for download).
	 *
	 * @param string $country   ISO-2 filter.
	 * @param int    $location  Location scope.
	 * @param bool   $download  Add the attachment hint (save as a file).
	 * @param string $date      Optional single day (Y-m-d) to scope to.
	 * @param bool   $subscribe Use the webcal:// scheme (live subscription).
	 * @return string
	 */
	private function feed_url( string $country, int $location, bool $download, string $date = '', bool $subscribe = false ): string {
		$args = array( 'tt_ics' => 1 );
		if ( '' !== $country ) {
			$args['country'] = $country;
		}
		if ( $location ) {
			$args['location'] = $location;
		}
		if ( '' !== $date ) {
			$args['date'] = $date;
		}
		if ( $download ) {
			$args['download'] = 1;
		}
		$url = add_query_arg( $args, home_url( '/' ) );

		// Subscribe links use the webcal scheme so calendar apps auto-update.
		if ( $subscribe ) {
			$url = preg_replace( '#^https?://#', 'webcal://', $url );
		}
		return $url;
	}

	/**
	 * "Add to Google Calendar" URL for one all-day delivery date.
	 *
	 * @param \DateTimeImmutable $date  Delivery date.
	 * @param array              $stops Cities delivered that day.
	 * @return string
	 */
	private function google_url( \DateTimeImmutable $date, array $stops ): string {
		$cities  = wp_list_pluck( $stops, 'title' );
		$first   = $cities[0] ?? '';
		$summary = sprintf( /* translators: %s: city name. */ __( 'Delivery — %s', 'tur-takvimi' ), $first );
		if ( count( $cities ) > 1 ) {
			$summary = sprintf(
				/* translators: 1: first city, 2: number of additional cities. */
				__( 'Delivery — %1$s +%2$d more', 'tur-takvimi' ),
				$first,
				count( $cities ) - 1
			);
		}

		return add_query_arg(
			array(
				'action' => 'TEMPLATE',
				'text'   => rawurlencode( $summary ),
				'dates'  => $date->format( 'Ymd' ) . '/' . $date->modify( '+1 day' )->format( 'Ymd' ),
				'details' => rawurlencode( implode( ', ', $cities ) ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Localized weekday labels starting Monday.
	 *
	 * @return string[]
	 */
	private function weekday_labels(): array {
		$labels = array();
		// 2024-01-01 is a Monday; walk seven days for locale-correct short names.
		$base = strtotime( '2024-01-01 12:00:00' );
		for ( $i = 0; $i < 7; $i++ ) {
			$labels[] = wp_date( 'D', $base + ( $i * DAY_IN_SECONDS ) );
		}
		return $labels;
	}

	/**
	 * Escape a value for an iCalendar text property.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function ics_escape( string $value ): string {
		$value = str_replace( '\\', '\\\\', $value ); // Backslash first.
		$value = str_replace( array( ';', ',' ), array( '\\;', '\\,' ), $value );
		return str_replace( array( "\r\n", "\n", "\r" ), '\\n', $value );
	}

	/**
	 * Fold an iCalendar content line to <=75 octets (RFC 5545), splitting on
	 * character boundaries so multibyte text is never cut mid-codepoint.
	 *
	 * @param string $line Content line.
	 * @return string
	 */
	private static function ics_fold( string $line ): string {
		if ( strlen( $line ) <= 74 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		$len   = mb_strlen( $line, 'UTF-8' );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = mb_substr( $line, $i, 1, 'UTF-8' );
			if ( strlen( $chunk . $ch ) > 73 ) {
				$out  .= ( '' === $out ? '' : "\r\n " ) . $chunk;
				$chunk = $ch;
			} else {
				$chunk .= $ch;
			}
		}
		return $out . ( '' === $out ? '' : "\r\n " ) . $chunk;
	}
}
