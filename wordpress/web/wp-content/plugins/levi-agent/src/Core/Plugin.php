<?php

namespace Levi\Agent\Core;

use Levi\Agent\Admin\ChatWidget;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\AI\AIClientFactory;
use Levi\Agent\API\ChatController;
use Levi\Agent\Memory\StateSnapshotService;

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
        new StateSnapshotService();
        
        // Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // Test connection AJAX handler
        add_action('wp_ajax_levi_test_connection', [$this, 'ajaxTestConnection']);
        
        // Memory reload AJAX handler
        add_action('wp_ajax_levi_reload_memories', [$this, 'ajaxReloadMemories']);
    }
    
    public function ajaxTestConnection(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $settings = new SettingsPage();
        $client = AIClientFactory::create($settings->getProvider());
        $result = $client->testConnection();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    public function ajaxReloadMemories(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $loader = new \Levi\Agent\Memory\MemoryLoader();
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
            'levi-agent-chat',
            LEVI_AGENT_PLUGIN_URL . 'assets/css/chat-widget.css',
            [],
            LEVI_AGENT_VERSION
        );

        // Same markdown rendering behavior as Mohami UI (GFM + sanitizer).
        wp_enqueue_script(
            'levi-marked',
            'https://cdn.jsdelivr.net/npm/marked/marked.min.js',
            [],
            '13.0.3',
            true
        );

        wp_enqueue_script(
            'levi-dompurify',
            'https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js',
            [],
            '3.1.6',
            true
        );

        wp_enqueue_script(
            'levi-agent-chat',
            LEVI_AGENT_PLUGIN_URL . 'assets/js/chat-widget.js',
            ['levi-marked', 'levi-dompurify'],
            LEVI_AGENT_VERSION,
            true
        );

        wp_localize_script('levi-agent-chat', 'leviAgent', [
            'restUrl' => rest_url('levi-agent/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userName' => wp_get_current_user()->display_name,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'adminNonce' => wp_create_nonce('levi_admin_nonce'),
        ]);
    }
}
