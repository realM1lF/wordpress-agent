<?php
/**
 * Plugin Name: Mohami AI Agent
 * Description: KI-Mitarbeiter für WordPress - inspiriert von Mohami
 * Version: 0.1.0
 * Author: realM1lF
 * License: GPL v2
 * Text Domain: mohami-agent
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MOHAMI_AGENT_VERSION', '0.1.0');
define('MOHAMI_AGENT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MOHAMI_AGENT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
require_once MOHAMI_AGENT_PLUGIN_DIR . 'vendor/autoload.php';

// Main Plugin Class
use Mohami\Agent\Core\Plugin;

// Initialize
add_action('plugins_loaded', function() {
    Plugin::instance();
});

// Activation hook
register_activation_hook(__FILE__, function() {
    require_once MOHAMI_AGENT_PLUGIN_DIR . 'src/Database/Tables.php';
    Mohami\Agent\Database\Tables::create();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Cleanup if needed
});
