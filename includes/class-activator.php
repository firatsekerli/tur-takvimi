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

		// v4: multi-country. Stamp existing cities/routes with the default
		// country so the new per-post field and filter work immediately.
		if ( version_compare( $stored ?: '0', '4', '<' ) ) {
			self::migrate_country();
		}

		// v5: WhatsApp group links moved from region term meta to a single
		// option of {label, url, region, country} rows (any number of links).
		if ( version_compare( $stored ?: '0', '5', '<' ) ) {
			self::migrate_whatsapp_groups();
		}

		self::create_tables();
		( new Schedule() )->regenerate_all();
		update_option( 'tur_takvimi_db_version', TURTAKVIMI_DB_VERSION );
	}

	/**
	 * Convert legacy per-region WhatsApp term metas into group-link rows on
	 * the new option, inferring each region's country from its locations.
	 */
	private static function migrate_whatsapp_groups(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::REGION,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return;
		}

		$rows    = (array) get_option( Whatsapp::GROUPS_OPTION, array() );
		$changed = false;
		foreach ( $terms as $term ) {
			$url = (string) get_term_meta( (int) $term->term_id, Whatsapp::TERM_META, true );
			if ( '' === $url ) {
				continue;
			}
			$exists = false;
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && (int) ( $row['region'] ?? 0 ) === (int) $term->term_id ) {
					$exists = true;
					break;
				}
			}
			if ( ! $exists ) {
				$rows[]  = array(
					'label'   => '',
					'url'     => $url,
					'region'  => (int) $term->term_id,
					'country' => self::region_country( (int) $term->term_id ),
				);
				$changed = true;
			}
			delete_term_meta( (int) $term->term_id, Whatsapp::TERM_META );
		}
		if ( $changed ) {
			update_option( Whatsapp::GROUPS_OPTION, $rows );
		}
	}

	/**
	 * Country a region belongs to, inferred from one of its locations
	 * (falls back to the site default).
	 *
	 * @param int $term_id Region term ID.
	 * @return string ISO-2.
	 */
	private static function region_country( int $term_id ): string {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => Post_Types::REGION,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);
		return $ids ? Country::of_post( (int) $ids[0] ) : Country::default_code();
	}

	/**
	 * Stamp every city and route that has no country yet with the default.
	 */
	private static function migrate_country(): void {
		$default = Country::default_code();
		$posts   = get_posts(
			array(
				'post_type'      => array( Post_Types::LOCATION, Post_Types::ROUTE ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $posts as $id ) {
			if ( '' === (string) get_post_meta( $id, '_tt_country', true ) ) {
				update_post_meta( $id, '_tt_country', $default );
			}
		}
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
		$subscribers     = $wpdb->prefix . 'tt_subscribers';
		$notify_log      = $wpdb->prefix . 'tt_notify_log';

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

		// Subscriber data is user content — created if missing, never dropped.
		$sql_subscribers = "CREATE TABLE {$subscribers} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(150) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(32) NOT NULL DEFAULT '',
			postcode VARCHAR(16) NOT NULL DEFAULT '',
			country CHAR(2) NOT NULL DEFAULT '',
			wa_optin TINYINT NOT NULL DEFAULT 0,
			token VARCHAR(40) NOT NULL DEFAULT '',
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY postcode (postcode),
			KEY wa_optin (wa_optin)
		) {$charset_collate};";

		$sql_notify_log = "CREATE TABLE {$notify_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tour_date DATE NOT NULL,
			kind VARCHAR(8) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			detail VARCHAR(200) NOT NULL DEFAULT '',
			sent DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sub_date_kind (subscriber_id, tour_date, kind)
		) {$charset_collate};";

		dbDelta( $sql_schedule );
		dbDelta( $sql_postcodes );
		dbDelta( $sql_subscribers );
		dbDelta( $sql_notify_log );
	}
}
