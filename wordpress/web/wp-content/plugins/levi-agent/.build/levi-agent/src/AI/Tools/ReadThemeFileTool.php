<?php

namespace Levi\Agent\AI\Tools;

class ReadThemeFileTool implements ToolInterface {

    public function getName(): string {
        return 'read_theme_file';
    }

    public function getDescription(): string {
        return 'Read a file from a theme directory. Returns file content to help understand templates, CSS, or PHP before editing.';
    }

    public function getParameters(): array {
        return [
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Theme slug (directory in wp-content/themes)',
                'required' => true,
            ],
            'relative_path' => [
                'type' => 'string',
                'description' => 'File path inside theme, e.g. style.css, functions.php, template-parts/header.php',
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
        return current_user_can('edit_themes') || current_user_can('switch_themes');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['theme_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');
        $maxBytes = (int) ($params['max_bytes'] ?? 250000);
        $offsetBytes = (int) ($params['offset_bytes'] ?? 0);

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'theme_slug and relative_path are required.'];
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

        $themeRoot = trailingslashit(get_theme_root()) . $slug;
        if (!is_dir($themeRoot)) {
            return ['success' => false, 'error' => 'Theme directory does not exist.'];
        }

        $targetPath = $themeRoot . '/' . $relativePath;
        if (!is_file($targetPath)) {
            return ['success' => false, 'error' => 'File does not exist.'];
        }

        $themeRootReal = realpath($themeRoot);
        $targetPathReal = realpath($targetPath);
        if ($themeRootReal === false || $targetPathReal === false || !str_starts_with($targetPathReal, $themeRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside theme directory.'];
        }

        $size = filesize($targetPathReal);
        if ($size === false) {
            return ['success' => false, 'error' => 'Could not determine file size.'];
        }

        if ($offsetBytes >= $size) {
            return [
                'success' => true,
                'theme_slug' => $slug,
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
            'theme_slug' => $slug,
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
