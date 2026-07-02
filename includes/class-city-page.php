<?php
/**
 * City (location) SEO pages — P2.
 *
 * Breakdance owns the visual template; this class ships the builder-agnostic
 * parts so a `tt_location` page works anywhere:
 *   - Shortcodes for the interactive blocks (map, stops, schedule) to drop into
 *     a Breakdance Template (or any builder/theme).
 *   - Auto-injected JSON-LD schema + SEO meta on single city pages (skipped when
 *     a dedicated SEO plugin is managing the document head).
 *   - A plain PHP fallback single template for non-Breakdance resellers.
 *
 * City pages are already exposed in WordPress core's XML sitemap because the
 * post type is public.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and decorates single tt_location pages.
 */
class City_Page {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		add_shortcode( 'tur_takvimi_city_map', array( $this, 'map' ) );
		add_shortcode( 'tur_takvimi_city_stops', array( $this, 'stops' ) );
		add_shortcode( 'tur_takvimi_city_schedule', array( $this, 'schedule' ) );
		add_shortcode( 'tur_takvimi_city', array( $this, 'panel' ) );

		add_action( 'wp_head', array( $this, 'head' ), 5 );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_filter( 'template_include', array( $this, 'fallback_template' ) );
	}

	/**
	 * Register Leaflet + the read-only city-map script.
	 */
	public function register_assets(): void {
		$css = apply_filters( 'tur_takvimi_leaflet_css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' );
		$js  = apply_filters( 'tur_takvimi_leaflet_js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' );

		wp_register_style( 'leaflet', $css, array(), '1.9.4' );
		wp_register_script( 'leaflet', $js, array(), '1.9.4', true );
		wp_register_script( 'tur-takvimi-map', TURTAKVIMI_URL . 'assets/js/frontend-map.js', array( 'leaflet' ), Plugin::asset_ver( 'assets/js/frontend-map.js' ), true );
	}

	/* --------------------------------------------------------------------- *
	 * Shortcodes
	 * --------------------------------------------------------------------- */

	/**
	 * Leaflet map of every delivery stop in the city.
	 *
	 * @param array $atts Shortcode attributes (id).
	 * @return string
	 */
	public function map( $atts ): string {
		$id = $this->resolve_id( $atts );
		if ( ! $id ) {
			return '';
		}

		$points = array();
		foreach ( $this->stop_list( $id ) as $a ) {
			if ( isset( $a['lat'], $a['lng'] ) && is_numeric( $a['lat'] ) && is_numeric( $a['lng'] ) ) {
				$points[] = array(
					'lat'   => (float) $a['lat'],
					'lng'   => (float) $a['lng'],
					'label' => trim( (string) ( $a['address'] ?? '' ) . ' ' . (string) ( $a['postcode'] ?? '' ) ),
				);
			}
		}
		if ( empty( $points ) ) {
			return '';
		}

		wp_enqueue_style( 'leaflet' );
		wp_enqueue_style( 'tur-takvimi' );
		wp_enqueue_script( 'tur-takvimi-map' );

		$opts = shortcode_atts( array( 'id' => 0, 'class' => '' ), $atts, 'tur_takvimi_city_map' );

		return sprintf(
			'<div class="tt-citymap%s" data-tt-citymap="%s" role="img" aria-label="%s"></div>',
			esc_attr( Shortcodes::extra_class( $opts['class'] ) ),
			esc_attr( wp_json_encode( array( 'points' => $points ) ) ),
			esc_attr( sprintf( /* translators: %s: city name. */ __( 'Delivery stops in %s', 'tur-takvimi' ), get_the_title( $id ) ) )
		);
	}

	/**
	 * Ordered list of the city's delivery addresses.
	 *
	 * @param array $atts Shortcode attributes (id).
	 * @return string
	 */
	public function stops( $atts ): string {
		$id = $this->resolve_id( $atts );
		if ( ! $id ) {
			return '';
		}
		$stops = $this->stop_list( $id );
		if ( empty( $stops ) ) {
			return '';
		}

		wp_enqueue_style( 'tur-takvimi' );
		wp_enqueue_script( 'tur-takvimi' );

		$opts    = shortcode_atts( array( 'id' => 0, 'heading' => null, 'class' => '', 'align' => '' ), $atts, 'tur_takvimi_city_stops' );
		$heading = Shortcodes::heading_attr( $opts['heading'], __( 'Delivery addresses', 'tur-takvimi' ) );
		$align   = Shortcodes::align_value( $opts['align'] );

		$default_freq = (int) Settings::get( 'default_frequency_weeks', 4 );

		// The city's WhatsApp group (region group → country group → channel).
		// Without one, the WhatsApp column is omitted entirely.
		$wa_url = Whatsapp::for_location( $id );

		ob_start();
		?>
		<section class="tt-stops<?php echo esc_attr( ( '' === $wa_url ? ' tt-stops--no-wa' : '' ) . Shortcodes::extra_class( $opts['class'] ) ); ?>" aria-label="<?php esc_attr_e( 'Delivery addresses', 'tur-takvimi' ); ?>"<?php echo '' !== $align ? ' data-tt-align="' . esc_attr( $align ) . '"' : ''; ?> data-tt-stops>
			<div class="tt-stops__bar">
				<?php if ( '' !== $heading ) : ?>
					<h2 class="tt-stops__heading"><?php echo esc_html( $heading ); ?></h2>
				<?php endif; ?>
				<?php if ( count( $stops ) > 1 ) : ?>
					<div class="tt-stops__filter">
						<span class="tt-stops__filter-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
						</span>
						<input type="search" data-tt-stops-filter placeholder="<?php esc_attr_e( 'Filter by postcode', 'tur-takvimi' ); ?>" aria-label="<?php esc_attr_e( 'Filter by postcode', 'tur-takvimi' ); ?>">
					</div>
				<?php endif; ?>
			</div>
			<div class="tt-stops__table" role="table">
				<div class="tt-stops__head" role="row">
					<span class="tt-stops__col tt-stops__col--pin" aria-hidden="true"></span>
					<span class="tt-stops__col tt-stops__col--addr" role="columnheader"><?php esc_html_e( 'Street address', 'tur-takvimi' ); ?></span>
					<span class="tt-stops__col tt-stops__col--pc" role="columnheader"><?php esc_html_e( 'Postcode', 'tur-takvimi' ); ?></span>
					<span class="tt-stops__col tt-stops__col--time" role="columnheader"><?php esc_html_e( 'Hour', 'tur-takvimi' ); ?></span>
					<?php if ( '' !== $wa_url ) : ?>
						<span class="tt-stops__col tt-stops__col--wa" aria-hidden="true"></span>
					<?php endif; ?>
					<span class="tt-stops__col tt-stops__col--cal" aria-hidden="true"></span>
				</div>
				<?php
				foreach ( $stops as $a ) :
					$addr = (string) ( $a['address'] ?? '' );
					$pc   = (string) ( $a['postcode'] ?? '' );
					$time = (string) ( $a['time'] ?? '' );
					$freq = array_key_exists( 'frequency', $a ) && '' !== $a['frequency'] ? (int) $a['frequency'] : $default_freq;

					// Subscribe to just this address's deliveries (webcal so the
					// calendar app keeps it up to date).
					$cal_url = add_query_arg(
						array(
							'tt_ics'  => 1,
							'location' => $id,
							'address' => (int) ( $a['_index'] ?? 0 ),
						),
						home_url( '/' )
					);
					$cal_url = (string) preg_replace( '#^https?://#', 'webcal://', $cal_url );
					/* translators: %s: street address. */
					$cal_label = $freq > 0 ? sprintf( __( 'Add %s deliveries to your calendar', 'tur-takvimi' ), $addr ) : '';
					?>
					<div class="tt-stops__row" role="row" data-tt-stop-row data-search="<?php echo esc_attr( strtolower( $pc . ' ' . $addr ) ); ?>">
						<span class="tt-stops__col tt-stops__col--pin" aria-hidden="true">📍</span>
						<span class="tt-stops__col tt-stops__col--addr" role="cell"><?php echo esc_html( $addr ); ?></span>
						<span class="tt-stops__col tt-stops__col--pc" role="cell"><?php echo esc_html( $pc ); ?></span>
						<span class="tt-stops__col tt-stops__col--time" role="cell">
							<?php echo '' !== $time ? esc_html( $time ) : '<span class="tt-stops__muted">—</span>'; ?>
						</span>
						<?php if ( '' !== $wa_url ) : ?>
							<span class="tt-stops__col tt-stops__col--wa" role="cell">
								<a class="tt-stops__wa" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener nofollow" title="<?php esc_attr_e( 'Join our WhatsApp group', 'tur-takvimi' ); ?>" aria-label="<?php esc_attr_e( 'Join our WhatsApp group', 'tur-takvimi' ); ?>">
									<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 1.82c2.16 0 4.18.84 5.71 2.37a8.05 8.05 0 0 1 2.37 5.72c0 4.46-3.63 8.09-8.09 8.09a8.1 8.1 0 0 1-4.13-1.13l-.3-.18-3.12.82.83-3.04-.19-.31a8.04 8.04 0 0 1-1.24-4.29c0-4.46 3.63-8.09 8.09-8.09Zm-4.7 4.85c-.16 0-.42.06-.64.3-.22.24-.84.82-.84 2 0 1.18.86 2.32.98 2.48.12.16 1.69 2.58 4.1 3.62.57.25 1.02.4 1.37.51.57.18 1.1.16 1.51.1.46-.07 1.42-.58 1.62-1.14.2-.56.2-1.04.14-1.14-.06-.1-.22-.16-.46-.28-.24-.12-1.42-.7-1.64-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-1.01-.37-1.93-1.19-.71-.64-1.19-1.42-1.33-1.66-.14-.24-.01-.37.11-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.2-.47-.4-.4-.54-.41h-.46Z"/></svg>
								</a>
							</span>
						<?php endif; ?>
						<span class="tt-stops__col tt-stops__col--cal" role="cell">
							<?php if ( $freq > 0 ) : ?>
								<a class="tt-stops__cal" href="<?php echo esc_url( $cal_url ); ?>" title="<?php echo esc_attr( $cal_label ); ?>" aria-label="<?php echo esc_attr( $cal_label ); ?>">
									<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg>
								</a>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="tt-stops__empty" data-tt-stops-empty style="display:none;"><?php esc_html_e( 'No stops match that postcode.', 'tur-takvimi' ); ?></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Next visit date and the route(s) serving the city.
	 *
	 * @param array $atts Shortcode attributes (id).
	 * @return string
	 */
	public function schedule( $atts ): string {
		$id = $this->resolve_id( $atts );
		if ( ! $id ) {
			return '';
		}

		wp_enqueue_style( 'tur-takvimi' );

		$opts     = shortcode_atts( array( 'id' => 0, 'class' => '' ), $atts, 'tur_takvimi_city_schedule' );
		$schedule = new Schedule();
		$dates    = $schedule->upcoming_tours_for_location( $id, 4 );
		$groups   = $this->route_groups( $schedule->routes_for_location( $id ) );

		ob_start();
		?>
		<section class="tt-citysched<?php echo esc_attr( Shortcodes::extra_class( $opts['class'] ) ); ?>" aria-label="<?php esc_attr_e( 'Delivery schedule', 'tur-takvimi' ); ?>">
			<div class="tt-citysched__next">
				<span class="tt-citysched__label"><?php esc_html_e( 'Next deliveries', 'tur-takvimi' ); ?></span>
				<?php if ( $dates ) : ?>
					<ol class="tt-citysched__dates">
						<?php foreach ( $dates as $i => $date ) : ?>
							<li class="tt-citysched__date<?php echo 0 === $i ? ' is-next' : ''; ?>"><?php echo esc_html( Rest_Api::format_day( $date ) ); ?></li>
						<?php endforeach; ?>
					</ol>
				<?php else : ?>
					<strong class="tt-citysched__date"><?php esc_html_e( 'No upcoming date scheduled yet.', 'tur-takvimi' ); ?></strong>
				<?php endif; ?>
			</div>
			<?php if ( $groups ) : ?>
				<div class="tt-citysched__route">
					<span class="tt-citysched__label"><?php esc_html_e( 'Route', 'tur-takvimi' ); ?></span>
					<span><?php echo esc_html( implode( ', ', $groups ) ); ?></span>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * All-in-one city panel (schedule + map + stops) for quick placement.
	 *
	 * @param array $atts Shortcode attributes (id).
	 * @return string
	 */
	public function panel( $atts ): string {
		$id = $this->resolve_id( $atts );
		if ( ! $id ) {
			return '';
		}
		$opts  = shortcode_atts( array( 'id' => 0, 'class' => '', 'align' => '' ), $atts, 'tur_takvimi_city' );
		$class = Shortcodes::extra_class( $opts['class'] );
		// Custom class goes on the wrapper only; align flows to the inner stops
		// list (the one part with a filter row).
		$child = array( 'id' => $id, 'align' => $opts['align'] );
		return '<div class="tt-city' . esc_attr( $class ) . '">'
			. $this->schedule( $child )
			. $this->map( $child )
			. $this->stops( $child )
			. '</div>';
	}

	/* --------------------------------------------------------------------- *
	 * SEO: JSON-LD + meta tags
	 * --------------------------------------------------------------------- */

	/**
	 * Inject JSON-LD and meta tags on single city pages.
	 */
	public function head(): void {
		if ( ! is_singular( Post_Types::LOCATION ) ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id ) {
			return;
		}

		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $this->json_ld( $id ) ) . "</script>\n";

		// Leave description/OG/canonical to a dedicated SEO plugin if present.
		if ( $this->seo_plugin_active() ) {
			return;
		}

		$desc = $this->meta_description( $id );
		$url  = (string) get_permalink( $id );
		printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( $desc ) );
		printf( "<meta property=\"og:type\" content=\"%s\">\n", 'website' );
		printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( $this->seo_title( $id ) ) );
		printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( $desc ) );
		printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( $url ) );
		printf( "<link rel=\"canonical\" href=\"%s\">\n", esc_url( $url ) );
	}

	/**
	 * SEO title for the city page (only when no SEO plugin is active).
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public function title_parts( $parts ) {
		if ( is_singular( Post_Types::LOCATION ) && ! $this->seo_plugin_active() ) {
			$parts['title'] = $this->seo_title( (int) get_queried_object_id() );
		}
		return $parts;
	}

	/**
	 * Build the schema.org graph for a city.
	 *
	 * @param int $id Location ID.
	 * @return array
	 */
	private function json_ld( int $id ): array {
		$brand   = (string) Settings::get( 'brand_name', 'Tur Takvimi' );
		$city    = get_the_title( $id );
		$country = Country::of_post( $id );
		$plz     = $this->postcode_list( $id );

		$data = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'LocalBusiness',
			'name'       => $brand . ' — ' . $city,
			'url'        => (string) get_permalink( $id ),
			'areaServed' => array(
				'@type' => 'City',
				'name'  => $city,
			),
			'address'    => array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $city,
				'addressCountry'  => $country,
			),
		);

		if ( $plz ) {
			$data['address']['postalCode'] = implode( ', ', $plz );
		}

		$lat = get_post_meta( $id, '_tt_lat', true );
		$lng = get_post_meta( $id, '_tt_lng', true );
		if ( '' !== $lat && '' !== $lng ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}

		return $data;
	}

	/**
	 * "{City} – {Brand}" SEO title.
	 *
	 * @param int $id Location ID.
	 * @return string
	 */
	private function seo_title( int $id ): string {
		return get_the_title( $id ) . ' – ' . (string) Settings::get( 'brand_name', 'Tur Takvimi' );
	}

	/**
	 * Meta description for a city page.
	 *
	 * @param int $id Location ID.
	 * @return string
	 */
	private function meta_description( int $id ): string {
		$brand = (string) Settings::get( 'brand_name', 'Tur Takvimi' );
		$city  = get_the_title( $id );
		$plz   = $this->postcode_list( $id );
		$plz   = $plz ? implode( ', ', array_slice( $plz, 0, 8 ) ) : '';

		return $plz
			? sprintf(
				/* translators: 1: brand, 2: city, 3: postcodes. */
				__( '%1$s delivery stops in %2$s (postcodes: %3$s). Enter your postcode to find your nearest stop and next visit date.', 'tur-takvimi' ),
				$brand,
				$city,
				$plz
			)
			: sprintf(
				/* translators: 1: brand, 2: city. */
				__( '%1$s delivery stops in %2$s. Enter your postcode to find your nearest stop and next visit date.', 'tur-takvimi' ),
				$brand,
				$city
			);
	}

	/* --------------------------------------------------------------------- *
	 * Fallback template
	 * --------------------------------------------------------------------- */

	/**
	 * Use the bundled single-city template for non-Breakdance setups when the
	 * theme has no city-specific template of its own.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function fallback_template( $template ): string {
		if ( ! is_singular( Post_Types::LOCATION ) || $this->breakdance_active() ) {
			return $template;
		}
		if ( ! apply_filters( 'tur_takvimi_use_fallback_template', true ) ) {
			return $template;
		}
		// Respect a theme that ships its own tt_location template.
		if ( ! in_array( basename( $template ), array( 'single.php', 'singular.php', 'index.php' ), true ) ) {
			return $template;
		}
		$custom = TURTAKVIMI_DIR . 'templates/single-tt_location.php';
		return file_exists( $custom ) ? $custom : $template;
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve the target location ID from an `id` attribute or the current post.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return int 0 when not a location.
	 */
	private function resolve_id( $atts ): int {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );
		$id   = (int) $atts['id'];
		if ( ! $id ) {
			$id = (int) get_the_ID();
		}
		return ( $id && Post_Types::LOCATION === get_post_type( $id ) ) ? $id : 0;
	}

	/**
	 * A city's delivery addresses, ordered by postcode.
	 *
	 * @param int $id Location ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function stop_list( int $id ): array {
		$addresses = json_decode( (string) get_post_meta( $id, '_tt_addresses', true ), true );
		$addresses = is_array( $addresses ) ? $addresses : array();
		// Keep the raw storage index: the .ics feed scopes by it, but rows are
		// displayed sorted by postcode.
		foreach ( $addresses as $i => &$a ) {
			$a['_index'] = (int) $i;
		}
		unset( $a );
		usort(
			$addresses,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a['postcode'] ?? '' ), (string) ( $b['postcode'] ?? '' ) );
			}
		);
		return $addresses;
	}

	/**
	 * Unique, sorted covered postcodes for a city.
	 *
	 * @param int $id Location ID.
	 * @return string[]
	 */
	private function postcode_list( int $id ): array {
		$raw = (string) get_post_meta( $id, '_tt_postcodes', true );
		$pcs = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$pcs = array_values( array_unique( (array) $pcs ) );
		sort( $pcs );
		return $pcs;
	}

	/**
	 * Distinct route-group names for the given route IDs.
	 *
	 * @param int[] $route_ids Route IDs.
	 * @return string[]
	 */
	private function route_groups( array $route_ids ): array {
		$names = array();
		foreach ( $route_ids as $rid ) {
			$terms = get_the_terms( $rid, Post_Types::REGION );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$names[ $t->name ] = true;
				}
			}
		}
		return array_keys( $names );
	}

	/**
	 * Whether a dedicated SEO plugin is managing the document head.
	 *
	 * @return bool
	 */
	private function seo_plugin_active(): bool {
		$active = defined( 'WPSEO_VERSION' ) // Yoast.
			|| defined( 'SEOPRESS_VERSION' )
			|| class_exists( 'RankMath' )
			|| defined( 'AIOSEO_VERSION' );
		return (bool) apply_filters( 'tur_takvimi_seo_plugin_active', $active );
	}

	/**
	 * Whether Breakdance is active (it owns templating when present).
	 *
	 * @return bool
	 */
	private function breakdance_active(): bool {
		$active = defined( '__BREAKDANCE_VERSION' )
			|| defined( 'BREAKDANCE_VERSION' )
			|| function_exists( '\Breakdance\Render\renderTemplate' );
		return (bool) apply_filters( 'tur_takvimi_breakdance_active', $active );
	}
}
