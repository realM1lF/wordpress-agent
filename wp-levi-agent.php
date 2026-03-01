<?php
/**
 * Plugin Name: Levi AI Agent
 * Description: KI-Mitarbeiter für WordPress - inspiriert von Mohami
 * Version: 0.1.0
 * Author: realM1lF
 * License: GPL v2
 * Text Domain: levi-agent
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Tested up to: 6.7
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LEVI_AGENT_VERSION', '0.1.0');
define('LEVI_AGENT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LEVI_AGENT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
if (file_exists(LEVI_AGENT_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once LEVI_AGENT_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    // Fallback to manual autoloading
    spl_autoload_register(function ($class) {
        $prefix = 'Levi\\Agent\\';
        $base_dir = LEVI_AGENT_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });
}

// Main Plugin Class
use Levi\Agent\Core\Plugin;
use Levi\Agent\Memory\StateSnapshotService;

// Ensure DB tables exist (runs before Plugin init - fixes missed activation hook)
add_action('plugins_loaded', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'levi_conversations';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        require_once LEVI_AGENT_PLUGIN_DIR . 'src/Database/Tables.php';
        Levi\Agent\Database\Tables::create();
    }
}, 1);

// Initialize
add_action('plugins_loaded', function() {
    load_plugin_textdomain('levi-agent', false, dirname(plugin_basename(__FILE__)) . '/languages');
    Plugin::instance();
}, 10);

// Activation hook
register_activation_hook(__FILE__, function() {
    require_once LEVI_AGENT_PLUGIN_DIR . 'src/Database/Tables.php';
    Levi\Agent\Database\Tables::create();
    StateSnapshotService::scheduleEvent();

    // Trigger one-time setup wizard redirect after activation.
    update_option('levi_setup_wizard_pending', 1);
    if (get_option('levi_setup_completed', null) === null) {
        update_option('levi_setup_completed', 0);
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    StateSnapshotService::unscheduleEvent();
});
