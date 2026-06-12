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

		// Core layer — always loaded, zero hard dependencies.
		( new Post_Types() )->register();
		( new Settings() )->register();
		( new Schedule() )->register();
		( new Rest_Api() )->register();
		( new Shortcodes() )->register();

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
}
