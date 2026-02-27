<?php

namespace Levi\Agent\AI\Tools;

class ReadPluginFileTool implements ToolInterface {

    public function getName(): string {
        return 'read_plugin_file';
    }

    public function getDescription(): string {
        return 'Read a file from a plugin directory. Returns file content to help code understanding before updates.';
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
                'description' => 'Maximum bytes to return (default 250000, max 2000000)',
                'default' => 250000,
            ],
            'offset_bytes' => [
                'type' => 'integer',
                'description' => 'Optional byte offset to continue reading large files',
                'default' => 0,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_plugins') || current_user_can('install_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');
        $maxBytes = (int) ($params['max_bytes'] ?? 250000);
        $offsetBytes = (int) ($params['offset_bytes'] ?? 0);

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'plugin_slug and relative_path are required.'];
        }
        if (str_contains($relativePath, '..')) {
            return ['success' => false, 'error' => 'Path traversal is not allowed.'];
        }
        if ($maxBytes < 1) {
            $maxBytes = 1;
        }
        if ($maxBytes > 2000000) {
            $maxBytes = 2000000;
        }
        if ($offsetBytes < 0) {
            $offsetBytes = 0;
        }

        $pluginRoot = trailingslashit(WP_PLUGIN_DIR) . $slug;
        if (!is_dir($pluginRoot)) {
            return ['success' => false, 'error' => 'Plugin directory does not exist.'];
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

        if ($offsetBytes >= $size) {
            return [
                'success' => true,
                'plugin_slug' => $slug,
                'relative_path' => $relativePath,
                'size' => $size,
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

        return [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_path' => $relativePath,
            'size' => $size,
            'offset_bytes' => $offsetBytes,
            'next_offset_bytes' => $nextOffset,
            'has_more' => $nextOffset < $size,
            'truncated' => $nextOffset < $size,
            'content' => $content,
        ];
    }
}
