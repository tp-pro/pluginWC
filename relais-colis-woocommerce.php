<?php
/**
 * Plugin Name: Relais Colis WooCommerce
 * Plugin URI: https://www.relaiscolis.com/
 * Description: Adds Relais Colis shipping method to WooCommerce.
 * Version: 0.0.1
 * Author: Calliweb
 * Author URI: https://www.calliweb.fr/
 * Text Domain: relais-colis-woocommerce
 * Domain Path: /languages
 * License: GPLv3
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Add a new settings tab to WooCommerce
add_filter('woocommerce_get_settings_pages', 'rc_add_settings_tab');

add_action( 'plugins_loaded', 'rc_load_textdomain' );
function rc_load_textdomain() {
    load_plugin_textdomain( 'relais-colis-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
/**
 * @param $settings
 * @return mixed
 */
function rc_add_settings_tab($settings): mixed
{
    $settings[] = include 'includes/class-rc-settings.php';
    return $settings;
}
