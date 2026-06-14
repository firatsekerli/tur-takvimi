<?php
/**
 * Route edit-screen meta box.
 *
 * Gives admins a simple UI to configure a route's recurrence (anchor date +
 * frequency), vehicle and the ordered list of locations it serves.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box for the tt_route post type.
 */
class Route_Meta {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post_' . Post_Types::ROUTE, array( $this, 'save' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the drag-to-reorder script on the route editor only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Post_Types::ROUTE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'tur-takvimi-admin', TURTAKVIMI_URL . 'assets/css/admin.css', array(), Plugin::asset_ver( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'tur-takvimi-admin-route', TURTAKVIMI_URL . 'assets/js/admin-route.js', array( 'jquery', 'jquery-ui-sortable' ), Plugin::asset_ver( 'assets/js/admin-route.js' ), true );
		wp_localize_script(
			'tur-takvimi-admin-route',
			'ttRouteI18n',
			array(
				'needCoords' => __( 'Need at least two cities with map pins to optimize. Geocode the addresses first.', 'tur-takvimi' ),
			)
		);
	}

	/**
	 * Register the meta box.
	 */
	public function add_box(): void {
		add_meta_box(
			'tt_route_settings',
			__( 'Route settings', 'tur-takvimi' ),
			array( $this, 'render' ),
			Post_Types::ROUTE,
			'normal',
			'high'
		);

		// The route group is edited via our own field below, so hide the
		// default region taxonomy box on the route screen.
		remove_meta_box( Post_Types::REGION . 'div', Post_Types::ROUTE, 'side' );
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current route post.
	 */
	public function render( $post ): void {
		wp_nonce_field( 'tt_route_save', 'tt_route_nonce' );

		$code      = (string) get_post_meta( $post->ID, '_tt_route_code', true );
		$vehicle   = (string) get_post_meta( $post->ID, '_tt_vehicle', true );
		$anchor    = (string) get_post_meta( $post->ID, '_tt_anchor_date', true );

		$group     = '';
		$assigned  = get_the_terms( $post->ID, Post_Types::REGION );
		if ( $assigned && ! is_wp_error( $assigned ) ) {
			$group = $assigned[0]->name;
		}
		$region_terms = get_terms(
			array(
				'taxonomy'   => Post_Types::REGION,
				'hide_empty' => false,
			)
		);
		$plz       = (string) get_post_meta( $post->ID, '_tt_plz_range', true );

		// Membership lives on the locations (many-to-many); here we only show
		// the resulting stops in visit order, which the admin can drag.
		$ordered = ( new Schedule() )->ordered_location_ids( $post->ID );
		?>
		<div class="tt-field">
			<label for="tt_route_code"><?php esc_html_e( 'Route ID', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt_route_code" name="tt_route_code" value="<?php echo esc_attr( $code ); ?>" placeholder="<?php esc_attr_e( 'e.g. R07', 'tur-takvimi' ); ?>">
		</div>
		<div class="tt-field">
			<label for="tt_rota_grubu"><?php esc_html_e( 'Route group', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt_rota_grubu" name="tt_rota_grubu" class="regular-text" list="tt_region_options" value="<?php echo esc_attr( $group ); ?>" placeholder="<?php esc_attr_e( 'e.g. Köln-Bonn-Aachen / Rheinland', 'tur-takvimi' ); ?>">
			<datalist id="tt_region_options">
				<?php
				if ( $region_terms && ! is_wp_error( $region_terms ) ) {
					foreach ( $region_terms as $term ) {
						echo '<option value="' . esc_attr( $term->name ) . '"></option>';
					}
				}
				?>
			</datalist>
			<p class="description"><?php esc_html_e( 'On first save, the title is set to "Route ID - Route group".', 'tur-takvimi' ); ?></p>
		</div>
		<div class="tt-field">
			<label for="tt_vehicle"><?php esc_html_e( 'Vehicle', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt_vehicle" name="tt_vehicle" class="regular-text" value="<?php echo esc_attr( $vehicle ); ?>" placeholder="<?php esc_attr_e( 'e.g. Vehicle 1', 'tur-takvimi' ); ?>">
		</div>
		<div class="tt-field">
			<label for="tt_anchor"><?php esc_html_e( 'First visit date (anchor)', 'tur-takvimi' ); ?></label>
			<input type="date" id="tt_anchor" name="tt_anchor" value="<?php echo esc_attr( $anchor ); ?>">
			<p class="description"><?php esc_html_e( 'Each stop recurs from this date at its own frequency (set per stop inside the location).', 'tur-takvimi' ); ?></p>
		</div>
		<div class="tt-field">
			<label><?php esc_html_e( 'Covered postcodes', 'tur-takvimi' ); ?></label>
			<p class="description">
				<?php
				echo $plz
					? esc_html( $plz )
					: esc_html__( 'Computed automatically from the assigned locations\' addresses when you save.', 'tur-takvimi' );
				?>
			</p>
		</div>
		<div class="tt-field">
			<label><?php esc_html_e( 'Route summary', 'tur-takvimi' ); ?></label>
			<table class="tt-route-summary">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Start city', 'tur-takvimi' ); ?></th>
						<td><?php echo $ordered ? esc_html( get_the_title( (int) $ordered[0] ) ) : '—'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'End city', 'tur-takvimi' ); ?></th>
						<td><?php echo $ordered ? esc_html( get_the_title( (int) end( $ordered ) ) ) : '—'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Number of cities', 'tur-takvimi' ); ?></th>
						<td><?php echo (int) count( $ordered ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Derived from the assigned cities in visit order (first = start, last = end).', 'tur-takvimi' ); ?></p>
		</div>
		<div class="tt-field">
			<label><?php esc_html_e( 'Locations served (in visit order)', 'tur-takvimi' ); ?></label>
			<?php if ( empty( $ordered ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'No locations assigned yet. Open a location and tick this route under "Routes this location belongs to".', 'tur-takvimi' ); ?>
				</p>
			<?php else : ?>
				<div class="tt-route-optimize">
					<label class="tt-route-optimize__label" for="tt-route-start"><?php esc_html_e( 'Start city', 'tur-takvimi' ); ?></label>
					<select id="tt-route-start" class="tt-route-optimize__start">
						<?php foreach ( $ordered as $loc_id ) : ?>
							<option value="<?php echo esc_attr( $loc_id ); ?>"><?php echo esc_html( get_the_title( $loc_id ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="tt-route-optimize"><?php esc_html_e( 'Optimize by distance', 'tur-takvimi' ); ?></button>
				</div>
				<ol id="tt-route-order" class="tt-route-order">
					<?php
					foreach ( $ordered as $loc_id ) :
						$lat = get_post_meta( $loc_id, '_tt_lat', true );
						$lng = get_post_meta( $loc_id, '_tt_lng', true );
						?>
						<li class="tt-route-order__item" data-id="<?php echo esc_attr( $loc_id ); ?>" data-lat="<?php echo esc_attr( $lat ); ?>" data-lng="<?php echo esc_attr( $lng ); ?>">
							<span class="tt-route-order__handle" aria-hidden="true">⠿</span>
							<span class="tt-route-order__title"><?php echo esc_html( get_the_title( $loc_id ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
				<input type="hidden" id="tt_location_order" name="tt_location_order" value="<?php echo esc_attr( wp_json_encode( $ordered ) ); ?>">
				<p class="description"><?php esc_html_e( 'Order is the postcode default; drag to override, or use "Optimize by distance" to order cities by geographic proximity from the start city. Save to keep the order.', 'tur-takvimi' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Persist meta box values.
	 *
	 * @param int $post_id Route post ID.
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST['tt_route_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['tt_route_nonce'] ), 'tt_route_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$code = sanitize_text_field( wp_unslash( $_POST['tt_route_code'] ?? '' ) );
		update_post_meta( $post_id, '_tt_route_code', $code );
		update_post_meta( $post_id, '_tt_vehicle', sanitize_text_field( wp_unslash( $_POST['tt_vehicle'] ?? '' ) ) );

		// Route group maps to the shared region taxonomy (created if new).
		$group = sanitize_text_field( wp_unslash( $_POST['tt_rota_grubu'] ?? '' ) );
		wp_set_object_terms( $post_id, '' !== $group ? $group : array(), Post_Types::REGION, false );

		// On first save (no manual title yet), build the title from ID + group.
		$post  = get_post( $post_id );
		$title = $post ? trim( (string) $post->post_title ) : '';
		if ( '' === $title || 'Auto Draft' === $title ) {
			$parts = array_filter( array( $code, $group ) );
			if ( $parts ) {
				remove_action( 'save_post_' . Post_Types::ROUTE, array( $this, 'save' ), 10 );
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => implode( ' - ', $parts ),
					)
				);
				add_action( 'save_post_' . Post_Types::ROUTE, array( $this, 'save' ), 10, 1 );
			}
		}
		update_post_meta( $post_id, '_tt_anchor_date', sanitize_text_field( wp_unslash( $_POST['tt_anchor'] ?? '' ) ) );

		// Manual visit-order override (membership itself lives on the locations).
		$order = json_decode( (string) wp_unslash( $_POST['tt_location_order'] ?? '[]' ), true );
		$order = is_array( $order ) ? array_values( array_map( 'absint', $order ) ) : array();
		update_post_meta( $post_id, '_tt_location_order', wp_json_encode( $order ) );

		// Recurrence and the covered-postcodes summary are refreshed by Schedule
		// on the save_post hook (priority 20).
	}
}
