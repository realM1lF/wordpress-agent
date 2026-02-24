<?php

namespace Mohami\Agent\Core;

use Mohami\Agent\Admin\ChatWidget;
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
        
        // REST API
        new ChatController();
        
        // Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
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
            ['wp-element', 'wp-components', 'wp-api-fetch'],
            MOHAMI_AGENT_VERSION,
            true
        );

        wp_localize_script('mohami-agent-chat', 'mohamiAgent', [
            'restUrl' => rest_url('mohami-agent/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userName' => wp_get_current_user()->display_name,
        ]);
    }
}
