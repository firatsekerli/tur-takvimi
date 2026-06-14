<?php
/**
 * Front-end shortcodes (builder-agnostic interface).
 *
 * These work inside Breakdance, Gutenberg, classic editor and any theme.
 * The calendar is server-rendered for SEO; both widgets enhance via JS.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the calendar and postcode-search shortcodes.
 */
class Shortcodes {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_shortcode( 'tur_takvimi_calendar', array( $this, 'calendar' ) );
		add_shortcode( 'tur_takvimi_postcode_search', array( $this, 'postcode_search' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (but do not force-enqueue) front-end assets.
	 */
	public function register_assets(): void {
		wp_register_style( 'tur-takvimi', TURTAKVIMI_URL . 'assets/css/frontend.css', array(), Plugin::asset_ver( 'assets/css/frontend.css' ) );
		wp_register_script( 'tur-takvimi', TURTAKVIMI_URL . 'assets/js/frontend.js', array(), Plugin::asset_ver( 'assets/js/frontend.js' ), true );

		wp_localize_script(
			'tur-takvimi',
			'TurTakvimi',
			array(
				'rest'    => esc_url_raw( rest_url( Rest_Api::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'country' => Settings::get( 'country', 'NL' ),
				'i18n'    => array(
					'searching' => __( 'Searching…', 'tur-takvimi' ),
					'nearest'   => __( 'Your nearest stop', 'tur-takvimi' ),
					'next'      => __( 'Next visit', 'tur-takvimi' ),
					'distance'  => __( 'Distance', 'tur-takvimi' ),
					'kmAway'    => __( 'km away', 'tur-takvimi' ),
					'inArea'    => __( 'In your area', 'tur-takvimi' ),
					'noResult'  => __( 'No stop found for this postcode.', 'tur-takvimi' ),
					'details'   => __( 'See delivery details', 'tur-takvimi' ),
					'noDate'    => __( 'Not scheduled yet', 'tur-takvimi' ),
				),
			)
		);

		$this->inline_brand_styles();
	}

	/**
	 * Inject white-label brand colors as CSS variables.
	 */
	private function inline_brand_styles(): void {
		$primary = Settings::get( 'primary_color', '#e3242b' );
		$accent  = Settings::get( 'accent_color', '#16a34a' );
		$css     = sprintf(
			':root{--tt-primary:%s;--tt-accent:%s;}',
			esc_attr( $primary ),
			esc_attr( $accent )
		);
		wp_add_inline_style( 'tur-takvimi', $css );
	}

	/**
	 * Render the weekly tour calendar (server-side for SEO).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function calendar( $atts ): string {
		$atts = shortcode_atts(
			array(
				'weeks'   => (int) Settings::get( 'calendar_weeks', 3 ),
				'country' => '',
			),
			$atts,
			'tur_takvimi_calendar'
		);

		wp_enqueue_style( 'tur-takvimi' );
		wp_enqueue_script( 'tur-takvimi' );

		$weeks    = max( 1, min( 12, (int) $atts['weeks'] ) );
		$start    = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
		$end      = $start->modify( '+' . $weeks . ' weeks -1 day' );
		$schedule = new Schedule();
		$tours    = $schedule->get_tours_between( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), strtoupper( (string) $atts['country'] ) );

		$days = array();
		foreach ( $tours as $tour ) {
			$days[ $tour['tour_date'] ][] = $tour;
		}

		$heading = Settings::get( 'calendar_heading', __( 'Weekly Delivery Tours', 'tur-takvimi' ) );
		$today   = current_time( 'Y-m-d' );

		ob_start();
		?>
		<section class="tt-calendar" aria-label="<?php echo esc_attr( $heading ); ?>">
			<h2 class="tt-calendar__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php if ( empty( $days ) ) : ?>
				<p class="tt-calendar__empty"><?php esc_html_e( 'No tours scheduled for this period yet.', 'tur-takvimi' ); ?></p>
			<?php else : ?>
				<?php foreach ( $days as $date => $items ) : ?>
					<article class="tt-day<?php echo $date === $today ? ' tt-day--today' : ''; ?>">
						<header class="tt-day__header">
							<span class="tt-day__date"><?php echo esc_html( Rest_Api::format_day( $date ) ); ?></span>
							<?php if ( $date === $today ) : ?>
								<span class="tt-day__badge"><?php esc_html_e( 'TODAY', 'tur-takvimi' ); ?></span>
							<?php endif; ?>
						</header>
						<div class="tt-day__stops">
							<?php
							foreach ( $items as $tour ) :
								foreach ( $tour['locations'] as $loc ) :
									?>
									<a class="tt-chip" href="<?php echo esc_url( $loc['url'] ); ?>">
										<span class="tt-chip__pin" aria-hidden="true">📍</span>
										<?php echo esc_html( $loc['title'] ); ?>
									</a>
									<?php
								endforeach;
							endforeach;
							?>
						</div>
					</article>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the postcode search widget.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function postcode_search( $atts ): string {
		$atts = shortcode_atts( array( 'country' => '' ), $atts, 'tur_takvimi_postcode_search' );

		wp_enqueue_style( 'tur-takvimi' );
		wp_enqueue_script( 'tur-takvimi' );

		// Lock the widget to a country when set; otherwise show the default's
		// example (the country is auto-detected from what the visitor types).
		$country = strtoupper( (string) $atts['country'] );
		$example = Country::example( '' !== $country ? $country : Country::default_code() );
		if ( '' === $example ) {
			$example = '45134';
		}
		$placeholder = sprintf(
			/* translators: %s: example postcode. */
			__( 'Postcode (e.g. %s)', 'tur-takvimi' ),
			$example
		);

		ob_start();
		?>
		<div class="tt-search" data-tt-search data-country="<?php echo esc_attr( $country ); ?>">
			<form class="tt-search__form" role="search">
				<label class="tt-search__sr" for="tt-postcode"><?php esc_html_e( 'Enter your postcode to find the nearest stop and date', 'tur-takvimi' ); ?></label>
				<div class="tt-search__row">
					<div class="tt-search__field">
						<span class="tt-search__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>
						</span>
						<input id="tt-postcode" class="tt-search__input" type="text" inputmode="numeric" autocomplete="postal-code" placeholder="<?php echo esc_attr( $placeholder ); ?>" data-tt-input>
					</div>
					<button type="submit" class="tt-search__button"><?php esc_html_e( 'Search', 'tur-takvimi' ); ?></button>
				</div>
			</form>
			<div class="tt-search__result" data-tt-result aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
