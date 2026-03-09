<?php

namespace Levi\Agent\AI\Tools;

class WritePluginFileTool implements ToolInterface {

    public function getName(): string {
        return 'write_plugin_file';
    }

    public function getDescription(): string {
        return 'Write or overwrite a file inside a plugin directory. Useful for creating plugin PHP, JS, CSS, and template files. '
            . 'For small changes (rename, bugfix, value tweak) prefer patch_plugin_file instead — it is much faster.';
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
            $resolved = $this->resolvePluginDirectory($slug);
            if ($resolved !== null) {
                $pluginRoot = $resolved;
                $slug = basename($resolved);
            } else {
                return [
                    'success' => false,
                    'error' => 'Plugin directory does not exist. Create plugin first.',
                    'suggestion' => 'Use get_plugins first to find the correct plugin_slug (directory name).',
                ];
            }
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

        $jsLint = $this->validateInlineJavaScript($targetPath);
        $fileExt = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        if (($jsLint['valid'] ?? true) === false && $fileExt === 'js') {
            if ($hadExistingFile && is_string($previousContent)) {
                $filesystem->put_contents($targetPath, $previousContent, FS_CHMOD_FILE);
            } else {
                $filesystem->delete($targetPath, false, 'f');
            }
            return [
                'success' => false,
                'error' => 'Write reverted: ' . ($jsLint['error'] ?? 'JavaScript syntax check failed.'),
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
        if (($jsLint['valid'] ?? true) === false) {
            $result['js_error'] = $jsLint['error']
                . ' The file was saved but likely contains broken frontend JavaScript. Please fix the syntax error.';
        }
        if (!empty($jsLint['warning'] ?? '')) {
            $result['js_warning'] = $jsLint['warning'];
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

    private function validateInlineJavaScript(string $path): array {
        if (!function_exists('exec')) {
            return ['valid' => true, 'warning' => 'JS validation skipped (exec unavailable).'];
        }

        $exitCode = 0;
        @exec('which node 2>/dev/null', $whichOut, $exitCode);
        if ($exitCode !== 0) {
            return ['valid' => true, 'warning' => 'JS validation skipped (node not found).'];
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'js') {
            $output = [];
            @exec('node --check ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                return [
                    'valid' => false,
                    'error' => 'JavaScript syntax error: ' . $this->extractNodeError($output),
                ];
            }
            return ['valid' => true];
        }

        if ($ext !== 'php') {
            return ['valid' => true];
        }

        $content = file_get_contents($path);
        if ($content === false || !str_contains($content, '<script')) {
            return ['valid' => true];
        }

        if (!preg_match_all('/<script(\s[^>]*)?>(.+?)<\/script>/si', $content, $matches)) {
            return ['valid' => true];
        }

        $errors = [];
        foreach ($matches[2] as $i => $jsBlock) {
            $attrs = $matches[1][$i] ?? '';
            if ($attrs !== '' && preg_match('/type\s*=\s*["\'](.*?)["\']/i', $attrs, $typeMatch)) {
                $type = strtolower(trim($typeMatch[1]));
                if ($type !== '' && $type !== 'text/javascript' && $type !== 'module') {
                    continue;
                }
            }

            $js = trim($jsBlock);
            if (strlen($js) < 20) {
                continue;
            }

            $cleaned = preg_replace('/<\?(?:php|=)\s.*?\?>/s', '0', $js);
            if (substr_count($cleaned, '0') - substr_count($js, '0') > 8) {
                continue;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'levi_js_');
            if ($tmpFile === false) {
                continue;
            }
            file_put_contents($tmpFile, $cleaned);
            $output = [];
            $exitCode = 0;
            @exec('node --check ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
            @unlink($tmpFile);

            if ($exitCode !== 0) {
                $errors[] = 'Script block #' . ($i + 1) . ': ' . $this->extractNodeError($output);
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'error' => 'JavaScript syntax error(s): ' . implode(' | ', $errors),
            ];
        }

        return ['valid' => true];
    }

    private function extractNodeError(array $output): string {
        foreach ($output as $line) {
            if (str_contains($line, 'SyntaxError')) {
                return trim($line);
            }
        }
        $filtered = array_filter($output, fn($l) => trim($l) !== '' && !str_starts_with(trim($l), 'at ') && !str_starts_with(trim($l), 'Node.js'));
        return implode(' — ', array_slice($filtered, 0, 3)) ?: 'Unknown JS error.';
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
