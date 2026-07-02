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
	 * Addresses geocoded per page load (kept small to stay well under the
	 * web-server gateway timeout).
	 */
	const GEOCODE_BATCH = 12;

	/**
	 * Locations whose addresses have already been reset in the current import
	 * run (so the first CSV row for a city replaces stale data, then following
	 * rows append).
	 *
	 * @var array<int,bool>
	 */
	private $reset_addresses = array();

	/**
	 * Message from the last geocode batch that stopped on a provider error.
	 *
	 * @var string
	 */
	private $last_geo_error = '';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_tur_takvimi_import', array( $this, 'handle' ) );
		add_action( 'admin_post_tur_takvimi_import_routes', array( $this, 'handle_routes' ) );
		// Clean JSON endpoint for the geocoding progress bar (admin-ajax, so no
		// admin-page HTML is prepended to the response).
		add_action( 'wp_ajax_tur_takvimi_geocode_batch', array( $this, 'ajax_geocode_batch' ) );
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

		// Background pin geocoder: process one small batch per page load and
		// auto-continue via a meta refresh, so no single request can time out.
		if ( isset( $_GET['tt_geocode'] ) && 'run' === $_GET['tt_geocode'] ) {
			$this->render_geocode();
			return;
		}

		// "Dismiss" the could-not-pin banner: stop flagging the remaining
		// unmatched addresses (the admin will place them by hand, or accept it).
		if ( isset( $_GET['tt_dismiss'] ) && wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'tt_dismiss' ) ) {
			$this->dismiss_unpinned();
		}

		$notice = get_transient( 'tur_takvimi_import_notice' );
		delete_transient( 'tur_takvimi_import_notice' );
		list( $total, $done ) = $this->geocode_stats();
		$pending     = $total - $done;
		$geocode_url = wp_nonce_url( admin_url( 'admin.php?page=tur-takvimi-import&tt_geocode=run' ), 'tt_geocode' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Locations', 'tur-takvimi' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( $pending > 0 ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						/* translators: %d: number of addresses still needing a map pin. */
						echo esc_html( sprintf( __( '%d addresses still need a map pin.', 'tur-takvimi' ), $pending ) );
						?>
						<a class="button button-primary" href="<?php echo esc_url( $geocode_url ); ?>"><?php esc_html_e( 'Geocode pins now', 'tur-takvimi' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
			<?php
			$unpinned = $this->unpinned_addresses();
			$unpinned_total = array_sum( array_map( static fn( $u ) => count( $u['addresses'] ), $unpinned ) );
			if ( $unpinned_total > 0 ) :
				$retry_url   = wp_nonce_url( admin_url( 'admin.php?page=tur-takvimi-import&tt_geocode=run&retry=1' ), 'tt_geocode' );
				$dismiss_url = wp_nonce_url( admin_url( 'admin.php?page=tur-takvimi-import&tt_dismiss=1' ), 'tt_dismiss' );
				?>
				<div class="notice notice-error">
					<p>
						<strong>
							<?php
							/* translators: %d: number of addresses that could not be geocoded. */
							echo esc_html( sprintf( __( '%d addresses could not be pinned automatically.', 'tur-takvimi' ), $unpinned_total ) );
							?>
						</strong>
						<a class="button" href="<?php echo esc_url( $retry_url ); ?>"><?php esc_html_e( 'Retry failed pins', 'tur-takvimi' ); ?></a>
						<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'tur-takvimi' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'Open a city below and search the address to place its pin manually, or retry the lookups.', 'tur-takvimi' ); ?></p>
					<ul style="margin:.25rem 0 .5rem 1rem;list-style:disc;max-height:260px;overflow:auto;">
						<?php foreach ( $unpinned as $u ) : ?>
							<li>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $u['id'] ) ); ?>"><strong><?php echo esc_html( $u['title'] ); ?></strong></a>:
								<?php echo esc_html( implode( ' · ', $u['addresses'] ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Upload a CSV with header: city, region, address, postcode, frequency. Each row is one delivery address. Importing is fast; map pins are geocoded afterwards on a progress screen (and can be resumed any time).', 'tur-takvimi' ); ?></p>
			<p><?php esc_html_e( 'Optional: add a "routes" column (e.g. R07;R08) to assign each city to its routes. Import routes first so the codes resolve.', 'tur-takvimi' ); ?></p>
			<p><?php esc_html_e( 'Optional: a "country" column (ISO-2, e.g. NL) sets each row\'s country; it defaults to the site default.', 'tur-takvimi' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tur_takvimi_import">
				<?php wp_nonce_field( 'tur_takvimi_import' ); ?>
				<input type="file" name="csv" accept=".csv" required>
				<?php submit_button( __( 'Import', 'tur-takvimi' ) ); ?>
			</form>

			<hr style="margin:2rem 0;">

			<h2><?php esc_html_e( 'Import Routes', 'tur-takvimi' ); ?></h2>
			<p><?php esc_html_e( 'Upload a CSV with header: route_id, route_group, vehicle, first_visit_date. Routes are matched by route_id (created or updated). Vehicle and date may be left empty. An optional "country" column (ISO-2) sets the route\'s country.', 'tur-takvimi' ); ?></p>
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
	 * The geocoding screen: renders the progress bar instantly and drives the
	 * batches with JavaScript (fetch), so the bar stays on screen and updates in
	 * place instead of blanking out while each batch runs server-side.
	 */
	private function render_geocode(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tt_geocode' ) ) {
			wp_die( esc_html__( 'Invalid or expired link. Please return to the import screen.', 'tur-takvimi' ) );
		}

		// "Retry" clears the could-not-geocode flags once before geocoding again.
		if ( ! empty( $_GET['retry'] ) ) {
			$this->clear_nogeo_flags();
		}

		list( $total, $done ) = $this->geocode_stats();
		// Raw URL (not wp_nonce_url, which esc_html-encodes & into &#038; — fine
		// for an href but it mangles the query string when used in fetch()).
		$batch_url = add_query_arg(
			array(
				'action'   => 'tur_takvimi_geocode_batch',
				'_wpnonce' => wp_create_nonce( 'tt_geocode' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		$back_url  = admin_url( 'admin.php?page=tur-takvimi-import' );

		$strings = array(
			'working' => __( 'Pinned %1$d of %2$d addresses (%3$d%%). Working…', 'tur-takvimi' ),
			'done'    => __( 'Done — %d addresses processed.', 'tur-takvimi' ),
			'rate'    => __( 'Geocoder rate limit reached — retrying automatically in %d seconds…', 'tur-takvimi' ),
			'stalled' => __( 'The geocoder is not responding, so it was paused. Check Tur Takvimi → Settings (geocoder provider / API key).', 'tur-takvimi' ),
			'network' => __( 'Connection problem — retrying…', 'tur-takvimi' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Geocoding map pins…', 'tur-takvimi' ); ?></h1>
			<p id="tt-geo-msg"><?php echo esc_html( sprintf( $strings['working'], $done, $total, $total > 0 ? (int) round( $done * 100 / $total ) : 100 ) ); ?></p>
			<progress id="tt-geo-bar" value="<?php echo esc_attr( (string) $done ); ?>" max="<?php echo esc_attr( (string) $total ); ?>" style="width:320px;height:18px;"></progress>
			<div id="tt-geo-stalled" class="notice notice-error" style="display:none;"><p id="tt-geo-stalled-msg"></p></div>
			<p>
				<a class="button button-primary" id="tt-geo-retry" href="<?php echo esc_url( $batch_url ); ?>" style="display:none;"><?php esc_html_e( 'Continue now', 'tur-takvimi' ); ?></a>
				<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'tur-takvimi' ); ?></a>
			</p>
		</div>
		<script>
		( function () {
			var batchUrl = <?php echo wp_json_encode( $batch_url ); ?>;
			var doneUrl  = <?php echo wp_json_encode( $back_url ); ?>;
			var str      = <?php echo wp_json_encode( $strings ); ?>;
			var bar     = document.getElementById( 'tt-geo-bar' );
			var msg     = document.getElementById( 'tt-geo-msg' );
			var stalled = document.getElementById( 'tt-geo-stalled' );
			var sMsg    = document.getElementById( 'tt-geo-stalled-msg' );
			var retry   = document.getElementById( 'tt-geo-retry' );

			function fmt( tpl ) {
				var a = arguments;
				return tpl.replace( /%%|%(\d)\$d|%d/g, function ( m, n ) {
					if ( m === '%%' ) { return '%'; }
					return a[ n ? parseInt( n, 10 ) : 1 ];
				} );
			}
			function pct( d, t ) { return t > 0 ? Math.round( d * 100 / t ) : 100; }

			function step() {
				stalled.style.display = 'none';
				retry.style.display = 'none';
				fetch( batchUrl, { credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( d ) {
						if ( ! d || ! d.ok ) { showStalled( ( d && d.error ) || '' ); return; }
						bar.value = d.done;
						bar.max = d.total;
						if ( d.done >= d.total ) {
							msg.textContent = fmt( str.done, d.total );
							bar.value = bar.max;
							setTimeout( function () { window.location.href = doneUrl; }, 1200 );
							return;
						}
						if ( d.rateLimited ) { countdown( 30 ); return; }
						if ( d.stalled ) { showStalled( d.error || str.stalled ); return; }
						msg.textContent = fmt( str.working, d.done, d.total, pct( d.done, d.total ) );
						step();
					} )
					.catch( function () { msg.textContent = str.network; setTimeout( step, 3000 ); } );
			}

			function countdown( secs ) {
				msg.textContent = fmt( str.rate, secs );
				var iv = setInterval( function () {
					secs -= 1;
					if ( secs <= 0 ) { clearInterval( iv ); step(); } else { msg.textContent = fmt( str.rate, secs ); }
				}, 1000 );
			}

			function showStalled( err ) {
				sMsg.textContent = str.stalled + ( err ? ' (' + err + ')' : '' );
				stalled.style.display = '';
				retry.style.display = '';
			}

			retry.addEventListener( 'click', function ( e ) { e.preventDefault(); step(); } );
			step();
		}() );
		</script>
		<?php
	}

	/**
	 * Process one batch and return JSON progress. Called repeatedly by the
	 * geocoding screen's JavaScript (tt_geocode=batch).
	 */
	public function ajax_geocode_batch(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'tt_geocode' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'auth' ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$processed            = $this->geocode_batch( self::GEOCODE_BATCH );
		list( $total, $done ) = $this->geocode_stats();
		$pending  = $total - $done;
		$stalled  = $pending > 0 && 0 === $processed && '' !== $this->last_geo_error;
		$rate     = $stalled && ( false !== stripos( $this->last_geo_error, '429' ) || false !== stripos( $this->last_geo_error, 'rate limit' ) );

		wp_send_json(
			array(
				'ok'          => true,
				'processed'   => $processed,
				'total'       => $total,
				'done'        => $done,
				'stalled'     => $stalled && ! $rate,
				'rateLimited' => $rate,
				'error'       => $this->last_geo_error,
			)
		);
	}

	/**
	 * Handle the uploaded CSV.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tur-takvimi' ) );
		}
		check_admin_referer( 'tur_takvimi_import' );

		// The import itself does no network calls, so it is fast; pins are
		// geocoded afterwards in batches. Still raise the limit for big files.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

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

		$this->reset_addresses = array();
		$created               = 0;
		$updated               = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$get  = static fn( $key ) => isset( $index[ $key ] ) ? trim( (string) ( $row[ $index[ $key ] ] ?? '' ) ) : '';
			$city = $get( 'city' );
			if ( '' === $city ) {
				continue;
			}

			$existing = $this->find_location( $city, $this->row_country( $get ) );
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

			// First row for this city in this run replaces any stale addresses.
			if ( empty( $this->reset_addresses[ (int) $location_id ] ) ) {
				delete_post_meta( (int) $location_id, '_tt_addresses' );
				$this->reset_addresses[ (int) $location_id ] = true;
			}

			$this->apply_meta( (int) $location_id, $get );
			$this->assign_routes( (int) $location_id, $get );

			if ( $is_new ) {
				++$created;
			} else {
				++$updated;
			}
		}
		fclose( $handle );

		// Refresh materialized schedule so new data appears immediately.
		( new Schedule() )->regenerate_all();

		set_transient(
			'tur_takvimi_import_notice',
			sprintf(
				/* translators: 1: created count, 2: updated count */
				__( 'Import complete: %1$d created, %2$d updated. Geocoding map pins…', 'tur-takvimi' ),
				$created,
				$updated
			),
			60
		);

		// Hand off to the batched pin geocoder (runs to completion, no timeout).
		wp_safe_redirect( wp_nonce_url( admin_url( 'admin.php?page=tur-takvimi-import&tt_geocode=run' ), 'tt_geocode' ) );
		exit;
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
			'group'   => array( 'route_group', 'route group', 'rota grubu', 'rota_grubu', 'rota bölgesi', 'rota bolgesi', 'group', 'grup' ),
			'vehicle' => array( 'vehicle', 'araç', 'arac', 'araba' ),
			'date'    => array( 'first_visit_date', 'first visit date', 'ilk ziyaret tarihi', 'i̇lk ziyaret tarihi', 'anchor', 'anchor_date', 'date', 'tarih' ),
			'country' => array( 'country', 'ülke', 'ulke', 'land' ),
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

			$group    = $get( 'group' );
			$existing = $this->find_route( $code, $this->row_country( $get ) );
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
			update_post_meta( (int) $route_id, '_tt_country', $this->row_country( $get ) );
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
	 * Find an existing route by its route code, scoped to a country.
	 *
	 * Route codes (R01, R02…) are reused across countries, so the same code in a
	 * different country is a different route. Matching without the country would
	 * make a Dutch R01 overwrite a German R01.
	 *
	 * @param string $code    Route code (e.g. R07).
	 * @param string $country Optional ISO-2 country to scope to.
	 * @return int|null
	 */
	private function find_route( string $code, string $country = '' ): ?int {
		$meta = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array(
				'key'   => '_tt_route_code',
				'value' => $code,
			),
		);
		if ( '' !== $country ) {
			$meta[] = array(
				'key'   => '_tt_country',
				'value' => strtoupper( $country ),
			);
		}
		$found = get_posts(
			array(
				'post_type'      => Post_Types::ROUTE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
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
		update_post_meta( $location_id, '_tt_country', $this->row_country( $get ) );

		$region = $get( 'region' );
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
		$time = $get( 'time' );
		if ( '' === $time ) {
			$time = $get( 'saat' ); // Turkish header.
		}
		if ( '' === $time ) {
			$time = $get( 'hour' );
		}

		$addresses = json_decode( (string) get_post_meta( $location_id, '_tt_addresses', true ), true );
		$addresses = is_array( $addresses ) ? $addresses : array();

		if ( '' !== $address ) {
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
			if ( '' !== $time ) {
				$entry['time'] = $time;
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
					if ( isset( $entry['time'] ) ) {
						$existing['time'] = $entry['time'];
					}
					$matched = true;
					break;
				}
			}
			unset( $existing );
			if ( ! $matched ) {
				$addresses[] = $entry;
			}
			update_post_meta( $location_id, '_tt_addresses', wp_json_encode( $addresses, JSON_UNESCAPED_UNICODE ) );
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
	 * Assign a location to the routes named in the CSV "routes" column
	 * (e.g. "R07;R08"). Codes are matched to existing routes by route code;
	 * import routes first so the codes resolve. Assignment is additive, so a
	 * re-import or manual ticks are preserved.
	 *
	 * @param int      $location_id Location ID.
	 * @param callable $get         Column accessor.
	 */
	private function assign_routes( int $location_id, callable $get ): void {
		$raw = $get( 'routes' );
		if ( '' === $raw ) {
			return; // No column / empty: leave existing assignments untouched.
		}

		// Link to routes in the city's own country, so a Dutch city's R01 finds
		// the Dutch R01 rather than a same-coded route in another country.
		$country  = (string) get_post_meta( $location_id, '_tt_country', true );
		$existing = array_map( 'intval', (array) get_post_meta( $location_id, '_tt_route_id', false ) );
		foreach ( preg_split( '/[;,|]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $code ) {
			$route_id = $this->find_route( trim( $code ), $country );
			if ( $route_id && ! in_array( $route_id, $existing, true ) ) {
				add_post_meta( $location_id, '_tt_route_id', $route_id );
				$existing[] = $route_id;
			}
		}
	}

	/**
	 * Country for a CSV row: the row's "country" column, else the site default.
	 *
	 * @param callable $get Column accessor.
	 * @return string ISO-2.
	 */
	private function row_country( callable $get ): string {
		$code = strtoupper( (string) $get( 'country' ) );
		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : Country::default_code();
	}

	/**
	 * Geocode a single address (cached).
	 *
	 * @param string $street   Street + house number.
	 * @param string $postcode Postcode.
	 * @param string $city     City name.
	 * @return array{lat:float,lng:float}|false|null Point on success, false on a
	 *         genuine no-match (flag it), null on a transient error (retry).
	 */
	private function geocode_address( string $street, string $postcode, string $city, string $country = '' ) {
		$query = trim( $street . ', ' . trim( $postcode . ' ' . $city ) );
		if ( strlen( $query ) < 5 ) {
			return false;
		}
		$country = '' !== $country ? $country : Country::default_code();
		$key     = 'tt_geoaddr_' . md5( strtolower( $country . '|' . $query ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'miss' === $cached ) {
			return false;
		}

		$results = Geocoder::search( $query, 1, $country );
		if ( ! empty( $results ) ) {
			$point = array(
				'lat'    => (float) $results[0]['lat'],
				'lng'    => (float) $results[0]['lng'],
				'approx' => false,
			);
			set_transient( $key, $point, MONTH_IN_SECONDS );
			return $point;
		}

		// Don't cache or flag a transient failure — let it retry next pass.
		if ( Geocoder::last_was_transient_error() ) {
			return null;
		}

		// Exact address not found: fall back to the postcode centroid so the
		// pin still lands in the right neighbourhood (flagged approximate).
		$pc = trim( $postcode );
		if ( '' !== $pc ) {
			$centroid = Geocoder::geocode_postcode( $pc, $country );
			if ( is_array( $centroid ) ) {
				$point = array(
					'lat'    => (float) $centroid['lat'],
					'lng'    => (float) $centroid['lng'],
					'approx' => true,
				);
				set_transient( $key, $point, MONTH_IN_SECONDS );
				return $point;
			}
			if ( Geocoder::last_was_transient_error() ) {
				return null;
			}
		}

		set_transient( $key, 'miss', WEEK_IN_SECONDS );
		return false;
	}

	/**
	 * All location IDs (any status).
	 *
	 * @return int[]
	 */
	private function all_location_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::LOCATION,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	/**
	 * Count how many addresses exist and how many are already resolved
	 * (have coordinates or were tried and could not be geocoded).
	 *
	 * @return array{0:int,1:int} [total, done]
	 */
	private function geocode_stats(): array {
		$total = 0;
		$done  = 0;
		foreach ( $this->all_location_ids() as $lid ) {
			$addresses = json_decode( (string) get_post_meta( $lid, '_tt_addresses', true ), true );
			if ( ! is_array( $addresses ) ) {
				continue;
			}
			foreach ( $addresses as $a ) {
				if ( '' === (string) ( $a['address'] ?? '' ) ) {
					continue;
				}
				++$total;
				if ( ( isset( $a['lat'], $a['lng'] ) ) || ! empty( $a['nogeo'] ) ) {
					++$done;
				}
			}
		}
		return array( $total, $done );
	}

	/**
	 * Geocode up to $limit unresolved addresses, filling map pins. Addresses
	 * that cannot be geocoded are flagged so they are not retried forever.
	 *
	 * @param int $limit Max addresses to process this call.
	 * @return int Number processed.
	 */
	private function geocode_batch( int $limit ): int {
		$this->last_geo_error = '';
		$processed            = 0;
		$stop                 = false;
		foreach ( $this->all_location_ids() as $lid ) {
			if ( $processed >= $limit || $stop ) {
				break;
			}
			$addresses = json_decode( (string) get_post_meta( $lid, '_tt_addresses', true ), true );
			if ( ! is_array( $addresses ) ) {
				continue;
			}
			$changed = false;
			foreach ( $addresses as &$a ) {
				if ( $processed >= $limit ) {
					break;
				}
				if ( '' === (string) ( $a['address'] ?? '' ) ) {
					continue;
				}
				if ( isset( $a['lat'], $a['lng'] ) || ! empty( $a['nogeo'] ) ) {
					continue;
				}
				$point = $this->geocode_address( (string) $a['address'], (string) ( $a['postcode'] ?? '' ), get_the_title( $lid ), Country::of_post( $lid ) );
				if ( is_array( $point ) ) {
					$a['lat'] = $point['lat'];
					$a['lng'] = $point['lng'];
					if ( ! empty( $point['approx'] ) ) {
						$a['approx'] = true;
					} else {
						unset( $a['approx'] );
					}
					unset( $a['nogeo'] );
				} elseif ( false === $point ) {
					$a['nogeo'] = true;
				} else {
					// Transient provider error: stop and surface it; retry later.
					$this->last_geo_error = Geocoder::$last_debug['error'] ? (string) Geocoder::$last_debug['error'] : __( 'Geocoder unavailable.', 'tur-takvimi' );
					$stop                 = true;
					break;
				}
				++$processed;
				$changed = true;
				// Pacing is handled centrally inside the geocoder (throttle), so
				// each address — including its postcode fallback — stays spaced.
			}
			unset( $a );
			if ( $changed ) {
				update_post_meta( $lid, '_tt_addresses', wp_json_encode( $addresses, JSON_UNESCAPED_UNICODE ) );
				$this->recompute_centroid( $lid, $addresses );
			}
		}
		return $processed;
	}

	/**
	 * Addresses that were tried but could not be geocoded, grouped by city.
	 *
	 * @return array<int,array{id:int,title:string,addresses:string[]}>
	 */
	private function unpinned_addresses(): array {
		$out = array();
		foreach ( $this->all_location_ids() as $lid ) {
			$addresses = json_decode( (string) get_post_meta( $lid, '_tt_addresses', true ), true );
			if ( ! is_array( $addresses ) ) {
				continue;
			}
			$fails = array();
			foreach ( $addresses as $a ) {
				if ( ! empty( $a['nogeo'] ) && empty( $a['dismissed'] ) && ! isset( $a['lat'], $a['lng'] ) ) {
					$fails[] = trim( (string) ( $a['address'] ?? '' ) . ' ' . (string) ( $a['postcode'] ?? '' ) );
				}
			}
			if ( $fails ) {
				$out[] = array(
					'id'        => $lid,
					'title'     => get_the_title( $lid ),
					'addresses' => $fails,
				);
			}
		}
		return $out;
	}

	/**
	 * Clear the "could not geocode" flag so those addresses are tried again.
	 */
	/**
	 * Mark every still-unpinned address as dismissed so the could-not-pin
	 * banner clears. They keep their nogeo flag, so the geocoder skips them and
	 * the progress counter stays complete; the admin can still pin them by hand.
	 */
	private function dismiss_unpinned(): void {
		foreach ( $this->all_location_ids() as $lid ) {
			$addresses = json_decode( (string) get_post_meta( $lid, '_tt_addresses', true ), true );
			if ( ! is_array( $addresses ) ) {
				continue;
			}
			$changed = false;
			foreach ( $addresses as &$a ) {
				if ( ! empty( $a['nogeo'] ) && ! isset( $a['lat'], $a['lng'] ) && empty( $a['dismissed'] ) ) {
					$a['dismissed'] = true;
					$changed        = true;
				}
			}
			unset( $a );
			if ( $changed ) {
				update_post_meta( $lid, '_tt_addresses', wp_json_encode( $addresses, JSON_UNESCAPED_UNICODE ) );
			}
		}
	}

	private function clear_nogeo_flags(): void {
		foreach ( $this->all_location_ids() as $lid ) {
			$addresses = json_decode( (string) get_post_meta( $lid, '_tt_addresses', true ), true );
			if ( ! is_array( $addresses ) ) {
				continue;
			}
			$changed = false;
			foreach ( $addresses as &$a ) {
				if ( ! empty( $a['nogeo'] ) || ! empty( $a['dismissed'] ) ) {
					unset( $a['nogeo'], $a['dismissed'] );
					$changed = true;
				}
			}
			unset( $a );
			if ( $changed ) {
				update_post_meta( $lid, '_tt_addresses', wp_json_encode( $addresses, JSON_UNESCAPED_UNICODE ) );
			}
		}
	}

	/**
	 * Set a location's centroid to the average of its pinned addresses.
	 *
	 * @param int   $location_id Location ID.
	 * @param array $addresses   Address entries.
	 */
	private function recompute_centroid( int $location_id, array $addresses ): void {
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
		}
	}

	/**
	 * Find an existing location by exact title, scoped to a country.
	 *
	 * A city name can exist in more than one country, so matching on name alone
	 * would let a Dutch city overwrite a same-named city in another country.
	 *
	 * @param string $city    City name.
	 * @param string $country Optional ISO-2 country to scope to.
	 * @return int|null
	 */
	private function find_location( string $city, string $country = '' ): ?int {
		$args = array(
			'post_type'      => Post_Types::LOCATION,
			'post_status'    => 'any',
			'title'          => $city,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);
		if ( '' !== $country ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_tt_country',
					'value' => strtoupper( $country ),
				),
			);
		}
		$found = get_posts( $args );
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
