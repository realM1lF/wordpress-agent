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

        $jsLint = $this->validateInlineJavaScript($targetPath);
        $fileExt = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        if (($jsLint['valid'] ?? true) === false && $fileExt === 'js') {
            $filesystem->put_contents($targetPath, $originalContent, FS_CHMOD_FILE);
            return [
                'success' => false,
                'error' => 'Patch reverted: ' . ($jsLint['error'] ?? 'JavaScript syntax check failed.'),
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
        if (($jsLint['valid'] ?? true) === false) {
            $result['js_error'] = $jsLint['error']
                . ' The patch was applied but the file likely contains broken frontend JavaScript. Please fix the syntax error.';
        }
        if (!empty($jsLint['warning'] ?? '')) {
            $result['js_warning'] = $jsLint['warning'];
        }

        $codeTagCheck = $this->detectCodeTagsInOutput($newContent, $relativePath);
        if ($codeTagCheck !== null) {
            $result['code_tag_warning'] = $codeTagCheck;
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

    private function detectCodeTagsInOutput(string $content, string $relativePath): ?string {
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if ($ext !== 'php') {
            return null;
        }

        if (stripos($content, '<code') === false && stripos($content, '</code>') === false) {
            return null;
        }

        $found = false;

        if (preg_match('/(?:return|echo|print)\s+[\'"].*?<\/?code[\s>]/is', $content)) {
            $found = true;
        }

        if (!$found && preg_match('/[\'"].*?<\/?code[\s>].*?[\'"]\s*[;.]/is', $content)) {
            $found = true;
        }

        if (!$found && preg_match('/<<<[\'"]?(?:HTML|EOT|HEREDOC|EOF)[\'"]?\s*\n.*?<\/?code[\s>].*?\n\w+;/is', $content)) {
            $found = true;
        }

        if (!$found) {
            return null;
        }

        return 'ACHTUNG: Die Datei enthaelt <code>-Tags in HTML-Output-Strings. '
            . '<code>-Tags verhindern, dass CSS-Styles greifen und zeigen Inhalte als Monospace-Text an. '
            . 'Entferne alle <code> und </code> Tags aus dem HTML-Output sofort mit patch_plugin_file. '
            . 'HTML-Elemente wie <div>, <h3>, <span> sind Render-Output, kein "Code zum Anzeigen".';
    }
}
