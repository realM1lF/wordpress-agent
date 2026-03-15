<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
use Levi\Agent\AI\Tools\Concerns\ResolvesPluginPaths;
use Levi\Agent\AI\Tools\Concerns\ResolvesThemePaths;
use Levi\Agent\AI\Tools\Concerns\TracksFileHistory;

class RevertFileTool extends AbstractTool {

    use ValidatesSyntax;
    use ResolvesPluginPaths;
    use ResolvesThemePaths;
    use TracksFileHistory;

    public function getName(): string {
        return 'revert_file';
    }

    public function getDescription(): string {
        return 'Revert a plugin or theme file to a previous version from the current session history. '
            . 'Only works for files that were modified by write/patch tools during this session.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Plugin slug (mutually exclusive with theme_slug)',
            ],
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Theme slug (mutually exclusive with plugin_slug)',
            ],
            'relative_path' => [
                'type' => 'string',
                'description' => 'Relative file path inside the plugin/theme',
                'required' => true,
            ],
            'version' => [
                'type' => 'integer',
                'description' => 'Version index to revert to (0 = most recent previous version, 1 = version before that, etc.)',
                'default' => 0,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('edit_plugins')
            || current_user_can('edit_themes') || current_user_can('switch_themes');
    }

    public function execute(array $params): array {
        $pluginSlug = sanitize_title($params['plugin_slug'] ?? '');
        $themeSlug = sanitize_title($params['theme_slug'] ?? '');
        $relativePath = ltrim((string) ($params['relative_path'] ?? ''), '/');
        $versionIndex = (int) ($params['version'] ?? 0);

        if ($pluginSlug === '' && $themeSlug === '') {
            return ['success' => false, 'error' => 'Either plugin_slug or theme_slug is required.'];
        }
        if ($pluginSlug !== '' && $themeSlug !== '') {
            return ['success' => false, 'error' => 'Provide either plugin_slug or theme_slug, not both.'];
        }
        if ($relativePath === '') {
            return ['success' => false, 'error' => 'relative_path is required.'];
        }

        if ($pluginSlug !== '') {
            $resolved = $this->resolvePluginRoot($pluginSlug);
            if (isset($resolved['error'])) {
                return ['success' => false] + $resolved;
            }
            $root = $resolved['root'];
            $slug = $resolved['slug'];
            $slugKey = 'plugin_slug';
        } else {
            $resolved = $this->resolveThemeRoot($themeSlug);
            if (isset($resolved['error'])) {
                return ['success' => false] + $resolved;
            }
            $root = $resolved['root'];
            $slug = $resolved['slug'];
            $slugKey = 'theme_slug';
        }

        $targetPath = $root . '/' . $relativePath;
        if (str_contains($relativePath, '..')) {
            return ['success' => false, 'error' => 'Path traversal is not allowed.'];
        }

        $rootReal = realpath($root);
        if ($rootReal === false) {
            return ['success' => false, 'error' => 'Root directory could not be resolved.'];
        }

        $history = $this->getFileHistory($targetPath);
        if (empty($history)) {
            return [
                'success' => false,
                'error' => 'No version history found for this file in the current session.',
                'suggestion' => 'Only files modified by write/patch tools have revertible history.',
            ];
        }

        if ($versionIndex < 0 || $versionIndex >= count($history)) {
            return [
                'success' => false,
                'error' => "Version index {$versionIndex} is out of range. Available versions: 0-" . (count($history) - 1),
                'available_versions' => array_map(function ($entry, $idx) {
                    return [
                        'index' => $idx,
                        'tool' => $entry['tool'] ?? 'unknown',
                        'timestamp' => date('Y-m-d H:i:s', $entry['timestamp'] ?? 0),
                    ];
                }, $history, array_keys($history)),
            ];
        }

        $previousContent = $this->revertToVersion($targetPath, $versionIndex);
        if ($previousContent === null) {
            return ['success' => false, 'error' => 'Could not retrieve version content.'];
        }

        $filesystem = $this->getFilesystem();
        if ($filesystem === null) {
            return ['success' => false, 'error' => 'WordPress filesystem is not available.'];
        }

        $currentContent = $filesystem->get_contents($targetPath);
        $this->recordFileVersion($targetPath, is_string($currentContent) ? $currentContent : '', 'revert_file');

        $written = $filesystem->put_contents($targetPath, $previousContent, FS_CHMOD_FILE);
        if (!$written) {
            return ['success' => false, 'error' => 'Could not write reverted content.'];
        }

        $lint = $this->validatePhpSyntax($targetPath);
        if (($lint['valid'] ?? false) !== true) {
            if (is_string($currentContent)) {
                $filesystem->put_contents($targetPath, $currentContent, FS_CHMOD_FILE);
            }
            return [
                'success' => false,
                'error' => 'Revert aborted: PHP syntax check failed on reverted content. ' . ($lint['error'] ?? ''),
                'suggestion' => 'The stored version itself may have had issues. Try a different version index.',
            ];
        }

        $cssLint = $this->validateCssSyntax($targetPath);
        if (($cssLint['valid'] ?? true) === false) {
            if (is_string($currentContent)) {
                $filesystem->put_contents($targetPath, $currentContent, FS_CHMOD_FILE);
            }
            return [
                'success' => false,
                'error' => 'Revert aborted: ' . ($cssLint['error'] ?? 'CSS syntax check failed.'),
            ];
        }

        $result = [
            'success' => true,
            $slugKey => $slug,
            'relative_path' => $relativePath,
            'reverted_to_version' => $versionIndex,
            'reverted_from_tool' => $history[$versionIndex]['tool'] ?? 'unknown',
            'reverted_from_time' => date('Y-m-d H:i:s', $history[$versionIndex]['timestamp'] ?? 0),
            'bytes_written' => strlen($previousContent),
            'message' => 'File successfully reverted.',
        ];

        $result += $this->buildReadBackData($filesystem, $targetPath);

        return $result;
    }
}
