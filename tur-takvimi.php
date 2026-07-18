<?php
/**
 * Plugin Name:       Tur Takvimi
 * Plugin URI:        https://example.com/tur-takvimi
 * Description:        Resellable, white-label tour calendar + location SEO + pre-order system for mobile/route-based delivery businesses. Core layer works standalone; an optional WooCommerce add-on enables products and upfront pre-payment.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Text Domain:       tur-takvimi
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package TurTakvimi
 */

defined( 'ABSPATH' ) || exit;

define( 'TURTAKVIMI_VERSION', '0.1.0' );
define( 'TURTAKVIMI_DB_VERSION', '7' );
define( 'TURTAKVIMI_FILE', __FILE__ );
define( 'TURTAKVIMI_DIR', plugin_dir_path( __FILE__ ) );
define( 'TURTAKVIMI_URL', plugin_dir_url( __FILE__ ) );
define( 'TURTAKVIMI_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal class-map autoloader. Maps TurTakvimi\Some_Class to
 * includes/class-some-class.php (lower-cased, underscores -> hyphens).
 */
spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'TurTakvimi\\' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( 'TurTakvimi\\' ) );
		$file     = 'class-' . str_replace( '_', '-', strtolower( $relative ) ) . '.php';
		$path     = TURTAKVIMI_DIR . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( '\\TurTakvimi\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\TurTakvimi\\Activator', 'deactivate' ) );

/**
 * Load translations on init (before post-type labels are registered) so the
 * admin UI and front-end follow the WordPress Site Language setting. Loading
 * earlier than `init` triggers a notice in WordPress 6.7+.
 */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'tur-takvimi', false, dirname( TURTAKVIMI_BASENAME ) . '/languages' );
	},
	1
);

/**
 * Boot the plugin once all plugins are loaded. Components only register hooks
 * here; label/string translation happens later on the `init` hook.
 */
add_action(
	'plugins_loaded',
	static function () {
		\TurTakvimi\Plugin::instance()->run();
	}
);
