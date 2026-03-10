<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://#
 * @since             1.0.0
 * @package           Florex_Woocommerce
 *
 * @wordpress-plugin
 * Plugin Name:       Florex
 * Description:       Integration between Florex and Woocommerce. Tracks UTMs, SKU visits & landing pages, prevents duplicate orders & more
 * Version:           4.5.1
 * Author:            Florex.io
 * Author URI:        https://florex.io
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       florex-woocommerce
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'FLOREX_WOOCOMMERCE_VERSION', '4.5.1' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-florex-woocommerce-activator.php
 */
function activate_florex_woocommerce() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-florex-woocommerce-activator.php';
	Florex_Woocommerce_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-florex-woocommerce-deactivator.php
 */
function deactivate_florex_woocommerce() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-florex-woocommerce-deactivator.php';
	Florex_Woocommerce_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_florex_woocommerce' );
register_deactivation_hook( __FILE__, 'deactivate_florex_woocommerce' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
//require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-florex-woocommerce.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_florex_woocommerce() {

	$plugin = new Florex_Woocommerce();
	$plugin->run();

}
run_florex_woocommerce();
