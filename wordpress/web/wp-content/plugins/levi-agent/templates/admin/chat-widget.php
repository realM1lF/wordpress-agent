<?php if (!defined('ABSPATH')) exit; ?>
<div id="levi-chat-widget" class="levi-chat-widget">
    <button class="levi-chat-toggle" id="levi-chat-toggle" title="KI-Assistent">
        <span class="dashicons dashicons-format-chat"></span>
    </button>
    <div class="levi-chat-window" id="levi-chat-window" style="display: none;">
        <div class="levi-chat-header">
            <span class="levi-chat-title">🤖 Levi Assistant <span class="levi-chat-alpha-badge">ALPHA</span></span>
            <div class="levi-chat-header-actions">
                <button class="levi-chat-expand" id="levi-chat-expand" title="Full Width">
                    <span class="dashicons dashicons-editor-expand"></span>
                </button>
                <button class="levi-chat-clear" id="levi-chat-clear" title="Session löschen">
                    <span class="dashicons dashicons-trash"></span>
                </button>
                <button class="levi-chat-close" id="levi-chat-close">&times;</button>
            </div>
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
            <div id="levi-chat-attachments" class="levi-chat-attachments" style="display:none;">
                <div id="levi-chat-file-list" class="levi-chat-file-list"></div>
                <button id="levi-chat-clear-files-btn" class="levi-chat-clear-files-btn" type="button" title="Alle entfernen">×</button>
            </div>
            <div class="levi-chat-input-row">
                <button id="levi-chat-upload-btn" class="levi-chat-upload-btn" type="button" title="Datei oder Bild anhängen">
                    <span class="dashicons dashicons-paperclip"></span>
                </button>
                <input id="levi-chat-file-input" type="file" accept=".txt,.md,.csv,.json,.xml,.log,.jpg,.jpeg,.png,.gif,.webp,text/plain,text/markdown,text/csv,application/json,image/*" multiple hidden>
                <textarea 
                    id="levi-chat-input" 
                    class="levi-chat-input" 
                    placeholder="Schreib eine Nachricht..."
                    rows="2"
                ></textarea>
                <button id="levi-chat-send" class="levi-chat-send">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </button>
                <button id="levi-chat-stop" class="levi-chat-stop" style="display:none;" title="Abbrechen">
                    <span class="dashicons dashicons-no"></span>
                </button>
            </div>
        </div>
    </div>
</div>
