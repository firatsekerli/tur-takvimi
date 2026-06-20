<?php
/**
 * Delivery-regions explorer: an interactive map + filterable stop list for the
 * homepage / "Teslimat Bölgeleri" page.
 *
 * The stop list is server-rendered (SEO + internal links to city pages); the
 * map and the filters (country, region/route, week, search) are progressive
 * enhancement on top, filtering the same markup entirely client-side.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * The [tur_takvimi_map] explorer.
 */
class Map_Explorer {

	/**
	 * Hook the shortcode and assets.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'tur_takvimi_map', array( $this, 'render' ) );
	}

	/**
	 * Register Leaflet (shared handle) and the explorer script.
	 */
	public function register_assets(): void {
		if ( ! wp_script_is( 'leaflet', 'registered' ) ) {
			$css = apply_filters( 'tur_takvimi_leaflet_css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' );
			$js  = apply_filters( 'tur_takvimi_leaflet_js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' );
			wp_register_style( 'leaflet', $css, array(), '1.9.4' );
			wp_register_script( 'leaflet', $js, array(), '1.9.4', true );
		}

		wp_register_script(
			'tur-takvimi-explorer',
			TURTAKVIMI_URL . 'assets/js/frontend-explorer.js',
			array( 'leaflet' ),
			Plugin::asset_ver( 'assets/js/frontend-explorer.js' ),
			true
		);
		wp_localize_script(
			'tur-takvimi-explorer',
			'TurTakvimiMap',
			array(
				'i18n' => array(
					/* translators: %d: number of delivery stops. */
					'stops' => __( '%d stops', 'tur-takvimi' ),
				),
			)
		);
	}

	/**
	 * Render the explorer.
	 *
	 * @param array $atts Shortcode attributes (country to hard-scope; height).
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'country' => '',
				'height'  => '600',
			),
			$atts,
			'tur_takvimi_map'
		);

		wp_enqueue_style( 'tur-takvimi' );
		wp_enqueue_style( 'leaflet' );
		wp_enqueue_script( 'leaflet' );
		wp_enqueue_script( 'tur-takvimi-explorer' );

		$pinned = strtoupper( (string) $atts['country'] );
		$stops  = $this->dataset( $pinned );

		// Distinct countries and regions actually present in the data.
		$countries        = array();
		$region_names     = array();
		$region_countries = array();
		foreach ( $stops as $s ) {
			$countries[ $s['country'] ] = true;
			foreach ( $s['regions'] as $slug => $name ) {
				$region_names[ $slug ]                  = $name;
				$region_countries[ $slug ][ $s['country'] ] = true;
			}
		}
		$countries = array_values( array_intersect( Country::supported(), array_keys( $countries ) ) );
		asort( $region_names );

		$show_country = '' === $pinned && count( $countries ) > 1;
		$height       = max( 320, min( 900, (int) $atts['height'] ) );

		// Compact points for the map (only stops we could locate).
		$points = array();
		foreach ( $stops as $s ) {
			if ( null !== $s['lat'] && null !== $s['lng'] ) {
				$points[] = array(
					'id'    => $s['id'],
					'lat'   => $s['lat'],
					'lng'   => $s['lng'],
					'label' => $s['title'],
				);
			}
		}

		ob_start();
		?>
		<div class="tt-explorer" data-tt-explorer style="--tt-map-h:<?php echo (int) $height; ?>px">
			<div class="tt-explorer__filters">
				<?php if ( $show_country ) : ?>
					<div class="tt-explorer__filter">
						<label class="tt-explorer__label" for="tt-explorer-country"><?php esc_html_e( 'Country', 'tur-takvimi' ); ?></label>
						<select id="tt-explorer-country" class="tt-explorer__select" data-tt-country>
							<option value=""><?php esc_html_e( 'All countries', 'tur-takvimi' ); ?></option>
							<?php foreach ( $countries as $code ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( Country::name( $code ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( $region_names ) : ?>
					<div class="tt-explorer__filter">
						<label class="tt-explorer__label" for="tt-explorer-region"><?php esc_html_e( 'Region / Route', 'tur-takvimi' ); ?></label>
						<select id="tt-explorer-region" class="tt-explorer__select" data-tt-region>
							<option value=""><?php esc_html_e( 'All regions', 'tur-takvimi' ); ?></option>
							<?php foreach ( $region_names as $slug => $name ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" data-country="<?php echo esc_attr( implode( ',', array_keys( $region_countries[ $slug ] ) ) ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<div class="tt-explorer__filter">
					<label class="tt-explorer__label" for="tt-explorer-week"><?php esc_html_e( 'Week', 'tur-takvimi' ); ?></label>
					<select id="tt-explorer-week" class="tt-explorer__select" data-tt-week>
						<option value=""><?php esc_html_e( 'All weeks', 'tur-takvimi' ); ?></option>
						<option value="0"><?php esc_html_e( 'This week', 'tur-takvimi' ); ?></option>
						<option value="1"><?php esc_html_e( 'Next week', 'tur-takvimi' ); ?></option>
						<option value="2"><?php esc_html_e( '+2 weeks', 'tur-takvimi' ); ?></option>
					</select>
				</div>

				<div class="tt-explorer__filter tt-explorer__filter--search">
					<label class="tt-explorer__label" for="tt-explorer-search"><?php esc_html_e( 'Search', 'tur-takvimi' ); ?></label>
					<div class="tt-explorer__search">
						<span class="tt-explorer__search-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
						</span>
						<input id="tt-explorer-search" type="search" data-tt-stop-search placeholder="<?php esc_attr_e( 'Search a city…', 'tur-takvimi' ); ?>" aria-label="<?php esc_attr_e( 'Search a city', 'tur-takvimi' ); ?>">
					</div>
				</div>
			</div>

			<div class="tt-explorer__body">
				<div class="tt-explorer__map" data-tt-explorer-map style="height:<?php echo (int) $height; ?>px"></div>
				<aside class="tt-explorer__panel">
					<header class="tt-explorer__panel-head">
						<strong><?php esc_html_e( 'Stops', 'tur-takvimi' ); ?></strong>
						<span class="tt-explorer__count" data-tt-stop-count>
							<?php
							/* translators: %d: number of delivery stops. */
							echo esc_html( sprintf( __( '%d stops', 'tur-takvimi' ), count( $stops ) ) );
							?>
						</span>
					</header>
					<?php if ( empty( $stops ) ) : ?>
						<p class="tt-explorer__empty"><?php esc_html_e( 'No delivery stops yet.', 'tur-takvimi' ); ?></p>
					<?php else : ?>
						<ul class="tt-explorer__stops">
							<?php foreach ( $stops as $s ) : ?>
								<li>
									<a class="tt-explorer__stop" href="<?php echo esc_url( $s['url'] ); ?>"
										data-tt-stop
										data-id="<?php echo (int) $s['id']; ?>"
										data-country="<?php echo esc_attr( $s['country'] ); ?>"
										data-regions="<?php echo esc_attr( implode( ',', array_keys( $s['regions'] ) ) ); ?>"
										data-week="<?php echo esc_attr( (string) $s['bucket'] ); ?>"
										data-title="<?php echo esc_attr( $s['search'] ); ?>">
										<span class="tt-explorer__dot" aria-hidden="true"></span>
										<span class="tt-explorer__stop-body">
											<span class="tt-explorer__stop-top">
												<span class="tt-explorer__stop-name"><?php echo esc_html( $s['title'] ); ?></span>
												<?php if ( $s['next_label'] ) : ?>
													<span class="tt-explorer__stop-date"><?php echo esc_html( $s['next_label'] ); ?></span>
												<?php endif; ?>
											</span>
											<?php if ( $s['region_label'] ) : ?>
												<span class="tt-explorer__stop-region"><?php echo esc_html( $s['region_label'] ); ?></span>
											<?php endif; ?>
										</span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</aside>
			</div>

			<script type="application/json" data-tt-explorer-points><?php echo wp_json_encode( $points, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the stop dataset (one entry per published city).
	 *
	 * @param string $country Optional ISO-2 hard scope.
	 * @return array<int,array<string,mixed>>
	 */
	private function dataset( string $country = '' ): array {
		$schedule  = new Schedule();
		$locations = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$today      = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
		$week_start = $today->modify( '-' . ( (int) $today->format( 'N' ) - 1 ) . ' days' );

		$stops = array();
		foreach ( $locations as $id ) {
			$id   = (int) $id;
			$code = Country::of_post( $id );
			if ( '' !== $country && $code !== $country ) {
				continue;
			}

			list( $lat, $lng ) = $this->coords( $id );

			$regions = array();
			$terms   = get_the_terms( $id, Post_Types::REGION );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$regions[ $term->slug ] = $term->name;
				}
			}

			$next       = $schedule->next_tour_for_location( $id );
			$bucket     = '';
			$next_label = '';
			if ( $next ) {
				$days   = (int) $week_start->diff( new \DateTimeImmutable( $next ) )->format( '%r%a' );
				$bucket = max( 0, intdiv( $days, 7 ) );
				// Compact "day month short-weekday" badge (e.g. 24 Haziran Sal).
				$next_label = wp_date( 'j F D', strtotime( $next . ' 12:00:00' ) );
			}

			$title = get_the_title( $id );
			$stops[] = array(
				'id'           => $id,
				'title'        => $title,
				'url'          => (string) get_permalink( $id ),
				'lat'          => $lat,
				'lng'          => $lng,
				'country'      => $code,
				'regions'      => $regions,
				'region_label' => $regions ? reset( $regions ) : '',
				'bucket'       => $bucket,
				'next'         => (string) $next,
				'next_label'   => $next_label,
				'search'       => function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title ),
			);
		}

		// Soonest visits first, then alphabetical; undated stops last.
		usort(
			$stops,
			static function ( $a, $b ) {
				$an = $a['next'] ?: '9999-12-31';
				$bn = $b['next'] ?: '9999-12-31';
				return $an === $bn ? strcmp( $a['title'], $b['title'] ) : strcmp( $an, $bn );
			}
		);

		return $stops;
	}

	/**
	 * City coordinates: the centroid, else the first geocoded address.
	 *
	 * @param int $id Location ID.
	 * @return array{0:?float,1:?float}
	 */
	private function coords( int $id ): array {
		$lat = get_post_meta( $id, '_tt_lat', true );
		$lng = get_post_meta( $id, '_tt_lng', true );
		if ( '' !== $lat && '' !== $lng ) {
			return array( (float) $lat, (float) $lng );
		}

		$addresses = json_decode( (string) get_post_meta( $id, '_tt_addresses', true ), true );
		if ( is_array( $addresses ) ) {
			foreach ( $addresses as $a ) {
				if ( isset( $a['lat'], $a['lng'] ) && is_numeric( $a['lat'] ) && is_numeric( $a['lng'] ) ) {
					return array( (float) $a['lat'], (float) $a['lng'] );
				}
			}
		}
		return array( null, null );
	}
}
