<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\SanitizesHtmlOutput;
use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
use Levi\Agent\AI\Tools\Concerns\ResolvesPluginPaths;

class WritePluginFileTool extends AbstractTool {

    use SanitizesHtmlOutput;
    use ValidatesSyntax;
    use ResolvesPluginPaths;

    public function getName(): string {
        return 'write_plugin_file';
    }

    public function getDescription(): string {
        return 'Write or overwrite a file inside a plugin directory. '
            . 'The plugin header in the main file (slug.php) is automatically preserved — '
            . 'you only need to provide the code body. '
            . 'For small changes prefer patch_plugin_file instead.';
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
                'description' => 'File content to write. For the main plugin file, the existing header block (Plugin Name, Version, etc.) is preserved automatically.',
                'required' => true,
            ],
            'create_dirs' => [
                'type' => 'boolean',
                'description' => 'Create missing directories automatically',
                'default' => true,
            ],
            'preserve_header' => [
                'type' => 'boolean',
                'description' => 'Keep the existing plugin header when writing the main file. Only set to false if you need to update metadata like version or description.',
                'default' => true,
            ],
        ];
    }

    public function getInputExamples(): array {
        return [
            ['plugin_slug' => 'my-plugin', 'relative_path' => 'my-plugin.php', 'content' => '<?php\n// Main plugin logic...'],
            ['plugin_slug' => 'my-plugin', 'relative_path' => 'includes/class-frontend.php', 'content' => '<?php\nclass My_Plugin_Frontend { ... }'],
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
        $preserveHeader = (bool) ($params['preserve_header'] ?? true);

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'plugin_slug and relative_path are required.'];
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
        $targetDir = $pathCheck['target_dir'];

        if (!is_dir($targetDir)) {
            if (!$createDirs || !wp_mkdir_p($targetDir)) {
                return ['success' => false, 'error' => 'Target directory does not exist and could not be created.'];
            }
        }

        $targetDirReal = realpath($targetDir);
        $pluginRootReal = realpath($pluginRoot);
        if ($targetDirReal === false || $pluginRootReal === false || !str_starts_with($targetDirReal, $pluginRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside plugin directory.'];
        }

        [$content, $strippedCount] = $this->stripCodeTagsFromOutput($content, $relativePath);

        $filesystem = $this->getFilesystem();
        if ($filesystem === null) {
            return ['success' => false, 'error' => 'WordPress filesystem is not available.'];
        }

        $hadExistingFile = $filesystem->exists($targetPath);
        $previousContent = null;
        if ($hadExistingFile) {
            $previousContent = $filesystem->get_contents($targetPath);
            if (!is_string($previousContent)) {
                return ['success' => false, 'error' => 'Could not read existing file content for safety backup.'];
            }
        }

        // --- Header protection for main plugin file ---
        $headerProtected = false;
        if ($this->isMainPluginFile($slug, $relativePath) && $preserveHeader && is_string($previousContent)) {
            $merged = $this->applyHeaderProtection($previousContent, $content);
            if ($merged !== null) {
                $content = $merged;
                $headerProtected = true;
            }
        }

        $written = $filesystem->put_contents($targetPath, $content, FS_CHMOD_FILE);
        if (!$written) {
            $this->rollbackWrite($filesystem, $targetPath, $hadExistingFile, $previousContent);
            return ['success' => false, 'error' => 'Could not write file content via WordPress filesystem.'];
        }

        $lint = $this->validatePhpSyntax($targetPath);
        if (($lint['valid'] ?? false) !== true) {
            $this->rollbackWrite($filesystem, $targetPath, $hadExistingFile, $previousContent);
            return [
                'success' => false,
                'error' => 'Write reverted: PHP syntax check failed. ' . ($lint['error'] ?? 'Unknown lint error.'),
                'suggestion' => 'Common issues: missing semicolons, unclosed brackets, undefined constants. Fix the syntax error and try again.',
            ];
        }

        $jsLint = $this->validateJsSyntax($targetPath);
        $fileExt = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        if (($jsLint['valid'] ?? true) === false && $fileExt === 'js') {
            $this->rollbackWrite($filesystem, $targetPath, $hadExistingFile, $previousContent);
            return [
                'success' => false,
                'error' => 'Write reverted: ' . ($jsLint['error'] ?? 'JavaScript syntax check failed.'),
            ];
        }

        if (preg_match('/\.php$/i', $relativePath)) {
            wp_cache_delete('plugins', 'plugins');
        }

        $result = [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_path' => $relativePath,
            'bytes_written' => strlen($content),
            'message' => 'Plugin file written successfully.',
        ];

        $result += $this->buildReadBackData($filesystem, $targetPath);

        if ($headerProtected) {
            $result['header_protected'] = true;
            $result['header_notice'] = 'The existing plugin header (Plugin Name, Version, etc.) was preserved. Only the code body was updated.';
        }
        if (!empty($lint['warning'])) {
            $result['warning'] = $lint['warning'];
        }
        if (($jsLint['valid'] ?? true) === false) {
            $result['js_error'] = $jsLint['error']
                . ' The file was saved but likely contains broken frontend JavaScript.';
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

        if (!$this->isMainPluginFile($slug, $relativePath)) {
            $constantWarnings = $this->checkUndefinedConstants($filesystem, $pluginRoot, $slug, $content);
            if (!empty($constantWarnings)) {
                $result['constant_warning'] = $constantWarnings;
            }
        }

        return $result;
    }

    // ── Constant Reference Check ─────────────────────────────────────────

    /**
     * Scan a sub-file for plugin constant references (e.g. MY_PLUGIN_FILE,
     * MY_PLUGIN_DIR) and verify they are defined in the main plugin file.
     *
     * @return string[] Warnings for each undefined constant
     */
    private function checkUndefinedConstants(
        \WP_Filesystem_Base $filesystem,
        string $pluginRoot,
        string $slug,
        string $content
    ): array {
        $constPrefix = strtoupper(str_replace('-', '_', $slug));
        $suffixes = ['_FILE', '_DIR', '_URL', '_VERSION', '_PATH', '_PLUGIN_FILE'];
        $candidates = [];
        foreach ($suffixes as $suffix) {
            $candidates[] = $constPrefix . $suffix;
        }

        $referenced = [];
        foreach ($candidates as $constName) {
            if (str_contains($content, $constName)) {
                $referenced[] = $constName;
            }
        }

        if (empty($referenced)) {
            return [];
        }

        $mainFile = $pluginRoot . '/' . $slug . '.php';
        if (!$filesystem->exists($mainFile)) {
            return [];
        }

        $mainContent = $filesystem->get_contents($mainFile);
        if (!is_string($mainContent)) {
            return [];
        }

        $warnings = [];
        foreach ($referenced as $constName) {
            if (!preg_match('/define\s*\(\s*[\'"]' . preg_quote($constName, '/') . '[\'"]\s*,/', $mainContent)) {
                $warnings[] = "Constant {$constName} is used in this file but not defined in {$slug}.php. Add define('{$constName}', ...) to the main plugin file before require_once.";
            }
        }

        return $warnings;
    }

    // ── Header Protection ────────────────────────────────────────────────

    /**
     * Split plugin file into header block and code body.
     * Header = <?php + doc comment containing "Plugin Name:".
     *
     * @return array{header: string, body: string}|null
     */
    private function splitHeaderAndBody(string $content): ?array {
        if (!preg_match(
            '/\A(<\?php\s*\n\s*\/\*\*\n(?:\s*\*[^\n]*\n)*\s*\*\/\s*\n)(.*)\z/s',
            $content,
            $matches
        )) {
            return null;
        }

        if (!str_contains($matches[1], 'Plugin Name:')) {
            return null;
        }

        return ['header' => $matches[1], 'body' => $matches[2]];
    }

    /**
     * Merge existing header with new code body.
     *
     * Handles three scenarios:
     * 1. AI sends full file with its own header → existing header + AI body
     * 2. AI sends <?php without header → existing header + code after <?php
     * 3. AI sends raw code body → existing header + raw code
     */
    private function applyHeaderProtection(string $existingContent, string $newContent): ?string {
        $existingParts = $this->splitHeaderAndBody($existingContent);
        if ($existingParts === null) {
            return null;
        }

        $newParts = $this->splitHeaderAndBody($newContent);
        if ($newParts !== null) {
            return $existingParts['header'] . $newParts['body'];
        }

        $trimmed = ltrim($newContent);
        if (str_starts_with($trimmed, '<?php')) {
            $bodyAfterTag = preg_replace('/\A<\?php\s*\n?/', '', $trimmed);
            return $existingParts['header'] . $bodyAfterTag;
        }

        return $existingParts['header'] . $newContent;
    }
}
