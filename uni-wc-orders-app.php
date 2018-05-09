<?php
/**
 * Plugin Name: WC Orders App WP API extension
 * Plugin URI:
 * Description: Custom WP API endpoints, JWT auth
 * Version: 1.0.0
 * Author: MooMoo Agency
 * Author URI: http://moomoo.agency
 * Domain Path: /languages/
 * Text Domain: wc-orders-app
 * Requires PHP: 7.0
 * WC requires at least: 3.2
 * WC tested up to: 3.3
 * License: GPL v3
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require plugin_dir_path( __FILE__ ) . 'includes/class-uni-wc-orders-app.php';

/**
 * Main instance of Uni_Wc_Orders_App.
 *
 * Returns the main instance of Uni_Wc_Orders_App to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return Uni_Wc_Orders_App
 */
function UniWcOrdersApp() {
    return Uni_Wc_Orders_App::instance();
}

// Global for backwards compatibility.
$GLOBALS['uniwcordersapp'] = UniWcOrdersApp();