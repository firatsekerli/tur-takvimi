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

		$country = strtoupper( (string) $atts['country'] );
		$loc_id  = (int) $atts['id'];
		// On a single city page, default to that city's schedule.
		if ( ! $loc_id && is_singular( Post_Types::LOCATION ) ) {
			$loc_id = (int) get_queried_object_id();
		}
		$months = max( 1, min( 3, (int) $atts['months'] ) );

		// Which month to show first (Y-m), navigable via ?tt_month.
		$cursor = $this->current_cursor();

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

		$today     = current_time( 'Y-m-d' );
		$subscribe = $this->feed_url( $country, $loc_id, false );
		$download  = $this->feed_url( $country, $loc_id, true );

		ob_start();
		?>
		<section class="tt-month" aria-label="<?php esc_attr_e( 'Delivery calendar', 'tur-takvimi' ); ?>">
			<div class="tt-month__bar">
				<div class="tt-month__nav">
					<a class="tt-month__navbtn" href="<?php echo esc_url( $this->month_url( $cursor->modify( '-1 month' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'tur-takvimi' ); ?>" rel="nofollow">‹</a>
					<a class="tt-month__navbtn" href="<?php echo esc_url( $this->month_url( $cursor->modify( '+1 month' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'tur-takvimi' ); ?>" rel="nofollow">›</a>
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

			<?php
			$weekdays = $this->weekday_labels();
			$grid     = $cursor;
			for ( $m = 0; $m < $months; $m++ ) :
				echo $this->render_grid( $grid, $by_date, $today, $weekdays ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$grid = $grid->modify( '+1 month' );
			endfor;
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a single month grid.
	 *
	 * @param \DateTimeImmutable $first    First day of the month.
	 * @param array              $by_date  Deliveries indexed by Y-m-d.
	 * @param string             $today    Today's Y-m-d.
	 * @param string[]           $weekdays Localized Mon..Sun labels.
	 * @return string
	 */
	private function render_grid( \DateTimeImmutable $first, array $by_date, string $today, array $weekdays ): string {
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
							<div class="tt-month__stops">
								<?php foreach ( $stops as $s ) : ?>
									<a class="tt-month__stop" href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['title'] ); ?></a>
								<?php endforeach; ?>
							</div>
							<a class="tt-month__gcal" href="<?php echo esc_url( $this->google_url( $date, $stops ) ); ?>" target="_blank" rel="noopener nofollow" title="<?php esc_attr_e( 'Add to Google Calendar', 'tur-takvimi' ); ?>">＋</a>
						<?php endif; ?>
					</div>
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
		$download = isset( $_GET['download'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ics = $this->build_ics( $country, $location, $route );

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
	 * @return string
	 */
	private function build_ics( string $country, int $location, int $route ): string {
		$start = current_time( 'Y-m-d' );
		try {
			$end = ( new \DateTimeImmutable( $start ) )->modify( '+' . Schedule::HORIZON_WEEKS . ' weeks' )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			$end = $start;
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

		$lines[] = 'END:VCALENDAR';

		$folded = array_map( array( self::class, 'ics_fold' ), $lines );
		return implode( "\r\n", $folded ) . "\r\n";
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
	 * @param string $country  ISO-2 filter.
	 * @param int    $location Location scope.
	 * @param bool   $download Force download (attachment) vs. subscribe.
	 * @return string
	 */
	private function feed_url( string $country, int $location, bool $download ): string {
		$args = array( 'tt_ics' => 1 );
		if ( '' !== $country ) {
			$args['country'] = $country;
		}
		if ( $location ) {
			$args['location'] = $location;
		}
		if ( $download ) {
			$args['download'] = 1;
		}
		$url = add_query_arg( $args, home_url( '/' ) );

		// Subscribe links use the webcal scheme so calendar apps auto-update.
		if ( ! $download ) {
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
