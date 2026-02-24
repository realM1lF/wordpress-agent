<?php if (!defined('ABSPATH')) exit; ?>
<div id="mohami-chat-widget" class="mohami-chat-widget">
    <button class="mohami-chat-toggle" id="mohami-chat-toggle" title="KI-Assistent">
        <span class="dashicons dashicons-format-chat"></span>
    </button>
    <div class="mohami-chat-window" id="mohami-chat-window" style="display: none;">
        <div class="mohami-chat-header">
            <span class="mohami-chat-title">🤖 Mohami Assistant</span>
            <button class="mohami-chat-close" id="mohami-chat-close">&times;</button>
        </div>
        <div class="mohami-chat-messages" id="mohami-chat-messages">
            <div class="mohami-message mohami-message-assistant">
                <div class="mohami-message-content">
                    Hallo <?php echo esc_html(wp_get_current_user()->display_name); ?>! 👋<br>
                    Ich bin dein WordPress KI-Assistent. Wie kann ich dir helfen?
                </div>
            </div>
        </div>
        <div class="mohami-chat-input-area">
            <textarea 
                id="mohami-chat-input" 
                class="mohami-chat-input" 
                placeholder="Schreib eine Nachricht..."
                rows="2"
            ></textarea>
            <button id="mohami-chat-send" class="mohami-chat-send">
                <span class="dashicons dashicons-arrow-right-alt2"></span>
            </button>
        </div>
    </div>
</div>
