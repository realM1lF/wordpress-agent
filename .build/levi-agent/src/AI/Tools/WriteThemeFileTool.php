<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\SanitizesHtmlOutput;
use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
use Levi\Agent\AI\Tools\Concerns\ResolvesThemePaths;
use Levi\Agent\AI\Tools\Concerns\TracksFileHistory;

class WriteThemeFileTool extends AbstractTool {

    use SanitizesHtmlOutput;
    use ValidatesSyntax;
    use ResolvesThemePaths;
    use TracksFileHistory;

    public function getName(): string {
        return 'write_theme_file';
    }

    public function getDescription(): string {
        return 'Create a NEW file inside a theme directory. '
            . 'If the file already exists, this tool REJECTS the write — use patch_theme_file to edit existing files. '
            . 'Set overwrite=true only for complete rewrites where more than 50% of the file changes. '
            . 'The theme header in style.css is automatically preserved.';
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
                'description' => 'File content to write. For style.css, the existing theme header is preserved automatically.',
                'required' => true,
            ],
            'create_dirs' => [
                'type' => 'boolean',
                'description' => 'Create missing directories automatically',
                'default' => true,
            ],
            'preserve_header' => [
                'type' => 'boolean',
                'description' => 'Keep the existing theme header when writing style.css. Only set false to update theme metadata.',
                'default' => true,
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'DANGER: Set to true to overwrite an existing file completely. '
                    . 'Only use when rewriting MORE than 50% of the file. '
                    . 'You MUST read the entire file first and include ALL existing code you want to keep. '
                    . 'For normal edits, use patch_theme_file instead.',
                'default' => false,
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
        $preserveHeader = (bool) ($params['preserve_header'] ?? true);

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'theme_slug and relative_path are required.'];
        }

        $resolved = $this->resolveThemeRoot($slug);
        if (isset($resolved['error'])) {
            return ['success' => false] + $resolved;
        }
        $themeRoot = $resolved['root'];
        $slug = $resolved['slug'];

        $pathCheck = $this->validateThemeFilePath($themeRoot, $relativePath);
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
        $themeRootReal = realpath($themeRoot);
        if ($targetDirReal === false || $themeRootReal === false || !str_starts_with($targetDirReal, $themeRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside theme directory.'];
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

        $overwrite = (bool) ($params['overwrite'] ?? false);
        if ($hadExistingFile && !$overwrite) {
            $existingLines = substr_count($previousContent, "\n") + 1;
            return [
                'success' => false,
                'error' => "File already exists ({$existingLines} lines). "
                    . "Use patch_theme_file for targeted changes to existing files. "
                    . "write_theme_file is only for creating NEW files.",
                'suggestion' => 'Read the file with read_theme_file first, then use patch_theme_file '
                    . 'with {search, replace} pairs to change only the specific parts you need. '
                    . 'This prevents accidental code loss. '
                    . 'If you truly need a complete rewrite (>50% change), set overwrite=true.',
                'existing_lines' => $existingLines,
            ];
        }

        // --- Header protection for style.css ---
        $headerProtected = false;
        if ($this->isThemeStylesheet($relativePath) && $preserveHeader && is_string($previousContent)) {
            $merged = $this->applyStylesheetHeaderProtection($previousContent, $content);
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

        $cssLint = $this->validateCssSyntax($targetPath);
        if (($cssLint['valid'] ?? true) === false) {
            $this->rollbackWrite($filesystem, $targetPath, $hadExistingFile, $previousContent);
            return [
                'success' => false,
                'error' => 'Write reverted: ' . ($cssLint['error'] ?? 'CSS syntax check failed.'),
            ];
        }

        if (preg_match('/\.php$/i', $relativePath) && function_exists('opcache_invalidate')) {
            opcache_invalidate($targetPath, true);
        }

        if (is_string($previousContent)) {
            $this->recordFileVersion($targetPath, $previousContent, $this->getName());
        }

        $result = [
            'success' => true,
            'theme_slug' => $slug,
            'relative_path' => $relativePath,
            'bytes_written' => strlen($content),
            'message' => 'Theme file written successfully.',
        ];

        $result += $this->buildReadBackData($filesystem, $targetPath);

        if ($headerProtected) {
            $result['header_protected'] = true;
            $result['header_notice'] = 'The existing theme header (Theme Name, Version, etc.) in style.css was preserved.';
        }
        if (!empty($lint['warning'])) {
            $result['warning'] = $lint['warning'];
        }
        if ($strippedCount > 0) {
            $result['stripped_tags'] = $strippedCount;
            $result['strip_notice'] = "$strippedCount <code>/<pre>-Tag(s) wurden automatisch entfernt.";
        }

        $codeTagCheck = $this->detectCodeTagsInOutput($content, $relativePath);
        if ($codeTagCheck !== null) {
            $result['code_tag_warning'] = $codeTagCheck;
        }

        if ($hadExistingFile && is_string($previousContent)) {
            $oldLines = substr_count($previousContent, "\n") + 1;
            $newLines = substr_count($content, "\n") + 1;
            $lostLines = $oldLines - $newLines;
            if ($lostLines > 5 && $lostLines > (int) ($oldLines * 0.1)) {
                $result['content_loss_warning'] = "[WARNUNG] Die Datei hatte vorher {$oldLines} Zeilen, "
                    . "jetzt nur noch {$newLines} Zeilen ({$lostLines} Zeilen weniger). "
                    . "Pruefe ob du versehentlich bestehenden Code entfernt hast. "
                    . "Falls ja, lies die vorherige Version und stelle den fehlenden Code wieder her.";
            }

            $diff = $this->buildCompactDiff($previousContent, $content);
            if ($diff !== null) {
                $result['diff_summary'] = $diff;
            }
        }

        return $result;
    }

    // ── style.css Header Protection ──────────────────────────────────────

    /**
     * Split style.css into its theme header comment and CSS body.
     *
     * @return array{header: string, body: string}|null
     */
    private function splitStylesheetHeaderAndBody(string $content): ?array {
        if (!preg_match('/\A(\/\*\n(?:[^\n]*\n)*?\*\/\s*\n)(.*)\z/s', $content, $matches)) {
            return null;
        }

        if (!str_contains($matches[1], 'Theme Name:')) {
            return null;
        }

        return ['header' => $matches[1], 'body' => $matches[2]];
    }

    private function applyStylesheetHeaderProtection(string $existingContent, string $newContent): ?string {
        $existingParts = $this->splitStylesheetHeaderAndBody($existingContent);
        if ($existingParts === null) {
            return null;
        }

        $newParts = $this->splitStylesheetHeaderAndBody($newContent);
        if ($newParts !== null) {
            return $existingParts['header'] . $newParts['body'];
        }

        return $existingParts['header'] . $newContent;
    }

    public function getInputExamples(): array
    {
        return [
            ['theme_slug' => 'my-theme', 'relative_path' => 'functions.php', 'content' => "<?php\nadd_action('wp_enqueue_scripts', function() {\n    wp_enqueue_style('custom', get_stylesheet_uri());\n});"],
            ['theme_slug' => 'my-theme', 'relative_path' => 'parts/header.html', 'content' => '<!-- wp:site-title /-->'],
        ];
    }
}
