<?php
/**
 * Custom post types, taxonomy and meta registration.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the tt_location and tt_route post types, the tt_region
 * taxonomy, and all associated post meta.
 */
class Post_Types {

	const LOCATION = 'tt_location';
	const ROUTE    = 'tt_route';
	const REGION   = 'tt_region';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	/**
	 * Register the location (city) and route post types.
	 */
	public function register_post_types(): void {
		$slug_base = Settings::get( 'location_slug_base', 'teslimat' );

		register_post_type(
			self::LOCATION,
			array(
				'labels'              => array(
					'name'          => __( 'Locations', 'tur-takvimi' ),
					'singular_name' => __( 'Location', 'tur-takvimi' ),
					'add_new_item'  => __( 'Add Location', 'tur-takvimi' ),
					'edit_item'     => __( 'Edit Location', 'tur-takvimi' ),
					'search_items'  => __( 'Search Locations', 'tur-takvimi' ),
				),
				'public'              => true,
				'has_archive'         => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-location',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
				'rewrite'             => array(
					'slug'       => sanitize_title( $slug_base ),
					'with_front' => false,
				),
			)
		);

		register_post_type(
			self::ROUTE,
			array(
				'labels'       => array(
					'name'          => __( 'Routes', 'tur-takvimi' ),
					'singular_name' => __( 'Route', 'tur-takvimi' ),
					'add_new_item'  => __( 'Add Route', 'tur-takvimi' ),
					'edit_item'     => __( 'Edit Route', 'tur-takvimi' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-share-alt2',
				'supports'     => array( 'title', 'custom-fields' ),
			)
		);
	}

	/**
	 * Register the region taxonomy (Rota Grubu).
	 */
	public function register_taxonomy(): void {
		register_taxonomy(
			self::REGION,
			array( self::LOCATION, self::ROUTE ),
			array(
				'labels'            => array(
					'name'          => __( 'Regions', 'tur-takvimi' ),
					'singular_name' => __( 'Region', 'tur-takvimi' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Register post meta so it is exposed to REST and Breakdance Dynamic Data.
	 */
	public function register_meta(): void {
		$location_meta = array(
			'_tt_lat'       => 'number',
			'_tt_lng'       => 'number',
			'_tt_postcodes' => 'string', // Comma/space separated covered postcodes.
			'_tt_addresses' => 'string', // JSON array of {address, postcode}.
		);
		foreach ( $location_meta as $key => $type ) {
			register_post_meta(
				self::LOCATION,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'number' === $type ? null : 'wp_kses_post',
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}

		$route_meta = array(
			'_tt_vehicle'         => 'string',
			'_tt_frequency_weeks' => 'integer',
			'_tt_anchor_date'     => 'string', // First occurrence, Y-m-d.
			'_tt_weekday'         => 'integer', // 0 (Sun) .. 6 (Sat).
			'_tt_plz_range'       => 'string',
			'_tt_location_ids'    => 'string',  // JSON ordered array of location post IDs.
		);
		foreach ( $route_meta as $key => $type ) {
			register_post_meta(
				self::ROUTE,
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}
}
