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
        const userName = typeof leviAgent.userName === 'string' ? leviAgent.userName : '';
        const userInitial = typeof leviAgent.userInitial === 'string' && leviAgent.userInitial
            ? leviAgent.userInitial
            : getInitial(userName, 'U');
        const userAvatarUrl = typeof leviAgent.userAvatarUrl === 'string' ? leviAgent.userAvatarUrl : '';
        const leviAvatarUrl = typeof leviAgent.leviAvatarUrl === 'string' ? leviAgent.leviAvatarUrl : '';

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
        let webSearchActive = false;
        const webSearchBtn = document.getElementById('levi-chat-web-search-btn');
        let titleInterval = null;
        let titleOriginal = null;
        const widget = document.getElementById('levi-chat-widget');

        function parseRgb(str) {
            if (!str || str === 'transparent' || str === 'rgba(0, 0, 0, 0)') return null;
            const m = str.match(/rgba?\(\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/);
            if (!m) return null;
            const a = m[4] !== undefined ? parseFloat(m[4]) : 1;
            if (a < 0.1) return null;
            return { r: parseFloat(m[1]) / 255, g: parseFloat(m[2]) / 255, b: parseFloat(m[3]) / 255 };
        }

        function srgbLuminance(c) {
            const lin = v => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            return 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
        }

        function detectBackgroundBrightness() {
            if (!widget || !window_) return;
            const prevVis = window_.style.visibility;
            const prevToggleVis = toggle.style.visibility;
            window_.style.visibility = 'hidden';
            toggle.style.visibility = 'hidden';

            const rect = window_.getBoundingClientRect();
            const cols = 5, rows = 7;
            let darkCount = 0, total = 0;

            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) {
                    const x = rect.left + (rect.width * (c + 0.5)) / cols;
                    const y = rect.top + (rect.height * (r + 0.5)) / rows;
                    const el = document.elementFromPoint(x, y);
                    if (!el) { total++; continue; }

                    let rgb = null;
                    let node = el;
                    while (node && node !== document.documentElement) {
                        rgb = parseRgb(getComputedStyle(node).backgroundColor);
                        if (rgb) break;
                        node = node.parentElement;
                    }

                    total++;
                    if (rgb && srgbLuminance(rgb) < 0.4) darkCount++;
                }
            }

            window_.style.visibility = prevVis || '';
            toggle.style.visibility = prevToggleVis || '';

            const darkRatio = total > 0 ? darkCount / total : 0;
            const isDark = darkRatio >= 0.85;
            widget.classList.toggle('levi-chat-light', !isDark);
        }

        let resizeTimer = null;
        window.addEventListener('resize', function() {
            if (window_.style.display === 'none') return;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(detectBackgroundBrightness, 500);
        });

        function startTitleWorking() {
            if (titleInterval) return;
            titleOriginal = document.title;
            var dotState = 0;
            var dots = ['', '.', '..', '...'];
            titleInterval = setInterval(function() {
                dotState = (dotState + 1) % dots.length;
                document.title = 'Levi arbeitet' + dots[dotState];
            }, 600);
        }

        function stopTitleWorking() {
            if (!titleInterval) return;
            clearInterval(titleInterval);
            titleInterval = null;
            if (titleOriginal) document.title = titleOriginal;
            titleOriginal = null;
        }

        function notifyIfHidden(label) {
            stopTitleWorking();
            if (!document.hidden) return;
            titleOriginal = titleOriginal || document.title;
            var orig = titleOriginal;
            titleInterval = setInterval(function() {
                document.title = document.title === orig ? label : orig;
            }, 1000);
            document.addEventListener('visibilitychange', function onVisible() {
                if (document.hidden) return;
                clearInterval(titleInterval);
                titleInterval = null;
                document.title = orig;
                titleOriginal = null;
                document.removeEventListener('visibilitychange', onVisible);
            });
        }

        function setChatOpen(isOpen) {
            window_.style.display = isOpen ? 'flex' : 'none';
            localStorage.setItem(openKey, isOpen ? '1' : '0');
            if (isOpen) {
                requestAnimationFrame(detectBackgroundBrightness);
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
            if (window_.style.display !== 'none') {
                requestAnimationFrame(detectBackgroundBrightness);
            }
        }

        if (localStorage.getItem(fullWidthKey) === '1') {
            setFullWidth(true);
        }

        // Restore server-side history if we already have a session; otherwise show full greeting
        if (sessionId) {
            loadHistory(sessionId);
            loadSessionUploads(sessionId);
        } else {
            renderHistory([]);
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

        if (webSearchBtn) {
            webSearchBtn.addEventListener('click', function() {
                webSearchActive = !webSearchActive;
                webSearchBtn.classList.toggle('levi-web-search-active', webSearchActive);
            });
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

        // Auto-resize textarea as content grows (up to max-height)
        function autoResizeInput() {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 150) + 'px';
        }
        input.addEventListener('input', autoResizeInput);

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
            input.style.height = 'auto';

            // Clear attachments from input area (consumed by this message)
            if (messageAttachments) {
                uploadedFiles = [];
                renderUploadedFiles();
            }

            const typing = addTypingIndicator();
            const phaseTimers = scheduleTypingPhases();
            setSendingState(true);

            if (supportsReadableStream()) {
                sendMessageSSE(text, typing, phaseTimers, isEdit);
            } else {
                sendMessageClassic(text, typing, phaseTimers, isEdit);
            }
        }

        async function sendMessageSSE(text, typing, phaseTimers, isEdit) {
            var useWebSearch = webSearchActive;
            webSearchActive = false;
            if (webSearchBtn) webSearchBtn.classList.remove('levi-web-search-active');
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
                        web_search: useWebSearch,
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
                    addMessage('Ich bin leider nicht ganz fertig geworden. Schreib einfach „mach weiter" und ich mach mich wieder an die Aufgabe.', 'assistant');
                    console.warn('Levi SSE: stream ended without done/error event');
                }
            } catch (error) {
                clearPhaseTimers(phaseTimers);
                typing.remove();
                setSendingState(false);
                currentAbortController = null;
                if (error.name === 'AbortError') {
                    addMessage('⏹ Abgebrochen.', 'assistant');
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
                    if (data.message) {
                        typing.setLabel(data.message);
                    }
                    return false;

                case 'progress':
                    if (data.message) {
                        typing.setLabel(data.message);
                    }
                    return false;

                case 'delta':
                    if (data.content) {
                        typing.appendDelta(data.content);
                    }
                    return false;

                case 'stream_start':
                    typing.setLabel('Levi antwortet...');
                    return false;

                case 'stream_end':
                    typing.clearStream();
                    return false;

                case 'heartbeat':
                    return false;

                case 'done':
                    clearPhaseTimers(phaseTimers);
                    typing.complete();
                    setSendingState(false);
                    notifyIfHidden('✅ Levi ist fertig!');

                    if (data.session_id) {
                        sessionId = data.session_id;
                        localStorage.setItem(sessionKey, sessionId);
                    }

                    const cleanedMessage = sanitizeAssistantMessage(data.message || 'Keine Antwort erhalten');
                    addMessage(cleanedMessage, 'assistant', undefined, undefined, data.usage);
                    if (data.pending_confirmation) {
                        appendConfirmationCard(data.pending_confirmation);
                    }
                    clearSessionFilesQuietly();
                    return true;

                case 'error':
                    clearPhaseTimers(phaseTimers);
                    typing.complete();
                    setSendingState(false);
                    notifyIfHidden('⚠ Levi braucht Hilfe');

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
            var useWebSearch = webSearchActive;
            webSearchActive = false;
            if (webSearchBtn) webSearchBtn.classList.remove('levi-web-search-active');
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
                    web_search: useWebSearch,
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
                notifyIfHidden(data.error ? '⚠ Levi braucht Hilfe' : '✅ Levi ist fertig!');

                if (data.error) {
                    addMessage('❌ ' + formatApiError(data.error, data.__httpStatus), 'assistant');
                    return;
                }

                if (data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem(sessionKey, sessionId);
                }

                const cleanedMessage = sanitizeAssistantMessage(data.message || 'Keine Antwort erhalten');
                addMessage(cleanedMessage, 'assistant', undefined, undefined, data.usage);
                if (data.pending_confirmation) {
                    appendConfirmationCard(data.pending_confirmation);
                }
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
            if (webSearchBtn) webSearchBtn.disabled = !!isSending;
            if (isSending) {
                send.style.display = 'none';
                if (stop) stop.style.display = 'inline-flex';
                if (document.hidden) startTitleWorking();
            } else {
                send.style.display = '';
                if (stop) stop.style.display = 'none';
                send.style.opacity = '1';
                input.focus();
                stopTitleWorking();
            }
        }

        document.addEventListener('visibilitychange', function() {
            if (document.hidden && sendInFlight) startTitleWorking();
            if (!document.hidden && sendInFlight) stopTitleWorking();
        });

        // Warn when leaving/reloading while Levi is processing
        window.addEventListener('beforeunload', function(e) {
            if (sendInFlight || uploadInFlight) {
                e.preventDefault();
                var msg = 'Levi arbeitet gerade an einer Aufgabe. Ein Seitenwechsel oder Neuladen würde den Prozess unterbrechen. Wirklich verlassen?';
                e.returnValue = msg;
                return msg;
            }
        });

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

        function formatTimestamp(dateInput) {
            var d = dateInput ? new Date(dateInput) : new Date();
            if (isNaN(d.getTime())) { d = new Date(); }
            var hh = String(d.getHours()).padStart(2, '0');
            var mm = String(d.getMinutes()).padStart(2, '0');
            var dd = String(d.getDate()).padStart(2, '0');
            var mo = String(d.getMonth() + 1).padStart(2, '0');
            return dd + '.' + mo + '. ' + hh + ':' + mm;
        }

        function formatUsageBadge(usage) {
            if (!usage || (!usage.prompt_tokens && !usage.completion_tokens)) return '';
            var total = (usage.prompt_tokens || 0) + (usage.completion_tokens || 0);
            var cached = usage.cached_tokens || 0;
            var calls = usage.api_calls || 1;
            var parts = [total.toLocaleString('de-DE') + ' tokens'];
            if (cached > 0) {
                var pct = Math.round(cached / (usage.prompt_tokens || 1) * 100);
                parts.push(pct + '% cached');
            }
            if (calls > 1) {
                parts.push(calls + ' calls');
            }
            return '<span class="levi-usage-badge" title="Prompt: ' + (usage.prompt_tokens || 0).toLocaleString('de-DE')
                + ' | Completion: ' + (usage.completion_tokens || 0).toLocaleString('de-DE')
                + ' | Cached: ' + cached.toLocaleString('de-DE')
                + ' | API-Calls: ' + calls + '">'
                + parts.join(' · ') + '</span>';
        }

        function addMessage(text, role, attachments, timestamp, usage) {
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

            var ts = formatTimestamp(timestamp);
            var usageBadge = (role === 'assistant') ? formatUsageBadge(usage) : '';
            let html = buildAvatarHtml(role) + '<div class="levi-message-main"><div class="levi-message-content">' + inner + '</div>'
                + '<span class="levi-message-time">' + ts + '</span>' + usageBadge;

            if (role === 'user') {
                html += '<button type="button" class="levi-message-edit-btn dashicons dashicons-edit" title="Nachricht bearbeiten" aria-label="Nachricht bearbeiten"></button>';
            }
            html += '</div>';

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

        function appendConfirmationCard(pending) {
            if (!pending || !pending.action_id) return;

            var card = document.createElement('div');
            card.className = 'levi-confirmation-card';
            card.setAttribute('data-action-id', pending.action_id);

            var label = document.createElement('div');
            label.className = 'levi-confirmation-label';
            label.textContent = 'Levi möchte: ' + (pending.description || pending.tool || 'Aktion ausführen');
            card.appendChild(label);

            var btnRow = document.createElement('div');
            btnRow.className = 'levi-confirmation-buttons';

            var confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = 'levi-confirm-btn levi-confirm-btn-primary';
            confirmBtn.textContent = 'Bestätigen';

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'levi-confirm-btn levi-confirm-btn-secondary';
            cancelBtn.textContent = 'Abbrechen';

            btnRow.appendChild(confirmBtn);
            btnRow.appendChild(cancelBtn);
            card.appendChild(btnRow);

            confirmBtn.addEventListener('click', async function() {
                confirmBtn.disabled = true;
                cancelBtn.disabled = true;
                confirmBtn.textContent = 'Wird ausgeführt...';
                card.classList.add('levi-confirmation-loading');

                var typing = addTypingIndicator();
                typing.setLabel('Levi führt bestätigte Aktion aus...');
                setSendingState(true);

                try {
                    var resp = await fetch(leviAgent.restUrl + 'chat/confirm-action', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': leviAgent.nonce,
                        },
                        body: JSON.stringify({ action_id: pending.action_id }),
                    });

                    if (!resp.ok && !resp.body) {
                        throw new Error('Server error: ' + resp.status);
                    }

                    var reader = resp.body.getReader();
                    var decoder = new TextDecoder();
                    var buffer = '';
                    var finalHandled = false;
                    var phaseTimers = {};

                    while (true) {
                        var chunk = await reader.read();
                        if (chunk.done) break;

                        buffer += decoder.decode(chunk.value, { stream: true });

                        while (buffer.includes('\n\n')) {
                            var eventEnd = buffer.indexOf('\n\n');
                            var eventStr = buffer.substring(0, eventEnd);
                            buffer = buffer.substring(eventEnd + 2);

                            var lines = eventStr.split('\n');
                            for (var li = 0; li < lines.length; li++) {
                                if (!lines[li].startsWith('data: ')) continue;
                                try {
                                    var data = JSON.parse(lines[li].substring(6));
                                    finalHandled = handleSSEEvent(data, typing, phaseTimers) || finalHandled;
                                } catch (e) {
                                    console.warn('SSE parse error:', e, lines[li]);
                                }
                            }
                        }
                    }

                    card.remove();
                    if (!finalHandled) {
                        typing.complete();
                        setSendingState(false);
                    }
                } catch (err) {
                    typing.remove();
                    card.remove();
                    setSendingState(false);
                    addMessage('❌ Bestätigung fehlgeschlagen: ' + err.message, 'assistant');
                }
            });

            cancelBtn.addEventListener('click', function() {
                card.remove();
            });

            messages.appendChild(card);
            messages.scrollTop = messages.scrollHeight;
        }

        function renderHistory(historyMessages) {
            messages.innerHTML = '';
            if (!Array.isArray(historyMessages) || historyMessages.length === 0) {
                var greeting = 'Hallo ' + (leviAgent.userName || '') + '! 👋\n\nIch bin dein WordPress KI-Assistent. Wie kann ich dir helfen?\n\n'
                    + '<span class="levi-session-hint">Das ist eine neue Session. Levi merkt sich den Gespraechsverlauf innerhalb dieser Session (bis zu 30 Tage). '
                    + 'Nutze den Papierkorb-Button um die Session zu löschen und eine neue zu starten.</span>'
                    + '<span class="levi-session-alert">VORSICHT: Ein Seitenwechsel unterbricht laufende Aufgaben von Levi. Falls du also an etwas arbeiten möchtest während Levi eine Aufgabe bearbeitet, öffne dir bitte einen neuen Tab und arbeite in diesem.</span>';
                addMessage(greeting, 'assistant');
                return;
            }

            historyMessages.forEach(msg => {
                if (!msg || !msg.role || !msg.content) {
                    return;
                }
                if (msg.role === 'user' || msg.role === 'assistant') {
                    addMessage(msg.content, msg.role, null, msg.created_at);
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
                buildAvatarHtml('assistant') +
                '<div class="levi-message-main"><div class="levi-message-content levi-typing">' +
                '<div class="levi-typing-row">' +
                '<span></span><span></span><span></span>' +
                '<small class="levi-typing-label">Levi verarbeitet die Anfrage...</small>' +
                '</div>' +
                '<div class="levi-chat-progress-track levi-chat-progress-indeterminate"><div class="levi-chat-progress-shimmer"></div></div>' +
                '</div></div>';
            messages.appendChild(typingDiv);
            messages.scrollTop = messages.scrollHeight;

            const labelEl = typingDiv.querySelector('.levi-typing-label');
            const contentEl = typingDiv.querySelector('.levi-message-content');
            const typingRow = typingDiv.querySelector('.levi-typing-row');
            const progressTrack = typingDiv.querySelector('.levi-chat-progress-track');
            let streamEl = null;
            let isStreaming = false;
            let streamBuffer = '';

            const setLabel = (label) => {
                if (labelEl) {
                    labelEl.textContent = label;
                }
            };

            const appendDelta = (text) => {
                if (!isStreaming) {
                    isStreaming = true;
                    if (typingRow) typingRow.style.display = 'none';
                    if (progressTrack) progressTrack.style.display = 'none';
                    streamEl = document.createElement('div');
                    streamEl.className = 'levi-stream-content';
                    contentEl.appendChild(streamEl);
                    contentEl.classList.remove('levi-typing');
                }
                streamBuffer += text;
                if (streamEl) {
                    streamEl.innerHTML = renderMessageContent(streamBuffer, 'assistant');
                    messages.scrollTop = messages.scrollHeight;
                }
            };

            const clearStream = () => {
                if (isStreaming) {
                    isStreaming = false;
                    streamBuffer = '';
                    streamEl = null;
                    if (typingRow) {
                        contentEl.appendChild(typingRow);
                        typingRow.style.display = '';
                    }
                    if (progressTrack) {
                        contentEl.appendChild(progressTrack);
                        progressTrack.style.display = '';
                    }
                }
            };

            setLabel('Levi verarbeitet die Anfrage...');

            return {
                setLabel,
                appendDelta,
                clearStream,
                complete: () => {
                    typingDiv.classList.add('levi-typing-complete');
                    setTimeout(() => typingDiv.remove(), 200);
                },
                remove: () => {
                    typingDiv.remove();
                },
            };
        }

        function scheduleTypingPhases() {
            return [];
        }

        function clearPhaseTimers(timerIds) {
            if (!Array.isArray(timerIds)) return;
            timerIds.forEach((id) => clearTimeout(id));
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
            return cleaned || 'Ich bin leider nicht ganz fertig geworden. Schreib einfach „mach weiter" und ich mach mich wieder an die Aufgabe.';
        }

        function fallbackPlainText(text) {
            return '<p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p>';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getInitial(name, fallback) {
            const source = String(name || '').trim();
            if (!source) return fallback;
            return source.charAt(0).toUpperCase();
        }

        function buildAvatarHtml(role) {
            const isAssistant = role === 'assistant';
            const letter = isAssistant ? 'L' : userInitial;
            const imgUrl = isAssistant ? leviAvatarUrl : userAvatarUrl;
            const title = isAssistant ? 'Levi' : (userName || 'User');

            let imageHtml = '';
            if (imgUrl) {
                imageHtml = '<img src="' + escapeHtml(imgUrl) + '" alt="' + escapeHtml(title) + '" class="levi-message-avatar-image" loading="lazy" decoding="async" onerror="this.style.display=\'none\'; this.parentElement.classList.add(\'levi-avatar-fallback-visible\');">';
            }

            return '<div class="levi-message-avatar levi-message-avatar-' + (isAssistant ? 'assistant' : 'user') + '">' +
                imageHtml +
                '<span class="levi-message-avatar-fallback">' + escapeHtml(letter) + '</span>' +
                '</div>';
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
                        const r = data.data.results || {};
                        const errors = r.errors || [];
                        const loadedCount = Object.keys(r.loaded || {}).length;
                        const hadChanges = (r.changed_identity || []).length > 0 || (r.changed_reference || []).length > 0;
                        result.innerHTML = ' <span style="color: ' + (errors.length ? 'orange' : 'green') + ';">✅ ' + data.data.message + '</span>';
                        if (hadChanges && errors.length === 0 && loadedCount > 0) {
                            const warningEl = document.getElementById('levi-memory-changes-warning');
                            if (warningEl) {
                                warningEl.style.display = 'none';
                            }
                        }
                        setTimeout(() => location.reload(), 1500);
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
