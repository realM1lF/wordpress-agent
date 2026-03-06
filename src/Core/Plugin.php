<?php

namespace Levi\Agent\Core;

use Levi\Agent\Admin\ChatWidget;
use Levi\Agent\Admin\SetupWizardPage;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\AI\AIClientFactory;
use Levi\Agent\API\ChatController;
use Levi\Agent\Memory\StateSnapshotService;
use Levi\Agent\Cron\CronTaskRunner;

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
        new SetupWizardPage();
        
        // REST API
        new ChatController();
        new StateSnapshotService();
        
        // Cron Task Runner
        new CronTaskRunner();
        
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
        
        // Only reload identity files (fast) - reference files are synced automatically via cron
        $loader = new \Levi\Agent\Memory\MemoryLoader();
        $results = $loader->loadIdentityFiles();
        
        wp_send_json_success([
            'message' => 'Identity files reloaded successfully',
            'results' => [
                'identity' => $results,
                'reference' => ['loaded' => [], 'errors' => []], // Reference files handled by background sync
            ],
        ]);
    }

    public function enqueueAssets(): void {
        // Only load on admin pages
        if (!is_admin()) {
            return;
        }

        $cssFile = LEVI_AGENT_PLUGIN_DIR . 'assets/css/chat-widget.css';
        wp_enqueue_style(
            'levi-agent-chat',
            LEVI_AGENT_PLUGIN_URL . 'assets/css/chat-widget.css',
            [],
            file_exists($cssFile) ? (string) filemtime($cssFile) : LEVI_AGENT_VERSION
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

        $jsFile = LEVI_AGENT_PLUGIN_DIR . 'assets/js/chat-widget.js';
        wp_enqueue_script(
            'levi-agent-chat',
            LEVI_AGENT_PLUGIN_URL . 'assets/js/chat-widget.js',
            ['levi-marked', 'levi-dompurify'],
            file_exists($jsFile) ? (string) filemtime($jsFile) : LEVI_AGENT_VERSION,
            true
        );

        $currentUser = wp_get_current_user();
        $firstName = trim((string) ($currentUser->first_name ?? ''));
        $displayName = $firstName !== '' ? $firstName : (string) ($currentUser->display_name ?? '');
        $userInitial = $displayName !== '' ? strtoupper(mb_substr($displayName, 0, 1)) : 'U';

        wp_localize_script('levi-agent-chat', 'leviAgent', [
            'restUrl' => rest_url('levi-agent/v1/'),
            'streamUrl' => rest_url('levi-agent/v1/chat/stream'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userName' => $displayName,
            'userInitial' => $userInitial,
            'userAvatarUrl' => get_avatar_url(get_current_user_id(), ['size' => 56]),
            'leviAvatarUrl' => LEVI_AGENT_PLUGIN_URL . 'assets/images/levi-avatar-icon.webp',
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'adminNonce' => wp_create_nonce('levi_admin_nonce'),
        ]);
    }
}
