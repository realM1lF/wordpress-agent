<?php

namespace Levi\Agent\AI\Tools;

class WriteThemeFileTool implements ToolInterface {

    public function getName(): string {
        return 'write_theme_file';
    }

    public function getDescription(): string {
        return 'Write or overwrite a file inside a theme directory. Useful for creating theme PHP, JS, CSS, and template files.';
    }

    public function getParameters(): array {
        return [
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Target theme slug (directory in wp-content/themes)',
                'required' => true,
            ],
            'relative_path' => [
                'type' => 'string',
                'description' => 'Relative file path inside the theme, e.g. "style.css", "functions.php", "template-parts/header.php"',
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
        return current_user_can('edit_themes') || current_user_can('switch_themes');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['theme_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');
        $content = (string) ($params['content'] ?? '');
        $createDirs = (bool) ($params['create_dirs'] ?? true);

        if ($slug === '' || $relativePath === '') {
            return [
                'success' => false,
                'error' => 'theme_slug and relative_path are required.',
            ];
        }

        // Prevent path traversal
        if (str_contains($relativePath, '..')) {
            return [
                'success' => false,
                'error' => 'Path traversal is not allowed.',
            ];
        }

        $themeRoot = trailingslashit(get_theme_root()) . $slug;
        if (!is_dir($themeRoot)) {
            return [
                'success' => false,
                'error' => 'Theme directory does not exist. Create theme first.',
            ];
        }

        $targetPath = $themeRoot . '/' . $relativePath;
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir)) {
            if (!$createDirs || !wp_mkdir_p($targetDir)) {
                return [
                    'success' => false,
                    'error' => 'Target directory does not exist and could not be created.',
                ];
            }
        }

        $themeRootReal = realpath($themeRoot);
        $targetDirReal = realpath($targetDir);
        if ($themeRootReal === false || $targetDirReal === false || !str_starts_with($targetDirReal, $themeRootReal)) {
            return [
                'success' => false,
                'error' => 'Resolved path is outside theme directory.',
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
            'theme_slug' => $slug,
            'relative_path' => $relativePath,
            'bytes_written' => $bytes,
            'message' => 'Theme file written successfully.',
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
