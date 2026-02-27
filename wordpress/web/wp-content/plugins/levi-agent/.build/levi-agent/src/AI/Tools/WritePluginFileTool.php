<?php

namespace Levi\Agent\AI\Tools;

class WritePluginFileTool implements ToolInterface {

    public function getName(): string {
        return 'write_plugin_file';
    }

    public function getDescription(): string {
        return 'Write or overwrite a file inside a plugin directory. Useful for creating plugin PHP, JS, CSS, and template files.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Target plugin slug (directory in wp-content/plugins)',
                'required' => true,
            ],
            'relative_path' => [
                'type' => 'string',
                'description' => 'Relative file path inside the plugin, e.g. "includes/class-api.php"',
                'required' => true,
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Full file content to write',
                'required' => true,
            ],
            'create_dirs' => [
                'type' => 'boolean',
                'description' => 'Create missing directories automatically',
                'default' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('edit_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');
        $content = (string) ($params['content'] ?? '');
        $createDirs = (bool) ($params['create_dirs'] ?? true);

        if ($slug === '' || $relativePath === '') {
            return [
                'success' => false,
                'error' => 'plugin_slug and relative_path are required.',
            ];
        }

        // Prevent path traversal
        if (str_contains($relativePath, '..')) {
            return [
                'success' => false,
                'error' => 'Path traversal is not allowed.',
            ];
        }

        $pluginRoot = trailingslashit(WP_PLUGIN_DIR) . $slug;
        if (!is_dir($pluginRoot)) {
            return [
                'success' => false,
                'error' => 'Plugin directory does not exist. Create plugin first.',
            ];
        }

        $targetPath = $pluginRoot . '/' . $relativePath;
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir)) {
            if (!$createDirs || !wp_mkdir_p($targetDir)) {
                return [
                    'success' => false,
                    'error' => 'Target directory does not exist and could not be created.',
                ];
            }
        }

        $pluginRootReal = realpath($pluginRoot);
        $targetDirReal = realpath($targetDir);
        if ($pluginRootReal === false || $targetDirReal === false || !str_starts_with($targetDirReal, $pluginRootReal)) {
            return [
                'success' => false,
                'error' => 'Resolved path is outside plugin directory.',
            ];
        }

        $filesystem = $this->getFilesystem();
        if ($filesystem === null) {
            return [
                'success' => false,
                'error' => 'WordPress filesystem is not available.',
            ];
        }
        $hadExistingFile = $filesystem->exists($targetPath);
        $previousContent = null;
        if ($hadExistingFile) {
            $previousContent = $filesystem->get_contents($targetPath);
            if (!is_string($previousContent)) {
                return [
                    'success' => false,
                    'error' => 'Could not read existing file content for safety backup.',
                ];
            }
        }
        $written = $filesystem->put_contents($targetPath, $content, FS_CHMOD_FILE);
        if (!$written) {
            return [
                'success' => false,
                'error' => 'Could not write file content via WordPress filesystem.',
            ];
        }
        $lint = $this->validatePhpSyntax($targetPath);
        if (($lint['valid'] ?? false) !== true) {
            if ($hadExistingFile && is_string($previousContent)) {
                $filesystem->put_contents($targetPath, $previousContent, FS_CHMOD_FILE);
            } else {
                $filesystem->delete($targetPath, false, 'f');
            }
            return [
                'success' => false,
                'error' => 'Write reverted: PHP syntax check failed. ' . ($lint['error'] ?? 'Unknown lint error.'),
            ];
        }
        $bytes = strlen($content);

        $result = [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_path' => $relativePath,
            'bytes_written' => $bytes,
            'message' => 'Plugin file written successfully.',
        ];
        if (!empty($lint['warning'])) {
            $result['warning'] = $lint['warning'];
        }
        return $result;
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

    private function validatePhpSyntax(string $path): array {
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            return ['valid' => true];
        }

        if (!function_exists('exec')) {
            return [
                'valid' => true,
                'warning' => 'PHP lint skipped (exec unavailable).',
            ];
        }

        $output = [];
        $exitCode = 0;
        @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            return [
                'valid' => false,
                'error' => trim(implode("\n", $output)) ?: 'PHP lint failed.',
            ];
        }

        return ['valid' => true];
    }
}
