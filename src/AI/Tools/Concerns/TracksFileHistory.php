<?php

namespace Levi\Agent\AI\Tools\Concerns;

trait TracksFileHistory {

    private const FILE_HISTORY_MAX_ENTRIES = 20;
    private const FILE_HISTORY_EXPIRY_SECONDS = 3600;

    protected function recordFileVersion(string $absolutePath, string $previousContent, string $toolName): void {
        $key = $this->getFileHistoryTransientKey();
        $history = get_transient($key);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, [
            'path' => $absolutePath,
            'content' => $previousContent,
            'tool' => $toolName,
            'timestamp' => time(),
        ]);

        if (count($history) > self::FILE_HISTORY_MAX_ENTRIES) {
            $history = array_slice($history, 0, self::FILE_HISTORY_MAX_ENTRIES);
        }

        set_transient($key, $history, self::FILE_HISTORY_EXPIRY_SECONDS);
    }

    protected function getFileHistory(string $absolutePath): array {
        $key = $this->getFileHistoryTransientKey();
        $history = get_transient($key);
        if (!is_array($history)) {
            return [];
        }

        return array_values(array_filter(
            $history,
            fn(array $entry) => ($entry['path'] ?? '') === $absolutePath
        ));
    }

    protected function revertToVersion(string $absolutePath, int $versionIndex): ?string {
        $versions = $this->getFileHistory($absolutePath);
        if (!isset($versions[$versionIndex])) {
            return null;
        }
        return $versions[$versionIndex]['content'] ?? null;
    }

    private function getFileHistoryTransientKey(): string {
        $sessionId = $this->resolveSessionId();
        return 'levi_file_history_' . $sessionId;
    }

    private function resolveSessionId(): string {
        if (defined('LEVI_SESSION_ID')) {
            return sanitize_title(LEVI_SESSION_ID);
        }
        return 'default';
    }
}
