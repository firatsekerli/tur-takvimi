<?php
/**
 * Location edit-screen meta box.
 *
 * Lets an admin create/edit a location entirely in the backend — postcodes
 * covered, geo coordinates and the list of street addresses — without CSV.
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
	 * Enqueue Leaflet + the geocoding script on the location editor only.
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

		/**
		 * Filter the Leaflet asset URLs so resellers can self-host them.
		 *
		 * @param string $url Default CDN URL.
		 */
		$leaflet_css = apply_filters( 'tur_takvimi_leaflet_css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' );
		$leaflet_js  = apply_filters( 'tur_takvimi_leaflet_js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' );

		wp_enqueue_style( 'leaflet', $leaflet_css, array(), '1.9.4' );
		wp_enqueue_style( 'tur-takvimi-admin', TURTAKVIMI_URL . 'assets/css/admin.css', array( 'leaflet' ), TURTAKVIMI_VERSION );

		wp_enqueue_script( 'leaflet', $leaflet_js, array(), '1.9.4', true );
		wp_enqueue_script( 'tur-takvimi-admin-location', TURTAKVIMI_URL . 'assets/js/admin-location.js', array( 'leaflet' ), TURTAKVIMI_VERSION, true );

		wp_localize_script(
			'tur-takvimi-admin-location',
			'TurTakvimiAdmin',
			array(
				'rest'   => esc_url_raw( rest_url( Rest_Api::NS ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'center' => array( 'lat' => 52.1326, 'lng' => 5.2913 ), // NL centroid.
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

		$postcodes = (string) get_post_meta( $post->ID, '_tt_postcodes', true );
		$lat       = (string) get_post_meta( $post->ID, '_tt_lat', true );
		$lng       = (string) get_post_meta( $post->ID, '_tt_lng', true );

		$addresses = json_decode( (string) get_post_meta( $post->ID, '_tt_addresses', true ), true );
		$addresses = is_array( $addresses ) ? $addresses : array();

		// Render the address list as "street ; postcode" lines for easy editing.
		$lines = array();
		foreach ( $addresses as $a ) {
			$street = isset( $a['address'] ) ? (string) $a['address'] : '';
			$pc     = isset( $a['postcode'] ) ? (string) $a['postcode'] : '';
			$lines[] = '' !== $pc ? $street . ' ; ' . $pc : $street;
		}
		$addresses_text = implode( "\n", $lines );
		?>
		<style>
			.tt-field { margin: 0 0 1rem; }
			.tt-field label { display:block; font-weight:600; margin-bottom:.25rem; }
			.tt-grid { display:flex; gap:1rem; flex-wrap:wrap; }
			.tt-grid .tt-field { flex:1; min-width:180px; }
			#tt_addresses { width:100%; }
		</style>

		<div class="tt-field">
			<label for="tt_postcodes"><?php esc_html_e( 'Postcodes covered', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt_postcodes" name="tt_postcodes" class="large-text" value="<?php echo esc_attr( $postcodes ); ?>" placeholder="1011 1071 1102">
			<p class="description"><?php esc_html_e( 'Optional. Postcodes from the addresses below are added automatically; add extra covered postcodes here only if a stop has no street address.', 'tur-takvimi' ); ?></p>
		</div>

		<div class="tt-field tt-geocode">
			<label for="tt-geocode-search"><?php esc_html_e( 'Search an address (auto-fills postcode & map)', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt-geocode-search" class="large-text tt-geocode__input" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. Damrak 1 Amsterdam', 'tur-takvimi' ); ?>">
			<ul id="tt-geocode-results" class="tt-geocode__results"></ul>
		</div>

		<div id="tt-map"></div>

		<div class="tt-grid">
			<div class="tt-field">
				<label for="tt_lat"><?php esc_html_e( 'Latitude', 'tur-takvimi' ); ?></label>
				<input type="text" id="tt_lat" name="tt_lat" value="<?php echo esc_attr( $lat ); ?>" placeholder="52.3676">
			</div>
			<div class="tt-field">
				<label for="tt_lng"><?php esc_html_e( 'Longitude', 'tur-takvimi' ); ?></label>
				<input type="text" id="tt_lng" name="tt_lng" value="<?php echo esc_attr( $lng ); ?>" placeholder="4.9041">
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Coordinates power the "nearest stop" search. Click the map or drag the pin to set them.', 'tur-takvimi' ); ?></p>

		<div class="tt-field">
			<label for="tt_addresses"><?php esc_html_e( 'Street addresses (one per line)', 'tur-takvimi' ); ?></label>
			<textarea id="tt_addresses" name="tt_addresses" rows="6" placeholder="Damrak 1 ; 1011&#10;Kalverstraat 92 ; 1012"><?php echo esc_textarea( $addresses_text ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Format: street ; postcode (postcode optional). One stop per line.', 'tur-takvimi' ); ?></p>
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

		update_post_meta( $post_id, '_tt_postcodes', sanitize_text_field( wp_unslash( $_POST['tt_postcodes'] ?? '' ) ) );

		$lat = str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['tt_lat'] ?? '' ) ) );
		$lng = str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['tt_lng'] ?? '' ) ) );
		if ( is_numeric( $lat ) ) {
			update_post_meta( $post_id, '_tt_lat', (float) $lat );
		} else {
			delete_post_meta( $post_id, '_tt_lat' );
		}
		if ( is_numeric( $lng ) ) {
			update_post_meta( $post_id, '_tt_lng', (float) $lng );
		} else {
			delete_post_meta( $post_id, '_tt_lng' );
		}

		// Parse the address list; each stop carries its own postcode.
		$raw            = (string) wp_unslash( $_POST['tt_addresses'] ?? '' );
		$addresses      = array();
		$from_addresses = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts       = array_map( 'trim', explode( ';', $line, 2 ) );
			$postcode    = sanitize_text_field( $parts[1] ?? '' );
			$addresses[] = array(
				'address'  => sanitize_text_field( $parts[0] ),
				'postcode' => $postcode,
			);
			if ( '' !== $postcode ) {
				$from_addresses[] = $postcode;
			}
		}
		update_post_meta( $post_id, '_tt_addresses', wp_json_encode( $addresses ) );

		// Covered postcodes = the optional explicit list UNION every per-address
		// postcode, so matching always reflects the real stops — never a range.
		$explicit = preg_split( '/[\s,]+/', (string) wp_unslash( $_POST['tt_postcodes'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		$all      = array_map( 'sanitize_text_field', array_merge( (array) $explicit, $from_addresses ) );
		$all      = array_values( array_unique( array_filter( $all ) ) );
		update_post_meta( $post_id, '_tt_postcodes', implode( ' ', $all ) );
	}
}
