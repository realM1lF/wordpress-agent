<?php

namespace Levi\Agent\AI\Tools;

class ReadPluginFileTool implements ToolInterface {

    private const DEFAULT_MAX_BYTES = 60000;
    private const ABSOLUTE_MAX_BYTES = 500000;
    private const BINARY_PROBE_BYTES = 512;
    private const MINIFIED_AVG_LINE_THRESHOLD = 500;
    private const MINIFIED_CAP_BYTES = 5000;
    private const MINIFIED_EXTENSIONS = ['js', 'css', 'json'];

    public function getName(): string {
        return 'read_plugin_file';
    }

    public function getDescription(): string {
        return 'Read a file from a plugin directory. Returns numbered lines for precise referencing. Binary/minified files are auto-detected.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Plugin slug (directory in wp-content/plugins)',
                'required' => true,
            ],
            'relative_path' => [
                'type' => 'string',
                'description' => 'File path inside plugin, e.g. includes/class-api.php',
                'required' => true,
            ],
            'max_bytes' => [
                'type' => 'integer',
                'description' => 'Maximum bytes to return (default 60000, max 500000)',
                'default' => self::DEFAULT_MAX_BYTES,
            ],
            'offset_bytes' => [
                'type' => 'integer',
                'description' => 'Optional byte offset to continue reading large files',
                'default' => 0,
            ],
            'line_numbers' => [
                'type' => 'boolean',
                'description' => 'Prepend line numbers to each line (default true)',
                'default' => true,
            ],
        ];
    }

    public function getInputExamples(): array {
        return [
            ['plugin_slug' => 'my-plugin', 'relative_path' => 'my-plugin.php'],
            ['plugin_slug' => 'my-plugin', 'relative_path' => 'includes/class-settings.php', 'max_bytes' => 30000],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_plugins') || current_user_can('install_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');
        $maxBytes = (int) ($params['max_bytes'] ?? self::DEFAULT_MAX_BYTES);
        $offsetBytes = (int) ($params['offset_bytes'] ?? 0);
        $lineNumbers = (bool) ($params['line_numbers'] ?? true);

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'plugin_slug and relative_path are required.'];
        }
        if (str_contains($relativePath, '..')) {
            return ['success' => false, 'error' => 'Path traversal is not allowed.'];
        }
        $maxBytes = max(1, min($maxBytes, self::ABSOLUTE_MAX_BYTES));
        $offsetBytes = max(0, $offsetBytes);

        $pluginRoot = trailingslashit(WP_PLUGIN_DIR) . $slug;
        if (!is_dir($pluginRoot)) {
            $resolved = $this->resolvePluginDirectory($slug);
            if ($resolved !== null) {
                $pluginRoot = $resolved;
                $slug = basename($resolved);
            } else {
                return [
                    'success' => false,
                    'error' => 'Plugin directory does not exist.',
                    'suggestion' => 'Use get_plugins first to find the correct plugin_slug (directory name).',
                ];
            }
        }

        $targetPath = $pluginRoot . '/' . $relativePath;
        if (!is_file($targetPath)) {
            return ['success' => false, 'error' => 'File does not exist.'];
        }

        $pluginRootReal = realpath($pluginRoot);
        $targetPathReal = realpath($targetPath);
        if ($pluginRootReal === false || $targetPathReal === false || !str_starts_with($targetPathReal, $pluginRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside plugin directory.'];
        }

        $size = filesize($targetPathReal);
        if ($size === false) {
            return ['success' => false, 'error' => 'Could not determine file size.'];
        }

        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $meta = [
            'file_extension' => $ext,
            'is_binary' => false,
            'is_minified' => false,
            'line_count' => null,
        ];

        // Binary detection: check first bytes for null bytes
        if ($size > 0 && $offsetBytes === 0) {
            $probe = file_get_contents($targetPathReal, false, null, 0, self::BINARY_PROBE_BYTES);
            if ($probe !== false && str_contains($probe, "\0")) {
                $meta['is_binary'] = true;
                return [
                    'success' => true,
                    'plugin_slug' => $slug,
                    'relative_path' => $relativePath,
                    'size' => $size,
                    'meta' => $meta,
                    'content' => '[Binary file — content not returned. Size: ' . $size . ' bytes, extension: .' . $ext . ']',
                    'has_more' => false,
                    'truncated' => false,
                ];
            }
        }

        if ($offsetBytes >= $size) {
            return [
                'success' => true,
                'plugin_slug' => $slug,
                'relative_path' => $relativePath,
                'size' => $size,
                'meta' => $meta,
                'offset_bytes' => $offsetBytes,
                'next_offset_bytes' => $offsetBytes,
                'has_more' => false,
                'truncated' => false,
                'content' => '',
            ];
        }

        $content = file_get_contents($targetPathReal, false, null, $offsetBytes, $maxBytes);
        if ($content === false) {
            return ['success' => false, 'error' => 'Could not read file content.'];
        }

        $bytesRead = strlen($content);
        $nextOffset = $offsetBytes + $bytesRead;

        // Minified detection for JS/CSS/JSON
        if (in_array($ext, self::MINIFIED_EXTENSIONS, true) && $offsetBytes === 0) {
            $lines = explode("\n", $content);
            $lineCount = count($lines);
            $meta['line_count'] = $lineCount;
            if ($lineCount > 0) {
                $avgLen = mb_strlen($content) / $lineCount;
                if ($avgLen > self::MINIFIED_AVG_LINE_THRESHOLD && $size > self::MINIFIED_CAP_BYTES) {
                    $meta['is_minified'] = true;
                    $content = mb_substr($content, 0, self::MINIFIED_CAP_BYTES);
                    $bytesRead = strlen($content);
                    $nextOffset = $offsetBytes + $bytesRead;
                    $content .= "\n...[minified file truncated — original " . $size . " bytes, avg line length " . (int) $avgLen . " chars]";
                }
            }
        }

        // Line count for text files (when not already computed)
        if ($meta['line_count'] === null && $offsetBytes === 0) {
            $meta['line_count'] = substr_count($content, "\n") + 1;
        }

        // Add line numbers
        if ($lineNumbers && !$meta['is_binary'] && !$meta['is_minified']) {
            $content = $this->addLineNumbers($content, $offsetBytes === 0 ? 1 : null, $targetPathReal, $offsetBytes);
        }

        return [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_path' => $relativePath,
            'size' => $size,
            'meta' => $meta,
            'offset_bytes' => $offsetBytes,
            'next_offset_bytes' => $nextOffset,
            'has_more' => $nextOffset < $size,
            'truncated' => $nextOffset < $size,
            'content' => $content,
        ];
    }

    private function addLineNumbers(string $content, ?int $startLine, string $filePath, int $offsetBytes): string {
        $lines = explode("\n", $content);
        if ($startLine === null && $offsetBytes > 0) {
            $before = file_get_contents($filePath, false, null, 0, $offsetBytes);
            $startLine = $before !== false ? substr_count($before, "\n") + 1 : 1;
        }
        $start = $startLine ?? 1;
        $totalLines = $start + count($lines) - 1;
        $pad = max(3, strlen((string) $totalLines));

        $numbered = [];
        foreach ($lines as $i => $line) {
            $num = str_pad((string) ($start + $i), $pad, ' ', STR_PAD_LEFT);
            $numbered[] = $num . '| ' . $line;
        }
        return implode("\n", $numbered);
    }

    private function resolvePluginDirectory(string $requestedSlug): ?string {
        if (!is_dir(WP_PLUGIN_DIR)) {
            return null;
        }
        $entries = scandir(WP_PLUGIN_DIR);
        if ($entries === false) {
            return null;
        }
        $normalized = $this->normalizeSlug($requestedSlug);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir(WP_PLUGIN_DIR . '/' . $entry)) {
                continue;
            }
            if ($entry === $requestedSlug) {
                continue;
            }
            if ($this->normalizeSlug($entry) === $normalized) {
                return WP_PLUGIN_DIR . '/' . $entry;
            }
        }
        return null;
    }

    private function normalizeSlug(string $slug): string {
        return strtolower(str_replace(['-', '_'], '', $slug));
    }
}
