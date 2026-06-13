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
		$selected  = json_decode( (string) get_post_meta( $post->ID, '_tt_location_ids', true ), true );
		$selected  = is_array( $selected ) ? array_map( 'intval', $selected ) : array();

		$locations = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<style>
			.tt-field { margin: 0 0 1rem; }
			.tt-field label { display:block; font-weight:600; margin-bottom:.25rem; }
			.tt-locations { max-height: 240px; overflow:auto; border:1px solid #ddd; padding:.5rem; border-radius:6px; }
			.tt-locations label { font-weight:400; display:block; }
		</style>
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
					: esc_html__( 'Computed automatically from the selected locations\' addresses when you save.', 'tur-takvimi' );
				?>
			</p>
		</div>
		<div class="tt-field">
			<label><?php esc_html_e( 'Locations served (in visit order)', 'tur-takvimi' ); ?></label>
			<div class="tt-locations">
				<?php if ( empty( $locations ) ) : ?>
					<em><?php esc_html_e( 'No locations yet. Create or import locations first.', 'tur-takvimi' ); ?></em>
				<?php else : ?>
					<?php foreach ( $locations as $loc ) : ?>
						<label>
							<input type="checkbox" name="tt_location_ids[]" value="<?php echo esc_attr( $loc->ID ); ?>" <?php checked( in_array( $loc->ID, $selected, true ) ); ?>>
							<?php echo esc_html( $loc->post_title ); ?>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Order follows the location list; reorder support comes in a later phase.', 'tur-takvimi' ); ?></p>
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

		$ids = isset( $_POST['tt_location_ids'] ) && is_array( $_POST['tt_location_ids'] )
			? array_values( array_map( 'absint', wp_unslash( $_POST['tt_location_ids'] ) ) )
			: array();
		update_post_meta( $post_id, '_tt_location_ids', wp_json_encode( $ids ) );

		// Derive the covered-postcodes summary from the member locations'
		// actual postcodes (display only — matching uses the per-location list).
		$postcodes = array();
		foreach ( $ids as $loc_id ) {
			$list      = preg_split( '/[\s,]+/', (string) get_post_meta( $loc_id, '_tt_postcodes', true ), -1, PREG_SPLIT_NO_EMPTY );
			$postcodes = array_merge( $postcodes, (array) $list );
		}
		$postcodes = array_values( array_unique( $postcodes ) );
		sort( $postcodes );
		update_post_meta( $post_id, '_tt_plz_range', implode( ', ', $postcodes ) );

		// Recurrence is re-materialized by Schedule on the save_post hook.
	}
}
