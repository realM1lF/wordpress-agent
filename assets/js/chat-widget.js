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
        const attachmentsBar = document.getElementById('levi-chat-attachments');
        const fileList = document.getElementById('levi-chat-file-list');

        const stop = document.getElementById('levi-chat-stop');

        const sessionKey = 'levi_session_id';
        const openKey = 'levi_chat_open';
        const fullWidthKey = 'levi_chat_fullwidth';
        let sessionId = localStorage.getItem(sessionKey) || null;
        let historyLoaded = false;
        let sendInFlight = false;
        let uploadInFlight = false;
        let uploadedFiles = [];
        let currentAbortController = null;
        let editingMessageEl = null;

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
        updateAttachmentsBar();

        // Send message on button click
        send.addEventListener('click', sendMessage);

        // Stop button aborts current request
        if (stop) {
            stop.addEventListener('click', function() {
                if (currentAbortController) {
                    currentAbortController.abort();
                    currentAbortController = null;
                }
            });
        }

        // Send message on Enter (Shift+Enter for new line)
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        function supportsReadableStream() {
            try {
                return typeof ReadableStream !== 'undefined'
                    && typeof TextDecoder !== 'undefined'
                    && leviAgent.streamUrl;
            } catch (e) {
                return false;
            }
        }

        function sendMessage() {
            if (sendInFlight) return;
            const text = input.value.trim();
            if (!text) return;

            const messageAttachments = uploadedFiles.length > 0 ? [...uploadedFiles] : null;

            // If editing, remove the old user message and its assistant reply from DOM
            const isEdit = editingMessageEl !== null;
            if (isEdit && editingMessageEl) {
                const nextSibling = editingMessageEl.nextElementSibling;
                if (nextSibling && nextSibling.classList.contains('levi-message-assistant')) {
                    nextSibling.remove();
                }
                editingMessageEl.remove();
                editingMessageEl = null;
            }

            currentAbortController = new AbortController();
            addMessage(text, 'user', messageAttachments);
            input.value = '';

            // Clear attachments from input area (consumed by this message)
            if (messageAttachments) {
                uploadedFiles = [];
                renderUploadedFiles();
            }

            const taskMode = inferTaskMode(text);
            const typing = addTypingIndicator(taskMode);
            const phaseTimers = scheduleTypingPhases(typing, taskMode);
            setSendingState(true);

            if (supportsReadableStream()) {
                sendMessageSSE(text, typing, phaseTimers, isEdit);
            } else {
                sendMessageClassic(text, typing, phaseTimers, isEdit);
            }
        }

        async function sendMessageSSE(text, typing, phaseTimers, isEdit) {
            try {
                const response = await fetch(leviAgent.streamUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': leviAgent.nonce,
                    },
                    body: JSON.stringify({
                        message: text,
                        session_id: sessionId,
                        replace_last: isEdit || false,
                    }),
                    signal: currentAbortController ? currentAbortController.signal : undefined,
                });

                if (!response.ok && !response.body) {
                    throw new Error('Server error: ' + response.status);
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let finalHandled = false;

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });

                    while (buffer.includes('\n\n')) {
                        const eventEnd = buffer.indexOf('\n\n');
                        const eventStr = buffer.substring(0, eventEnd);
                        buffer = buffer.substring(eventEnd + 2);

                        const lines = eventStr.split('\n');
                        for (const line of lines) {
                            if (!line.startsWith('data: ')) continue;
                            try {
                                const data = JSON.parse(line.substring(6));
                                finalHandled = handleSSEEvent(data, typing, phaseTimers) || finalHandled;
                            } catch (e) {
                                console.warn('SSE parse error:', e, line);
                            }
                        }
                    }
                }

                if (!finalHandled) {
                    clearPhaseTimers(phaseTimers);
                    typing.complete();
                    setSendingState(false);
                    addMessage('Ich konnte gerade keine Antwort generieren. Schreibe "mach weiter", damit ich es erneut versuche.', 'assistant');
                    console.warn('Levi SSE: stream ended without done/error event');
                }
            } catch (error) {
                clearPhaseTimers(phaseTimers);
                typing.remove();
                setSendingState(false);
                currentAbortController = null;
                if (error.name === 'AbortError') {
                    addMessage('⏹ Abgebrochen.', 'assistant');
                } else if (error instanceof TypeError && /input stream|network/i.test(error.message)) {
                    console.info('Levi: SSE connection lost (page reload/navigation). Backend continues working.');
                } else {
                    addMessage('❌ Entschuldigung, es gab einen Fehler: ' + error.message, 'assistant');
                    console.error('SSE Error:', error);
                }
            }
        }

        function handleSSEEvent(data, typing, phaseTimers) {
            if (!data || !data.type) return false;

            switch (data.type) {
                case 'status':
                case 'progress':
                    if (phaseTimers && typeof phaseTimers === 'object') {
                        phaseTimers.serverOverride = true;
                    }
                    if (data.message) {
                        typing.setLabel(data.message);
                    }
                    return false;

                case 'heartbeat':
                    return false;

                case 'done': {
                    clearPhaseTimers(phaseTimers);

                    if (data.session_id) {
                        sessionId = data.session_id;
                        localStorage.setItem(sessionKey, sessionId);
                    }

                    typing.scheduleComplete(function() {
                        typing.complete();
                        setSendingState(false);
                        var cleanedMessage = sanitizeAssistantMessage(data.message || 'Keine Antwort erhalten');
                        addMessage(cleanedMessage, 'assistant');
                        clearSessionFilesQuietly();
                    });
                    return true;
                }

                case 'error':
                    clearPhaseTimers(phaseTimers);
                    typing.complete();
                    setSendingState(false);

                    if (data.session_id) {
                        sessionId = data.session_id;
                        localStorage.setItem(sessionKey, sessionId);
                    }

                    addMessage('❌ ' + (data.message || 'Unbekannter Fehler'), 'assistant');
                    return true;

                default:
                    return false;
            }
        }

        function sendMessageClassic(text, typing, phaseTimers, isEdit) {
            fetch(leviAgent.restUrl + 'chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': leviAgent.nonce,
                },
                body: JSON.stringify({
                    message: text,
                    session_id: sessionId,
                    replace_last: isEdit || false,
                }),
                signal: currentAbortController ? currentAbortController.signal : undefined,
            })
            .then(async response => {
                const text = await response.text();
                try {
                    const data = JSON.parse(text);
                    data.__httpStatus = response.status;
                    return data;
                } catch (e) {
                    console.error('Server returned non-JSON:', text.substring(0, 500));
                    throw new Error('Server error: Invalid response format');
                }
            })
            .then(data => {
                clearPhaseTimers(phaseTimers);
                typing.complete();
                setSendingState(false);

                if (data.error) {
                    addMessage('❌ ' + formatApiError(data.error, data.__httpStatus), 'assistant');
                    return;
                }

                if (data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem(sessionKey, sessionId);
                }

                const cleanedMessage = sanitizeAssistantMessage(data.message || 'Keine Antwort erhalten');
                addMessage(cleanedMessage, 'assistant');
                clearSessionFilesQuietly();
            })
            .catch(error => {
                clearPhaseTimers(phaseTimers);
                typing.remove();
                setSendingState(false);
                currentAbortController = null;
                if (error.name === 'AbortError') {
                    addMessage('⏹ Abgebrochen.', 'assistant');
                } else {
                    addMessage('❌ Entschuldigung, es gab einen Fehler: ' + error.message, 'assistant');
                    console.error('Error:', error);
                }
            });
        }

        function setSendingState(isSending) {
            sendInFlight = !!isSending;
            send.disabled = !!isSending;
            input.disabled = !!isSending;
            if (uploadBtn) uploadBtn.disabled = !!isSending;
            if (isSending) {
                send.style.display = 'none';
                if (stop) stop.style.display = 'inline-flex';
            } else {
                send.style.display = '';
                if (stop) stop.style.display = 'none';
                send.style.opacity = '1';
                input.focus();
            }
        }

        function uploadSelectedFiles(fileListObj) {
            if (!fileListObj || fileListObj.length === 0 || uploadInFlight) {
                return;
            }

            const previews = {};
            for (const file of fileListObj) {
                if (/^image\//i.test(file.type)) {
                    previews[file.name] = URL.createObjectURL(file);
                }
            }

            uploadInFlight = true;
            uploadBtn.disabled = true;
            uploadBtn.classList.add('levi-uploading');

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
                uploadBtn.classList.remove('levi-uploading');
                if (fileInput) {
                    fileInput.value = '';
                }

                if (data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem(sessionKey, sessionId);
                }

                if (data.error) {
                    addMessage('❌ ' + formatApiError(data.error, data.__httpStatus), 'assistant');
                    return;
                }

                const uploaded = Array.isArray(data.files) ? data.files : [];
                uploaded.forEach(function(f) {
                    if (f && f.name && previews[f.name]) {
                        f.preview = previews[f.name];
                    }
                });
                const fullList = Array.isArray(data.session_files) ? data.session_files : null;
                if (fullList) {
                    fullList.forEach(function(f) {
                        if (f && f.name && previews[f.name]) {
                            f.preview = previews[f.name];
                        }
                    });
                }
                if (uploaded.length > 0) {
                    uploadedFiles = fullList || uploadedFiles.concat(uploaded).slice(-5);
                    renderUploadedFiles();
                }

                if (Array.isArray(data.errors) && data.errors.length > 0) {
                    addMessage('⚠️ ' + data.errors.join(' | '), 'assistant');
                }
            })
            .catch(error => {
                uploadInFlight = false;
                uploadBtn.disabled = false;
                uploadBtn.classList.remove('levi-uploading');
                if (fileInput) {
                    fileInput.value = '';
                }
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
                const fileType = (f && f.type) ? String(f.type) : '';
                const isImage = /^(jpg|jpeg|png|gif|webp)$/i.test(fileType);
                const preview = (f && f.preview) ? f.preview : '';
                let thumb;
                if (isImage && preview) {
                    thumb = '<img src="' + escapeHtml(preview) + '" class="levi-chat-file-chip-thumb" alt="">';
                } else {
                    const icon = isImage ? 'dashicons-format-image' : 'dashicons-media-text';
                    thumb = '<span class="dashicons ' + icon + '"></span>';
                }
                chip.innerHTML = thumb + '<span class="levi-chat-file-chip-name">' + name + '</span>'
                    + '<button type="button" class="levi-chat-file-chip-remove" data-file-id="' + fileId + '" title="Entfernen">×</button>';
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
            updateAttachmentsBar();
        }

        function updateAttachmentsBar() {
            if (!attachmentsBar) return;
            attachmentsBar.style.display = uploadedFiles.length > 0 ? 'flex' : 'none';
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

        function clearSessionFilesQuietly() {
            if (!sessionId) return;
            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(sessionId) + '/uploads', {
                method: 'DELETE',
                headers: { 'X-WP-Nonce': leviAgent.nonce },
            }).catch(function() {});
        }

        function clearSessionFiles() {
            if (!sessionId) {
                uploadedFiles = [];
                renderUploadedFiles();
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
                if (jobPollTimer) {
                    clearInterval(jobPollTimer);
                    jobPollTimer = null;
                }
                localStorage.removeItem(sessionKey);
                sessionId = null;
                historyLoaded = false;
                uploadedFiles = [];
                renderUploadedFiles();
                renderHistory([]);
            })
            .catch((error) => {
                addMessage('❌ Session konnte nicht gelöscht werden: ' + error.message, 'assistant');
            });
        }

        function addMessage(text, role, attachments) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'levi-message levi-message-' + role;

            let inner = '';
            if (role === 'user' && attachments && attachments.length > 0) {
                inner += '<div class="levi-message-attachments">';
                attachments.forEach(function(f) {
                    const name = escapeHtml((f && f.name) ? String(f.name) : 'Datei');
                    const fileType = (f && f.type) ? String(f.type) : '';
                    const isImage = /^(jpg|jpeg|png|gif|webp)$/i.test(fileType);
                    const preview = (f && f.preview) ? escapeHtml(f.preview) : '';
                    let thumb;
                    if (isImage && preview) {
                        thumb = '<img src="' + preview + '" class="levi-msg-file-thumb" alt="">';
                    } else {
                        const icon = isImage ? 'dashicons-format-image' : 'dashicons-media-text';
                        thumb = '<span class="dashicons ' + icon + '"></span>';
                    }
                    inner += '<span class="levi-msg-file">' + thumb + name + '</span>';
                });
                inner += '</div>';
            }
            inner += renderMessageContent(text, role);

            let html = '<div class="levi-message-content">' + inner + '</div>';

            if (role === 'user') {
                html += '<button type="button" class="levi-message-edit-btn dashicons dashicons-edit" title="Nachricht bearbeiten" aria-label="Nachricht bearbeiten"></button>';
            }

            messageDiv.innerHTML = html;

            if (role === 'user') {
                const editBtn = messageDiv.querySelector('.levi-message-edit-btn');
                if (editBtn) {
                    editBtn.addEventListener('click', function() {
                        if (sendInFlight) return;
                        input.value = text;
                        input.focus();
                        editingMessageEl = messageDiv;
                    });
                }
            }

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
                checkActiveJob(currentSessionId);
            })
            .catch(() => {
                // Keep default greeting if history could not be loaded.
            });
        }

        let jobPollTimer = null;

        function checkActiveJob(currentSessionId) {
            if (!currentSessionId) return;

            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(currentSessionId) + '/job-status', {
                method: 'GET',
                headers: { 'X-WP-Nonce': leviAgent.nonce },
            })
            .then(async response => {
                const text = await response.text();
                return JSON.parse(text);
            })
            .then(data => {
                if (!data || !data.active) return;
                const typing = addTypingIndicator('write');
                typing.setLabel(data.message || 'Levi arbeitet...');
                setSendingState(true);
                startJobPolling(currentSessionId, typing);
            })
            .catch(() => {});
        }

        function startJobPolling(currentSessionId, typing) {
            if (jobPollTimer) {
                clearInterval(jobPollTimer);
            }
            jobPollTimer = setInterval(() => {
                fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(currentSessionId) + '/job-status', {
                    method: 'GET',
                    headers: { 'X-WP-Nonce': leviAgent.nonce },
                })
                .then(async response => {
                    const text = await response.text();
                    return JSON.parse(text);
                })
                .then(data => {
                    if (!data || !data.active) {
                        clearInterval(jobPollTimer);
                        jobPollTimer = null;
                        typing.complete();
                        setSendingState(false);
                        reloadChatHistory(currentSessionId);
                        return;
                    }
                    if (data.message) {
                        typing.setLabel(data.message);
                    }
                })
                .catch(() => {
                    clearInterval(jobPollTimer);
                    jobPollTimer = null;
                    typing.complete();
                    setSendingState(false);
                });
            }, 2000);
        }

        function reloadChatHistory(currentSessionId) {
            if (!currentSessionId) return;
            fetch(leviAgent.restUrl + 'chat/' + encodeURIComponent(currentSessionId) + '/history', {
                method: 'GET',
                headers: { 'X-WP-Nonce': leviAgent.nonce },
            })
            .then(async response => {
                const text = await response.text();
                return JSON.parse(text);
            })
            .then(data => {
                renderHistory(data.messages || []);
            })
            .catch(() => {});
        }

        function addTypingIndicator(taskMode) {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'levi-message levi-message-assistant';
            typingDiv.innerHTML =
                '<div class="levi-message-content levi-typing">' +
                '<div class="levi-typing-row">' +
                '<span></span><span></span><span></span>' +
                '<small class="levi-typing-label"></small>' +
                '</div>' +
                '<div class="levi-chat-progress-track levi-chat-progress-indeterminate"><div class="levi-chat-progress-shimmer"></div></div>' +
                '</div>';
            messages.appendChild(typingDiv);
            messages.scrollTop = messages.scrollHeight;

            const labelEl = typingDiv.querySelector('.levi-typing-label');
            const MIN_DISPLAY_MS = 600;
            let labelSetAt = Date.now();
            let queue = [];
            let drainTimer = null;
            let doneCallback = null;

            function showLabel(label) {
                if (labelEl) labelEl.textContent = label;
                labelSetAt = Date.now();
            }

            function drainQueue() {
                drainTimer = null;
                if (queue.length > 0) {
                    showLabel(queue.shift());
                    drainTimer = setTimeout(drainQueue, MIN_DISPLAY_MS);
                    return;
                }
                if (doneCallback) {
                    var fn = doneCallback;
                    doneCallback = null;
                    var elapsed = Date.now() - labelSetAt;
                    var wait = Math.max(0, MIN_DISPLAY_MS - elapsed);
                    if (wait > 0) {
                        drainTimer = setTimeout(fn, wait);
                    } else {
                        fn();
                    }
                }
            }

            function queueLabel(label) {
                var elapsed = Date.now() - labelSetAt;
                if (!drainTimer && elapsed >= MIN_DISPLAY_MS) {
                    showLabel(label);
                } else {
                    queue.push(label);
                    if (!drainTimer) {
                        var wait = Math.max(0, MIN_DISPLAY_MS - elapsed);
                        drainTimer = setTimeout(drainQueue, wait);
                    }
                }
            }

            showLabel(getTypingLabel(taskMode, 'start'));

            return {
                setLabel: queueLabel,
                scheduleComplete: function(callback) {
                    if (queue.length === 0 && !drainTimer) {
                        var elapsed = Date.now() - labelSetAt;
                        var wait = Math.max(0, MIN_DISPLAY_MS - elapsed);
                        if (wait > 0) {
                            setTimeout(callback, wait);
                        } else {
                            callback();
                        }
                    } else {
                        doneCallback = callback;
                    }
                },
                complete: function() {
                    if (drainTimer) clearTimeout(drainTimer);
                    queue = [];
                    doneCallback = null;
                    typingDiv.classList.add('levi-typing-complete');
                    setTimeout(function() { typingDiv.remove(); }, 200);
                },
                remove: function() {
                    if (drainTimer) clearTimeout(drainTimer);
                    queue = [];
                    doneCallback = null;
                    typingDiv.remove();
                },
            };
        }

        function inferTaskMode(text) {
            const t = String(text || '').toLowerCase();
            if (/\b(code|plugin|php|javascript|js|css|datei|funktion|implement|bugfix|refactor)\b/.test(t)) {
                return 'code';
            }
            if (/\b(rechtschreibung|analyse|prüf|review|durchsuch|korrigier)\b/.test(t)) {
                return 'analysis';
            }
            return 'write';
        }

        function getTypingLabel(taskMode, phase) {
            if (taskMode === 'code') {
                return phase === 'final' ? 'Levi finalisiert Code...' : 'Levi codet...';
            }
            if (taskMode === 'analysis') {
                return phase === 'final' ? 'Levi fasst Ergebnisse zusammen...' : 'Levi analysiert...';
            }
            return phase === 'final' ? 'Levi finalisiert Antwort...' : 'Levi schreibt...';
        }

        function scheduleTypingPhases(typing, taskMode) {
            const timers = {
                ids: [],
                serverOverride: false,
            };
            timers.ids = [
                setTimeout(() => {
                    if (!timers.serverOverride) typing.setLabel('Levi arbeitet...');
                }, 3000),
                setTimeout(() => {
                    if (!timers.serverOverride) typing.setLabel(getTypingLabel(taskMode, 'final'));
                }, 8000),
            ];
            return timers;
        }

        function clearPhaseTimers(timerObj) {
            if (!timerObj) return;
            const ids = Array.isArray(timerObj) ? timerObj : (timerObj.ids || []);
            ids.forEach((id) => clearTimeout(id));
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

        function sanitizeAssistantMessage(message) {
            let cleaned = String(message || '');
            // Remove leaked tool protocol tokens from provider responses.
            cleaned = cleaned.replace(/<\|tool_calls_section_begin\|>[\s\S]*$/gi, '');
            cleaned = cleaned.replace(/<\|[^|>]+?\|>/g, '');
            cleaned = cleaned.replace(/(?:^|\n)\s*functions\.[a-z0-9_]+\s*:\s*\d+[\s\S]*$/i, '');
            cleaned = cleaned.replace(/\n{3,}/g, '\n\n').trim();
            return cleaned || 'Ich konnte gerade keine Antwort generieren. Schreibe "mach weiter", damit ich es erneut versuche.';
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

        // Manual WordPress/plugin indexing (state snapshot) with progress indicator
        const runSnapshotBtn = document.getElementById('levi-run-state-snapshot');
        if (runSnapshotBtn) {
            runSnapshotBtn.addEventListener('click', function() {
                const result = document.getElementById('levi-state-snapshot-result');
                const progressWrap = document.getElementById('levi-state-snapshot-progress-wrap');
                const progressBar = document.getElementById('levi-state-snapshot-progress');
                if (!result || !progressWrap || !progressBar) {
                    return;
                }

                runSnapshotBtn.disabled = true;
                result.textContent = ' Indexierung läuft...';
                progressWrap.style.display = 'block';
                progressBar.style.width = '6%';

                let progress = 6;
                const timer = setInterval(() => {
                    progress = Math.min(92, progress + 7);
                    progressBar.style.width = progress + '%';
                }, 450);

                fetch(leviAgent.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=levi_run_state_snapshot&nonce=' + encodeURIComponent(leviAgent.adminNonce),
                })
                .then(r => r.json())
                .then(data => {
                    clearInterval(timer);
                    runSnapshotBtn.disabled = false;
                    progressBar.style.width = '100%';

                    if (data.success) {
                        const meta = data?.data?.meta || {};
                        const capturedAt = meta.captured_at || '-';
                        const status = meta.status || 'unknown';
                        result.innerHTML = ' <span style="color: green;">✅ ' + (data.data.message || 'Done') + ' (' + capturedAt + ', ' + status + ')</span>';
                        setTimeout(() => { location.reload(); }, 1200);
                    } else {
                        result.innerHTML = ' <span style="color: red;">❌ ' + (data.data || 'Failed') + '</span>';
                    }
                })
                .catch(err => {
                    clearInterval(timer);
                    runSnapshotBtn.disabled = false;
                    progressBar.style.width = '100%';
                    result.innerHTML = ' <span style="color: red;">❌ Error: ' + err.message + '</span>';
                });
            });
        }
    });
})();
