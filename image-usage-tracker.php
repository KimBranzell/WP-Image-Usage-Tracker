<?php
/**
 * Plugin Name: Image Usage Tracker
 * Plugin URI: https://yourwebsite.com/plugins/image-usage-tracker
 * Description: Track where images from the media library are being used across your WordPress site
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: image-usage-tracker
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IUT_VERSION', '1.0.0');
define('IUT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IUT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
require_once IUT_PLUGIN_DIR . 'includes/class-autoloader.php';

// Initialize plugin
function iut_initialize() {
    $plugin = new ImageUsageTracker\Core();
    $plugin->init();
    $plugin->activate();
}
add_action('plugins_loaded', 'iut_initialize');
