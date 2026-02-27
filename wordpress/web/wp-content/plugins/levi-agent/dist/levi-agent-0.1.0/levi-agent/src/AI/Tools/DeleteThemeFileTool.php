<?php

namespace Levi\Agent\AI\Tools;

class DeleteThemeFileTool implements ToolInterface {

    public function getName(): string {
        return 'delete_theme_file';
    }

    public function getDescription(): string {
        return 'Delete a file inside a theme directory.';
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
