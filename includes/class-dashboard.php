<?php
/**
 * Dashboard overview widgets.
 *
 * Three client-friendly panels on the WordPress dashboard: counts (cities,
 * routes, regions, countries, stops), the next delivery dates, and a small
 * visits-per-week bar chart. Self-contained styling — no chart libraries.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the dashboard widgets.
 */
class Dashboard {

	/**
	 * Shared dataset for all widgets (computed once per page).
	 *
	 * @var array<string,mixed>|null
	 */
	private $data = null;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widgets' ) );
	}

	/**
	 * Register the widgets.
	 */
	public function add_widgets(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$brand = (string) Settings::get( 'brand_name', 'Tur Takvimi' );

		wp_add_dashboard_widget( 'tur_takvimi_overview', $brand . ' — ' . __( 'Overview', 'tur-takvimi' ), array( $this, 'render_stats' ) );
		wp_add_dashboard_widget( 'tur_takvimi_upcoming', $brand . ' — ' . __( 'Next deliveries', 'tur-takvimi' ), array( $this, 'render_upcoming' ) );
		wp_add_dashboard_widget( 'tur_takvimi_weeks', $brand . ' — ' . __( 'Visits per week (next 8 weeks)', 'tur-takvimi' ), array( $this, 'render_chart' ) );
	}

	/**
	 * Print the shared widget styles once per page.
	 */
	private function styles(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		$primary = (string) Settings::get( 'primary_color', '#e3242b' );
		$accent  = (string) Settings::get( 'accent_color', '#16a34a' );
		?>
		<style>
			.tt-dash__grid { display: grid; grid-template-columns: repeat( auto-fit, minmax( 88px, 1fr ) ); gap: 8px; margin: 4px 0 10px; }
			.tt-dash__stat { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 8px; padding: 10px 6px; text-align: center; }
			.tt-dash__num { display: block; font-size: 1.5em; font-weight: 700; line-height: 1.2; color: <?php echo esc_html( $primary ); ?>; }
			.tt-dash__lbl { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #646970; margin-top: 2px; }
			.tt-dash__next { margin: 0; }
			.tt-dash__next li { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; border-bottom: 1px solid #f0f0f1; margin: 0; }
			.tt-dash__next li:last-child { border-bottom: 0; }
			.tt-dash__date { font-weight: 600; }
			.tt-dash__count { color: #646970; white-space: nowrap; }
			.tt-dash__bars { display: flex; align-items: flex-end; gap: 6px; height: 96px; margin-top: 6px; }
			.tt-dash__bar { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; }
			.tt-dash__bar i { display: block; width: 100%; min-height: 2px; border-radius: 3px 3px 0 0; background: <?php echo esc_html( $accent ); ?>; }
			.tt-dash__bar span { font-size: 10px; color: #646970; margin-top: 3px; white-space: nowrap; }
			.tt-dash__warn { margin-top: 10px; padding: 8px 10px; border-left: 4px solid #dba617; background: #fcf9e8; }
			.tt-dash__links { margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f1; }
		</style>
		<?php
	}

	/**
	 * Widget: stat tiles + quick links.
	 */
	public function render_stats(): void {
		$data = $this->collect();
		$this->styles();

		$tiles = array(
			array( __( 'Locations', 'tur-takvimi' ), $data['cities'] ),
			array( __( 'Routes', 'tur-takvimi' ), $data['routes'] ),
			array( __( 'Regions', 'tur-takvimi' ), $data['regions'] ),
			array( __( 'Countries', 'tur-takvimi' ), $data['countries'] ),
			array( __( 'Stops', 'tur-takvimi' ), $data['stops'] ),
		);
		?>
		<div class="tt-dash__grid">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="tt-dash__stat">
					<span class="tt-dash__num"><?php echo esc_html( number_format_i18n( (int) $tile[1] ) ); ?></span>
					<span class="tt-dash__lbl"><?php echo esc_html( $tile[0] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $data['unpinned'] > 0 ) : ?>
			<div class="tt-dash__warn">
				<?php
				/* translators: %d: number of addresses still needing a map pin. */
				echo esc_html( sprintf( __( '%d addresses still need a map pin.', 'tur-takvimi' ), (int) $data['unpinned'] ) );
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tur-takvimi-import' ) ); ?>"><?php esc_html_e( 'Geocode pins now', 'tur-takvimi' ); ?></a>
			</div>
		<?php endif; ?>

		<p class="tt-dash__links">
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::LOCATION ) ); ?>"><?php esc_html_e( 'Locations', 'tur-takvimi' ); ?></a>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::ROUTE ) ); ?>"><?php esc_html_e( 'Routes', 'tur-takvimi' ); ?></a>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=tur-takvimi-import' ) ); ?>"><?php esc_html_e( 'Import', 'tur-takvimi' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Widget: the next delivery dates.
	 */
	public function render_upcoming(): void {
		$data = $this->collect();
		$this->styles();

		if ( ! $data['next'] ) {
			echo '<p>' . esc_html__( 'No tours scheduled for this period yet.', 'tur-takvimi' ) . '</p>';
			return;
		}
		?>
		<ul class="tt-dash__next">
			<?php foreach ( $data['next'] as $day ) : ?>
				<li>
					<span class="tt-dash__date"><?php echo esc_html( $day['label'] ); ?></span>
					<span class="tt-dash__count">
						<?php
						/* translators: %d: number of cities visited that day. */
						echo esc_html( sprintf( __( '%d cities', 'tur-takvimi' ), (int) $day['cities'] ) );
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Widget: visits-per-week bar chart.
	 */
	public function render_chart(): void {
		$data = $this->collect();
		$this->styles();

		if ( ! $data['weeks'] ) {
			echo '<p>' . esc_html__( 'No tours scheduled for this period yet.', 'tur-takvimi' ) . '</p>';
			return;
		}
		$max = max( 1, max( wp_list_pluck( $data['weeks'], 'count' ) ) );
		?>
		<div class="tt-dash__bars">
			<?php
			foreach ( $data['weeks'] as $week ) :
				$h = max( 3, (int) round( $week['count'] * 100 / $max ) );
				?>
				<div class="tt-dash__bar" title="<?php echo esc_attr( sprintf( /* translators: %d: number of city visits. */ __( '%d cities', 'tur-takvimi' ), (int) $week['count'] ) ); ?>">
					<i style="height:<?php echo (int) $h; ?>%"></i>
					<span><?php echo esc_html( $week['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Gather the shared dataset (computed once per page, cached briefly; the
	 * stop counts scan every city's address JSON).
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		if ( null !== $this->data ) {
			return $this->data;
		}
		$cached = get_transient( 'tur_takvimi_dash' );
		if ( is_array( $cached ) ) {
			$this->data = $cached;
			return $cached;
		}

		$city_ids = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		update_postmeta_cache( array_map( 'intval', $city_ids ) );

		$stops     = 0;
		$unpinned  = 0;
		$countries = array();
		foreach ( $city_ids as $id ) {
			$countries[ Country::of_post( (int) $id ) ] = true;
			$addresses = json_decode( (string) get_post_meta( (int) $id, '_tt_addresses', true ), true );
			if ( ! is_array( $addresses ) ) {
				continue;
			}
			foreach ( $addresses as $a ) {
				if ( ! is_array( $a ) ) {
					continue;
				}
				++$stops;
				if ( ! isset( $a['lat'], $a['lng'] ) && empty( $a['dismissed'] ) ) {
					++$unpinned;
				}
			}
		}

		$region_count = wp_count_terms( array( 'taxonomy' => Post_Types::REGION, 'hide_empty' => true ) );

		// Upcoming schedule: the next distinct dates and per-week visit totals.
		$schedule = new Schedule();
		$today    = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
		$tours    = $schedule->get_tours_between( $today->format( 'Y-m-d' ), $today->modify( '+8 weeks' )->format( 'Y-m-d' ) );

		$by_date = array();
		$weeks   = array();
		$monday  = $today->modify( '-' . ( (int) $today->format( 'N' ) - 1 ) . ' days' );
		foreach ( $tours as $tour ) {
			$count = count( $tour['locations'] );

			$by_date[ $tour['tour_date'] ] = ( $by_date[ $tour['tour_date'] ] ?? 0 ) + $count;

			$bucket = intdiv( (int) $monday->diff( new \DateTimeImmutable( $tour['tour_date'] ) )->days, 7 );
			if ( $bucket >= 0 && $bucket < 8 ) {
				$weeks[ $bucket ] = ( $weeks[ $bucket ] ?? 0 ) + $count;
			}
		}
		ksort( $by_date );

		$next = array();
		foreach ( array_slice( $by_date, 0, 6, true ) as $date => $count ) {
			$next[] = array(
				'label'  => Rest_Api::format_day( $date ),
				'cities' => $count,
			);
		}

		$week_bars = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$week_bars[] = array(
				'label' => wp_date( 'j M', $monday->modify( '+' . $i . ' weeks' )->getTimestamp() ),
				'count' => (int) ( $weeks[ $i ] ?? 0 ),
			);
		}

		$data = array(
			'cities'    => count( $city_ids ),
			'routes'    => (int) ( wp_count_posts( Post_Types::ROUTE )->publish ?? 0 ),
			'regions'   => is_wp_error( $region_count ) ? 0 : (int) $region_count,
			'countries' => count( $countries ),
			'stops'     => $stops,
			'unpinned'  => $unpinned,
			'next'      => $next,
			'weeks'     => $week_bars,
		);

		set_transient( 'tur_takvimi_dash', $data, 10 * MINUTE_IN_SECONDS );
		$this->data = $data;
		return $data;
	}
}
