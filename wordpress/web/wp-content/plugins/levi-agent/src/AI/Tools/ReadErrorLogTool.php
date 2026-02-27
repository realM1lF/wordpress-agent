<?php

namespace Levi\Agent\AI\Tools;

class ReadErrorLogTool implements ToolInterface {

    public function getName(): string {
        return 'read_error_log';
    }

    public function getDescription(): string {
        return 'Read the WordPress debug.log file to diagnose PHP errors, warnings, and notices. Useful for debugging plugin issues, fatal errors, or unexpected behavior. Returns the last N lines.';
    }

    public function getParameters(): array {
        return [
            'lines' => [
                'type' => 'integer',
                'description' => 'Number of lines to read from the end of the log (default 100, max 500)',
            ],
            'filter' => [
                'type' => 'string',
                'description' => 'Optional keyword to filter log lines (e.g. "Fatal", "Warning", a plugin slug, or "Levi")',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $lines = min(500, max(1, (int) ($params['lines'] ?? 100)));
        $filter = trim((string) ($params['filter'] ?? ''));

        $logPath = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($logPath)) {
            return [
                'success' => false,
                'error' => 'debug.log does not exist. Ensure WP_DEBUG and WP_DEBUG_LOG are enabled in wp-config.php.',
            ];
        }

        if (!is_readable($logPath)) {
            return [
                'success' => false,
                'error' => 'debug.log exists but is not readable (permissions issue).',
            ];
        }

        $fileSize = filesize($logPath);
        if ($fileSize === 0) {
            return [
                'success' => true,
                'lines' => [],
                'total_lines' => 0,
                'file_size' => 0,
                'message' => 'debug.log is empty - no errors logged.',
            ];
        }

        $allLines = $this->tailFile($logPath, $lines * 3);

        if ($filter !== '') {
            $filterLower = mb_strtolower($filter);
            $allLines = array_filter($allLines, function ($line) use ($filterLower) {
                return str_contains(mb_strtolower($line), $filterLower);
            });
            $allLines = array_values($allLines);
        }

        $result = array_slice($allLines, -$lines);

        return [
            'success' => true,
            'lines' => $result,
            'total_lines' => count($result),
            'file_size' => $fileSize,
            'file_size_human' => size_format($fileSize),
            'filter' => $filter ?: null,
        ];
    }

    private function tailFile(string $path, int $lines): array {
        $maxBytes = 512 * 1024;
        $fileSize = filesize($path);
        $readBytes = min($fileSize, $maxBytes);

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        fseek($handle, -$readBytes, SEEK_END);
        $content = fread($handle, $readBytes);
        fclose($handle);

        if ($content === false) {
            return [];
        }

        $allLines = explode("\n", $content);
        if (end($allLines) === '') {
            array_pop($allLines);
        }

        return array_slice($allLines, -$lines);
    }
}
