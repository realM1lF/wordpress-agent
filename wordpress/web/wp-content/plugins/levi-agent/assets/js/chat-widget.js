/**
 * Levi Chat Widget JavaScript
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('levi-chat-toggle');
        const window_ = document.getElementById('levi-chat-window');
        const close = document.getElementById('levi-chat-close');
        const clear = document.getElementById('levi-chat-clear');
        const input = document.getElementById('levi-chat-input');
        const send = document.getElementById('levi-chat-send');
        const messages = document.getElementById('levi-chat-messages');

        const sessionKey = 'levi_session_id';
        const openKey = 'levi_chat_open';
        let sessionId = localStorage.getItem(sessionKey) || null;
        let historyLoaded = false;

        function setChatOpen(isOpen) {
            window_.style.display = isOpen ? 'flex' : 'none';
            localStorage.setItem(openKey, isOpen ? '1' : '0');
            if (isOpen) {
                input.focus();
            }
        }

        // Restore open/closed state after navigation
        if (localStorage.getItem(openKey) === '1') {
            setChatOpen(true);
        }

        // Restore server-side history if we already have a session
        if (sessionId) {
            loadHistory(sessionId);
        }

        // Toggle chat window
        toggle.addEventListener('click', function() {
            const isVisible = window_.style.display !== 'none';
            setChatOpen(!isVisible);
        });

        // Close chat window
        close.addEventListener('click', function() {
            setChatOpen(false);
        });

        // Clear current session
        if (clear) {
            clear.addEventListener('click', clearCurrentSession);
        }

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

                if (Array.isArray(data.execution_trace) && data.execution_trace.length > 0) {
                    addMessage(formatExecutionTrace(data.execution_trace), 'assistant');
                }

                // Store session ID
                if (data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem(sessionKey, sessionId);
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

        function clearCurrentSession() {
            if (!sessionId) {
                historyLoaded = false;
                renderHistory([]);
                return;
            }

            if (!window.confirm('Aktuelle Chat-Session wirklich löschen?')) {
                return;
            }

            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(sessionId), {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': leviAgent.nonce,
                },
            })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Could not parse delete response');
                }
            })
            .then(data => {
                if (data && data.error) {
                    throw new Error(data.error);
                }
                localStorage.removeItem(sessionKey);
                sessionId = null;
                historyLoaded = false;
                renderHistory([]);
            })
            .catch((error) => {
                addMessage('❌ Session konnte nicht gelöscht werden: ' + error.message, 'assistant');
            });
        }

        function addMessage(text, role) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'levi-message levi-message-' + role;
            messageDiv.innerHTML = '<div class="levi-message-content">' + renderMessageContent(text, role) + '</div>';
            messages.appendChild(messageDiv);
            messages.scrollTop = messages.scrollHeight;
        }

        function renderHistory(historyMessages) {
            messages.innerHTML = '';
            if (!Array.isArray(historyMessages) || historyMessages.length === 0) {
                addMessage('Hallo ' + (leviAgent.userName || '') + '! 👋\nIch bin dein WordPress KI-Assistent. Wie kann ich dir helfen?', 'assistant');
                return;
            }

            historyMessages.forEach(msg => {
                if (!msg || !msg.role || !msg.content) {
                    return;
                }
                if (msg.role === 'user' || msg.role === 'assistant') {
                    addMessage(msg.content, msg.role);
                }
            });
        }

        function loadHistory(currentSessionId) {
            if (!currentSessionId || historyLoaded) {
                return;
            }

            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(currentSessionId) + '/history', {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': leviAgent.nonce,
                },
            })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Could not parse history response');
                }
            })
            .then(data => {
                historyLoaded = true;
                renderHistory(data.messages || []);
            })
            .catch(() => {
                // Keep default greeting if history could not be loaded.
            });
        }

        function addTypingIndicator() {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'levi-message levi-message-assistant';
            typingDiv.innerHTML = 
                '<div class="levi-message-content levi-typing">' +
                '<span></span><span></span><span></span>' +
                '<small style="margin-left:8px;color:#6c7781;">Levi arbeitet an Teilaufgaben...</small>' +
                '</div>';
            messages.appendChild(typingDiv);
            messages.scrollTop = messages.scrollHeight;
            return typingDiv;
        }

        function formatExecutionTrace(trace) {
            const relevant = trace.filter(t => t && (t.status === 'completed' || t.status === 'failed')).slice(-6);
            const summaryParts = relevant.map((t, idx) => {
                const icon = t.status === 'completed' ? '✓' : '✗';
                const label = t.status === 'completed' ? 'fertig' : 'Problem';
                return icon + ' Schritt ' + (idx + 1) + ' (' + (t.tool || 'tool') + '): ' + (t.summary || label);
            });

            if (relevant.length === 0) {
                return '🔧 Keine auswertbaren Teilaufgaben im Trace gefunden.';
            }

            return '🔧 Zwischenstand:\n' + summaryParts.join('\n');
        }

        function renderMessageContent(text, role) {
            if (typeof text !== 'string') {
                return '';
            }

            if (role === 'assistant') {
                return renderAssistantMarkdown(text);
            }

            return escapeHtml(text).replace(/\n/g, '<br>');
        }

        function renderAssistantMarkdown(markdown) {
            const source = String(markdown || '');

            if (!window.marked || typeof window.marked.parse !== 'function') {
                return fallbackPlainText(source);
            }

            window.marked.setOptions({
                gfm: true,
                breaks: true,
                headerIds: false,
                mangle: false,
            });

            const rawHtml = window.marked.parse(source);
            if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
                return window.DOMPurify.sanitize(rawHtml, {
                    USE_PROFILES: { html: true },
                });
            }

            return fallbackPlainText(source);
        }

        function fallbackPlainText(text) {
            return '<p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p>';
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

        // Repair database button (on settings page)
        const repairBtn = document.getElementById('levi-repair-database');
        if (repairBtn) {
            repairBtn.addEventListener('click', function() {
                const result = document.getElementById('levi-repair-result');
                result.textContent = ' Repairing...';
                repairBtn.disabled = true;

                fetch(leviAgent.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=levi_repair_database&nonce=' + encodeURIComponent(leviAgent.adminNonce),
                })
                .then(r => r.json())
                .then(data => {
                    repairBtn.disabled = false;
                    if (data.success) {
                        result.innerHTML = ' <span style="color: green;">✅ ' + (data.data.message || 'Done') + '</span>';
                        setTimeout(() => { result.textContent = ''; }, 3000);
                    } else {
                        result.innerHTML = ' <span style="color: red;">❌ ' + (data.data || 'Failed') + '</span>';
                    }
                })
                .catch(err => {
                    repairBtn.disabled = false;
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
