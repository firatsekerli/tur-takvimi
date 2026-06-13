<?php
/**
 * Activation / deactivation and schema migrations.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Creates custom tables, registers rewrite rules and runs migrations.
 */
class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::create_tables();

		( new Post_Types() )->register_post_types();
		flush_rewrite_rules();

		add_option( 'tur_takvimi_version', TURTAKVIMI_VERSION );
		update_option( 'tur_takvimi_db_version', TURTAKVIMI_DB_VERSION );
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Apply schema changes after a plugin file update (no reactivation needed).
	 *
	 * The schedule table is a derived cache, so it is safe to rebuild and
	 * re-materialize whenever the DB version changes.
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( 'tur_takvimi_db_version' );
		if ( $stored === TURTAKVIMI_DB_VERSION ) {
			return;
		}

		// v3: route membership moved from the route (_tt_location_ids) to the
		// location (_tt_route_id, many-to-many). Migrate existing assignments.
		if ( version_compare( $stored ?: '0', '3', '<' ) ) {
			self::migrate_route_membership();
		}

		self::create_tables();
		( new Schedule() )->regenerate_all();
		update_option( 'tur_takvimi_db_version', TURTAKVIMI_DB_VERSION );
	}

	/**
	 * Copy each route's old location list onto the locations as _tt_route_id,
	 * preserving the original order as the route's manual override.
	 */
	private static function migrate_route_membership(): void {
		$routes = get_posts(
			array(
				'post_type'      => Post_Types::ROUTE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $routes as $route_id ) {
			$ids = json_decode( (string) get_post_meta( $route_id, '_tt_location_ids', true ), true );
			if ( ! is_array( $ids ) || empty( $ids ) ) {
				continue;
			}
			$ids = array_values( array_map( 'intval', $ids ) );
			foreach ( $ids as $location_id ) {
				$existing = array_map( 'intval', (array) get_post_meta( $location_id, '_tt_route_id', false ) );
				if ( ! in_array( (int) $route_id, $existing, true ) ) {
					add_post_meta( $location_id, '_tt_route_id', (int) $route_id );
				}
			}
			update_post_meta( $route_id, '_tt_location_order', wp_json_encode( $ids ) );
			delete_post_meta( $route_id, '_tt_location_ids' );
		}
	}

	/**
	 * Create/refresh the schedule and postcode tables.
	 */
	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$schedule        = $wpdb->prefix . 'tt_schedule';
		$postcodes       = $wpdb->prefix . 'tt_postcodes';

		// Schedule is a derived cache; drop it so key/column changes apply cleanly.
		$wpdb->query( "DROP TABLE IF EXISTS {$schedule}" ); // phpcs:ignore WordPress.DB

		$sql_schedule = "CREATE TABLE {$schedule} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			route_id BIGINT UNSIGNED NOT NULL,
			location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tour_date DATE NOT NULL,
			weekday TINYINT NOT NULL,
			vehicle VARCHAR(100) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			PRIMARY KEY  (id),
			KEY tour_date (tour_date),
			KEY route_id (route_id),
			KEY location_id (location_id),
			UNIQUE KEY route_loc_date (route_id, location_id, tour_date)
		) {$charset_collate};";

		$sql_postcodes = "CREATE TABLE {$postcodes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			country CHAR(2) NOT NULL DEFAULT 'DE',
			pc4 VARCHAR(8) NOT NULL,
			city VARCHAR(150) NOT NULL DEFAULT '',
			lat DECIMAL(9,6) NOT NULL,
			lng DECIMAL(9,6) NOT NULL,
			PRIMARY KEY  (id),
			KEY country_pc4 (country, pc4)
		) {$charset_collate};";

		dbDelta( $sql_schedule );
		dbDelta( $sql_postcodes );
	}
}
