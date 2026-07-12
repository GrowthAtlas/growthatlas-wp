<?php
/**
 * Plugin Name:       GrowthAtlas
 * Plugin URI:        https://growthatlas.io/connector-api#wordpress
 * Description:       Official WordPress connector for GrowthAtlas. Receives AI-generated SEO content from GrowthAtlas and publishes it to your WordPress site automatically.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            GrowthAtlas
 * Author URI:        https://growthatlas.io
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       growthatlas
 */

if (! defined('ABSPATH')) {
    exit;
}

define('GROWTHATLAS_VERSION', '1.0.0');
define('GROWTHATLAS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GROWTHATLAS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GROWTHATLAS_PLUGIN_DIR . 'includes/class-api-router.php';
require_once GROWTHATLAS_PLUGIN_DIR . 'includes/class-settings.php';
require_once GROWTHATLAS_PLUGIN_DIR . 'includes/class-version-checker.php';
require_once GROWTHATLAS_PLUGIN_DIR . 'includes/class-content-handler.php';
require_once GROWTHATLAS_PLUGIN_DIR . 'includes/class-seo-bridge.php';

add_action('init', function () {
    GrowthAtlas\Settings::init();
});

add_action('rest_api_init', function () {
    GrowthAtlas\ApiRouter::register_routes();
});

register_activation_hook(__FILE__, function () {
    if (! get_option('growthatlas_api_key')) {
        update_option('growthatlas_api_key', bin2hex(random_bytes(24)));
    }
});
