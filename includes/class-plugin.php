<?php
/**
 * Main plugin orchestrator.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and wires together all components.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the WooCommerce commerce layer is available.
	 *
	 * @var bool
	 */
	private $has_woocommerce = false;

	/**
	 * Get the singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks and load components.
	 */
	public function run(): void {
		$this->has_woocommerce = class_exists( 'WooCommerce' );

		// Apply DB migrations after a plain file update (no reactivation needed).
		add_action( 'init', array( Activator::class, 'maybe_upgrade' ), 99 );

		// Core layer — always loaded, zero hard dependencies.
		( new Post_Types() )->register();
		( new Settings() )->register();
		( new Schedule() )->register();
		( new Rest_Api() )->register();
		( new Shortcodes() )->register();
		( new City_Page() )->register();
		( new Map_Explorer() )->register();
		( new Calendar() )->register();
		( new Whatsapp() )->register();
		( new Subscribers() )->register();
		( new Notifier() )->register();

		// Page builders render shortcodes out-of-band, so the per-shortcode
		// asset enqueues never reach the editor canvas. Load them up-front there.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_builder' ), 99 );

		if ( is_admin() ) {
			( new Importer() )->register();
			( new Location_Meta() )->register();
			( new Route_Meta() )->register();
		}

		/**
		 * Commerce layer hook point. The WooCommerce-dependent add-on registers
		 * here only when Woo is active, keeping the core builder/payment-agnostic.
		 */
		if ( $this->has_woocommerce ) {
			do_action( 'tur_takvimi_load_commerce' );
		}

		do_action( 'tur_takvimi_loaded', $this );
	}

	/**
	 * Whether the commerce (WooCommerce) layer is active.
	 */
	public function has_commerce(): bool {
		return $this->has_woocommerce;
	}

	/**
	 * In a builder canvas/preview, force-enqueue the front-end assets so
	 * shortcodes (which normally enqueue lazily during render) display fully.
	 * Runs only inside builders, never on the live front end.
	 */
	public function enqueue_for_builder(): void {
		if ( ! self::is_builder_preview() ) {
			return;
		}
		foreach ( array( 'tur-takvimi', 'tur-takvimi-calendar', 'leaflet', 'tur-takvimi-explorer', 'tur-takvimi-map' ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				wp_enqueue_style( $handle );
			}
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}
	}

	/**
	 * Whether the current request is a page-builder canvas/preview render,
	 * where shortcode content is shown but per-shortcode enqueues are dropped.
	 *
	 * @return bool
	 */
	public static function is_builder_preview(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// Breakdance builder app + canvas/preview render.
		if ( function_exists( '\Breakdance\Render\isPreviewPage' ) && \Breakdance\Render\isPreviewPage() ) {
			return true;
		}

		// Canvas/preview iframe flags used by the common builders.
		$flags = array(
			'breakdance',        // Breakdance builder.
			'breakdance_iframe',
			'elementor-preview', // Elementor.
			'fl_builder',        // Beaver Builder.
			'brizy-edit',        // Brizy.
			'brizy-edit-iframe',
			'ct_builder',        // Oxygen.
		);
		foreach ( $flags as $flag ) {
			if ( isset( $_GET[ $flag ] ) ) {
				return true;
			}
		}

		// Oxygen / Breakdance edit constant.
		if ( defined( 'SHOW_CT_BUILDER' ) && SHOW_CT_BUILDER ) {
			return true;
		}
		// phpcs:enable

		/**
		 * Allow a site to flag additional builder/preview contexts.
		 *
		 * @param bool $is_preview Whether this is a builder preview render.
		 */
		return (bool) apply_filters( 'tur_takvimi_is_builder_preview', false );
	}

	/**
	 * Cache-busting version for a bundled asset: its modified time, so updates
	 * are picked up immediately, falling back to the plugin version.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	public static function asset_ver( string $relative ): string {
		$mtime = @filemtime( TURTAKVIMI_DIR . ltrim( $relative, '/' ) );
		return $mtime ? (string) $mtime : TURTAKVIMI_VERSION;
	}
}
