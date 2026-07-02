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

		// Şehir list: stop count (flagging approximate / unpinned stops).
		add_filter( 'manage_' . self::LOCATION . '_posts_columns', array( $this, 'stops_column' ), 11 );
		add_action( 'manage_' . self::LOCATION . '_posts_custom_column', array( $this, 'stops_column_value' ), 10, 2 );
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
				'supports'            => array( 'title', 'thumbnail', 'excerpt', 'custom-fields' ),
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

		$term_countries = self::region_countries( $post_type );
		$region         = isset( $_GET[ self::REGION ] ) ? sanitize_title( wp_unslash( $_GET[ self::REGION ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="' . esc_attr( self::REGION ) . '" id="tt_region_filter">';
		echo '<option value="">' . esc_html__( 'All regions', 'tur-takvimi' ) . '</option>';
		foreach ( $terms as $term ) {
			printf(
				'<option value="%s" data-countries="%s"%s>%s</option>',
				esc_attr( $term->slug ),
				esc_attr( implode( ',', $term_countries[ (int) $term->term_id ] ?? array() ) ),
				selected( $region, $term->slug, false ),
				esc_html( $term->name )
			);
		}
		echo '</select>';

		// Narrow the region list to the chosen country without a round-trip.
		?>
		<script>
		( function () {
			var country = document.querySelector( 'select[name="tt_country"]' );
			var region = document.getElementById( 'tt_region_filter' );
			if ( ! country || ! region ) {
				return;
			}
			var all = Array.prototype.slice.call( region.options ).map( function ( o ) {
				return {
					value: o.value,
					text: o.text,
					countries: ( o.getAttribute( 'data-countries' ) || '' ).split( ',' ),
					selected: o.selected
				};
			} );
			function apply() {
				var co = country.value;
				var keep = region.value;
				region.length = 0;
				all.forEach( function ( o ) {
					if ( o.value && co && o.countries.indexOf( co ) === -1 ) {
						return;
					}
					region.add( new Option( o.text, o.value, false, o.value === keep ) );
				} );
			}
			country.addEventListener( 'change', apply );
			apply();
		}() );
		</script>
		<?php
	}

	/**
	 * Which countries each region term is used in, for the given post type
	 * (inferred from the posts carrying the term — regions have no country
	 * of their own).
	 *
	 * @param string $post_type Post type to inspect.
	 * @return array<int,string[]> term_id => ISO-2 codes.
	 */
	public static function region_countries( string $post_type ): array {
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		if ( ! $ids ) {
			return array();
		}
		update_postmeta_cache( $ids );

		$assignments = wp_get_object_terms( $ids, self::REGION, array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $assignments ) ) {
			return array();
		}

		$map = array();
		foreach ( $assignments as $term ) {
			$code = Country::of_post( (int) $term->object_id );
			if ( ! in_array( $code, $map[ (int) $term->term_id ] ?? array(), true ) ) {
				$map[ (int) $term->term_id ][] = $code;
			}
		}
		return $map;
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

	/**
	 * Add a "Stops" count column after the country column (Şehir list only).
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function stops_column( $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'tt_country' === $key ) {
				$out['tt_stops'] = __( 'Stops', 'tur-takvimi' );
			}
		}
		if ( ! isset( $out['tt_stops'] ) ) {
			$out['tt_stops'] = __( 'Stops', 'tur-takvimi' );
		}
		return $out;
	}

	/**
	 * Render the stop count, flagging approximate (🟠) and unpinned (•) stops.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function stops_column_value( $column, $post_id ): void {
		if ( 'tt_stops' !== $column ) {
			return;
		}
		$addresses = json_decode( (string) get_post_meta( (int) $post_id, '_tt_addresses', true ), true );
		$addresses = is_array( $addresses ) ? $addresses : array();

		$approx   = 0;
		$unpinned = 0;
		foreach ( $addresses as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			if ( ! isset( $a['lat'], $a['lng'] ) ) {
				++$unpinned;
			} elseif ( ! empty( $a['approx'] ) ) {
				++$approx;
			}
		}

		echo (int) count( $addresses );
		if ( $approx > 0 ) {
			printf( ' <span title="%s">🟠 %d</span>', esc_attr__( 'Approximately pinned stops (postcode area)', 'tur-takvimi' ), (int) $approx );
		}
		if ( $unpinned > 0 ) {
			printf( ' <span title="%s">• %d</span>', esc_attr__( 'Stops without a map pin', 'tur-takvimi' ), (int) $unpinned );
		}
	}
}
