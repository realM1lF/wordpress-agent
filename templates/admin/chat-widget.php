<?php if (!defined('ABSPATH')) exit; ?>
<div id="levi-chat-widget" class="levi-chat-widget">
    <button class="levi-chat-toggle" id="levi-chat-toggle" title="KI-Assistent">
        <span class="dashicons dashicons-format-chat"></span>
    </button>
    <div class="levi-chat-window" id="levi-chat-window" style="display: none;">
        <div class="levi-chat-header">
            <span class="levi-chat-title">🤖 Levi Assistant</span>
            <button class="levi-chat-close" id="levi-chat-close">&times;</button>
        </div>
        <div class="levi-chat-messages" id="levi-chat-messages">
            <div class="levi-message levi-message-assistant">
                <div class="levi-message-content">
                    Hallo <?php echo esc_html(wp_get_current_user()->display_name); ?>! 👋<br>
                    Ich bin dein WordPress KI-Assistent. Wie kann ich dir helfen?
                </div>
            </div>
        </div>
        <div class="levi-chat-input-area">
            <textarea 
                id="levi-chat-input" 
                class="levi-chat-input" 
                placeholder="Schreib eine Nachricht..."
                rows="2"
            ></textarea>
            <button id="levi-chat-send" class="levi-chat-send">
                <span class="dashicons dashicons-arrow-right-alt2"></span>
            </button>
        </div>
    </div>
</div>
