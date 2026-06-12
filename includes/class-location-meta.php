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
				'center'    => array( 'lat' => 51.1657, 'lng' => 10.4515 ), // Germany centroid.
				'city'      => $title,
				'addresses' => is_array( $stored ) ? array_values( $stored ) : array(),
				'i18n'      => array(
					'street'   => __( 'Street address', 'tur-takvimi' ),
					'postcode' => __( 'Postcode', 'tur-takvimi' ),
					'remove'   => __( 'Remove', 'tur-takvimi' ),
					'addRow'   => __( 'Add a stop manually', 'tur-takvimi' ),
					'empty'    => __( 'No stops yet. Search an address above to add one.', 'tur-takvimi' ),
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
		<p class="description">
			<?php esc_html_e( 'Search an address to add a delivery stop. The postcode and map pin are filled in automatically.', 'tur-takvimi' ); ?>
		</p>

		<div class="tt-field tt-geocode">
			<label for="tt-geocode-search"><?php esc_html_e( 'Search an address', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt-geocode-search" class="large-text tt-geocode__input" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. Frankenstr. 290 Essen', 'tur-takvimi' ); ?>">
			<ul id="tt-geocode-results" class="tt-geocode__results"></ul>
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

		$addresses = array();
		$postcodes = array();
		$lat_sum   = 0.0;
		$lng_sum   = 0.0;
		$geo_count = 0;

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

			if ( isset( $row['lat'], $row['lng'] ) && is_numeric( $row['lat'] ) && is_numeric( $row['lng'] ) ) {
				$entry['lat'] = (float) $row['lat'];
				$entry['lng'] = (float) $row['lng'];
				$lat_sum     += $entry['lat'];
				$lng_sum     += $entry['lng'];
				++$geo_count;
			}

			$addresses[] = $entry;
			if ( '' !== $postcode ) {
				$postcodes[] = $postcode;
			}
		}

		update_post_meta( $post_id, '_tt_addresses', wp_json_encode( $addresses ) );

		// Covered postcodes are derived from the stops — no separate field.
		$postcodes = array_values( array_unique( $postcodes ) );
		update_post_meta( $post_id, '_tt_postcodes', implode( ' ', $postcodes ) );

		// City centroid = average of geocoded stops (used for the SEO map view).
		if ( $geo_count > 0 ) {
			update_post_meta( $post_id, '_tt_lat', round( $lat_sum / $geo_count, 6 ) );
			update_post_meta( $post_id, '_tt_lng', round( $lng_sum / $geo_count, 6 ) );
		}
	}
}
