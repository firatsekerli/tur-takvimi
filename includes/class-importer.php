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
			<p><?php esc_html_e( 'Upload a CSV with header: city, postcodes, lat, lng, region, address. Rows with the same city are merged (addresses appended).', 'tur-takvimi' ); ?></p>
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
	 * Apply postcode/geo/region/address meta to a location.
	 *
	 * @param int      $location_id Location ID.
	 * @param callable $get         Column accessor.
	 */
	private function apply_meta( int $location_id, callable $get ): void {
		$postcodes = $get( 'postcodes' );
		$lat       = $get( 'lat' );
		$lng       = $get( 'lng' );
		$region    = $get( 'region' );
		$address   = $get( 'address' );

		if ( '' !== $postcodes ) {
			$existing = (string) get_post_meta( $location_id, '_tt_postcodes', true );
			$merged   = trim( $existing . ' ' . $postcodes );
			update_post_meta( $location_id, '_tt_postcodes', $merged );
		}
		if ( '' !== $lat ) {
			update_post_meta( $location_id, '_tt_lat', (float) str_replace( ',', '.', $lat ) );
		}
		if ( '' !== $lng ) {
			update_post_meta( $location_id, '_tt_lng', (float) str_replace( ',', '.', $lng ) );
		}
		if ( '' !== $region ) {
			wp_set_object_terms( $location_id, $region, Post_Types::REGION );
		}
		if ( '' !== $address ) {
			$addresses   = json_decode( (string) get_post_meta( $location_id, '_tt_addresses', true ), true );
			$addresses   = is_array( $addresses ) ? $addresses : array();
			$addresses[] = array(
				'address'  => $address,
				'postcode' => $postcodes,
			);
			update_post_meta( $location_id, '_tt_addresses', wp_json_encode( $addresses ) );
		}
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
