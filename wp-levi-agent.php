<?php
/**
 * Plugin Name: Levi AI Agent
 * Description: KI-Mitarbeiter für WordPress - inspiriert von Mohami
 * Version: 0.7.1
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

define('LEVI_AGENT_VERSION', '0.7.1');
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
use Levi\Agent\Database\Tables;

// Ensure DB tables exist (runs before Plugin init - fixes missed activation hook)
add_action('plugins_loaded', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'levi_conversations';
    $auditTable = $wpdb->prefix . 'levi_audit_log';
    $usageTable = $wpdb->prefix . 'levi_usage_log';
    if (
        $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table
        || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $auditTable)) !== $auditTable
        || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $usageTable)) !== $usageTable
    ) {
        require_once LEVI_AGENT_PLUGIN_DIR . 'src/Database/Tables.php';
        Tables::create();
    }

    if (!wp_next_scheduled('levi_cleanup_audit_log')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'levi_cleanup_audit_log');
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
    Tables::create();
    StateSnapshotService::scheduleEvent();
    if (!wp_next_scheduled('levi_cleanup_audit_log')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'levi_cleanup_audit_log');
    }

    // Trigger one-time setup wizard redirect after activation.
    update_option('levi_setup_wizard_pending', 1);
    if (get_option('levi_setup_completed', null) === null) {
        update_option('levi_setup_completed', 0);
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    StateSnapshotService::unscheduleEvent();
    wp_clear_scheduled_hook('levi_cleanup_audit_log');
});

add_action('levi_cleanup_audit_log', function() {
    require_once LEVI_AGENT_PLUGIN_DIR . 'src/Database/Tables.php';
    Tables::cleanupAuditLog(7);
});

// WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('levi test', \Levi\Agent\Testing\TestCommand::class);
}
