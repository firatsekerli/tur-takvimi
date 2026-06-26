<?php
/**
 * WhatsApp groups — a join link per Bölge (region), with a country-wide channel
 * as the fallback.
 *
 * Region groups are stored as term meta on the region taxonomy; the country
 * channel lives in Settings. A location resolves to its region's group, else
 * its country's channel.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Region WhatsApp groups + the picker / join shortcodes.
 */
class Whatsapp {

	const TERM_META       = '_tt_whatsapp';
	const CHANNELS_OPTION = 'tur_takvimi_wa_channels';

	/**
	 * Hook the admin page, term-meta UI and shortcodes.
	 */
	public function register(): void {
		add_action( Post_Types::REGION . '_add_form_fields', array( $this, 'add_field' ) );
		add_action( Post_Types::REGION . '_edit_form_fields', array( $this, 'edit_field' ) );
		add_action( 'created_' . Post_Types::REGION, array( $this, 'save_field' ) );
		add_action( 'edited_' . Post_Types::REGION, array( $this, 'save_field' ) );

		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_post_tur_takvimi_whatsapp_save', array( $this, 'save_page' ) );

		add_shortcode( 'tur_takvimi_whatsapp', array( $this, 'picker' ) );
		add_shortcode( 'tur_takvimi_whatsapp_join', array( $this, 'join_button' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Admin page: Tur Takvimi → WhatsApp
	 * --------------------------------------------------------------------- */

	/**
	 * Register the WhatsApp submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'tur-takvimi',
			__( 'WhatsApp', 'tur-takvimi' ),
			__( 'WhatsApp', 'tur-takvimi' ),
			'manage_options',
			'tur-takvimi-whatsapp',
			array( $this, 'render_page' )
		);
	}

	/**
	 * One screen for every WhatsApp link: per-region groups + per-country
	 * channel fallbacks, with each region's country shown.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$channels = (array) get_option( self::CHANNELS_OPTION, array() );
		$terms    = get_terms(
			array(
				'taxonomy'   => Post_Types::REGION,
				'hide_empty' => false,
			)
		);
		$terms = is_wp_error( $terms ) ? array() : $terms;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WhatsApp', 'tur-takvimi' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'WhatsApp links saved.', 'tur-takvimi' ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:50rem">
				<?php esc_html_e( 'Customers see their region\'s group. If a region has no group, they see that country\'s channel instead.', 'tur-takvimi' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tur_takvimi_whatsapp_save">
				<?php wp_nonce_field( 'tt_wa_save' ); ?>

				<h2><?php esc_html_e( 'Region groups', 'tur-takvimi' ); ?></h2>
				<table class="widefat striped" style="max-width:60rem">
					<thead>
						<tr>
							<th style="width:22%"><?php esc_html_e( 'Region', 'tur-takvimi' ); ?></th>
							<th style="width:8%"><?php esc_html_e( 'Country', 'tur-takvimi' ); ?></th>
							<th><?php esc_html_e( 'WhatsApp group link', 'tur-takvimi' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $terms ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No regions yet. Import routes/cities first.', 'tur-takvimi' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $terms as $term ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $term->name ); ?></strong></td>
								<td><?php echo esc_html( self::region_country( (int) $term->term_id ) ); ?></td>
								<td><input type="url" class="large-text" name="wa_region[<?php echo (int) $term->term_id; ?>]" value="<?php echo esc_attr( (string) get_term_meta( (int) $term->term_id, self::TERM_META, true ) ); ?>" placeholder="https://chat.whatsapp.com/…"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2 style="margin-top:2rem"><?php esc_html_e( 'Country channels (fallback)', 'tur-takvimi' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( Country::supported() as $code ) : ?>
						<tr>
							<th scope="row">
								<?php
								$label = Country::name( $code );
								echo esc_html( '' !== $label ? $label . ' (' . $code . ')' : $code );
								?>
							</th>
							<td><input type="url" class="regular-text" name="wa_channel[<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( (string) ( $channels[ $code ] ?? '' ) ); ?>" placeholder="https://whatsapp.com/channel/…"></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the WhatsApp page: region term metas + country channel option.
	 */
	public function save_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tur-takvimi' ) );
		}
		check_admin_referer( 'tt_wa_save' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value below.
		$regions = isset( $_POST['wa_region'] ) ? (array) wp_unslash( $_POST['wa_region'] ) : array();
		foreach ( $regions as $tid => $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( '' !== $url ) {
				update_term_meta( (int) $tid, self::TERM_META, $url );
			} else {
				delete_term_meta( (int) $tid, self::TERM_META );
			}
		}

		$channels = array();
		$raw      = isset( $_POST['wa_channel'] ) ? (array) wp_unslash( $_POST['wa_channel'] ) : array();
		foreach ( $raw as $code => $url ) {
			$code = strtoupper( sanitize_text_field( (string) $code ) );
			$url  = esc_url_raw( trim( (string) $url ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) && '' !== $url ) {
				$channels[ $code ] = $url;
			}
		}
		// phpcs:enable
		update_option( self::CHANNELS_OPTION, $channels );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=tur-takvimi-whatsapp' ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * Region term meta (Regions → add / edit)
	 * --------------------------------------------------------------------- */

	/**
	 * Field on the "add region" screen.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function add_field( $taxonomy ): void {
		wp_nonce_field( 'tt_wa_term', 'tt_wa_nonce' );
		?>
		<div class="form-field">
			<label for="tt_wa_url"><?php esc_html_e( 'WhatsApp group link', 'tur-takvimi' ); ?></label>
			<input type="url" name="tt_wa_url" id="tt_wa_url" value="" placeholder="https://chat.whatsapp.com/…">
			<p><?php esc_html_e( 'Invite link to this region\'s WhatsApp group. Shown to customers in this region.', 'tur-takvimi' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Field on the "edit region" screen.
	 *
	 * @param \WP_Term $term Region term.
	 */
	public function edit_field( $term ): void {
		$val = (string) get_term_meta( (int) $term->term_id, self::TERM_META, true );
		wp_nonce_field( 'tt_wa_term', 'tt_wa_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label for="tt_wa_url"><?php esc_html_e( 'WhatsApp group link', 'tur-takvimi' ); ?></label></th>
			<td>
				<input type="url" name="tt_wa_url" id="tt_wa_url" class="regular-text" value="<?php echo esc_attr( $val ); ?>" placeholder="https://chat.whatsapp.com/…">
				<p class="description"><?php esc_html_e( 'Invite link to this region\'s WhatsApp group. Shown to customers in this region.', 'tur-takvimi' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the region's WhatsApp link.
	 *
	 * @param int $term_id Region term ID.
	 */
	public function save_field( $term_id ): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		if ( ! isset( $_POST['tt_wa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tt_wa_nonce'] ) ), 'tt_wa_term' ) ) {
			return;
		}
		$url = isset( $_POST['tt_wa_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['tt_wa_url'] ) ) ) : '';
		if ( '' !== $url ) {
			update_term_meta( (int) $term_id, self::TERM_META, $url );
		} else {
			delete_term_meta( (int) $term_id, self::TERM_META );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Resolution helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Country-wide channel URL for an ISO-2 code (or '').
	 *
	 * @param string $country ISO-2 code.
	 * @return string
	 */
	public static function channel( string $country ): string {
		$channels = (array) get_option( self::CHANNELS_OPTION, array() );
		return (string) ( $channels[ strtoupper( $country ) ] ?? '' );
	}

	/**
	 * The best WhatsApp link for a location: its region's group, else the
	 * country channel.
	 *
	 * @param int $location_id Location ID.
	 * @return string
	 */
	public static function for_location( int $location_id ): string {
		$terms = wp_get_object_terms( $location_id, Post_Types::REGION, array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $tid ) {
				$url = (string) get_term_meta( (int) $tid, self::TERM_META, true );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}
		return self::channel( Country::of_post( $location_id ) );
	}

	/**
	 * Country a region belongs to, inferred from one of its locations.
	 *
	 * @param int $term_id Region term ID.
	 * @return string ISO-2 or ''.
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
		return $ids ? Country::of_post( (int) $ids[0] ) : '';
	}

	/* --------------------------------------------------------------------- *
	 * Shortcodes
	 * --------------------------------------------------------------------- */

	/**
	 * [tur_takvimi_whatsapp] — the "choose your region" list of WhatsApp groups
	 * for a country, plus the country channel.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function picker( $atts ): string {
		$atts = shortcode_atts(
			array(
				'country' => '',
				'class'   => '',
				'heading' => null,
			),
			$atts,
			'tur_takvimi_whatsapp'
		);

		$country = strtoupper( (string) $atts['country'] );
		if ( '' === $country ) {
			$country = Country::default_code();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::REGION,
				'hide_empty' => true,
			)
		);
		$rows = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$url = (string) get_term_meta( (int) $term->term_id, self::TERM_META, true );
				if ( '' === $url ) {
					continue;
				}
				if ( self::region_country( (int) $term->term_id ) !== $country ) {
					continue;
				}
				$rows[] = array(
					'name' => $term->name,
					'url'  => $url,
				);
			}
		}
		usort( $rows, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

		$channel = self::channel( $country );
		if ( ! $rows && '' === $channel ) {
			return '';
		}

		$heading = Shortcodes::heading_attr( $atts['heading'], __( 'Choose your region to follow us on WhatsApp', 'tur-takvimi' ) );

		wp_enqueue_style( 'tur-takvimi' );
		ob_start();
		?>
		<section class="tt-wa<?php echo esc_attr( Shortcodes::extra_class( $atts['class'] ) ); ?>">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="tt-wa__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( $rows ) : ?>
				<ol class="tt-wa__list">
					<?php foreach ( $rows as $r ) : ?>
						<li>
							<a class="tt-wa__link" href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener nofollow">
								<span class="tt-wa__icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm5.6 14.06c-.24.66-1.4 1.27-1.92 1.31-.49.04-.95.22-3.2-.67-2.71-1.07-4.42-3.84-4.55-4.02-.13-.18-1.09-1.45-1.09-2.77 0-1.32.69-1.97.94-2.24.24-.27.53-.34.71-.34h.51c.16 0 .38-.06.6.46.22.53.75 1.83.82 1.96.07.13.11.29.02.47-.34.69-.71.66-.51 1l.36.43c.14.16.96 1.42 2.3 2.06 1.07.49 1.29.4 1.52.37.23-.03.74-.3.85-.6.1-.29.1-.54.07-.6-.04-.05-.2-.08-.42-.18Z"/></svg>
								</span>
								<?php echo esc_html( $r['name'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php if ( '' !== $channel ) : ?>
				<p class="tt-wa__channel">
					<a href="<?php echo esc_url( $channel ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'Or follow our WhatsApp channel', 'tur-takvimi' ); ?></a>
				</p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * [tur_takvimi_whatsapp_join] — a single button to join the WhatsApp group
	 * for a city (its region's group, or the country channel).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function join_button( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'class' => '',
			),
			$atts,
			'tur_takvimi_whatsapp_join'
		);
		$id = (int) $atts['id'];
		if ( ! $id && is_singular( Post_Types::LOCATION ) ) {
			$id = (int) get_queried_object_id();
		}
		if ( ! $id ) {
			return '';
		}
		$url = self::for_location( $id );
		if ( '' === $url ) {
			return '';
		}

		wp_enqueue_style( 'tur-takvimi' );
		return sprintf(
			'<a class="tt-wa__join%s" href="%s" target="_blank" rel="noopener nofollow"><span class="tt-wa__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Z"/></svg></span>%s</a>',
			esc_attr( Shortcodes::extra_class( $atts['class'] ) ),
			esc_url( $url ),
			esc_html__( 'Join our WhatsApp group', 'tur-takvimi' )
		);
	}
}
