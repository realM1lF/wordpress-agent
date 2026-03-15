<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\SanitizesHtmlOutput;
use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
use Levi\Agent\AI\Tools\Concerns\ResolvesThemePaths;
use Levi\Agent\AI\Tools\Concerns\TracksFileHistory;

class PatchThemeFileTool extends AbstractTool {

    use SanitizesHtmlOutput;
    use ValidatesSyntax;
    use ResolvesThemePaths;
    use TracksFileHistory;

    public function getName(): string {
        return 'patch_theme_file';
    }

    public function getDescription(): string {
        return 'Apply targeted search-and-replace patches to an existing theme file. '
            . 'Much faster than write_theme_file for small changes (rename, bugfix, value change). '
            . 'Each replacement must have a unique "search" string that appears exactly once in the file. '
            . 'Automatically validates PHP/CSS syntax after patching and rolls back on errors.';
    }

    public function getInputExamples(): array {
        return [
            ['theme_slug' => 'my-theme', 'relative_path' => 'functions.php', 'replacements' => [['search' => '$color = "blue";', 'replace' => '$color = "red";']]],
            ['theme_slug' => 'my-theme', 'relative_path' => 'style.css', 'replacements' => [['search' => 'font-size: 14px;', 'replace' => 'font-size: 16px;']]],
        ];
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
                'description' => 'Relative file path inside the theme, e.g. "style.css", "functions.php"',
                'required' => true,
            ],
            'replacements' => [
                'type' => 'array',
                'description' => 'Array of {search, replace, replace_all?} objects. Each "search" must appear exactly once unless replace_all is true.',
                'required' => true,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Exact text to find (must occur exactly once, unless replace_all is true)'],
                        'replace' => ['type' => 'string', 'description' => 'Replacement text'],
                        'replace_all' => ['type' => 'boolean', 'description' => 'Replace ALL occurrences of search string (default: false). Useful for bulk renaming.'],
                    ],
                ],
            ],
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'Preview changes without writing. Returns diff_summary of what would change.',
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
        $replacements = $params['replacements'] ?? [];
        $dryRun = (bool) ($params['dry_run'] ?? false);

        if ($slug === '' || $relativePath === '') {
            return ['success' => false, 'error' => 'theme_slug and relative_path are required.'];
        }
        if (!is_array($replacements) || empty($replacements)) {
            return ['success' => false, 'error' => 'replacements array is required and must not be empty.'];
        }
        if (count($replacements) > 50) {
            return ['success' => false, 'error' => 'Too many replacements (max 50). Use write_theme_file for large rewrites.'];
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

        if (!is_file($targetPath)) {
            return [
                'success' => false,
                'error' => 'File does not exist.',
                'suggestion' => 'Use write_theme_file to create new files.',
            ];
        }

        $targetPathReal = realpath($targetPath);
        $themeRootReal = realpath($themeRoot);
        if ($targetPathReal === false || $themeRootReal === false || !str_starts_with($targetPathReal, $themeRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside theme directory.'];
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

            $replaceAll = (bool) ($replacement['replace_all'] ?? false);
            $count = substr_count($content, $search);
            if ($count === 0) {
                $msg = "Replacement #$i: search string not found.";
                $closest = $this->findClosestMatch($search, $content);
                if ($closest) {
                    $best = $closest[0];
                    $msg .= " Closest match at line {$best['line']} ({$best['similarity']}% similar): '{$best['content']}'";
                } else {
                    $msg .= " Preview: '" . mb_substr($search, 0, 60) . "'";
                }
                $errors[] = $msg;
                continue;
            }
            if ($count > 1 && !$replaceAll) {
                $errors[] = "Replacement #$i: search string is ambiguous ($count occurrences). Make it more specific or set replace_all=true.";
                continue;
            }

            $entry = [
                'index' => $i,
                'search_preview' => mb_substr($search, 0, 80),
                'replace_preview' => mb_substr($replace, 0, 80),
            ];

            if ($replaceAll) {
                $content = str_replace($search, $replace, $content);
                $entry['occurrences_replaced'] = $count;
            } else {
                $matchPos = strpos($content, $search);
                $matchLine = $matchPos !== false ? substr_count($content, "\n", 0, $matchPos) + 1 : null;
                $content = str_replace($search, $replace, $content);
                if ($matchLine !== null) {
                    $entry['matched_at_line'] = $matchLine;
                }
            }
            $applied[] = $entry;
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
                'theme_slug' => $slug,
                'relative_path' => $relativePath,
                'patches_applied' => 0,
                'message' => 'No changes needed — file content unchanged.',
            ];
        }

        if ($dryRun) {
            $result = [
                'success' => true,
                'dry_run' => true,
                'theme_slug' => $slug,
                'relative_path' => $relativePath,
                'patches_preview' => $applied,
            ];
            $diff = $this->buildCompactDiff($originalContent, $content);
            if ($diff !== null) {
                $result['diff_summary'] = $diff;
            }
            if (!empty($errors)) {
                $result['partial_errors'] = $errors;
            }
            return $result;
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
                'suggestion' => 'The replacement may have broken the PHP syntax. Read the file first with read_theme_file, then apply a corrected patch.',
            ];
        }

        $cssLint = $this->validateCssSyntax($targetPath);
        if (($cssLint['valid'] ?? true) === false) {
            $filesystem->put_contents($targetPath, $originalContent, FS_CHMOD_FILE);
            return [
                'success' => false,
                'error' => 'Patch reverted: ' . ($cssLint['error'] ?? 'CSS syntax check failed.'),
                'patches_attempted' => $applied,
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

        $this->recordFileVersion($targetPath, $originalContent, $this->getName());

        $result = [
            'success' => true,
            'theme_slug' => $slug,
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
