<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\SanitizesHtmlOutput;
use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
use Levi\Agent\AI\Tools\Concerns\ResolvesPluginPaths;

class PatchPluginFileTool extends AbstractTool {

    use SanitizesHtmlOutput;
    use ValidatesSyntax;
    use ResolvesPluginPaths;

    public function getName(): string {
        return 'patch_plugin_file';
    }

    public function getDescription(): string {
        return 'Apply targeted search-and-replace patches to an existing plugin file. '
            . 'Much faster than write_plugin_file for small changes (rename, bugfix, value change). '
            . 'Each replacement must have a unique "search" string that appears exactly once in the file. '
            . 'Automatically validates PHP syntax after patching and rolls back on errors.';
    }

    public function getInputExamples(): array {
        return [
            ['plugin_slug' => 'my-plugin', 'file' => 'my-plugin.php', 'replacements' => [['search' => '$price = 10;', 'replace' => '$price = 15;']]],
        ];
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
                'description' => 'Relative file path inside the plugin',
                'required' => true,
            ],
            'replacements' => [
                'type' => 'array',
                'description' => 'Array of {search, replace} objects. Each "search" must appear exactly once in the file.',
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
        if (!is_array($replacements) || empty($replacements)) {
            return ['success' => false, 'error' => 'replacements array is required and must not be empty.'];
        }
        if (count($replacements) > 50) {
            return ['success' => false, 'error' => 'Too many replacements (max 50). Use write_plugin_file for large rewrites.'];
        }

        $resolved = $this->resolvePluginRoot($slug);
        if (isset($resolved['error'])) {
            return ['success' => false] + $resolved;
        }
        $pluginRoot = $resolved['root'];
        $slug = $resolved['slug'];

        $pathCheck = $this->validatePluginFilePath($pluginRoot, $relativePath);
        if (isset($pathCheck['error'])) {
            return ['success' => false, 'error' => $pathCheck['error']];
        }
        $targetPath = $pathCheck['target_path'];

        if (!is_file($targetPath)) {
            return [
                'success' => false,
                'error' => 'File does not exist.',
                'suggestion' => 'Use write_plugin_file to create new files.',
            ];
        }

        $targetPathReal = realpath($targetPath);
        $pluginRootReal = realpath($pluginRoot);
        if ($targetPathReal === false || $pluginRootReal === false || !str_starts_with($targetPathReal, $pluginRootReal)) {
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
                $errors[] = "Replacement #$i: search string not found. Preview: '" . mb_substr($search, 0, 60) . "'";
                continue;
            }
            if ($count > 1) {
                $errors[] = "Replacement #$i: search string is ambiguous ($count occurrences). Make it more specific.";
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

        [$content, $strippedCount] = $this->stripCodeTagsFromOutput($content, $relativePath);

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
                'error' => 'Patch reverted: PHP syntax check failed. ' . ($lint['error'] ?? 'Unknown lint error.'),
                'patches_attempted' => $applied,
                'suggestion' => 'The replacement may have broken the PHP syntax. Read the file first with read_plugin_file, then apply a corrected patch.',
            ];
        }

        $jsLint = $this->validateJsSyntax($targetPath);
        $fileExt = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        if (($jsLint['valid'] ?? true) === false && $fileExt === 'js') {
            $filesystem->put_contents($targetPath, $originalContent, FS_CHMOD_FILE);
            return [
                'success' => false,
                'error' => 'Patch reverted: ' . ($jsLint['error'] ?? 'JavaScript syntax check failed.'),
                'patches_attempted' => $applied,
            ];
        }

        if (preg_match('/\.php$/i', $relativePath)) {
            wp_cache_delete('plugins', 'plugins');
        }

        $result = [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_path' => $relativePath,
            'patches_applied' => count($applied),
            'patches' => $applied,
            'message' => count($applied) . ' replacement(s) applied successfully.',
        ];

        $result += $this->buildReadBackData($filesystem, $targetPath);

        if (!empty($errors)) {
            $result['partial_errors'] = $errors;
        }
        if (!empty($lint['warning'])) {
            $result['warning'] = $lint['warning'];
        }
        if (($jsLint['valid'] ?? true) === false) {
            $result['js_error'] = $jsLint['error']
                . ' The patch was applied but the file likely contains broken frontend JavaScript.';
        }
        if (!empty($jsLint['warning'] ?? '')) {
            $result['js_warning'] = $jsLint['warning'];
        }
        if ($strippedCount > 0) {
            $result['stripped_tags'] = $strippedCount;
            $result['strip_notice'] = "$strippedCount <code>/<pre>-Tag(s) wurden automatisch entfernt.";
        }

        $codeTagCheck = $this->detectCodeTagsInOutput($content, $relativePath);
        if ($codeTagCheck !== null) {
            $result['code_tag_warning'] = $codeTagCheck;
        }

        return $result;
    }
}
