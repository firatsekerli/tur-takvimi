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
		$wa_url = Whatsapp::for_location( $id );

		// Calendar links exist only for scheduled stops (frequency > 0). When
		// neither icon can render anywhere, the actions column is omitted.
		$has_cal = false;
		foreach ( $stops as $a ) {
			$f = array_key_exists( 'frequency', $a ) && '' !== $a['frequency'] ? (int) $a['frequency'] : $default_freq;
			if ( $f > 0 ) {
				$has_cal = true;
				break;
			}
		}
		$show_actions = '' !== $wa_url || $has_cal;

		ob_start();
		?>
		<section class="tt-stops<?php echo esc_attr( ( $show_actions ? '' : ' tt-stops--no-actions' ) . Shortcodes::extra_class( $opts['class'] ) ); ?>" aria-label="<?php esc_attr_e( 'Delivery addresses', 'tur-takvimi' ); ?>"<?php echo '' !== $align ? ' data-tt-align="' . esc_attr( $align ) . '"' : ''; ?> data-tt-stops>
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
					<?php if ( $show_actions ) : ?>
						<span class="tt-stops__col tt-stops__col--actions" aria-hidden="true"></span>
					<?php endif; ?>
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
						<?php if ( $show_actions ) : ?>
							<span class="tt-stops__col tt-stops__col--actions" role="cell">
								<?php if ( '' !== $wa_url ) : ?>
									<a class="tt-stops__wa" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener nofollow" title="<?php esc_attr_e( 'Join our WhatsApp group', 'tur-takvimi' ); ?>" aria-label="<?php esc_attr_e( 'Join our WhatsApp group', 'tur-takvimi' ); ?>">
										<svg viewBox="0 0 24 24" width="18" height="18" fill="#fff" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>
									</a>
								<?php endif; ?>
								<?php if ( $freq > 0 ) : ?>
									<a class="tt-stops__cal" href="<?php echo esc_url( $cal_url ); ?>" title="<?php echo esc_attr( $cal_label ); ?>" aria-label="<?php echo esc_attr( $cal_label ); ?>">
										<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg>
									</a>
								<?php endif; ?>
							</span>
						<?php endif; ?>
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
		$pickup   = self::pickup_label( $id );

		ob_start();
		?>
		<section class="tt-citysched<?php echo esc_attr( ( '' !== $pickup ? ' tt-citysched--pickup' : '' ) . Shortcodes::extra_class( $opts['class'] ) ); ?>" aria-label="<?php esc_attr_e( 'Delivery schedule', 'tur-takvimi' ); ?>">
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
			<?php if ( '' !== $pickup ) : ?>
				<div class="tt-citysched__pickup">
					<span class="tt-citysched__label"><?php esc_html_e( 'Pre-order pickup point', 'tur-takvimi' ); ?></span>
					<span><?php echo esc_html( $pickup ); ?></span>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The address marked as a city's pickup point, or null. Public so the
	 * checkout/commerce layer can show per-city delivery details from it.
	 *
	 * @param int $id Location ID.
	 * @return array<string,mixed>|null The raw address entry {address, postcode, time, …}.
	 */
	public static function pickup_address( int $id ): ?array {
		$addresses = json_decode( (string) get_post_meta( $id, '_tt_addresses', true ), true );
		if ( is_array( $addresses ) ) {
			foreach ( $addresses as $a ) {
				if ( is_array( $a ) && ! empty( $a['pickup'] ) ) {
					return $a;
				}
			}
		}
		return null;
	}

	/**
	 * Display string for a city's pickup point: "Street, Postcode · Hour".
	 *
	 * @param int $id Location ID.
	 * @return string '' when no pickup point is marked.
	 */
	private static function pickup_label( int $id ): string {
		$pickup = self::pickup_address( $id );
		if ( null === $pickup ) {
			return '';
		}
		$text = trim( (string) ( $pickup['address'] ?? '' ) );
		$pc   = trim( (string) ( $pickup['postcode'] ?? '' ) );
		if ( '' !== $pc ) {
			$text .= ( '' !== $text ? ', ' : '' ) . $pc;
		}
		$time = trim( (string) ( $pickup['time'] ?? '' ) );
		if ( '' !== $time ) {
			$text .= ( '' !== $text ? ' · ' : '' ) . $time;
		}
		return $text;
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
