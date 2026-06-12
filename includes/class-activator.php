<?php
/**
 * Activation / deactivation routines.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Creates custom tables, registers rewrite rules and seeds bundled data.
 */
class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::create_tables();

		// Register post types so their rewrite rules exist before we flush.
		( new Post_Types() )->register_post_types();
		flush_rewrite_rules();

		Postcode::maybe_seed();

		add_option( 'tur_takvimi_version', TURTAKVIMI_VERSION );
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Create the schedule and postcode tables.
	 */
	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$schedule        = $wpdb->prefix . 'tt_schedule';
		$postcodes       = $wpdb->prefix . 'tt_postcodes';

		$sql_schedule = "CREATE TABLE {$schedule} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			route_id BIGINT UNSIGNED NOT NULL,
			tour_date DATE NOT NULL,
			weekday TINYINT NOT NULL,
			vehicle VARCHAR(100) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			PRIMARY KEY  (id),
			KEY tour_date (tour_date),
			KEY route_id (route_id),
			UNIQUE KEY route_date (route_id, tour_date)
		) {$charset_collate};";

		$sql_postcodes = "CREATE TABLE {$postcodes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			country CHAR(2) NOT NULL DEFAULT 'NL',
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
