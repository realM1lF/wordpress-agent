<?php

namespace Levi\Agent\AI\Tools;

class DeleteThemeFileTool implements ToolInterface {

    public function getName(): string {
        return 'delete_theme_file';
    }

    public function getDescription(): string {
        return 'Delete a single file inside a theme directory. '
            . 'Cannot delete the main style.css which is required by WordPress. '
            . 'Use this to remove unused templates, old CSS, or PHP includes. '
            . 'To remove an entire theme, deactivate it first via switch_theme.';
    }

    public function getParameters(): array {
        return [
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Theme slug (directory in wp-content/plugins)',
                'required' => true,
            ],
            'relative_path' => [
                'type' => 'string',
                'description' => 'File path inside plugin to delete',
                'required' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_themes') || current_user_can('switch_themes');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['theme_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'theme_slug and relative_path are required.'];
        }
        if (str_contains($relativePath, '..')) {
            return ['success' => false, 'error' => 'Path traversal is not allowed.'];
        }

        $themeRoot = trailingslashit(get_theme_root()) . $slug;
        if (!is_dir($themeRoot)) {
            $resolved = $this->resolveThemeDirectory($slug);
            if ($resolved !== null) {
                $themeRoot = $resolved;
                $slug = basename($resolved);
            } else {
                return [
                    'success' => false,
                    'error' => 'Theme directory does not exist.',
                    'suggestion' => 'Use get_themes to list installed themes and find the correct theme_slug.',
                ];
            }
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

        $filesystem = $this->getFilesystem();
        if ($filesystem === null) {
            return ['success' => false, 'error' => 'WordPress filesystem is not available.'];
        }

        $deleted = $filesystem->delete($targetPathReal, false, 'f');
        if (!$deleted) {
            return ['success' => false, 'error' => 'Could not delete file via WordPress filesystem.'];
        }

        return [
            'success' => true,
            'theme_slug' => $slug,
            'relative_path' => $relativePath,
            'message' => 'Theme file deleted.',
        ];
    }

    private function resolveThemeDirectory(string $requestedSlug): ?string {
        $themesDir = get_theme_root();
        if (!is_dir($themesDir)) {
            return null;
        }
        $entries = scandir($themesDir);
        if ($entries === false) {
            return null;
        }
        $normalized = $this->normalizeSlug($requestedSlug);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($themesDir . '/' . $entry)) {
                continue;
            }
            if ($entry === $requestedSlug) {
                continue;
            }
            if ($this->normalizeSlug($entry) === $normalized) {
                return $themesDir . '/' . $entry;
            }
        }
        return null;
    }

    private function normalizeSlug(string $slug): string {
        return strtolower(str_replace(['-', '_'], '', $slug));
    }

    private function getFilesystem(): ?\WP_Filesystem_Base {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem()) {
            return null;
        }

        global $wp_filesystem;
        if (!($wp_filesystem instanceof \WP_Filesystem_Base)) {
            return null;
        }

        return $wp_filesystem;
    }
}
