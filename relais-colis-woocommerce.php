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

// Test if woocommerce is active
if (
    !file_exists(WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'woocommerce' . DIRECTORY_SEPARATOR . 'woocommerce.php')
    && !in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))
    && (is_multisite() && !in_array('woocommerce/woocommerce.php',
            apply_filters('active_plugins', array_keys(get_site_option('active_sitewide_plugins')))))
) {
    exit;
}

// Load defines
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

define('RC_FOLDER', plugin_dir_path(__FILE__));

const RC_INCLUDES = RC_FOLDER . 'includes' . DS;
const RC_LANGUAGE = RC_FOLDER . 'languages' . DS;
const RC_ASSETS = RC_FOLDER . 'assets' . DS;
const RC_JS = RC_ASSETS . 'js' . DS;
const RC_CSS = RC_ASSETS . 'css' . DS;
const RC_API = RC_INCLUDES . 'api' . DS;

// Add a new settings tab to WooCommerce
add_filter('woocommerce_get_settings_pages', 'rc_add_settings_tab');

add_action('plugins_loaded', 'rc_load_textdomain');
function rc_load_textdomain(): void
{
    load_plugin_textdomain('relais-colis-woocommerce', false, RC_LANGUAGE);
}

/**
 * @param array $settings
 * @return mixed
 */
function rc_add_settings_tab(array $settings): array
{
    $settings[] = include 'includes/class-rc-settings.php';
    return $settings;
}