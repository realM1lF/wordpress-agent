<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
use Levi\Agent\AI\Tools\Concerns\ResolvesPluginPaths;
use Levi\Agent\AI\Tools\Concerns\TracksFileHistory;

class RenameInPluginTool extends AbstractTool {

    use ValidatesSyntax;
    use ResolvesPluginPaths;
    use TracksFileHistory;

    public function getName(): string {
        return 'rename_in_plugin';
    }

    public function getDescription(): string {
        return 'Atomically rename a string across all matching files in a plugin. '
            . 'Finds all occurrences via grep, replaces them, validates syntax, and rolls back ALL files on any error. '
            . 'Ideal for renaming functions, classes, hooks, or text-domain strings.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Target plugin slug (directory in wp-content/plugins)',
                'required' => true,
            ],
            'old_name' => [
                'type' => 'string',
                'description' => 'The exact string to find and replace (e.g. function name, class name, hook name)',
                'required' => true,
            ],
            'new_name' => [
                'type' => 'string',
                'description' => 'The replacement string',
                'required' => true,
            ],
            'file_glob' => [
                'type' => 'string',
                'description' => 'Filter files by extension, e.g. "*.php" (default: "*.php")',
                'default' => '*.php',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('edit_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        $oldName = (string) ($params['old_name'] ?? '');
        $newName = (string) ($params['new_name'] ?? '');
        $fileGlob = (string) ($params['file_glob'] ?? '*.php');

        if ($slug === '' || $oldName === '' || $newName === '') {
            return ['success' => false, 'error' => 'plugin_slug, old_name, and new_name are required.'];
        }
        if ($oldName === $newName) {
            return ['success' => false, 'error' => 'old_name and new_name must be different.'];
        }

        $resolved = $this->resolvePluginRoot($slug);
        if (isset($resolved['error'])) {
            return ['success' => false] + $resolved;
        }
        $pluginRoot = $resolved['root'];
        $slug = $resolved['slug'];

        $pluginRootReal = realpath($pluginRoot);
        if ($pluginRootReal === false) {
            return ['success' => false, 'error' => 'Plugin root directory could not be resolved.'];
        }

        $filesystem = $this->getFilesystem();
        if ($filesystem === null) {
            return ['success' => false, 'error' => 'WordPress filesystem is not available.'];
        }

        $extensionFilter = null;
        if (preg_match('/^\*\.([a-zA-Z0-9]+)$/', $fileGlob, $m)) {
            $extensionFilter = strtolower($m[1]);
        }

        $affectedFiles = [];
        $totalOccurrences = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pluginRootReal, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if ($extensionFilter !== null && $ext !== $extensionFilter) {
                    continue;
                }
                if ($file->getSize() > 500 * 1024) {
                    continue;
                }

                $filePath = $file->getPathname();
                $content = @file_get_contents($filePath);
                if ($content === false) {
                    continue;
                }
                if (str_contains($content, "\x00")) {
                    continue;
                }

                $count = substr_count($content, $oldName);
                if ($count === 0) {
                    continue;
                }

                $affectedFiles[] = [
                    'path' => $filePath,
                    'relative_path' => ltrim(substr($filePath, strlen($pluginRootReal)), '/'),
                    'original_content' => $content,
                    'occurrences' => $count,
                ];
                $totalOccurrences += $count;
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'File scan failed: ' . $e->getMessage()];
        }

        if (empty($affectedFiles)) {
            return [
                'success' => true,
                'plugin_slug' => $slug,
                'renamed_in_files' => 0,
                'total_occurrences' => 0,
                'message' => "No occurrences of '{$oldName}' found.",
            ];
        }

        foreach ($affectedFiles as &$fileInfo) {
            $newContent = str_replace($oldName, $newName, $fileInfo['original_content']);
            $written = $filesystem->put_contents($fileInfo['path'], $newContent, FS_CHMOD_FILE);
            if (!$written) {
                $this->rollbackRename($filesystem, $affectedFiles);
                return [
                    'success' => false,
                    'error' => "Could not write file: {$fileInfo['relative_path']}. All changes rolled back.",
                ];
            }
            $fileInfo['written'] = true;
        }
        unset($fileInfo);

        $syntaxErrors = [];
        foreach ($affectedFiles as $fileInfo) {
            $ext = strtolower(pathinfo($fileInfo['path'], PATHINFO_EXTENSION));
            if ($ext === 'php') {
                $lint = $this->validatePhpSyntax($fileInfo['path']);
                if (($lint['valid'] ?? false) !== true) {
                    $syntaxErrors[] = [
                        'file' => $fileInfo['relative_path'],
                        'error' => $lint['error'] ?? 'PHP syntax check failed.',
                    ];
                }
            } elseif ($ext === 'css') {
                $cssLint = $this->validateCssSyntax($fileInfo['path']);
                if (($cssLint['valid'] ?? true) === false) {
                    $syntaxErrors[] = [
                        'file' => $fileInfo['relative_path'],
                        'error' => $cssLint['error'] ?? 'CSS syntax check failed.',
                    ];
                }
            }
        }

        if (!empty($syntaxErrors)) {
            $this->rollbackRename($filesystem, $affectedFiles);
            return [
                'success' => false,
                'error' => 'Rename rolled back: syntax errors detected in ' . count($syntaxErrors) . ' file(s).',
                'syntax_errors' => $syntaxErrors,
            ];
        }

        foreach ($affectedFiles as $fileInfo) {
            $this->recordFileVersion($fileInfo['path'], $fileInfo['original_content'], $this->getName());
        }

        $filesSummary = array_map(fn($f) => [
            'file' => $f['relative_path'],
            'occurrences' => $f['occurrences'],
        ], $affectedFiles);

        return [
            'success' => true,
            'plugin_slug' => $slug,
            'old_name' => $oldName,
            'new_name' => $newName,
            'renamed_in_files' => count($affectedFiles),
            'total_occurrences' => $totalOccurrences,
            'files' => $filesSummary,
            'message' => "Renamed '{$oldName}' to '{$newName}' across " . count($affectedFiles) . " file(s) ({$totalOccurrences} occurrences).",
        ];
    }

    private function rollbackRename(\WP_Filesystem_Base $filesystem, array $affectedFiles): void {
        foreach ($affectedFiles as $fileInfo) {
            if (!($fileInfo['written'] ?? false)) {
                continue;
            }
            $filesystem->put_contents($fileInfo['path'], $fileInfo['original_content'], FS_CHMOD_FILE);
        }
    }
}
