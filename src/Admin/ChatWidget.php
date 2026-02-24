<?php

namespace Levi\Agent\Admin;

class ChatWidget {
    public function __construct() {
        add_action('admin_footer', [$this, 'renderChatWidget']);
        add_action('wp_dashboard_setup', [$this, 'addDashboardWidget']);
    }

    public function renderChatWidget(): void {
        // Only show for users with edit_posts capability
        if (!current_user_can('edit_posts')) {
            return;
        }
        include LEVI_AGENT_PLUGIN_DIR . 'templates/admin/chat-widget.php';
    }

    public function addDashboardWidget(): void {
        wp_add_dashboard_widget(
            'levi_ai_chat',
            __('Levi AI Assistant', 'levi-agent'),
            [$this, 'renderDashboardWidget']
        );
    }

    public function renderDashboardWidget(): void {
        echo '<div id="mohami-dashboard-chat"></div>';
    }
}
