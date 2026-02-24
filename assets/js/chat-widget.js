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
    });
})();
