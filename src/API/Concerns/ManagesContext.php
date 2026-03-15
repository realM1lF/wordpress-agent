<?php

namespace Levi\Agent\API\Concerns;

use Levi\Agent\AI\PIIRedactor;

trait ManagesContext {

    private function estimateTokenCount($content): int {
        if (is_string($content)) {
            return (int) ceil(mb_strlen($content) / 3.5);
        }
        if (is_array($content)) {
            $tokens = 0;
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $tokens += (int) ceil(mb_strlen((string) ($part['text'] ?? '')) / 3.5);
                } elseif (is_array($part) && ($part['type'] ?? '') === 'image_url') {
                    $tokens += 1000;
                }
            }
            return $tokens;
        }
        return 0;
    }

    private function trimMessagesToBudget(array $messages, ?string $sessionId = null): array {
        $runtimeSettings = $this->settings->getSettings();
        $maxContextTokens = max(1000, (int) ($runtimeSettings['max_context_tokens'] ?? 100000));

        $totalTokens = 0;
        foreach ($messages as $msg) {
            $totalTokens += $this->estimateTokenCount($msg['content'] ?? '');
        }

        if ($totalTokens <= $maxContextTokens) {
            return $messages;
        }

        // Separate fixed messages from trimmable history.
        // System messages (index 0, and optional summary at index 1) + user message (last) are protected.
        $systemMessages = [];
        $historyMessages = [];
        $userMsg = array_pop($messages);

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemMessages[] = $msg;
            } else {
                $historyMessages[] = $msg;
            }
        }

        $reservedTokens = $this->estimateTokenCount($userMsg['content'] ?? '');
        foreach ($systemMessages as $sm) {
            $reservedTokens += $this->estimateTokenCount($sm['content'] ?? '');
        }

        $availableBudget = $maxContextTokens - $reservedTokens;
        if ($availableBudget < 500) {
            $availableBudget = 500;
        }

        // Work backwards through history (newest first), accumulating tokens
        $keptHistory = [];
        $droppedHistory = [];
        $usedTokens = 0;
        for ($i = count($historyMessages) - 1; $i >= 0; $i--) {
            $msgTokens = $this->estimateTokenCount($historyMessages[$i]['content'] ?? '');
            if ($usedTokens + $msgTokens > $availableBudget) {
                $droppedHistory = array_slice($historyMessages, 0, $i + 1);
                break;
            }
            $usedTokens += $msgTokens;
            array_unshift($keptHistory, $historyMessages[$i]);
        }

        // Trigger background summarization for dropped messages
        if (!empty($droppedHistory) && $sessionId !== null) {
            $this->triggerSummarization($sessionId, $droppedHistory);
        }

        $trimmedCount = count($droppedHistory);
        if ($trimmedCount > 0) {
            error_log(sprintf(
                'Levi Token Budget: trimmed %d older messages, %d kept (estimated %d -> %d tokens)',
                $trimmedCount,
                count($keptHistory),
                $totalTokens,
                $reservedTokens + $usedTokens
            ));
        }

        $result = $systemMessages;
        foreach ($keptHistory as $msg) {
            $result[] = $msg;
        }
        $result[] = $userMsg;

        return $result;
    }

    /**
     * Trigger summarization of dropped messages.
     * Runs inline (lazy) — only when trimming actually happens.
     * Uses a cheap/fast model to minimize latency.
     */
    private function triggerSummarization(string $sessionId, array $droppedMessages): void {
        try {
            $existingSummary = $this->conversationRepo->getLatestSummary($sessionId);
            $existingSummaryText = $existingSummary !== null ? (string) $existingSummary['content'] : null;

            $totalCount = $this->conversationRepo->getMessageCount($sessionId);

            $lastDroppedMsg = end($droppedMessages);
            $coveredUpToId = (int) ($lastDroppedMsg['id'] ?? 0);
            if ($coveredUpToId === 0) {
                $coveredUpToId = count($droppedMessages);
            }

            if ($existingSummary !== null) {
                $previousCoveredId = (int) ($existingSummary['context_hash'] ?? 0);
                if ($previousCoveredId >= $coveredUpToId) {
                    return;
                }
            }

            $summarizer = new \Levi\Agent\AI\SessionSummarizer();
            $summary = $summarizer->summarize(
                $droppedMessages,
                $existingSummaryText,
                $totalCount,
                count($droppedMessages)
            );

            if ($summary !== null) {
                $userId = get_current_user_id();
                $this->conversationRepo->saveSummary($sessionId, $userId, $summary, $coveredUpToId);
                error_log(sprintf(
                    'Levi SessionSummary: created for session %s, covering %d messages',
                    $sessionId,
                    count($droppedMessages)
                ));
            }
        } catch (\Throwable $e) {
            error_log('Levi SessionSummary error: ' . $e->getMessage());
        }
    }

    private function halveHistory(array $messages): array {
        if (count($messages) <= 3) {
            return $messages;
        }
        $userMsg = array_pop($messages);

        $systemMessages = [];
        $historyMessages = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemMessages[] = $msg;
            } else {
                $historyMessages[] = $msg;
            }
        }

        $kept = array_slice($historyMessages, (int) ceil(count($historyMessages) / 2));
        return array_merge($systemMessages, $kept, [$userMsg]);
    }

    /**
     * Compact messages for tool-loop iterations to reduce token count.
     *
     * Phase 1 (iteration 2+): Drop older pure-chat messages, keep last 6.
     * Phase 2 (>70% fill): Trim older tool results to short summaries.
     * Phase 3 (>85% fill): Aggressively truncate older tool results.
     *
     * System messages, assistant messages with tool_calls, and their paired
     * tool results are structurally preserved (IDs intact) — only content
     * is shortened to prevent API validation errors.
     */
    private function compactMessagesForToolLoop(array $messages, int $iteration): array {
        if ($iteration < 2) {
            return $messages;
        }

        // Drop oldest pure-chat pairs to stay within conversational window
        $pureChatIndices = [];
        foreach ($messages as $i => $msg) {
            $role = $msg['role'] ?? '';
            if (($role === 'user' || $role === 'assistant') && empty($msg['tool_calls'])) {
                $pureChatIndices[] = $i;
            }
        }

        $keep = 6;
        if (count($pureChatIndices) > $keep) {
            $dropIndices = array_flip(array_slice($pureChatIndices, 0, -$keep));
            $compacted = [];
            foreach ($messages as $i => $msg) {
                if (!isset($dropIndices[$i])) {
                    $compacted[] = $msg;
                }
            }
        } else {
            $compacted = $messages;
        }

        // Estimate fill ratio for progressive pruning
        $runtimeSettings = $this->settings->getSettings();
        $maxContextTokens = max(1000, (int) ($runtimeSettings['max_context_tokens'] ?? 100000));

        $totalTokens = 0;
        foreach ($compacted as $msg) {
            $content = $msg['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content);
            }
            $totalTokens += $this->estimateTokenCount((string) $content);
            if (!empty($msg['tool_calls'])) {
                $totalTokens += $this->estimateTokenCount(json_encode($msg['tool_calls']));
            }
        }
        $fillRatio = $totalTokens / $maxContextTokens;

        // Always run dedup (Stage 1); soft-trim at 40%; hard-clear at 55%
        if ($fillRatio > 0.35 || $iteration >= 3) {
            $effectiveRatio = max($fillRatio, $iteration >= 5 ? 0.60 : 0.0);
            $protectCount = $iteration >= 8 ? 2 : 3;
            $compacted = $this->trimOlderToolResults($compacted, $protectCount, $effectiveRatio, $iteration);
        }

        // Strip internal metadata before sending to the API
        foreach ($compacted as &$msg) {
            unset($msg['_levi_iteration'], $msg['_levi_tool']);
        }
        unset($msg);

        return $compacted;
    }

    /**
     * Three-stage pruning pipeline for tool-result messages (inspired by OpenClaw).
     *
     * Stage 1 — Dedup: If the same tool was called with identical key-args multiple
     *   times, replace all but the newest result with a short placeholder.
     * Stage 2 — Soft-Trim: Keep head + tail of oversized older results.
     * Stage 3 — Hard-Clear: Replace the oldest unprotected results entirely.
     *
     * Keeps role + tool_call_id intact to maintain API message pairing.
     *
     * @param float $fillRatio Current context fill ratio (0.0–1.0)
     */
    private function trimOlderToolResults(array $messages, int $protectLast = 4, float $fillRatio = 0.0, int $currentIteration = 0): array {
        $toolIndices = [];
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') === 'tool') {
                $toolIndices[] = $i;
            }
        }

        if (count($toolIndices) <= $protectLast) {
            return $messages;
        }

        $protectedSet = array_flip(array_slice($toolIndices, -$protectLast));

        // Also protect tool results that appear after the last user message
        $lastUserIdx = -1;
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $lastUserIdx = $i;
            }
        }
        if ($lastUserIdx >= 0) {
            foreach ($toolIndices as $ti) {
                if ($ti > $lastUserIdx) {
                    $protectedSet[$ti] = true;
                }
            }
        }

        // --- Stage 1: Dedup identical tool calls ---
        $messages = $this->deduplicateToolResults($messages, $toolIndices, $protectedSet);

        // --- Stage 2: Soft-Trim (fill > 0.40) ---
        if ($fillRatio > 0.40) {
            foreach ($messages as $i => &$msg) {
                if (($msg['role'] ?? '') !== 'tool' || isset($protectedSet[$i])) {
                    continue;
                }
                $content = (string) ($msg['content'] ?? '');
                $len = mb_strlen($content);
                if ($len <= 500) {
                    continue;
                }

                $msgIteration = ($msg['_levi_iteration'] ?? null);
                if ($msgIteration === null) {
                    $msgIteration = $currentIteration;
                }
                $resultAge = $currentIteration - (int) $msgIteration;
                $toolName = (string) ($msg['_levi_tool'] ?? $this->extractToolNameFromContext($messages, $i));

                if ($resultAge >= 3 && $toolName !== '') {
                    $msg['content'] = $this->summarizeOldToolResult($content, $toolName);
                } else {
                    $head = mb_substr($content, 0, 800);
                    $tail = mb_substr($content, -300);
                    $msg['content'] = $head . "\n...[soft-trimmed, original " . $len . " chars]...\n" . $tail;
                }
            }
            unset($msg);
        }

        // --- Stage 3: Hard-Clear (fill > 0.55) ---
        if ($fillRatio > 0.55) {
            $unprotected = [];
            foreach ($toolIndices as $ti) {
                if (!isset($protectedSet[$ti])) {
                    $unprotected[] = $ti;
                }
            }
            $hardClearCount = (int) ceil(count($unprotected) / 2);
            $hardClearSet = array_flip(array_slice($unprotected, 0, $hardClearCount));

            foreach ($messages as $i => &$msg) {
                if (!isset($hardClearSet[$i])) {
                    continue;
                }
                $msg['content'] = '[Tool-Ergebnis entfernt — altes Ergebnis, nur Content geloescht, tool_call_id intakt]';
            }
            unset($msg);
        }

        return $messages;
    }

    /**
     * Replace duplicate tool results (same tool + same key-args) with a placeholder,
     * keeping only the newest occurrence.
     */
    private function deduplicateToolResults(array $messages, array $toolIndices, array $protectedSet): array {
        $seen = [];
        foreach ($toolIndices as $ti) {
            $content = (string) ($messages[$ti]['content'] ?? '');
            $toolName = $this->extractToolNameFromContext($messages, $ti);
            if ($toolName === '') {
                continue;
            }
            $key = $toolName . '|' . $this->hashToolResultKey($content, $toolName);
            $seen[$key][] = $ti;
        }

        foreach ($seen as $indices) {
            if (count($indices) < 2) {
                continue;
            }
            // Keep the last (newest) occurrence, replace older ones
            array_pop($indices);
            foreach ($indices as $oldIdx) {
                if (isset($protectedSet[$oldIdx])) {
                    continue;
                }
                $messages[$oldIdx]['content'] = '[Duplikat-Ergebnis entfernt — neueres Ergebnis desselben Tools weiter unten]';
            }
        }

        return $messages;
    }

    /**
     * Find the tool name from the preceding assistant message's tool_calls.
     */
    private function extractToolNameFromContext(array $messages, int $toolResultIndex): string {
        $toolCallId = $messages[$toolResultIndex]['tool_call_id'] ?? '';
        if ($toolCallId === '') {
            return '';
        }
        for ($i = $toolResultIndex - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if (($msg['role'] ?? '') === 'assistant' && !empty($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $tc) {
                    if (($tc['id'] ?? '') === $toolCallId) {
                        return trim($tc['function']['name'] ?? '');
                    }
                }
            }
        }
        return '';
    }

    /**
     * Create a dedup key from tool result content based on the tool type.
     * For read tools, extracts identifying args (slug, file path, etc.).
     * For other tools, uses a content hash prefix.
     */
    private function hashToolResultKey(string $content, string $toolName): string {
        $readTools = ['read_plugin_file', 'read_theme_file', 'get_pages', 'get_posts',
            'get_post', 'get_plugins', 'list_plugin_files', 'list_theme_files',
            'get_option', 'get_media', 'get_users', 'read_error_log'];

        if (in_array($toolName, $readTools, true)) {
            // For read tools: extract slug/path from the JSON result for identity
            if (preg_match('/"(?:plugin_slug|slug|file_path|path)"\s*:\s*"([^"]{1,120})"/', $content, $m)) {
                return $toolName . ':' . $m[1];
            }
        }
        // Fallback: first 200 chars as identity (catches truly identical results)
        return md5(mb_substr($content, 0, 200));
    }

    /**
     * Create a semantic one-line summary of a tool result for context compression.
     * Used for tool results older than 3 iterations to drastically reduce token usage
     * while preserving the essential information the model needs.
     */
    private function summarizeOldToolResult(string $content, string $toolName): string {
        $data = @json_decode($content, true);
        if (!is_array($data)) {
            return '[' . $toolName . ': ' . mb_substr($content, 0, 150) . '...]';
        }

        $success = ($data['success'] ?? false) ? 'Erfolg' : 'Fehler';

        switch ($toolName) {
            case 'read_plugin_file':
            case 'read_theme_file':
                $slug = $data['plugin_slug'] ?? $data['theme_slug'] ?? '?';
                $path = $data['relative_path'] ?? '?';
                $lines = $data['meta']['line_count'] ?? $data['total_lines'] ?? '?';
                $symbols = [];
                $raw = $data['content'] ?? '';
                if (is_string($raw) && $raw !== '') {
                    if (preg_match_all('/\bfunction\s+(\w+)\s*\(/m', $raw, $m)) {
                        $symbols = array_merge($symbols, array_map(fn($f) => $f . '()', array_slice($m[1], 0, 8)));
                    }
                    if (preg_match_all('/\bclass\s+(\w+)/m', $raw, $m)) {
                        $symbols = array_merge($symbols, array_slice($m[1], 0, 4));
                    }
                }
                $sym = !empty($symbols) ? ', Symbole: ' . implode(', ', $symbols) : '';
                return "[Gelesen: {$slug}/{$path}, {$lines} Zeilen{$sym}]";

            case 'list_plugin_files':
            case 'list_theme_files':
                $slug = $data['plugin_slug'] ?? $data['theme_slug'] ?? '?';
                $total = $data['total'] ?? count($data['entries'] ?? []);
                $dirs = [];
                foreach (($data['entries'] ?? []) as $entry) {
                    if (($entry['type'] ?? '') === 'dir' && !str_contains(($entry['path'] ?? ''), '/')) {
                        $dirs[] = $entry['path'] . '/';
                    }
                }
                $dirStr = !empty($dirs) ? ', Verzeichnisse: ' . implode(', ', array_slice($dirs, 0, 6)) : '';
                return "[Dateiliste: {$slug}, {$total} Dateien{$dirStr}]";

            case 'grep_plugin_files':
            case 'grep_theme_files':
                $pattern = $data['pattern'] ?? '?';
                $totalMatches = $data['total_matches'] ?? 0;
                $filesMatched = $data['files_matched'] ?? 0;
                $fileNames = [];
                foreach (array_slice($data['results'] ?? [], 0, 5) as $r) {
                    $f = basename($r['file'] ?? '');
                    if ($f !== '' && !in_array($f, $fileNames, true)) {
                        $fileNames[] = $f;
                    }
                }
                $files = !empty($fileNames) ? ': ' . implode(', ', $fileNames) : '';
                return "[Suche: '{$pattern}' → {$totalMatches} Treffer in {$filesMatched} Dateien{$files}]";

            case 'get_posts':
            case 'get_pages':
                $type = $data['queried_post_type'] ?? ($toolName === 'get_pages' ? 'page' : 'post');
                $count = $data['count'] ?? count($data['posts'] ?? $data['pages'] ?? []);
                $items = $data['posts'] ?? $data['pages'] ?? [];
                $ids = array_slice(array_column($items, 'id'), 0, 5);
                $idStr = !empty($ids) ? ', IDs: ' . implode(', ', $ids) : '';
                return "[Gelesen: {$count} Eintraege ({$type}){$idStr}]";

            case 'get_post':
                $id = $data['post']['id'] ?? $data['id'] ?? '?';
                $title = $data['post']['title'] ?? $data['title'] ?? '';
                $titleStr = $title !== '' ? ": {$title}" : '';
                return "[Gelesen: Beitrag #{$id}{$titleStr}]";

            case 'write_plugin_file':
            case 'write_theme_file':
                $slug = $data['plugin_slug'] ?? $data['theme_slug'] ?? '?';
                $path = $data['relative_path'] ?? '?';
                $bytes = $data['bytes_written'] ?? '?';
                return "[Geschrieben: {$slug}/{$path}, {$bytes} Bytes, {$success}]";

            case 'patch_plugin_file':
            case 'patch_theme_file':
                $slug = $data['plugin_slug'] ?? $data['theme_slug'] ?? '?';
                $path = $data['relative_path'] ?? '?';
                $count = $data['patches_applied'] ?? 0;
                return "[Gepatcht: {$slug}/{$path}, {$count} Ersetzungen, {$success}]";

            case 'check_plugin_health':
                $slug = $data['plugin_slug'] ?? '?';
                $healthy = ($data['healthy'] ?? false) ? 'gesund' : 'Probleme';
                $checked = $data['files_checked'] ?? 0;
                $issues = count($data['issues'] ?? []);
                return "[Health-Check: {$slug}, {$checked} Dateien, {$healthy}, {$issues} Issues]";

            case 'read_error_log':
                $lineCount = $data['total_lines'] ?? count($data['lines'] ?? []);
                return "[Error-Log: {$lineCount} Zeilen gelesen]";

            default:
                if (!($data['success'] ?? true)) {
                    $err = $data['error'] ?? 'unbekannt';
                    return "[{$toolName}: Fehler — " . mb_substr($err, 0, 100) . "]";
                }
                return '[' . $toolName . ': ' . mb_substr($content, 0, 150) . '...]';
        }
    }

    private function compactToolResultForModel(array $result): string {
        $compact = $result;
        foreach (['posts', 'pages', 'media', 'users', 'plugins'] as $listKey) {
            if (!isset($compact[$listKey]) || !is_array($compact[$listKey])) {
                continue;
            }
            $originalCount = count($compact[$listKey]);
            if ($originalCount > 20) {
                $compact[$listKey] = array_slice($compact[$listKey], 0, 20);
                $compact[$listKey . '_truncated_count'] = $originalCount - 20;
            }
        }

        if (isset($compact['content']) && is_string($compact['content']) && mb_strlen($compact['content']) > 4000) {
            $head = mb_substr($compact['content'], 0, 2500);
            $tail = mb_substr($compact['content'], -1200);
            $compact['content'] = $head . "\n\n...[truncated middle section]...\n\n" . $tail;
        }

        $json = wp_json_encode($compact);
        if (!is_string($json)) {
            return '{"success":false,"error":"Could not serialize tool result."}';
        }
        if (mb_strlen($json) > 12000) {
            $head = mb_substr($json, 0, 8000);
            $tail = mb_substr($json, -3500);
            $json = $head . "\n...[truncated middle section]...\n" . $tail;
        }

        $redactor = PIIRedactor::getInstance();
        if ($redactor->isEnabled()) {
            $json = $redactor->redact($json);
        }

        return $json;
    }
}
