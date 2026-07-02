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

		// Admin list: country filter + column on both post types.
		add_action( 'restrict_manage_posts', array( $this, 'country_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_by_country' ) );
		foreach ( array( self::LOCATION, self::ROUTE ) as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( $this, 'country_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'country_column_value' ), 10, 2 );
		}
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

		// A location may belong to several routes (stored as repeated meta).
		register_post_meta(
			self::LOCATION,
			'_tt_route_id',
			array(
				'type'          => 'integer',
				'single'        => false,
				'show_in_rest'  => true,
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		$route_meta = array(
			'_tt_route_code'      => 'string',
			'_tt_vehicle'         => 'string',
			'_tt_anchor_date'     => 'string', // First occurrence, Y-m-d.
			'_tt_plz_range'       => 'string', // Derived covered-postcodes summary.
			'_tt_location_order'  => 'string', // JSON manual override of stop order.
			'_tt_start_city'      => 'string', // Derived: first stop in visit order.
			'_tt_end_city'        => 'string', // Derived: last stop in visit order.
			'_tt_city_count'      => 'integer', // Derived: number of cities served.
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

		// Country (ISO-2) on both cities and routes for multi-country sites.
		foreach ( array( self::LOCATION, self::ROUTE ) as $type ) {
			register_post_meta(
				$type,
				'_tt_country',
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Add "Country" and "Region" filter dropdowns above the Şehir/Rota
	 * list tables.
	 *
	 * @param string $post_type Current screen post type.
	 */
	public function country_filter( $post_type ): void {
		if ( ! in_array( $post_type, array( self::LOCATION, self::ROUTE ), true ) ) {
			return;
		}
		$current = isset( $_GET['tt_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['tt_country'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="tt_country">';
		echo '<option value="">' . esc_html__( 'All countries', 'tur-takvimi' ) . '</option>';
		foreach ( Country::supported() as $code ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $code )
			);
		}
		echo '</select>';

		// Region (Bölge) filter: a select on the taxonomy's own query var, so
		// WordPress applies it to the list query natively — no extra hook.
		$terms = get_terms(
			array(
				'taxonomy'   => self::REGION,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! $terms || is_wp_error( $terms ) ) {
			return;
		}
		$region = isset( $_GET[ self::REGION ] ) ? sanitize_title( wp_unslash( $_GET[ self::REGION ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="' . esc_attr( self::REGION ) . '">';
		echo '<option value="">' . esc_html__( 'All regions', 'tur-takvimi' ) . '</option>';
		foreach ( $terms as $term ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $term->slug ),
				selected( $region, $term->slug, false ),
				esc_html( $term->name )
			);
		}
		echo '</select>';
	}

	/**
	 * Apply the country filter to the admin list query.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function filter_by_country( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$pt = $query->get( 'post_type' );
		if ( ! in_array( $pt, array( self::LOCATION, self::ROUTE ), true ) ) {
			return;
		}
		if ( empty( $_GET['tt_country'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = strtoupper( sanitize_text_field( wp_unslash( $_GET['tt_country'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$query->set(
			'meta_query',
			array(
				array(
					'key'   => '_tt_country',
					'value' => $code,
				),
			)
		);
	}

	/**
	 * Add a "Country" column to the list tables.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function country_column( $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['tt_country'] = __( 'Country', 'tur-takvimi' );
			}
		}
		return $out;
	}

	/**
	 * Render the country column value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function country_column_value( $column, $post_id ): void {
		if ( 'tt_country' === $column ) {
			echo esc_html( Country::of_post( (int) $post_id ) );
		}
	}
}
