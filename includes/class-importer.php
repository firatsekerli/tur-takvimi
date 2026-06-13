<?php
/**
 * CSV importer for locations.
 *
 * Phase 0: a simple, re-runnable CSV importer that creates/updates
 * tt_location posts. A full column-mapping + XLSX UI lands in a later phase.
 *
 * Expected CSV header:
 *   city,postcodes,lat,lng,region,address
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-side location importer.
 */
class Importer {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_tur_takvimi_import', array( $this, 'handle' ) );
		add_action( 'admin_post_tur_takvimi_import_routes', array( $this, 'handle_routes' ) );
	}

	/**
	 * Add the import screen under the Tur Takvimi menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'tur-takvimi',
			__( 'Import Locations', 'tur-takvimi' ),
			__( 'Import', 'tur-takvimi' ),
			'manage_options',
			'tur-takvimi-import',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the upload form and last-run summary.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( 'tur_takvimi_import_notice' );
		delete_transient( 'tur_takvimi_import_notice' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Locations', 'tur-takvimi' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Upload a CSV with header: city, region, address, postcode, frequency. Each row is one delivery address; it is geocoded and pinned on the map automatically. Re-importing the same file fills any missing pins without creating duplicates.', 'tur-takvimi' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tur_takvimi_import">
				<?php wp_nonce_field( 'tur_takvimi_import' ); ?>
				<input type="file" name="csv" accept=".csv" required>
				<?php submit_button( __( 'Import', 'tur-takvimi' ) ); ?>
			</form>

			<hr style="margin:2rem 0;">

			<h2><?php esc_html_e( 'Import Routes', 'tur-takvimi' ); ?></h2>
			<p><?php esc_html_e( 'Upload a CSV with header: route_id, route_group, vehicle, first_visit_date. Routes are matched by route_id (created or updated). Vehicle and date may be left empty.', 'tur-takvimi' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tur_takvimi_import_routes">
				<?php wp_nonce_field( 'tur_takvimi_import_routes' ); ?>
				<input type="file" name="csv" accept=".csv" required>
				<?php submit_button( __( 'Import Routes', 'tur-takvimi' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the uploaded CSV.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tur-takvimi' ) );
		}
		check_admin_referer( 'tur_takvimi_import' );

		// Geocoding each address can take a while for large files.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		ignore_user_abort( true );

		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$this->redirect( __( 'No file uploaded.', 'tur-takvimi' ) );
		}

		$handle = fopen( $_FILES['csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			$this->redirect( __( 'Could not read the file.', 'tur-takvimi' ) );
		}

		$header = array_map(
			static fn( $h ) => strtolower( trim( (string) $h ) ),
			(array) fgetcsv( $handle )
		);
		$index = array_flip( $header );

		$created = 0;
		$updated = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$get  = static fn( $key ) => isset( $index[ $key ] ) ? trim( (string) ( $row[ $index[ $key ] ] ?? '' ) ) : '';
			$city = $get( 'city' );
			if ( '' === $city ) {
				continue;
			}

			$existing = $this->find_location( $city );
			$is_new   = ! $existing;

			$location_id = $existing ?: wp_insert_post(
				array(
					'post_type'   => Post_Types::LOCATION,
					'post_status' => 'publish',
					'post_title'  => $city,
				)
			);
			if ( is_wp_error( $location_id ) || ! $location_id ) {
				continue;
			}

			$this->apply_meta( (int) $location_id, $get );

			if ( $is_new ) {
				++$created;
			} else {
				++$updated;
			}
		}
		fclose( $handle );

		// Refresh materialized schedule so new data appears immediately.
		( new Schedule() )->regenerate_all();

		$this->redirect(
			sprintf(
				/* translators: 1: created count, 2: updated count */
				__( 'Import complete: %1$d created, %2$d updated.', 'tur-takvimi' ),
				$created,
				$updated
			)
		);
	}

	/**
	 * Handle the uploaded route CSV (route_id, route_group, vehicle,
	 * first_visit_date). Routes are matched by route_id.
	 */
	public function handle_routes(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tur-takvimi' ) );
		}
		check_admin_referer( 'tur_takvimi_import_routes' );

		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$this->redirect( __( 'No file uploaded.', 'tur-takvimi' ) );
		}

		$handle = fopen( $_FILES['csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			$this->redirect( __( 'Could not read the file.', 'tur-takvimi' ) );
		}

		// Map header aliases (English + Turkish) to canonical keys.
		$aliases = array(
			'code'    => array( 'route_id', 'route id', 'rota id', 'rota_id', 'paket id', 'code', 'id' ),
			'group'   => array( 'route_group', 'route group', 'rota grubu', 'rota_grubu', 'group', 'grup' ),
			'vehicle' => array( 'vehicle', 'araç', 'arac', 'araba' ),
			'date'    => array( 'first_visit_date', 'first visit date', 'ilk ziyaret tarihi', 'i̇lk ziyaret tarihi', 'anchor', 'anchor_date', 'date', 'tarih' ),
		);
		$raw_header = array_map(
			static fn( $h ) => function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $h ) ) : strtolower( trim( (string) $h ) ),
			(array) fgetcsv( $handle )
		);
		$index = array();
		foreach ( $aliases as $key => $names ) {
			foreach ( $raw_header as $pos => $name ) {
				if ( in_array( $name, $names, true ) ) {
					$index[ $key ] = $pos;
					break;
				}
			}
		}

		$created = 0;
		$updated = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$get  = static fn( $key ) => isset( $index[ $key ] ) ? trim( (string) ( $row[ $index[ $key ] ] ?? '' ) ) : '';
			$code = $get( 'code' );
			if ( '' === $code ) {
				continue;
			}

			$group   = $get( 'group' );
			$existing = $this->find_route( $code );
			$is_new   = ! $existing;

			$route_id = $existing ?: wp_insert_post(
				array(
					'post_type'   => Post_Types::ROUTE,
					'post_status' => 'publish',
					'post_title'  => trim( implode( ' - ', array_filter( array( $code, $group ) ) ) ),
				)
			);
			if ( is_wp_error( $route_id ) || ! $route_id ) {
				continue;
			}

			update_post_meta( (int) $route_id, '_tt_route_code', $code );
			if ( '' !== $get( 'vehicle' ) ) {
				update_post_meta( (int) $route_id, '_tt_vehicle', $get( 'vehicle' ) );
			}
			if ( '' !== $get( 'date' ) ) {
				update_post_meta( (int) $route_id, '_tt_anchor_date', $get( 'date' ) );
			}
			if ( '' !== $group ) {
				wp_set_object_terms( (int) $route_id, $group, Post_Types::REGION, false );
			}

			if ( $is_new ) {
				++$created;
			} else {
				++$updated;
			}
		}
		fclose( $handle );

		( new Schedule() )->regenerate_all();

		$this->redirect(
			sprintf(
				/* translators: 1: created count, 2: updated count */
				__( 'Route import complete: %1$d created, %2$d updated.', 'tur-takvimi' ),
				$created,
				$updated
			)
		);
	}

	/**
	 * Find an existing route by its route code.
	 *
	 * @param string $code Route code (e.g. R07).
	 * @return int|null
	 */
	private function find_route( string $code ): ?int {
		$found = get_posts(
			array(
				'post_type'      => Post_Types::ROUTE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_tt_route_code',
						'value' => $code,
					),
				),
			)
		);
		return $found ? (int) $found[0] : null;
	}

	/**
	 * Apply region + address (with coordinates and frequency) to a location.
	 *
	 * One CSV row = one delivery address. Coordinates are taken from the CSV
	 * (lat/lng) when present, otherwise geocoded server-side so the address is
	 * pinned on the map. Re-importing the same file fills missing pins without
	 * creating duplicates (addresses are matched by street + postcode).
	 *
	 * @param int      $location_id Location ID.
	 * @param callable $get         Column accessor.
	 */
	private function apply_meta( int $location_id, callable $get ): void {
		$region  = $get( 'region' );
		if ( '' !== $region ) {
			wp_set_object_terms( $location_id, $region, Post_Types::REGION );
		}

		$address  = $get( 'address' );
		$postcode = $get( 'postcode' );
		if ( '' === $postcode ) {
			$postcode = $get( 'postcodes' ); // Back-compat header name.
		}
		$lat  = $get( 'lat' );
		$lng  = $get( 'lng' );
		$freq = $get( 'frequency' );

		$addresses = json_decode( (string) get_post_meta( $location_id, '_tt_addresses', true ), true );
		$addresses = is_array( $addresses ) ? $addresses : array();

		if ( '' !== $address ) {
			// Geocode for the map pin when coordinates were not supplied.
			if ( '' === $lat || '' === $lng ) {
				$point = $this->geocode_address( $address, $postcode, get_the_title( $location_id ) );
				if ( $point ) {
					$lat = (string) $point['lat'];
					$lng = (string) $point['lng'];
				}
			}

			$entry = array(
				'address'  => $address,
				'postcode' => $postcode,
			);
			if ( '' !== $lat && '' !== $lng ) {
				$entry['lat'] = (float) str_replace( ',', '.', $lat );
				$entry['lng'] = (float) str_replace( ',', '.', $lng );
			}
			if ( '' !== $freq ) {
				$entry['frequency'] = max( 0, (int) $freq );
			}

			// Dedupe by street + postcode so re-imports update instead of append.
			$matched = false;
			foreach ( $addresses as &$existing ) {
				if ( strcasecmp( (string) ( $existing['address'] ?? '' ), $address ) === 0
					&& (string) ( $existing['postcode'] ?? '' ) === $postcode ) {
					if ( isset( $entry['lat'], $entry['lng'] ) ) {
						$existing['lat'] = $entry['lat'];
						$existing['lng'] = $entry['lng'];
					}
					if ( isset( $entry['frequency'] ) ) {
						$existing['frequency'] = $entry['frequency'];
					}
					$matched = true;
					break;
				}
			}
			unset( $existing );
			if ( ! $matched ) {
				$addresses[] = $entry;
			}
			update_post_meta( $location_id, '_tt_addresses', wp_json_encode( $addresses ) );
		}

		// Covered postcodes = unique set across the address list (+ a bare row).
		$postcodes = array();
		foreach ( $addresses as $a ) {
			if ( '' !== (string) ( $a['postcode'] ?? '' ) ) {
				$postcodes[] = (string) $a['postcode'];
			}
		}
		if ( '' !== $postcode && '' === $address ) {
			$postcodes[] = $postcode;
		}
		$postcodes = array_values( array_unique( $postcodes ) );
		if ( $postcodes ) {
			update_post_meta( $location_id, '_tt_postcodes', implode( ' ', $postcodes ) );
		}

		// City centroid = average of address pins (fallback: a row-level lat/lng).
		$sum_lat = 0.0;
		$sum_lng = 0.0;
		$count   = 0;
		foreach ( $addresses as $a ) {
			if ( isset( $a['lat'], $a['lng'] ) ) {
				$sum_lat += (float) $a['lat'];
				$sum_lng += (float) $a['lng'];
				++$count;
			}
		}
		if ( $count > 0 ) {
			update_post_meta( $location_id, '_tt_lat', round( $sum_lat / $count, 6 ) );
			update_post_meta( $location_id, '_tt_lng', round( $sum_lng / $count, 6 ) );
		} elseif ( '' !== $lat && '' !== $lng ) {
			update_post_meta( $location_id, '_tt_lat', (float) str_replace( ',', '.', $lat ) );
			update_post_meta( $location_id, '_tt_lng', (float) str_replace( ',', '.', $lng ) );
		}
	}

	/**
	 * Geocode a single address (cached) so it can be pinned on the map.
	 *
	 * @param string $street   Street + house number.
	 * @param string $postcode Postcode.
	 * @param string $city     City name.
	 * @return array{lat:float,lng:float}|null
	 */
	private function geocode_address( string $street, string $postcode, string $city ): ?array {
		$query = trim( $street . ', ' . trim( $postcode . ' ' . $city ) );
		if ( strlen( $query ) < 5 ) {
			return null;
		}
		$key    = 'tt_geoaddr_' . md5( strtolower( $query ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'miss' === $cached ) {
			return null;
		}

		$results = Geocoder::search( $query, 1 );
		if ( empty( $results ) ) {
			set_transient( $key, 'miss', WEEK_IN_SECONDS );
			return null;
		}
		$point = array(
			'lat' => (float) $results[0]['lat'],
			'lng' => (float) $results[0]['lng'],
		);
		set_transient( $key, $point, MONTH_IN_SECONDS );
		return $point;
	}

	/**
	 * Find an existing location by exact title.
	 *
	 * @param string $city City name.
	 * @return int|null
	 */
	private function find_location( string $city ): ?int {
		$found = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'any',
				'title'          => $city,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $found ? (int) $found[0] : null;
	}

	/**
	 * Redirect back to the import screen with a notice.
	 *
	 * @param string $message Notice text.
	 */
	private function redirect( string $message ): void {
		set_transient( 'tur_takvimi_import_notice', $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=tur-takvimi-import' ) );
		exit;
	}
}
