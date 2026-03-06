<?php
/**
 * Plugin Name: Paystack WPForms Payment Gateway
 * Plugin URI:  https://theophilusadegbohungbe.com
 * Description: Paystack payments for WPForms Lite and WPForms Pro.
 * Version:     1.0.0
 * Author:      Theophilus Adegbohungbe
 * Author URI:  https://theophilusadegbohungbe.com
 * Text Domain: wpfs-paystack
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WPFSPaystack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPFS_PAYSTACK_VERSION', '1.0.0' );
define( 'WPFS_PAYSTACK_FILE', __FILE__ );
define( 'WPFS_PAYSTACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFS_PAYSTACK_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPFSPaystack\\';

		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = WPFS_PAYSTACK_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Bootstrap the plugin once all plugins are loaded.
 *
 * @return void
 */
function wpfs_paystack_bootstrap() {

	load_plugin_textdomain( 'wpfs-paystack', false, dirname( plugin_basename( WPFS_PAYSTACK_FILE ) ) . '/languages' );

	if ( ! function_exists( 'wpforms' ) ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', 'wpfs_paystack_wpforms_required_notice' );
		}

		return;
	}

	if ( did_action( 'wpforms_loaded' ) ) {
		\WPFSPaystack\Plugin::instance();

		return;
	}

	add_action( 'wpforms_loaded', [ '\WPFSPaystack\Plugin', 'instance' ] );
}
add_action( 'plugins_loaded', 'wpfs_paystack_bootstrap', 1 );

/**
 * Display an admin notice when WPForms is not active.
 *
 * @return void
 */
function wpfs_paystack_wpforms_required_notice() {

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Paystack WPForms Payment Gateway needs WPForms Lite or WPForms Pro to be installed and active.', 'wpfs-paystack' );
	echo '</p></div>';
}
