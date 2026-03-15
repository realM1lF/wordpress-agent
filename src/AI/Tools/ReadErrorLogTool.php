<?php

namespace Levi\Agent\AI\Tools;

class ReadErrorLogTool implements ToolInterface {

    public function getName(): string {
        return 'read_error_log';
    }

    public function getDescription(): string {
        return 'Read the WordPress debug.log file to diagnose PHP errors, warnings, and notices. '
            . 'Use "since" to only see recent errors (e.g. "1h", "30m", "2d"). '
            . 'Without "since", returns the last N lines which may include old, already-fixed errors.';
    }

    public function getParameters(): array {
        return [
            'lines' => [
                'type' => 'integer',
                'description' => 'Number of lines to read from the end of the log (default 100, max 500)',
            ],
            'filter' => [
                'type' => 'string',
                'description' => 'Optional keyword to filter log lines (e.g. "Fatal", "Warning", a plugin slug)',
            ],
            'since' => [
                'type' => 'string',
                'description' => 'Only show entries newer than this. Examples: "1h" (last hour), "30m" (last 30 minutes), "2d" (last 2 days), "2025-03-14". Default: "24h" when diagnosing current issues.',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $lines = min(500, max(1, (int) ($params['lines'] ?? 100)));
        $filter = trim((string) ($params['filter'] ?? ''));
        $since = trim((string) ($params['since'] ?? ''));

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

        $sinceTimestamp = $this->parseSince($since);
        if ($sinceTimestamp !== null) {
            $allLines = $this->filterByTimestamp($allLines, $sinceTimestamp);
        }

        if ($filter !== '') {
            $filterLower = mb_strtolower($filter);
            $allLines = array_filter($allLines, function ($line) use ($filterLower) {
                return str_contains(mb_strtolower($line), $filterLower);
            });
            $allLines = array_values($allLines);
        }

        $result = array_slice($allLines, -$lines);

        $response = [
            'success' => true,
            'lines' => $result,
            'total_lines' => count($result),
            'file_size' => $fileSize,
            'file_size_human' => size_format($fileSize),
            'filter' => $filter ?: null,
        ];

        if ($sinceTimestamp !== null) {
            $response['since'] = gmdate('Y-m-d H:i:s', $sinceTimestamp) . ' UTC';
        }

        if (empty($result) && $sinceTimestamp !== null) {
            $response['message'] = 'No errors found in the requested time range.';
        }

        return $response;
    }

    /**
     * Parse "since" parameter into a Unix timestamp.
     * Supports relative durations (30m, 1h, 2d, 1w) and absolute dates (2025-03-14).
     */
    private function parseSince(string $since): ?int {
        if ($since === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*(m|min|h|hour|d|day|w|week)s?$/i', $since, $m)) {
            $amount = (int) $m[1];
            $unit = strtolower($m[2][0]);
            $multipliers = ['m' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
            return time() - ($amount * ($multipliers[$unit] ?? 3600));
        }

        $ts = strtotime($since);
        return ($ts !== false && $ts > 0) ? $ts : null;
    }

    /**
     * Filter log lines to only include entries at or after the given timestamp.
     * PHP error log format: [DD-Mon-YYYY HH:MM:SS UTC]
     * Lines without a timestamp (stack traces, continuation) are kept if
     * the preceding timestamped line passed the filter.
     */
    private function filterByTimestamp(array $lines, int $sinceTimestamp): array {
        $filtered = [];
        $lastTimestampPassed = false;

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\s/', $line, $m)) {
                $lineTs = strtotime($m[1]);
                $lastTimestampPassed = ($lineTs !== false && $lineTs >= $sinceTimestamp);
            }

            if ($lastTimestampPassed) {
                $filtered[] = $line;
            }
        }

        return $filtered;
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

    public function getInputExamples(): array
    {
        return [
            ['since' => '1h'],
            ['since' => '24h', 'filter' => 'Fatal'],
            ['lines' => 50, 'filter' => 'Warning'],
        ];
    }
}
