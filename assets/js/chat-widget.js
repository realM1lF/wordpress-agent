/**
 * Mohami Chat Widget JavaScript
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('mohami-chat-toggle');
        const window_ = document.getElementById('mohami-chat-window');
        const close = document.getElementById('mohami-chat-close');
        const input = document.getElementById('mohami-chat-input');
        const send = document.getElementById('mohami-chat-send');
        const messages = document.getElementById('mohami-chat-messages');

        let sessionId = localStorage.getItem('mohami_session_id') || null;

        // Toggle chat window
        toggle.addEventListener('click', function() {
            const isVisible = window_.style.display !== 'none';
            window_.style.display = isVisible ? 'none' : 'flex';
            if (!isVisible) {
                input.focus();
            }
        });

        // Close chat window
        close.addEventListener('click', function() {
            window_.style.display = 'none';
        });

        // Send message on button click
        send.addEventListener('click', sendMessage);

        // Send message on Enter (Shift+Enter for new line)
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        function sendMessage() {
            const text = input.value.trim();
            if (!text) return;

            // Add user message
            addMessage(text, 'user');
            input.value = '';

            // Show typing indicator
            const typing = addTypingIndicator();

            // Send to API
            fetch(mohamiAgent.restUrl + 'chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': mohamiAgent.nonce,
                },
                body: JSON.stringify({
                    message: text,
                    session_id: sessionId,
                }),
            })
            .then(response => response.json())
            .then(data => {
                // Remove typing indicator
                typing.remove();

                // Store session ID
                if (data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem('mohami_session_id', sessionId);
                }

                // Add assistant response
                addMessage(data.message, 'assistant');
            })
            .catch(error => {
                typing.remove();
                addMessage('Entschuldigung, es gab einen Fehler. Bitte versuche es erneut.', 'assistant');
                console.error('Error:', error);
            });
        }

        function addMessage(text, role) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'mohami-message mohami-message-' + role;
            messageDiv.innerHTML = '<div class="mohami-message-content">' + escapeHtml(text) + '</div>';
            messages.appendChild(messageDiv);
            messages.scrollTop = messages.scrollHeight;
        }

        function addTypingIndicator() {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'mohami-message mohami-message-assistant';
            typingDiv.innerHTML = 
                '<div class="mohami-message-content mohami-typing">' +
                '<span></span><span></span><span></span>' +
                '</div>';
            messages.appendChild(typingDiv);
            messages.scrollTop = messages.scrollHeight;
            return typingDiv;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Test connection button (on settings page)
        const testBtn = document.getElementById('mohami-test-connection');
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                const result = document.getElementById('mohami-test-result');
                result.textContent = ' Testing...';
                
                fetch(mohamiAgent.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=mohami_test_connection&nonce=' + encodeURIComponent(mohamiAgent.adminNonce),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        result.innerHTML = ' <span style="color: green;">✅ ' + (data.data.message || 'Success') + '</span>';
                    } else {
                        result.innerHTML = ' <span style="color: red;">❌ ' + (data.data || 'Failed') + '</span>';
                    }
                })
                .catch(err => {
                    result.innerHTML = ' <span style="color: red;">❌ Error: ' + err.message + '</span>';
                });
            });
        }

        // Reload memories button (on settings page)
        const reloadBtn = document.getElementById('mohami-reload-memories');
        if (reloadBtn) {
            reloadBtn.addEventListener('click', function() {
                const result = document.getElementById('mohami-reload-result');
                result.textContent = ' Reloading...';
                reloadBtn.disabled = true;
                
                fetch(mohamiAgent.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=mohami_reload_memories&nonce=' + encodeURIComponent(mohamiAgent.adminNonce),
                })
                .then(r => r.json())
                .then(data => {
                    reloadBtn.disabled = false;
                    if (data.success) {
                        const identityCount = Object.keys(data.data.results.identity.loaded || {}).length;
                        const referenceCount = Object.keys(data.data.results.reference.loaded || {}).length;
                        result.innerHTML = ' <span style="color: green;">✅ Reloaded! Identity: ' + identityCount + ', Reference: ' + referenceCount + ' files</span>';
                        // Reload page after 2 seconds to show updated stats
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        result.innerHTML = ' <span style="color: red;">❌ ' + (data.data || 'Failed') + '</span>';
                    }
                })
                .catch(err => {
                    reloadBtn.disabled = false;
                    result.innerHTML = ' <span style="color: red;">❌ Error: ' + err.message + '</span>';
                });
            });
        }
    });
})();
