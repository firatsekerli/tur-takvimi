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
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current route post.
	 */
	public function render( $post ): void {
		wp_nonce_field( 'tt_route_save', 'tt_route_nonce' );

		$vehicle   = (string) get_post_meta( $post->ID, '_tt_vehicle', true );
		$frequency = (int) get_post_meta( $post->ID, '_tt_frequency_weeks', true );
		$anchor    = (string) get_post_meta( $post->ID, '_tt_anchor_date', true );
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
			<label for="tt_vehicle"><?php esc_html_e( 'Vehicle', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt_vehicle" name="tt_vehicle" class="regular-text" value="<?php echo esc_attr( $vehicle ); ?>" placeholder="<?php esc_attr_e( 'e.g. Vehicle 1', 'tur-takvimi' ); ?>">
		</div>
		<div class="tt-field">
			<label for="tt_anchor"><?php esc_html_e( 'First visit date (anchor)', 'tur-takvimi' ); ?></label>
			<input type="date" id="tt_anchor" name="tt_anchor" value="<?php echo esc_attr( $anchor ); ?>">
			<p class="description"><?php esc_html_e( 'The recurrence repeats from this date.', 'tur-takvimi' ); ?></p>
		</div>
		<div class="tt-field">
			<label for="tt_frequency"><?php esc_html_e( 'Repeat every (weeks)', 'tur-takvimi' ); ?></label>
			<input type="number" id="tt_frequency" name="tt_frequency" min="1" max="26" value="<?php echo esc_attr( $frequency ?: 4 ); ?>">
		</div>
		<div class="tt-field">
			<label for="tt_plz"><?php esc_html_e( 'Postcode range (display)', 'tur-takvimi' ); ?></label>
			<input type="text" id="tt_plz" name="tt_plz" class="regular-text" value="<?php echo esc_attr( $plz ); ?>" placeholder="1011 - 1102">
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

		update_post_meta( $post_id, '_tt_vehicle', sanitize_text_field( wp_unslash( $_POST['tt_vehicle'] ?? '' ) ) );
		update_post_meta( $post_id, '_tt_anchor_date', sanitize_text_field( wp_unslash( $_POST['tt_anchor'] ?? '' ) ) );
		update_post_meta( $post_id, '_tt_frequency_weeks', max( 1, absint( $_POST['tt_frequency'] ?? 4 ) ) );
		update_post_meta( $post_id, '_tt_plz_range', sanitize_text_field( wp_unslash( $_POST['tt_plz'] ?? '' ) ) );

		$ids = isset( $_POST['tt_location_ids'] ) && is_array( $_POST['tt_location_ids'] )
			? array_values( array_map( 'absint', wp_unslash( $_POST['tt_location_ids'] ) ) )
			: array();
		update_post_meta( $post_id, '_tt_location_ids', wp_json_encode( $ids ) );

		// Recurrence is re-materialized by Schedule on the save_post hook.
	}
}
