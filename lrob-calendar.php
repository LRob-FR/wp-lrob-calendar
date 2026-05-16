<?php
/**
 * Plugin Name: LRob - Calendar
 * Plugin URI: https://www.lrob.fr/wordpress/plugins/lrob-calendar/
 * Description: A powerful and clean event calendar for WordPress with recurring events, categories, locations, import/export and more.
 * Version: 1.1.3
 * Author: LRob
 * Author URI: https://www.lrob.fr
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: lrob-calendar
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LROB_CALENDAR_VERSION', '1.1.3');
define('LROB_CALENDAR_FILE', __FILE__);
define('LROB_CALENDAR_PATH', plugin_dir_path(__FILE__));
define('LROB_CALENDAR_URL', plugin_dir_url(__FILE__));
define('LROB_CALENDAR_BASENAME', plugin_basename(__FILE__));
define('LROB_CALENDAR_PLUGIN_URL', 'https://www.lrob.fr/wordpress/plugins/lrob-calendar/');
define('LROB_CALENDAR_GITHUB_URL', 'https://github.com/LRob-FR/wp-lrob-calendar');
define('LROB_CALENDAR_GITHUB_ISSUES_URL', LROB_CALENDAR_GITHUB_URL . '/issues');

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'LRob_Calendar';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    
    $file = str_replace($prefix, '', $class);
    $file = str_replace('_', '-', strtolower($file));
    $file = LROB_CALENDAR_PATH . 'includes/class-lrob-calendar' . $file . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Activation/Deactivation
register_activation_hook(__FILE__, ['LRob_Calendar', 'activate']);
register_deactivation_hook(__FILE__, ['LRob_Calendar', 'deactivate']);

// Check DB schema version on every load. Cheap when up-to-date (one option read),
// catches the case where the plugin files are updated without re-activation.
add_action('plugins_loaded', ['LRob_Calendar_Database', 'maybe_upgrade'], 5);

// Init
add_action('plugins_loaded', function () {
    LRob_Calendar::instance();
});
