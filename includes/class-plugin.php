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
