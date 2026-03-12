<?php

namespace Levi\Agent\Admin;

class ChatWidget {
    private static bool $initialized = false;

    public function __construct() {
        // Prevent multiple initializations
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        
        add_action('admin_footer', [$this, 'renderChatWidget']);
    }

    public function renderChatWidget(): void {
        // Only show for users with edit_posts capability
        if (!current_user_can('edit_posts')) {
            return;
        }
        include LEVI_AGENT_PLUGIN_DIR . 'templates/admin/chat-widget.php';
    }
}
