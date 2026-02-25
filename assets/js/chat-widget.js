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
        const expand = document.getElementById('levi-chat-expand');
        const input = document.getElementById('levi-chat-input');
        const send = document.getElementById('levi-chat-send');
        const messages = document.getElementById('levi-chat-messages');
        const uploadBtn = document.getElementById('levi-chat-upload-btn');
        const clearFilesBtn = document.getElementById('levi-chat-clear-files-btn');
        const fileInput = document.getElementById('levi-chat-file-input');
        const uploadStatus = document.getElementById('levi-chat-upload-status');
        const contextHint = document.getElementById('levi-chat-context-hint');
        const fileList = document.getElementById('levi-chat-file-list');

        const sessionKey = 'levi_session_id';
        const openKey = 'levi_chat_open';
        const fullWidthKey = 'levi_chat_fullwidth';
        let sessionId = localStorage.getItem(sessionKey) || null;
        let historyLoaded = false;
        let sendInFlight = false;
        let uploadInFlight = false;
        let uploadedFiles = [];

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

        function setFullWidth(enabled) {
            window_.classList.toggle('levi-chat-window-fullwidth', !!enabled);
            localStorage.setItem(fullWidthKey, enabled ? '1' : '0');
            const icon = expand ? expand.querySelector('.dashicons') : null;
            if (icon) {
                icon.className = enabled
                    ? 'dashicons dashicons-editor-contract'
                    : 'dashicons dashicons-editor-expand';
            }
            if (expand) {
                expand.title = enabled ? 'Standardgröße' : 'Full Width';
            }
        }

        if (localStorage.getItem(fullWidthKey) === '1') {
            setFullWidth(true);
        }

        // Restore server-side history if we already have a session
        if (sessionId) {
            loadHistory(sessionId);
            loadSessionUploads(sessionId);
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

        if (expand) {
            expand.addEventListener('click', function() {
                const isEnabled = window_.classList.contains('levi-chat-window-fullwidth');
                setFullWidth(!isEnabled);
            });
        }

        // Clear current session
        if (clear) {
            clear.addEventListener('click', clearCurrentSession);
        }

        if (uploadBtn && fileInput) {
            uploadBtn.addEventListener('click', function() {
                if (!uploadInFlight) {
                    fileInput.click();
                }
            });
            fileInput.addEventListener('change', function() {
                uploadSelectedFiles(fileInput.files);
            });
        }
        if (clearFilesBtn) {
            clearFilesBtn.addEventListener('click', clearSessionFiles);
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
            if (sendInFlight) return;
            const text = input.value.trim();
            if (!text) return;

            // Add user message
            addMessage(text, 'user');
            input.value = '';

            // Show typing indicator
            const typing = addTypingIndicator();
            setSendingState(true);

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
                    data.__httpStatus = response.status;
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
                setSendingState(false);

                // Check for API errors
                if (data.error) {
                    addMessage('❌ ' + formatApiError(data.error, data.__httpStatus), 'assistant');
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
                setSendingState(false);
                addMessage('❌ Entschuldigung, es gab einen Fehler: ' + error.message, 'assistant');
                console.error('Error:', error);
            });
        }

        function setSendingState(isSending) {
            sendInFlight = !!isSending;
            send.disabled = !!isSending;
            input.disabled = !!isSending;
            if (isSending) {
                send.style.opacity = '0.7';
            } else {
                send.style.opacity = '1';
                input.focus();
            }
        }

        function uploadSelectedFiles(fileListObj) {
            if (!fileListObj || fileListObj.length === 0 || uploadInFlight) {
                return;
            }

            uploadInFlight = true;
            uploadBtn.disabled = true;
            if (uploadStatus) {
                uploadStatus.textContent = 'Upload läuft...';
            }

            const formData = new FormData();
            for (const file of fileListObj) {
                formData.append('files[]', file);
            }
            if (sessionId) {
                formData.append('session_id', sessionId);
            }

            fetch(leviAgent.restUrl + 'chat/upload', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': leviAgent.nonce,
                },
                body: formData,
            })
            .then(async response => {
                const text = await response.text();
                try {
                    const data = JSON.parse(text);
                    data.__httpStatus = response.status;
                    return data;
                } catch (e) {
                    throw new Error('Upload response konnte nicht gelesen werden');
                }
            })
            .then(data => {
                uploadInFlight = false;
                uploadBtn.disabled = false;
                if (fileInput) {
                    fileInput.value = '';
                }

                if (data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem(sessionKey, sessionId);
                }

                if (data.error) {
                    if (uploadStatus) uploadStatus.textContent = 'Upload fehlgeschlagen';
                    addMessage('❌ ' + formatApiError(data.error, data.__httpStatus), 'assistant');
                    return;
                }

                const uploaded = Array.isArray(data.files) ? data.files : [];
                const fullList = Array.isArray(data.session_files) ? data.session_files : null;
                if (uploaded.length > 0) {
                    uploadedFiles = fullList || uploadedFiles.concat(uploaded).slice(-5);
                    renderUploadedFiles();
                    if (uploadStatus) uploadStatus.textContent = uploaded.length + ' Datei(en) hochgeladen';
                    addMessage('📎 Datei(en) hochgeladen: ' + uploaded.map(f => f.name).join(', '), 'assistant');
                } else {
                    if (uploadStatus) uploadStatus.textContent = 'Keine Datei übernommen';
                }

                if (Array.isArray(data.errors) && data.errors.length > 0) {
                    addMessage('⚠️ Upload-Hinweise: ' + data.errors.join(' | '), 'assistant');
                }
            })
            .catch(error => {
                uploadInFlight = false;
                uploadBtn.disabled = false;
                if (fileInput) {
                    fileInput.value = '';
                }
                if (uploadStatus) uploadStatus.textContent = 'Upload fehlgeschlagen';
                addMessage('❌ Upload-Fehler: ' + error.message, 'assistant');
            });
        }

        function renderUploadedFiles() {
            if (!fileList) return;
            fileList.innerHTML = '';
            uploadedFiles.forEach((f) => {
                const chip = document.createElement('span');
                chip.className = 'levi-chat-file-chip';
                const name = escapeHtml((f && f.name) ? String(f.name) : 'Datei');
                const fileId = escapeHtml((f && f.id) ? String(f.id) : '');
                chip.innerHTML = '<span class="dashicons dashicons-media-text"></span><span>' + name + '</span>'
                    + '<button type="button" class="levi-chat-file-chip-remove" data-file-id="' + fileId + '" title="Datei entfernen">×</button>';
                fileList.appendChild(chip);
            });
            fileList.querySelectorAll('.levi-chat-file-chip-remove').forEach((btn) => {
                btn.addEventListener('click', function() {
                    const fileId = btn.getAttribute('data-file-id');
                    if (fileId) {
                        removeSessionFile(fileId);
                    }
                });
            });
            updateContextHint();
        }

        function updateContextHint() {
            if (!contextHint) return;
            const count = uploadedFiles.length;
            if (count === 0) {
                contextHint.textContent = '';
                return;
            }
            contextHint.textContent = count + ' Datei(en) sind im aktuellen Chat-Kontext aktiv.';
        }

        function loadSessionUploads(currentSessionId) {
            if (!currentSessionId) return;
            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(currentSessionId) + '/uploads', {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': leviAgent.nonce,
                },
            })
            .then(async (response) => {
                const text = await response.text();
                const data = JSON.parse(text);
                data.__httpStatus = response.status;
                return data;
            })
            .then((data) => {
                if (data.error) {
                    return;
                }
                uploadedFiles = Array.isArray(data.files) ? data.files : [];
                renderUploadedFiles();
            })
            .catch(() => {
                // ignore silently, chat works without upload context preload
            });
        }

        function clearSessionFiles() {
            if (!sessionId) {
                uploadedFiles = [];
                renderUploadedFiles();
                if (uploadStatus) uploadStatus.textContent = '';
                return;
            }
            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(sessionId) + '/uploads', {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': leviAgent.nonce,
                },
            })
            .then(async (response) => {
                const text = await response.text();
                const data = JSON.parse(text);
                data.__httpStatus = response.status;
                return data;
            })
            .then((data) => {
                if (data.error) {
                    addMessage('❌ ' + formatApiError(data.error, data.__httpStatus), 'assistant');
                    return;
                }
                uploadedFiles = [];
                renderUploadedFiles();
                if (uploadStatus) uploadStatus.textContent = 'Uploads entfernt';
            })
            .catch((error) => {
                addMessage('❌ Uploads konnten nicht gelöscht werden: ' + error.message, 'assistant');
            });
        }

        function removeSessionFile(fileId) {
            if (!sessionId || !fileId) return;
            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(sessionId) + '/uploads/' + encodeURIComponent(fileId), {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': leviAgent.nonce,
                },
            })
            .then(async (response) => {
                const text = await response.text();
                const data = JSON.parse(text);
                data.__httpStatus = response.status;
                return data;
            })
            .then((data) => {
                if (data.error) {
                    addMessage('❌ ' + formatApiError(data.error, data.__httpStatus), 'assistant');
                    return;
                }
                uploadedFiles = Array.isArray(data.files) ? data.files : [];
                renderUploadedFiles();
                if (uploadStatus) uploadStatus.textContent = 'Datei entfernt';
            })
            .catch((error) => {
                addMessage('❌ Datei konnte nicht entfernt werden: ' + error.message, 'assistant');
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
                    const data = JSON.parse(text);
                    data.__httpStatus = response.status;
                    return data;
                } catch (e) {
                    throw new Error('Could not parse delete response');
                }
            })
            .then(data => {
                if (data && data.error) {
                    throw new Error(formatApiError(data.error, data.__httpStatus));
                }
                localStorage.removeItem(sessionKey);
                sessionId = null;
                historyLoaded = false;
                uploadedFiles = [];
                renderUploadedFiles();
                if (uploadStatus) {
                    uploadStatus.textContent = '';
                }
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

        function formatApiError(message, httpStatus) {
            const msg = String(message || 'Unbekannter Fehler');
            if (httpStatus === 401 || httpStatus === 403) {
                return 'Berechtigung/Nonce ungültig. Seite neu laden und erneut versuchen. (' + msg + ')';
            }
            if (httpStatus === 429) {
                return 'Rate-Limit erreicht. Bitte kurz warten und erneut versuchen.';
            }
            if (httpStatus === 503) {
                return 'Provider/Modell aktuell nicht verfügbar. Bitte anderes Modell wählen oder später erneut versuchen. (' + msg + ')';
            }
            if (httpStatus >= 500) {
                return 'Serverfehler. Bitte später erneut versuchen. (' + msg + ')';
            }
            return msg;
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
