<?php

namespace Levi\Agent\AI\Tools;

class PatchPluginFileTool implements ToolInterface {

    public function getName(): string {
        return 'patch_plugin_file';
    }

    public function getDescription(): string {
        return 'Apply targeted search-and-replace patches to an existing plugin file. '
            . 'Much faster than write_plugin_file for small changes (rename, bugfix, value change). '
            . 'Use write_plugin_file only for new files or complete rewrites. '
            . 'Each replacement must have a unique "search" string that appears exactly once in the file.';
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
                'description' => 'Relative file path inside the plugin, e.g. "figur-musik-rabatt.php"',
                'required' => true,
            ],
            'replacements' => [
                'type' => 'array',
                'description' => 'Array of {search, replace} objects. Each "search" string must appear exactly once in the file. '
                    . 'Example: [{"search": "Version: 1.0.0", "replace": "Version: 1.1.0"}]',
                'required' => true,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Exact text to find (must occur exactly once)'],
                        'replace' => ['type' => 'string', 'description' => 'Replacement text'],
                    ],
                ],
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('edit_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');
        $replacements = $params['replacements'] ?? [];

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'plugin_slug and relative_path are required.'];
        }
        if (str_contains($relativePath, '..')) {
            return ['success' => false, 'error' => 'Path traversal is not allowed.'];
        }
        if (!is_array($replacements) || empty($replacements)) {
            return ['success' => false, 'error' => 'replacements array is required and must not be empty.'];
        }
        if (count($replacements) > 50) {
            return ['success' => false, 'error' => 'Too many replacements (max 50). Use write_plugin_file for large rewrites.'];
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
                    'error' => 'Plugin directory does not exist.',
                    'suggestion' => 'Use get_plugins first to find the correct plugin_slug.',
                ];
            }
        }

        $targetPath = $pluginRoot . '/' . $relativePath;
        if (!is_file($targetPath)) {
            return ['success' => false, 'error' => 'File does not exist. Use write_plugin_file to create new files.'];
        }

        $pluginRootReal = realpath($pluginRoot);
        $targetPathReal = realpath($targetPath);
        if ($pluginRootReal === false || $targetPathReal === false || !str_starts_with($targetPathReal, $pluginRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside plugin directory.'];
        }

        $filesystem = $this->getFilesystem();
        if ($filesystem === null) {
            return ['success' => false, 'error' => 'WordPress filesystem is not available.'];
        }

        $originalContent = $filesystem->get_contents($targetPath);
        if (!is_string($originalContent)) {
            return ['success' => false, 'error' => 'Could not read existing file content.'];
        }

        $content = $originalContent;
        $applied = [];
        $errors = [];

        foreach ($replacements as $i => $replacement) {
            if (!is_array($replacement)) {
                $errors[] = "Replacement #$i: not a valid object.";
                continue;
            }

            $search = (string) ($replacement['search'] ?? '');
            $replace = (string) ($replacement['replace'] ?? '');

            if ($search === '') {
                $errors[] = "Replacement #$i: 'search' must not be empty.";
                continue;
            }

            $count = substr_count($content, $search);
            if ($count === 0) {
                $errors[] = "Replacement #$i: search string not found in file. Preview: '" . mb_substr($search, 0, 60) . "'";
                continue;
            }
            if ($count > 1) {
                $errors[] = "Replacement #$i: search string is ambiguous ($count occurrences). Make the search string more specific.";
                continue;
            }

            $content = str_replace($search, $replace, $content);
            $applied[] = [
                'index' => $i,
                'search_preview' => mb_substr($search, 0, 80),
                'replace_preview' => mb_substr($replace, 0, 80),
            ];
        }

        if (!empty($errors) && empty($applied)) {
            return [
                'success' => false,
                'error' => 'No replacements could be applied.',
                'details' => $errors,
            ];
        }

        if ($content === $originalContent) {
            return [
                'success' => true,
                'plugin_slug' => $slug,
                'relative_path' => $relativePath,
                'patches_applied' => 0,
                'message' => 'No changes needed — file content unchanged.',
            ];
        }

        $written = $filesystem->put_contents($targetPath, $content, FS_CHMOD_FILE);
        if (!$written) {
            return ['success' => false, 'error' => 'Could not write patched file content.'];
        }

        $lint = $this->validatePhpSyntax($targetPath);
        if (($lint['valid'] ?? false) !== true) {
            $filesystem->put_contents($targetPath, $originalContent, FS_CHMOD_FILE);
            return [
                'success' => false,
                'error' => 'Patch reverted: PHP syntax check failed after patching. ' . ($lint['error'] ?? 'Unknown lint error.'),
                'patches_attempted' => $applied,
            ];
        }

        $result = [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_path' => $relativePath,
            'patches_applied' => count($applied),
            'patches' => $applied,
            'message' => count($applied) . ' replacement(s) applied successfully.',
        ];

        if (!empty($errors)) {
            $result['partial_errors'] = $errors;
        }
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

    private function validatePhpSyntax(string $path): array {
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            return ['valid' => true];
        }
        if (!function_exists('exec')) {
            return ['valid' => true, 'warning' => 'PHP lint skipped (exec unavailable).'];
        }
        $output = [];
        $exitCode = 0;
        @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            return ['valid' => false, 'error' => trim(implode("\n", $output)) ?: 'PHP lint failed.'];
        }
        return ['valid' => true];
    }
}
