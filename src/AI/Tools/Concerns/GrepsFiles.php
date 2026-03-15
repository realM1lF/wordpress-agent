<?php

namespace Levi\Agent\AI\Tools\Concerns;

/**
 * Shared grep logic for searching text/regex patterns across files in a directory.
 * Used by GrepPluginFilesTool and GrepThemeFilesTool.
 */
trait GrepsFiles {

    private const GREP_MAX_FILES = 100;
    private const GREP_MAX_TOTAL_BYTES = 2 * 1024 * 1024; // 2 MB
    private const GREP_MAX_RESULTS_LIMIT = 200;
    private const GREP_DEFAULT_RESULTS = 50;
    private const GREP_MAX_CONTEXT = 3;
    private const GREP_SKIP_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'mp3', 'zip', 'gz', 'tar', 'map'];
    private const GREP_MAX_FILE_SIZE = 500 * 1024; // 500 KB

    /**
     * Common grep parameter definitions (pattern, file_glob, is_regex, max_results, context_lines, case_sensitive).
     */
    protected function getGrepParameters(): array {
        return [
            'pattern' => [
                'type' => 'string',
                'description' => 'Search string or regex pattern to find in files',
                'required' => true,
            ],
            'file_glob' => [
                'type' => 'string',
                'description' => 'Filter files by extension, e.g. "*.php", "*.css", "*.js" (default: all text files)',
            ],
            'is_regex' => [
                'type' => 'boolean',
                'description' => 'Treat pattern as a regular expression (default: false, uses literal string match)',
                'default' => false,
            ],
            'case_sensitive' => [
                'type' => 'boolean',
                'description' => 'Case-sensitive matching (default: true). Set to false for case-insensitive search.',
                'default' => true,
            ],
            'max_results' => [
                'type' => 'integer',
                'description' => 'Maximum number of matches to return (default: 50, max: 200)',
                'default' => self::GREP_DEFAULT_RESULTS,
            ],
            'context_lines' => [
                'type' => 'integer',
                'description' => 'Number of context lines before and after each match (default: 1, max: 3)',
                'default' => 1,
            ],
        ];
    }

    /**
     * Execute grep search across all files in a directory.
     *
     * @param string $rootPath  Absolute path to the directory to search
     * @param array  $params    Tool parameters (pattern, file_glob, is_regex, case_sensitive, max_results, context_lines)
     * @return array Search results with matches, counts, and metadata
     */
    protected function executeGrep(string $rootPath, array $params): array {
        $pattern = (string) ($params['pattern'] ?? '');
        $fileGlob = (string) ($params['file_glob'] ?? '');
        $isRegex = (bool) ($params['is_regex'] ?? false);
        $caseSensitive = (bool) ($params['case_sensitive'] ?? true);
        $maxResults = min(max(1, (int) ($params['max_results'] ?? self::GREP_DEFAULT_RESULTS)), self::GREP_MAX_RESULTS_LIMIT);
        $contextLines = min(max(0, (int) ($params['context_lines'] ?? 1)), self::GREP_MAX_CONTEXT);

        if ($pattern === '') {
            return ['success' => false, 'error' => 'pattern is required.'];
        }

        if ($isRegex) {
            $flags = $caseSensitive ? '' : 'i';
            if (@preg_match('~' . $pattern . '~' . $flags, '') === false) {
                return ['success' => false, 'error' => 'Invalid regex pattern: ' . $pattern];
            }
        }

        $extensionFilter = null;
        if ($fileGlob !== '') {
            if (preg_match('/^\*\.([a-zA-Z0-9]+)$/', $fileGlob, $m)) {
                $extensionFilter = strtolower($m[1]);
            }
        }

        $results = [];
        $totalMatches = 0;
        $filesMatched = [];
        $filesScanned = 0;
        $totalBytes = 0;
        $truncated = false;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($rootPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if ($filesScanned >= self::GREP_MAX_FILES || $totalBytes >= self::GREP_MAX_TOTAL_BYTES) {
                    $truncated = true;
                    break;
                }

                $ext = strtolower($file->getExtension());
                if (in_array($ext, self::GREP_SKIP_EXTENSIONS, true)) {
                    continue;
                }
                if ($file->getSize() > self::GREP_MAX_FILE_SIZE) {
                    continue;
                }
                if (str_contains($file->getFilename(), '.min.')) {
                    continue;
                }

                if ($extensionFilter !== null && $ext !== $extensionFilter) {
                    continue;
                }

                $filePath = $file->getPathname();
                $relativePath = ltrim(substr($filePath, strlen($rootPath)), '/');

                $content = @file_get_contents($filePath);
                if ($content === false) {
                    continue;
                }

                $filesScanned++;
                $totalBytes += strlen($content);

                if (str_contains($content, "\x00")) {
                    continue;
                }

                $lines = explode("\n", $content);
                $fileHasMatch = false;

                foreach ($lines as $lineIdx => $lineContent) {
                    $lineNum = $lineIdx + 1;
                    $matched = false;

                    if ($isRegex) {
                        $flags = $caseSensitive ? '' : 'i';
                        $matched = (bool) preg_match('~' . $pattern . '~' . $flags, $lineContent);
                    } else {
                        if ($caseSensitive) {
                            $matched = str_contains($lineContent, $pattern);
                        } else {
                            $matched = stripos($lineContent, $pattern) !== false;
                        }
                    }

                    if (!$matched) {
                        continue;
                    }

                    $totalMatches++;
                    if (!$fileHasMatch) {
                        $fileHasMatch = true;
                        $filesMatched[] = $relativePath;
                    }

                    if (count($results) >= $maxResults) {
                        $truncated = true;
                        break;
                    }

                    $match = [
                        'file' => $relativePath,
                        'line' => $lineNum,
                        'content' => rtrim($lineContent),
                    ];

                    if ($contextLines > 0) {
                        $before = [];
                        for ($i = max(0, $lineIdx - $contextLines); $i < $lineIdx; $i++) {
                            $before[] = rtrim($lines[$i]);
                        }
                        $after = [];
                        for ($i = $lineIdx + 1; $i <= min(count($lines) - 1, $lineIdx + $contextLines); $i++) {
                            $after[] = rtrim($lines[$i]);
                        }
                        $match['context_before'] = $before;
                        $match['context_after'] = $after;
                    }

                    $results[] = $match;
                }

                if ($truncated) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Search failed: ' . $e->getMessage()];
        }

        return [
            'pattern' => $pattern,
            'total_matches' => $totalMatches,
            'files_matched' => count($filesMatched),
            'files_scanned' => $filesScanned,
            'results' => $results,
            'truncated' => $truncated,
        ];
    }
}
