<?php

namespace Mohami\Agent\Core;

use Mohami\Agent\Admin\ChatWidget;
use Mohami\Agent\Admin\SettingsPage;
use Mohami\Agent\API\ChatController;

class Plugin {
    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init(): void {
        // Admin
        new ChatWidget();
        new SettingsPage();
        
        // REST API
        new ChatController();
        
        // Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // Test connection AJAX handler
        add_action('wp_ajax_mohami_test_connection', [$this, 'ajaxTestConnection']);
        
        // Memory reload AJAX handler
        add_action('wp_ajax_mohami_reload_memories', [$this, 'ajaxReloadMemories']);
    }
    
    public function ajaxTestConnection(): void {
        check_ajax_referer('mohami_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $client = new \Mohami\Agent\AI\OpenRouterClient();
        $result = $client->testConnection();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    public function ajaxReloadMemories(): void {
        check_ajax_referer('mohami_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $loader = new \Mohami\Agent\Memory\MemoryLoader();
        $results = $loader->loadAllMemories();
        
        wp_send_json_success([
            'message' => 'Memories reloaded successfully',
            'results' => $results,
        ]);
    }

    public function enqueueAssets(): void {
        // Only load on admin pages
        if (!is_admin()) {
            return;
        }

        wp_enqueue_style(
            'mohami-agent-chat',
            MOHAMI_AGENT_PLUGIN_URL . 'assets/css/chat-widget.css',
            [],
            MOHAMI_AGENT_VERSION
        );

        wp_enqueue_script(
            'mohami-agent-chat',
            MOHAMI_AGENT_PLUGIN_URL . 'assets/js/chat-widget.js',
            [],
            MOHAMI_AGENT_VERSION,
            true
        );

        wp_localize_script('mohami-agent-chat', 'mohamiAgent', [
            'restUrl' => rest_url('mohami-agent/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userName' => wp_get_current_user()->display_name,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'adminNonce' => wp_create_nonce('mohami_admin_nonce'),
        ]);
    }
}
