<?php
/**
 * WhatsApp groups — a manageable list of group links, each assigned to a
 * Bölge (region) and Ülke (country), plus a per-country channel fallback.
 *
 * Group links live in one option (rows of {label, url, region, country})
 * managed on the Tur Takvimi → WhatsApp page, where any number of links can
 * be added. A location resolves to the first group assigned to one of its
 * regions, else the country-wide group (no region) for its country, else the
 * country's channel.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp group links admin page + the picker / join shortcodes.
 */
class Whatsapp {

	const TERM_META       = '_tt_whatsapp'; // Legacy (pre-v5) per-region storage; migrated into GROUPS_OPTION.
	const GROUPS_OPTION   = 'tur_takvimi_wa_groups';
	const CHANNELS_OPTION = 'tur_takvimi_wa_channels';

	/**
	 * Inferred region => countries map for the admin page (term_id => ISO-2[]).
	 *
	 * @var array<int,string[]>
	 */
	private $region_countries = array();

	/**
	 * Hook the admin page and shortcodes.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_post_tur_takvimi_whatsapp_save', array( $this, 'save_page' ) );

		add_shortcode( 'tur_takvimi_whatsapp', array( $this, 'picker' ) );
		add_shortcode( 'tur_takvimi_whatsapp_join', array( $this, 'join_button' ) );
	}

	/**
	 * The configured group links, normalized.
	 *
	 * @return array<int,array{label:string,url:string,region:int,country:string}>
	 */
	public static function groups(): array {
		$out = array();
		foreach ( (array) get_option( self::GROUPS_OPTION, array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = (string) ( $row['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$country = strtoupper( (string) ( $row['country'] ?? '' ) );
			$out[]   = array(
				'label'   => (string) ( $row['label'] ?? '' ),
				'url'     => $url,
				'region'  => (int) ( $row['region'] ?? 0 ),
				'country' => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : Country::default_code(),
			);
		}
		return $out;
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
	 * One screen for every WhatsApp link: a dynamic list of group links (each
	 * assigned to a region + country) and the per-country channel fallbacks.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$groups   = self::groups();
		$channels = (array) get_option( self::CHANNELS_OPTION, array() );
		$regions  = get_terms(
			array(
				'taxonomy'   => Post_Types::REGION,
				'hide_empty' => false,
			)
		);
		$regions  = is_wp_error( $regions ) ? array() : $regions;

		// Regions carry no country of their own; infer each region's countries
		// from the cities using it, so the row's country can filter the list.
		$this->region_countries = Post_Types::region_countries( Post_Types::LOCATION );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WhatsApp', 'tur-takvimi' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'WhatsApp links saved.', 'tur-takvimi' ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:50rem">
				<?php esc_html_e( 'Add group links and assign each to a region and a country. A city shows its region\'s group; a link without a region acts as the country-wide group. If nothing matches, the country channel below is shown.', 'tur-takvimi' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tur_takvimi_whatsapp_save">
				<?php wp_nonce_field( 'tt_wa_save' ); ?>

				<h2><?php esc_html_e( 'Group links', 'tur-takvimi' ); ?></h2>
				<table class="widefat striped" style="max-width:70rem" id="tt-wa-groups">
					<thead>
						<tr>
							<th style="width:18%"><?php esc_html_e( 'Name (optional)', 'tur-takvimi' ); ?></th>
							<th><?php esc_html_e( 'WhatsApp group link', 'tur-takvimi' ); ?></th>
							<th style="width:20%"><?php esc_html_e( 'Region', 'tur-takvimi' ); ?></th>
							<th style="width:15%"><?php esc_html_e( 'Country', 'tur-takvimi' ); ?></th>
							<th style="width:6%"></th>
						</tr>
					</thead>
					<tbody data-tt-wa-body>
						<?php if ( ! $groups ) : ?>
							<tr data-tt-wa-empty><td colspan="5"><?php esc_html_e( 'No group links yet. Add the first one below.', 'tur-takvimi' ); ?></td></tr>
						<?php endif; ?>
						<?php
						foreach ( $groups as $i => $g ) {
							$this->group_row( (string) $i, $g, $regions );
						}
						?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="tt-wa-add"><?php esc_html_e( 'Add group link', 'tur-takvimi' ); ?></button></p>

				<template id="tt-wa-row-tpl">
					<?php
					$this->group_row(
						'__i__',
						array(
							'label'   => '',
							'url'     => '',
							'region'  => 0,
							'country' => Country::default_code(),
						),
						$regions
					);
					?>
				</template>

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
		<script>
		( function () {
			var body = document.querySelector( '[data-tt-wa-body]' );
			var tpl  = document.getElementById( 'tt-wa-row-tpl' );
			var add  = document.getElementById( 'tt-wa-add' );
			var i    = <?php echo (int) count( $groups ); ?>;

			// Region options carry the countries they are used in (inferred from
			// the cities); keep each row's region list scoped to its country.
			function filterRow( tr ) {
				if ( ! tr ) {
					return;
				}
				var country = tr.querySelector( 'select[name$="[country]"]' );
				var region = tr.querySelector( 'select[name$="[region]"]' );
				if ( ! country || ! region ) {
					return;
				}
				if ( ! region.dataset.ttAll ) {
					region.dataset.ttAll = JSON.stringify(
						Array.prototype.map.call( region.options, function ( o ) {
							return { v: o.value, t: o.text, c: o.getAttribute( 'data-countries' ) || '' };
						} )
					);
				}
				var keep = region.value;
				var co = country.value;
				region.length = 0;
				JSON.parse( region.dataset.ttAll ).forEach( function ( o ) {
					// '0' = country-wide; regions with no cities yet stay visible.
					if ( '0' !== o.v && co && o.c && o.c.split( ',' ).indexOf( co ) === -1 ) {
						return;
					}
					var opt = new Option( o.t, o.v, false, o.v === keep );
					opt.setAttribute( 'data-countries', o.c );
					region.add( opt );
				} );
			}

			add.addEventListener( 'click', function () {
				var empty = body.querySelector( '[data-tt-wa-empty]' );
				if ( empty ) {
					empty.remove();
				}
				var holder = document.createElement( 'table' );
				holder.innerHTML = '<tbody>' + tpl.innerHTML.replace( /__i__/g, String( i++ ) ) + '</tbody>';
				var row = holder.querySelector( 'tr' );
				if ( row ) {
					body.appendChild( row );
					filterRow( row );
					var first = row.querySelector( 'input' );
					if ( first ) {
						first.focus();
					}
				}
			} );

			body.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '[data-tt-wa-remove]' );
				if ( btn ) {
					btn.closest( 'tr' ).remove();
				}
			} );

			body.addEventListener( 'change', function ( e ) {
				if ( e.target && /\[country\]$/.test( e.target.name || '' ) ) {
					filterRow( e.target.closest( 'tr' ) );
				}
			} );

			Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), filterRow );
		}() );
		</script>
		<?php
	}

	/**
	 * Render one editable group-link row.
	 *
	 * @param string   $i       Row index (or the '__i__' template placeholder).
	 * @param array    $g       Group row {label, url, region, country}.
	 * @param \WP_Term[] $regions All region terms.
	 */
	private function group_row( string $i, array $g, array $regions ): void {
		$name = 'wa_groups[' . $i . ']';
		?>
		<tr>
			<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $g['label'] ); ?>" placeholder="<?php esc_attr_e( 'Shown name (optional)', 'tur-takvimi' ); ?>"></td>
			<td><input type="url" class="large-text" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $g['url'] ); ?>" placeholder="https://chat.whatsapp.com/…"></td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[region]" style="width:100%">
					<option value="0"><?php esc_html_e( 'Country-wide (no region)', 'tur-takvimi' ); ?></option>
					<?php foreach ( $regions as $term ) : ?>
						<option value="<?php echo (int) $term->term_id; ?>" data-countries="<?php echo esc_attr( implode( ',', $this->region_countries[ (int) $term->term_id ] ?? array() ) ); ?>" <?php selected( (int) $g['region'], (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[country]" style="width:100%">
					<?php foreach ( Country::supported() as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $g['country'], $code ); ?>><?php echo esc_html( Country::name( $code ) . ' (' . $code . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><button type="button" class="button-link button-link-delete" data-tt-wa-remove><?php esc_html_e( 'Remove', 'tur-takvimi' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Persist the WhatsApp page: the group-link rows + country channel option.
	 */
	public function save_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tur-takvimi' ) );
		}
		check_admin_referer( 'tt_wa_save' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value below.
		$groups = array();
		$rows   = isset( $_POST['wa_groups'] ) && is_array( $_POST['wa_groups'] ) ? wp_unslash( $_POST['wa_groups'] ) : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) );
			if ( '' === $url ) {
				continue; // A row without a link is dropped.
			}
			$region = absint( $row['region'] ?? 0 );
			if ( $region ) {
				$term = get_term( $region, Post_Types::REGION );
				if ( ! $term || is_wp_error( $term ) ) {
					$region = 0; // Deleted region: keep the link as country-wide.
				}
			}
			$country = strtoupper( sanitize_text_field( (string) ( $row['country'] ?? '' ) ) );
			if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$country = Country::default_code();
			}
			$groups[] = array(
				'label'   => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'url'     => $url,
				'region'  => $region,
				'country' => $country,
			);
		}
		update_option( self::GROUPS_OPTION, $groups );

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
	 * The best WhatsApp link for a location: the first group assigned to one of
	 * its regions, else the country-wide group for its country, else the
	 * country channel.
	 *
	 * @param int $location_id Location ID.
	 * @return string
	 */
	public static function for_location( int $location_id ): string {
		$groups  = self::groups();
		$country = Country::of_post( $location_id );

		if ( $groups ) {
			$terms    = wp_get_object_terms( $location_id, Post_Types::REGION, array( 'fields' => 'ids' ) );
			$term_ids = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
			foreach ( $groups as $g ) {
				if ( $g['region'] && in_array( $g['region'], $term_ids, true ) ) {
					return $g['url'];
				}
			}
			foreach ( $groups as $g ) {
				if ( ! $g['region'] && $g['country'] === $country ) {
					return $g['url'];
				}
			}
		}
		return self::channel( $country );
	}

	/**
	 * Display name for a group link: its label, else its region's name, else
	 * the country name.
	 *
	 * @param array $g Group row.
	 * @return string
	 */
	private static function group_name( array $g ): string {
		if ( '' !== $g['label'] ) {
			return $g['label'];
		}
		if ( $g['region'] ) {
			$term = get_term( $g['region'], Post_Types::REGION );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return Country::name( $g['country'] );
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

		$rows = array();
		foreach ( self::groups() as $g ) {
			if ( $g['country'] !== $country ) {
				continue;
			}
			$rows[] = array(
				'name' => self::group_name( $g ),
				'url'  => $g['url'],
			);
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
	 * for a city (its region's group, the country-wide group, or the channel).
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
