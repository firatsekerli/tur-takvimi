<?php
/**
 * Location edit-screen meta box.
 *
 * Admins build a city's delivery stops by searching addresses (geocoded:
 * postcode + coordinates filled automatically). Each stop is stored with its
 * coordinates so it can be shown as a map pin here and on the front end.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box for the tt_location post type.
 */
class Location_Meta {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post_' . Post_Types::LOCATION, array( $this, 'save' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue Leaflet + the address/map script on the location editor only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Post_Types::LOCATION !== $screen->post_type ) {
			return;
		}

		$leaflet_css = apply_filters( 'tur_takvimi_leaflet_css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' );
		$leaflet_js  = apply_filters( 'tur_takvimi_leaflet_js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' );

		wp_enqueue_style( 'leaflet', $leaflet_css, array(), '1.9.4' );
		wp_enqueue_style( 'tur-takvimi-admin', TURTAKVIMI_URL . 'assets/css/admin.css', array( 'leaflet' ), Plugin::asset_ver( 'assets/css/admin.css' ) );

		wp_enqueue_script( 'leaflet', $leaflet_js, array(), '1.9.4', true );
		wp_enqueue_script( 'tur-takvimi-admin-location', TURTAKVIMI_URL . 'assets/js/admin-location.js', array( 'leaflet' ), Plugin::asset_ver( 'assets/js/admin-location.js' ), true );

		$post_id = get_the_ID();
		$stored  = json_decode( (string) get_post_meta( $post_id, '_tt_addresses', true ), true );
		$title   = get_the_title( $post_id );
		if ( 'Auto Draft' === $title ) {
			$title = '';
		}

		wp_localize_script(
			'tur-takvimi-admin-location',
			'TurTakvimiAdmin',
			array(
				'rest'      => esc_url_raw( rest_url( Rest_Api::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'center'      => array( 'lat' => 51.1657, 'lng' => 10.4515 ), // Germany centroid.
				'city'        => $title,
				'addresses'   => is_array( $stored ) ? array_values( $stored ) : array(),
				'defaultFreq' => (int) Settings::get( 'default_frequency_weeks', 4 ),
				'i18n'        => array(
					'street'   => __( 'Street address', 'tur-takvimi' ),
					'postcode' => __( 'Postcode', 'tur-takvimi' ),
					'time'     => __( 'Hour', 'tur-takvimi' ),
					'timeTitle' => __( 'Delivery time for this stop (e.g. 09:00 or 09:00–12:00)', 'tur-takvimi' ),
					'freq'     => __( 'Weeks', 'tur-takvimi' ),
					'freqTitle' => __( 'Visit frequency in weeks (0 = on demand)', 'tur-takvimi' ),
					'remove'   => __( 'Remove', 'tur-takvimi' ),
					'pickup'   => __( 'Pickup point', 'tur-takvimi' ),
					'pickupTitle' => __( 'Mark this stop as the pickup point. It is shown on the city page next to the route.', 'tur-takvimi' ),
					'addRow'   => __( 'Add a stop manually', 'tur-takvimi' ),
					'empty'    => __( 'No stops yet. Search an address above to add one.', 'tur-takvimi' ),
					'approxHint' => __( 'Approximate — pinned to the postcode. Search the exact address to refine it.', 'tur-takvimi' ),
				),
			)
		);
	}

	/**
	 * Register the meta box.
	 */
	public function add_box(): void {
		add_meta_box(
			'tt_location_settings',
			__( 'Location details', 'tur-takvimi' ),
			array( $this, 'render' ),
			Post_Types::LOCATION,
			'normal',
			'high'
		);

		// The city's Bölge is derived from its routes on save, so hide the
		// manual taxonomy box.
		remove_meta_box( Post_Types::REGION . 'div', Post_Types::LOCATION, 'side' );
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current location post.
	 */
	public function render( $post ): void {
		wp_nonce_field( 'tt_location_save', 'tt_location_nonce' );

		$addresses = json_decode( (string) get_post_meta( $post->ID, '_tt_addresses', true ), true );
		$addresses = is_array( $addresses ) ? $addresses : array();
		?>
		<?php $this->render_country_field( $post ); ?>
		<?php $this->render_routes_field( $post ); ?>

		<div class="tt-field tt-geocode">
			<label for="tt-geocode-search"><?php esc_html_e( 'Search an address', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt-geocode-search" class="large-text tt-geocode__input" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. Frankenstr. 290 Essen', 'tur-takvimi' ); ?>">
			<ul id="tt-geocode-results" class="tt-geocode__results"></ul>
			<p class="description">
				<?php esc_html_e( 'Search an address to add a delivery stop. The postcode and map pin are filled in automatically.', 'tur-takvimi' ); ?>
			</p>
		</div>

		<div id="tt-map"></div>

		<div class="tt-field">
			<label><?php esc_html_e( 'Delivery stops', 'tur-takvimi' ); ?></label>
			<div id="tt-address-list" class="tt-address-list"></div>
			<button type="button" class="button" id="tt-add-address"><?php esc_html_e( 'Add a stop manually', 'tur-takvimi' ); ?></button>
			<input type="hidden" id="tt_addresses_json" name="tt_addresses_json" value="<?php echo esc_attr( wp_json_encode( array_values( $addresses ) ) ); ?>">
		</div>
		<?php
	}

	/**
	 * Country selector (multi-country sites).
	 *
	 * @param \WP_Post $post Current location post.
	 */
	private function render_country_field( $post ): void {
		$current   = Country::of_post( $post->ID );
		$countries = Country::supported();
		?>
		<div class="tt-field tt-country">
			<label for="tt_country_sel"><?php esc_html_e( 'Country', 'tur-takvimi' ); ?></label>
			<select id="tt_country_sel" name="tt_country">
				<?php foreach ( $countries as $code ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>><?php echo esc_html( $code ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Which country this city is in. Set this first so address searches are geocoded in the right country.', 'tur-takvimi' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Route assignment checkboxes (a location may belong to several routes).
	 *
	 * @param \WP_Post $post Current location post.
	 */
	private function render_routes_field( $post ): void {
		$routes = get_posts(
			array(
				'post_type'      => Post_Types::ROUTE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$assigned = array_map( 'intval', (array) get_post_meta( $post->ID, '_tt_route_id', false ) );
		?>
		<div class="tt-field tt-routes">
			<label><?php esc_html_e( 'Route(s)', 'tur-takvimi' ); ?></label>
			<?php if ( empty( $routes ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'No routes yet. Create a route first, then assign this location to it.', 'tur-takvimi' ); ?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'The routes this city belongs to. Pick one or more; a city can be served by several routes.', 'tur-takvimi' ); ?>
				</p>
				<ul class="tt-routes__list">
					<?php foreach ( $routes as $route ) : ?>
						<?php
						// The title is usually "CODE - Group" already; only prefix
						// the code when the title does not start with it.
						$code  = (string) get_post_meta( $route->ID, '_tt_route_code', true );
						$title = (string) $route->post_title;
						$label = ( '' !== $code && 0 !== strpos( $title, $code ) ) ? $code . ' — ' . $title : $title;
						?>
						<li data-country="<?php echo esc_attr( Country::of_post( (int) $route->ID ) ); ?>">
							<label>
								<input type="checkbox" name="tt_route_ids[]" value="<?php echo esc_attr( $route->ID ); ?>" <?php checked( in_array( (int) $route->ID, $assigned, true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<p id="tt-routes-empty" class="description" style="display:none;"><?php esc_html_e( 'No routes for this country yet. Create a route and set its country to match.', 'tur-takvimi' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Persist meta box values.
	 *
	 * @param int $post_id Location post ID.
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST['tt_location_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['tt_location_nonce'] ), 'tt_location_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw  = (string) wp_unslash( $_POST['tt_addresses_json'] ?? '[]' );
		$rows = json_decode( $raw, true );
		$rows = is_array( $rows ) ? $rows : array();

		$addresses  = array();
		$postcodes  = array();
		$lat_sum    = 0.0;
		$lng_sum    = 0.0;
		$geo_count  = 0;
		$has_pickup = false;

		foreach ( $rows as $row ) {
			$street   = sanitize_text_field( (string) ( $row['address'] ?? '' ) );
			$postcode = sanitize_text_field( (string) ( $row['postcode'] ?? '' ) );
			if ( '' === $street && '' === $postcode ) {
				continue;
			}

			$entry = array(
				'address'  => $street,
				'postcode' => $postcode,
			);

			// Visit frequency in weeks; 0 means on-demand (no fixed schedule).
			if ( array_key_exists( 'frequency', $row ) && '' !== $row['frequency'] ) {
				$entry['frequency'] = max( 0, (int) $row['frequency'] );
			}

			// Optional delivery time/hour for this stop (free text: "09:00").
			$time = sanitize_text_field( (string) ( $row['time'] ?? '' ) );
			if ( '' !== $time ) {
				$entry['time'] = $time;
			}

			// The city's pickup point (at most one; shown on the city page and
			// used by the checkout to display delivery details per city).
			if ( ! empty( $row['pickup'] ) && ! $has_pickup ) {
				$entry['pickup'] = true;
				$has_pickup      = true;
			}

			if ( isset( $row['lat'], $row['lng'] ) && is_numeric( $row['lat'] ) && is_numeric( $row['lng'] ) ) {
				$entry['lat'] = (float) $row['lat'];
				$entry['lng'] = (float) $row['lng'];
				$lat_sum     += $entry['lat'];
				$lng_sum     += $entry['lng'];
				++$geo_count;
				// Preserve the "approximate" (postcode-level) marker from import.
				if ( ! empty( $row['approx'] ) ) {
					$entry['approx'] = true;
				}
			}

			$addresses[] = $entry;
			if ( '' !== $postcode ) {
				$postcodes[] = $postcode;
			}
		}

		update_post_meta( $post_id, '_tt_addresses', wp_json_encode( $addresses, JSON_UNESCAPED_UNICODE ) );

		// Covered postcodes are derived from the stops — no separate field.
		$postcodes = array_values( array_unique( $postcodes ) );
		update_post_meta( $post_id, '_tt_postcodes', implode( ' ', $postcodes ) );

		// City centroid = average of geocoded stops (used for the SEO map view).
		if ( $geo_count > 0 ) {
			update_post_meta( $post_id, '_tt_lat', round( $lat_sum / $geo_count, 6 ) );
			update_post_meta( $post_id, '_tt_lng', round( $lng_sum / $geo_count, 6 ) );
		}

		$this->save_routes( $post_id );
		self::sync_regions( $post_id );

		if ( isset( $_POST['tt_country'] ) ) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_POST['tt_country'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
				update_post_meta( $post_id, '_tt_country', $code );
			}
		}
	}

	/**
	 * Derive the city's Bölge terms from its assigned routes (the manual
	 * taxonomy box is hidden). A city with no routes keeps whatever terms it
	 * has — e.g. from an import — instead of being wiped.
	 *
	 * @param int $location_id Location post ID.
	 */
	public static function sync_regions( int $location_id ): void {
		$route_ids = array_map( 'intval', (array) get_post_meta( $location_id, '_tt_route_id', false ) );
		if ( ! $route_ids ) {
			return;
		}
		$term_ids = array();
		foreach ( $route_ids as $route_id ) {
			$terms = get_the_terms( $route_id, Post_Types::REGION );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_ids[ (int) $term->term_id ] = true;
				}
			}
		}
		if ( $term_ids ) {
			wp_set_object_terms( $location_id, array_keys( $term_ids ), Post_Types::REGION, false );
		}
	}

	/**
	 * Replace the location's route assignments (stored as repeated meta so each
	 * route is independently queryable). The Schedule listener (priority 20)
	 * rebuilds the affected routes after this runs.
	 *
	 * @param int $post_id Location post ID.
	 */
	private function save_routes( $post_id ): void {
		$selected = isset( $_POST['tt_route_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['tt_route_ids'] ) ) : array();
		$selected = array_values( array_unique( array_filter( $selected ) ) );

		delete_post_meta( $post_id, '_tt_route_id' );
		foreach ( $selected as $route_id ) {
			add_post_meta( $post_id, '_tt_route_id', $route_id );
		}
	}
}
