<?php

namespace Levi\Agent\AI\Tools;

class ListThemeFilesTool implements ToolInterface {

    public function getName(): string {
        return 'list_theme_files';
    }

    public function getDescription(): string {
        return 'List files and directories inside a theme. Useful to inspect theme structure before editing templates, CSS, or PHP.';
    }

    public function getParameters(): array {
        return [
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Theme slug (directory in wp-content/themes)',
                'required' => true,
            ],
            'relative_dir' => [
                'type' => 'string',
                'description' => 'Optional subdirectory inside the theme (default: theme root)',
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
        return current_user_can('edit_themes') || current_user_can('switch_themes');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['theme_slug'] ?? '');
        $relativeDir = ltrim((string) ($params['relative_dir'] ?? ''), '/');
        $maxDepth = (int) ($params['max_depth'] ?? 3);
        $includeHidden = (bool) ($params['include_hidden'] ?? false);
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(1000, (int) ($params['per_page'] ?? 200)));

        if ($slug === '') {
            return ['success' => false, 'error' => 'theme_slug is required.'];
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

        $themeRoot = trailingslashit(get_theme_root()) . $slug;
        if (!is_dir($themeRoot)) {
            return ['success' => false, 'error' => 'Theme directory does not exist.'];
        }

        $startDir = $relativeDir === '' ? $themeRoot : $themeRoot . '/' . $relativeDir;
        if (!is_dir($startDir)) {
            return ['success' => false, 'error' => 'Target directory does not exist.'];
        }

        $themeRootReal = realpath($themeRoot);
        $startDirReal = realpath($startDir);
        if ($themeRootReal === false || $startDirReal === false || !str_starts_with($startDirReal, $themeRootReal)) {
            return ['success' => false, 'error' => 'Resolved path is outside theme directory.'];
        }

        $entries = [];
        $this->walk($startDirReal, $themeRootReal, $entries, 0, $maxDepth, $includeHidden);
        usort($entries, fn($a, $b) => strcmp((string) $a['path'], (string) $b['path']));

        $total = count($entries);
        $offset = ($page - 1) * $perPage;
        $pagedEntries = array_slice($entries, $offset, $perPage);
        $hasMore = ($offset + count($pagedEntries)) < $total;

        return [
            'success' => true,
            'theme_slug' => $slug,
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

    private function walk(string $dir, string $themeRootReal, array &$entries, int $depth, int $maxDepth, bool $includeHidden): void {
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
            if ($realPath === false || !str_starts_with($realPath, $themeRootReal)) {
                continue;
            }

            $relativePath = ltrim(substr($realPath, strlen($themeRootReal)), '/');
            $isDir = is_dir($realPath);

            $entries[] = [
                'path' => $relativePath,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? null : filesize($realPath),
            ];

            if ($isDir && $depth < $maxDepth) {
                $this->walk($realPath, $themeRootReal, $entries, $depth + 1, $maxDepth, $includeHidden);
            }
        }
    }
}
