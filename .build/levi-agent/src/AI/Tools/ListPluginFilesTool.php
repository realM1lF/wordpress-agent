<?php

namespace Levi\Agent\AI\Tools;

class ListPluginFilesTool implements ToolInterface {

    public function getName(): string {
        return 'list_plugin_files';
    }

    public function getDescription(): string {
        return 'List files and directories inside a plugin. Useful to inspect plugin structure before editing code.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Plugin slug (directory in wp-content/plugins)',
                'required' => true,
            ],
            'relative_dir' => [
                'type' => 'string',
                'description' => 'Optional subdirectory inside the plugin (default: plugin root)',
            ],
            'max_depth' => [
                'type' => 'integer',
                'description' => 'Max recursion depth (default: 3, max: 8)',
                'default' => 3,
            ],
            'include_hidden' => [
                'type' => 'boolean',
                'description' => 'Include hidden files',
                'default' => false,
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Pagination page (starts at 1)',
                'default' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Entries per page (default 200, max 1000)',
                'default' => 200,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_plugins') || current_user_can('install_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        $relativeDir = ltrim((string) ($params['relative_dir'] ?? ''), '/');
        $maxDepth = (int) ($params['max_depth'] ?? 3);
        $includeHidden = (bool) ($params['include_hidden'] ?? false);
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(1000, (int) ($params['per_page'] ?? 200)));

        if ($slug === '') {
            return ['success' => false, 'error' => 'plugin_slug is required.'];
        }
        if ($maxDepth < 1) {
            $maxDepth = 1;
        }
        if ($maxDepth > 8) {
            $maxDepth = 8;
        }
        if (str_contains($relativeDir, '..')) {
            return ['success' => false, 'error' => 'Path traversal is not allowed.'];
        }

        $pluginRoot = trailingslashit(WP_PLUGIN_DIR) . $slug;
        if (!is_dir($pluginRoot)) {
            return ['success' => false, 'error' => 'Plugin directory does not exist.'];
        }

        $startDir = $relativeDir === '' ? $pluginRoot : $pluginRoot . '/' . $relativeDir;
        if (!is_dir($startDir)) {
            return ['success' => false, 'error' => 'Target directory does not exist.'];
        }

        $pluginRootReal = realpath($pluginRoot);
        $startDirReal = realpath($startDir);
        if ($pluginRootReal === false || $startDirReal === false || !str_starts_with($startDirReal, $pluginRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside plugin directory.'];
        }

        $entries = [];
        $this->walk($startDirReal, $pluginRootReal, $entries, 0, $maxDepth, $includeHidden);
        usort($entries, fn($a, $b) => strcmp((string) $a['path'], (string) $b['path']));

        $total = count($entries);
        $offset = ($page - 1) * $perPage;
        $pagedEntries = array_slice($entries, $offset, $perPage);
        $hasMore = ($offset + count($pagedEntries)) < $total;

        return [
            'success' => true,
            'plugin_slug' => $slug,
            'relative_dir' => $relativeDir,
            'count' => count($pagedEntries),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'max_pages' => (int) ceil($total / $perPage),
            'entries' => $pagedEntries,
        ];
    }

    private function walk(string $dir, string $pluginRootReal, array &$entries, int $depth, int $maxDepth, bool $includeHidden): void {
        if ($depth > $maxDepth) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (!$includeHidden && str_starts_with($item, '.')) {
                continue;
            }

            $absPath = $dir . '/' . $item;
            $realPath = realpath($absPath);
            if ($realPath === false || !str_starts_with($realPath, $pluginRootReal)) {
                continue;
            }

            $relativePath = ltrim(substr($realPath, strlen($pluginRootReal)), '/');
            $isDir = is_dir($realPath);

            $entries[] = [
                'path' => $relativePath,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? null : filesize($realPath),
            ];

            if ($isDir && $depth < $maxDepth) {
                $this->walk($realPath, $pluginRootReal, $entries, $depth + 1, $maxDepth, $includeHidden);
            }
        }
    }
}
