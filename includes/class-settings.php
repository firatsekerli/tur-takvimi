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
			'country'            => 'NL',
			'currency'           => 'EUR',
			'working_days'       => array( 5, 6, 0 ), // Fri, Sat, Sun.
			'calendar_weeks'     => 3,
			'discount_percent'   => 10,
			'order_cutoff_days'  => 2,
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
		$out['country']            = strtoupper( sanitize_text_field( $in['country'] ?? 'NL' ) );
		$out['currency']           = strtoupper( sanitize_text_field( $in['currency'] ?? 'EUR' ) );
		$out['calendar_weeks']     = max( 1, min( 12, absint( $in['calendar_weeks'] ?? 3 ) ) );
		$out['discount_percent']   = max( 0, min( 100, absint( $in['discount_percent'] ?? 10 ) ) );
		$out['order_cutoff_days']  = max( 0, absint( $in['order_cutoff_days'] ?? 2 ) );

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
	 * Render the settings form.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tur Takvimi — Settings', 'tur-takvimi' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'tur_takvimi' ); ?>
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
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[primary_color]" id="tt_primary" type="text" value="<?php echo esc_attr( $s['primary_color'] ); ?>"> <input name="<?php echo esc_attr( self::OPTION ); ?>[accent_color]" type="text" value="<?php echo esc_attr( $s['accent_color'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tt_slug"><?php esc_html_e( 'Location URL base', 'tur-takvimi' ); ?></label></th>
						<td><code>/</code><input name="<?php echo esc_attr( self::OPTION ); ?>[location_slug_base]" id="tt_slug" type="text" value="<?php echo esc_attr( $s['location_slug_base'] ); ?>"><code>/...</code>
						<p class="description"><?php esc_html_e( 'Resave Permalinks after changing this.', 'tur-takvimi' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="tt_country"><?php esc_html_e( 'Country / currency', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[country]" id="tt_country" type="text" size="3" value="<?php echo esc_attr( $s['country'] ); ?>"> <input name="<?php echo esc_attr( self::OPTION ); ?>[currency]" type="text" size="4" value="<?php echo esc_attr( $s['currency'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tt_weeks"><?php esc_html_e( 'Calendar weeks shown', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[calendar_weeks]" id="tt_weeks" type="number" min="1" max="12" value="<?php echo esc_attr( $s['calendar_weeks'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tt_discount"><?php esc_html_e( 'Upfront discount (%)', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[discount_percent]" id="tt_discount" type="number" min="0" max="100" value="<?php echo esc_attr( $s['discount_percent'] ); ?>"> <span class="description"><?php esc_html_e( 'Used by the WooCommerce commerce layer.', 'tur-takvimi' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="tt_cutoff"><?php esc_html_e( 'Order cutoff (days before visit)', 'tur-takvimi' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[order_cutoff_days]" id="tt_cutoff" type="number" min="0" value="<?php echo esc_attr( $s['order_cutoff_days'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
