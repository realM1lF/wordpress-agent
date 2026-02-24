<?php

namespace Levi\Agent\AI\Tools;

class DeletePluginFileTool implements ToolInterface {

    public function getName(): string {
        return 'delete_plugin_file';
    }

    public function getDescription(): string {
        return 'Delete a file inside a plugin directory.';
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
                'description' => 'File path inside plugin to delete',
                'required' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_plugins') || current_user_can('install_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'plugin_slug and relative_path are required.'];
        }
        if (str_contains($relativePath, '..')) {
            return ['success' => false, 'error' => 'Path traversal is not allowed.'];
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

        $deleted = @unlink($targetPathReal);
        if (!$deleted) {
            return ['success' => false, 'error' => 'Could not delete file.'];
        }

        return [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_path' => $relativePath,
            'message' => 'Plugin file deleted.',
        ];
    }
}
