/**
 * Levi Chat Widget JavaScript
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('levi-chat-toggle');
        const window_ = document.getElementById('levi-chat-window');
        const close = document.getElementById('levi-chat-close');
        const input = document.getElementById('levi-chat-input');
        const send = document.getElementById('levi-chat-send');
        const messages = document.getElementById('levi-chat-messages');

        let sessionId = localStorage.getItem('levi_session_id') || null;

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
            fetch(leviAgent.restUrl + 'chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': leviAgent.nonce,
                },
                body: JSON.stringify({
                    message: text,
                    session_id: sessionId,
                }),
            })
            .then(async response => {
                const text = await response.text();
                
                // Try to parse as JSON
                try {
                    const data = JSON.parse(text);
                    return data;
                } catch (e) {
                    // Not JSON - likely PHP error output
                    console.error('Server returned non-JSON:', text.substring(0, 500));
                    throw new Error('Server error: Invalid response format');
                }
            })
            .then(data => {
                // Remove typing indicator
                typing.remove();

                // Check for API errors
                if (data.error) {
                    addMessage('❌ ' + data.error, 'assistant');
                    return;
                }

                // Store session ID
                if (data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem('levi_session_id', sessionId);
                }

                // Add assistant response
                addMessage(data.message || 'Keine Antwort erhalten', 'assistant');
            })
            .catch(error => {
                typing.remove();
                addMessage('❌ Entschuldigung, es gab einen Fehler: ' + error.message, 'assistant');
                console.error('Error:', error);
            });
        }

        function addMessage(text, role) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'levi-message levi-message-' + role;
            messageDiv.innerHTML = '<div class="levi-message-content">' + escapeHtml(text) + '</div>';
            messages.appendChild(messageDiv);
            messages.scrollTop = messages.scrollHeight;
        }

        function addTypingIndicator() {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'levi-message levi-message-assistant';
            typingDiv.innerHTML = 
                '<div class="levi-message-content levi-typing">' +
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
        const testBtn = document.getElementById('levi-test-connection');
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                const result = document.getElementById('levi-test-result');
                result.textContent = ' Testing...';
                
                fetch(leviAgent.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=levi_test_connection&nonce=' + encodeURIComponent(leviAgent.adminNonce),
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
        const reloadBtn = document.getElementById('levi-reload-memories');
        if (reloadBtn) {
            reloadBtn.addEventListener('click', function() {
                const result = document.getElementById('levi-reload-result');
                result.textContent = ' Reloading...';
                reloadBtn.disabled = true;
                
                fetch(leviAgent.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=levi_reload_memories&nonce=' + encodeURIComponent(leviAgent.adminNonce),
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
