<?php
/**
 * White-label settings.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and exposes all brand/locale configuration so nothing is hardcoded.
 */
class Settings {

	const OPTION = 'tur_takvimi_settings';

	/**
	 * Default values. Every reseller-tunable value lives here.
	 */
	public static function defaults(): array {
		return array(
			'brand_name'         => 'Tur Takvimi',
			'logo_id'            => 0,
			'primary_color'      => '#e3242b',
			'accent_color'       => '#16a34a',
			'calendar_heading'   => __( 'Weekly Delivery Tours', 'tur-takvimi' ),
			'location_slug_base' => 'teslimat',
			'country'            => 'DE',
			'countries'          => array( 'DE' ), // Supported countries (ISO-2); default is always included.
			'currency'           => 'EUR',
			'working_days'       => array( 5, 6, 0 ), // Fri, Sat, Sun.
			'calendar_weeks'         => 3,
			'default_frequency_weeks' => 4,
			'discount_percent'       => 10,
			'order_cutoff_days'      => 2,
			'service_radius_km'      => 0, // 0 = no limit; otherwise the max km a postcode may be from a stop.
			'geocoder_provider'      => 'photon', // 'photon' (free) or 'locationiq' (keyed).
			'geocoder_api_key'       => '',
			'geocoder_region'        => 'us1',    // LocationIQ region: us1 or eu1.
		);
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Get a single setting value with fallback to its default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional explicit fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$stored   = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		if ( isset( $stored[ $key ] ) ) {
			return $stored[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		return $defaults[ $key ] ?? null;
	}

	/**
	 * Register the settings store and sanitizer.
	 */
	public function register_settings(): void {
		register_setting(
			'tur_takvimi',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$out = self::defaults();
		$in  = is_array( $input ) ? $input : array();

		$out['brand_name']         = sanitize_text_field( $in['brand_name'] ?? $out['brand_name'] );
		$out['logo_id']            = absint( $in['logo_id'] ?? 0 );
		$out['primary_color']      = sanitize_hex_color( $in['primary_color'] ?? '' ) ?: $out['primary_color'];
		$out['accent_color']       = sanitize_hex_color( $in['accent_color'] ?? '' ) ?: $out['accent_color'];
		$out['calendar_heading']   = sanitize_text_field( $in['calendar_heading'] ?? $out['calendar_heading'] );
		$out['location_slug_base'] = sanitize_title( $in['location_slug_base'] ?? $out['location_slug_base'] );
		$out['country']            = strtoupper( sanitize_text_field( $in['country'] ?? 'DE' ) );
		$out['currency']           = strtoupper( sanitize_text_field( $in['currency'] ?? 'EUR' ) );

		// Supported countries: ISO-2 list with optional "CODE:Label" pairs
		// (e.g. "DE:Almanya, NL:Hollanda"). The default country is always kept.
		$countries = array();
		foreach ( array_map( 'trim', explode( ',', (string) ( $in['countries'] ?? '' ) ) ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			$parts = explode( ':', $token, 2 );
			$code  = strtoupper( trim( $parts[0] ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$countries[ $code ] = isset( $parts[1] ) ? sanitize_text_field( trim( $parts[1] ) ) : '';
			}
		}
		if ( ! isset( $countries[ $out['country'] ] ) ) {
			$countries = array( $out['country'] => '' ) + $countries;
		}
		$out['countries'] = $countries;
		$out['calendar_weeks']          = max( 1, min( 12, absint( $in['calendar_weeks'] ?? 3 ) ) );
		$out['default_frequency_weeks'] = max( 1, min( 52, absint( $in['default_frequency_weeks'] ?? 4 ) ) );
		$out['discount_percent']   = max( 0, min( 100, absint( $in['discount_percent'] ?? 10 ) ) );
		$out['order_cutoff_days']  = max( 0, absint( $in['order_cutoff_days'] ?? 2 ) );
		$out['service_radius_km']  = max( 0, absint( $in['service_radius_km'] ?? 0 ) );

		$provider                  = sanitize_key( $in['geocoder_provider'] ?? 'photon' );
		$out['geocoder_provider']  = in_array( $provider, array( 'photon', 'locationiq' ), true ) ? $provider : 'photon';
		$out['geocoder_api_key']   = sanitize_text_field( $in['geocoder_api_key'] ?? '' );
		$region                    = sanitize_key( $in['geocoder_region'] ?? 'us1' );
		$out['geocoder_region']    = in_array( $region, array( 'us1', 'eu1' ), true ) ? $region : 'us1';

		$days = isset( $in['working_days'] ) && is_array( $in['working_days'] )
			? array_map( 'absint', $in['working_days'] )
			: $out['working_days'];
		$out['working_days'] = array_values( array_intersect( range( 0, 6 ), $days ) );

		return $out;
	}

	/**
	 * Add the settings page under its own menu.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Tur Takvimi', 'tur-takvimi' ),
			__( 'Tur Takvimi', 'tur-takvimi' ),
			'manage_options',
			'tur-takvimi',
			array( $this, 'render_page' ),
			'dashicons-calendar-alt',
			26
		);
	}

	/**
	 * Render the settings form, grouped into titled sections.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		?>
		<style>
			.tt-settings-grid { display: grid; grid-template-columns: repeat( auto-fit, minmax( 420px, 1fr ) ); gap: 16px; max-width: 1200px; margin-top: 12px; }
			.tt-settings-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 4px 20px 14px; }
			.tt-settings-card > h2 { margin: 12px 0 0; padding: 0; }
			.tt-settings-card .form-table th { width: 170px; padding: 14px 10px 14px 0; }
			.tt-settings-card .form-table td { padding: 14px 0; }
		</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tur Takvimi — Settings', 'tur-takvimi' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'tur_takvimi' ); ?>

				<div class="tt-settings-grid">
				<section class="tt-settings-card">
				<h2 class="title"><?php esc_html_e( 'Brand', 'tur-takvimi' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tt_brand_name"><?php esc_html_e( 'Brand name', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[brand_name]" id="tt_brand_name" type="text" class="regular-text" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tt_heading"><?php esc_html_e( 'Calendar heading', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[calendar_heading]" id="tt_heading" type="text" class="regular-text" value="<?php echo esc_attr( $s['calendar_heading'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tt_primary"><?php esc_html_e( 'Primary color', 'tur-takvimi' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[primary_color]" id="tt_primary" type="color" value="<?php echo esc_attr( $s['primary_color'] ); ?>">
							<code><?php echo esc_html( $s['primary_color'] ); ?></code>
							<p class="description"><?php esc_html_e( 'Headings, buttons and highlights across the front-end widgets.', 'tur-takvimi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tt_accent"><?php esc_html_e( 'Accent color', 'tur-takvimi' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[accent_color]" id="tt_accent" type="color" value="<?php echo esc_attr( $s['accent_color'] ); ?>">
							<code><?php echo esc_html( $s['accent_color'] ); ?></code>
							<p class="description"><?php esc_html_e( 'Secondary color: date chips, calendar day markers. Readable text colors are derived automatically.', 'tur-takvimi' ); ?></p>
						</td>
					</tr>
				</table>

				</section>
				<section class="tt-settings-card">
				<h2 class="title"><?php esc_html_e( 'Countries & currency', 'tur-takvimi' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tt_slug"><?php esc_html_e( 'Location URL base', 'tur-takvimi' ); ?></label></th>
						<td><code>/</code><input name="<?php echo esc_attr( self::OPTION ); ?>[location_slug_base]" id="tt_slug" type="text" value="<?php echo esc_attr( $s['location_slug_base'] ); ?>"><code>/...</code>
						<p class="description"><?php esc_html_e( 'Resave Permalinks after changing this.', 'tur-takvimi' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="tt_country"><?php esc_html_e( 'Country / currency', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[country]" id="tt_country" type="text" size="3" value="<?php echo esc_attr( $s['country'] ); ?>"> <input name="<?php echo esc_attr( self::OPTION ); ?>[currency]" type="text" size="4" value="<?php echo esc_attr( $s['currency'] ); ?>">
						<p class="description"><?php esc_html_e( 'Default country (ISO-2) for new cities/routes and the postcode search fallback.', 'tur-takvimi' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="tt_countries"><?php esc_html_e( 'Supported countries', 'tur-takvimi' ); ?></label></th>
						<?php
						$country_pairs = array();
						foreach ( Country::map() as $cc => $clabel ) {
							$country_pairs[] = '' !== $clabel ? $cc . ':' . $clabel : $cc;
						}
						?>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[countries]" id="tt_countries" type="text" class="regular-text" value="<?php echo esc_attr( implode( ', ', $country_pairs ) ); ?>" placeholder="DE:Almanya, NL:Hollanda">
						<p class="description"><?php esc_html_e( 'Comma-separated ISO-2 codes the business delivers to, with an optional display name (e.g. DE:Almanya, NL:Hollanda). The postcode search auto-detects the country from these, and shows a country picker when more than one is listed.', 'tur-takvimi' ); ?></p></td>
					</tr>
				</table>

				</section>
				<section class="tt-settings-card">
				<h2 class="title"><?php esc_html_e( 'Calendar & schedule', 'tur-takvimi' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tt_weeks"><?php esc_html_e( 'Calendar weeks shown', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[calendar_weeks]" id="tt_weeks" type="number" min="1" max="12" value="<?php echo esc_attr( $s['calendar_weeks'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tt_default_freq"><?php esc_html_e( 'Default visit frequency (weeks)', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[default_frequency_weeks]" id="tt_default_freq" type="number" min="1" max="52" value="<?php echo esc_attr( $s['default_frequency_weeks'] ); ?>"> <span class="description"><?php esc_html_e( 'Used for stops that have no frequency set.', 'tur-takvimi' ); ?></span></td>
					</tr>
				</table>

				</section>
				<section class="tt-settings-card">
				<h2 class="title"><?php esc_html_e( 'Pre-orders (WooCommerce)', 'tur-takvimi' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tt_discount"><?php esc_html_e( 'Upfront discount (%)', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[discount_percent]" id="tt_discount" type="number" min="0" max="100" value="<?php echo esc_attr( $s['discount_percent'] ); ?>"> <span class="description"><?php esc_html_e( 'Used by the WooCommerce commerce layer.', 'tur-takvimi' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="tt_cutoff"><?php esc_html_e( 'Order cutoff (days before visit)', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[order_cutoff_days]" id="tt_cutoff" type="number" min="0" value="<?php echo esc_attr( $s['order_cutoff_days'] ); ?>"></td>
					</tr>
				</table>

				</section>
				<section class="tt-settings-card">
				<h2 class="title"><?php esc_html_e( 'Postcode search & geocoding', 'tur-takvimi' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tt_radius"><?php esc_html_e( 'Service radius (km)', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[service_radius_km]" id="tt_radius" type="number" min="0" value="<?php echo esc_attr( $s['service_radius_km'] ); ?>"> <span class="description"><?php esc_html_e( '0 = no limit. When set, a postcode farther than this from every stop is treated as outside the delivery area.', 'tur-takvimi' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="tt_geocoder"><?php esc_html_e( 'Geocoder', 'tur-takvimi' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[geocoder_provider]" id="tt_geocoder">
								<option value="photon" <?php selected( $s['geocoder_provider'], 'photon' ); ?>><?php esc_html_e( 'Photon (free, no key)', 'tur-takvimi' ); ?></option>
								<option value="locationiq" <?php selected( $s['geocoder_provider'], 'locationiq' ); ?>><?php esc_html_e( 'LocationIQ (API key)', 'tur-takvimi' ); ?></option>
							</select>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[geocoder_region]" aria-label="<?php esc_attr_e( 'LocationIQ region', 'tur-takvimi' ); ?>">
								<option value="us1" <?php selected( $s['geocoder_region'], 'us1' ); ?>>us1</option>
								<option value="eu1" <?php selected( $s['geocoder_region'], 'eu1' ); ?>>eu1</option>
							</select>
							<p class="description"><?php esc_html_e( 'LocationIQ uses OpenStreetMap data and allows bulk imports. Pick the region closest to you (eu1 for Europe).', 'tur-takvimi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tt_geocoder_key"><?php esc_html_e( 'LocationIQ API key', 'tur-takvimi' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[geocoder_api_key]" id="tt_geocoder_key" type="password" autocomplete="off" class="regular-text" value="<?php echo esc_attr( $s['geocoder_api_key'] ); ?>">
							<p class="description"><?php esc_html_e( 'From your LocationIQ dashboard → Access Tokens. Only used when the geocoder is set to LocationIQ.', 'tur-takvimi' ); ?></p>
						</td>
					</tr>
				</table>

				</section>
				</div>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_shortcode_help(); ?>
		</div>
		<?php
	}

	/**
	 * Reference panel: shortcodes and the styling attributes they accept.
	 */
	private function render_shortcode_help(): void {
		?>
		<hr>
		<h2><?php esc_html_e( 'Shortcodes & styling', 'tur-takvimi' ); ?></h2>
		<p class="description" style="max-width:48rem">
			<?php esc_html_e( 'Every shortcode below accepts two styling attributes:', 'tur-takvimi' ); ?>
		</p>
		<ul style="max-width:48rem;list-style:disc;margin-left:1.5rem">
			<li>
				<code>align="left|center|right"</code> —
				<?php esc_html_e( 'aligns the filter / toolbar row of the shortcode (default: as designed).', 'tur-takvimi' ); ?>
			</li>
			<li>
				<code>class="my-class"</code> —
				<?php esc_html_e( 'adds your own CSS class(es) to the outer element, so you can target it from the theme/customizer for any other styling.', 'tur-takvimi' ); ?>
			</li>
			<li>
				<code>heading="no"</code> <?php esc_html_e( 'or', 'tur-takvimi' ); ?> <code>heading="Custom title"</code> —
				<?php esc_html_e( 'hides or overrides the built-in heading (calendar and address-list only). Use "no" when your page already has a title.', 'tur-takvimi' ); ?>
			</li>
			<li>
				<code>filter="no"</code> —
				<?php esc_html_e( 'hides the address list\'s postcode search box (city panel and address list).', 'tur-takvimi' ); ?>
			</li>
			<li>
				<code>schedule="no"</code>, <code>map="no"</code>, <code>stops="no"</code> —
				<?php esc_html_e( 'removes that block from the full city panel.', 'tur-takvimi' ); ?>
			</li>
		</ul>

		<table class="widefat striped" style="max-width:60rem;margin-top:1rem">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Shortcode', 'tur-takvimi' ); ?></th>
					<th><?php esc_html_e( 'Shows', 'tur-takvimi' ); ?></th>
					<th><?php esc_html_e( 'Main attributes', 'tur-takvimi' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>[tur_takvimi_postcode_search]</code></td>
					<td><?php esc_html_e( 'Nearest-stop postcode finder.', 'tur-takvimi' ); ?></td>
					<td><code>country</code>, <code>align</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_map]</code></td>
					<td><?php esc_html_e( 'Delivery-regions explorer (map + filterable stop list).', 'tur-takvimi' ); ?></td>
					<td><code>country</code>, <code>height</code>, <code>align</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_calendar]</code></td>
					<td><?php esc_html_e( 'Upcoming delivery days as a grouped list.', 'tur-takvimi' ); ?></td>
					<td><code>weeks</code>, <code>country</code>, <code>heading</code>, <code>align</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_calendar_month]</code></td>
					<td><?php esc_html_e( 'Month-grid calendar.', 'tur-takvimi' ); ?></td>
					<td><code>months</code>, <code>country</code>, <code>id</code>, <code>align</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_city]</code></td>
					<td><?php esc_html_e( 'Full city panel: schedule + map + address list, combined.', 'tur-takvimi' ); ?></td>
					<td><code>id</code>, <code>heading</code>, <code>filter</code>, <code>schedule</code>, <code>map</code>, <code>stops</code>, <code>align</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_city_schedule]</code></td>
					<td><?php esc_html_e( 'A city\'s next delivery dates and route(s).', 'tur-takvimi' ); ?></td>
					<td><code>id</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_city_map]</code></td>
					<td><?php esc_html_e( 'A map of one city\'s delivery stops.', 'tur-takvimi' ); ?></td>
					<td><code>id</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_city_stops]</code></td>
					<td><?php esc_html_e( 'A city\'s delivery addresses (with per-address calendar links).', 'tur-takvimi' ); ?></td>
					<td><code>id</code>, <code>heading</code>, <code>filter</code>, <code>align</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_whatsapp]</code></td>
					<td><?php esc_html_e( 'WhatsApp groups: pick-your-region list + the country channel.', 'tur-takvimi' ); ?></td>
					<td><code>country</code>, <code>heading</code>, <code>class</code></td>
				</tr>
				<tr>
					<td><code>[tur_takvimi_whatsapp_join]</code></td>
					<td><?php esc_html_e( 'A single "join the WhatsApp group" button for one city.', 'tur-takvimi' ); ?></td>
					<td><code>id</code>, <code>class</code></td>
				</tr>
			</tbody>
		</table>
		<p class="description" style="max-width:60rem">
			<?php esc_html_e( 'The city shortcodes default to the current city on a single-city page, or take an explicit id="123".', 'tur-takvimi' ); ?>
		</p>

		<p class="description" style="max-width:60rem;margin-top:1rem">
			<?php
			printf(
				/* translators: 1 and 2: example shortcodes wrapped in <code>. */
				esc_html__( 'Examples: %1$s left-aligns the calendar filters and hides its heading; %2$s adds a custom class you can style.', 'tur-takvimi' ),
				'<code>[tur_takvimi_calendar align="left" heading="no"]</code>',
				'<code>[tur_takvimi_map class="benim-harita"]</code>'
			);
			?>
		</p>
		<p class="description" style="max-width:60rem">
			<?php esc_html_e( 'Shortcodes render full-width (100%) of their container — wrap one in a column or set a max-width on the container to constrain it.', 'tur-takvimi' ); ?>
		</p>
		<?php
	}
}
