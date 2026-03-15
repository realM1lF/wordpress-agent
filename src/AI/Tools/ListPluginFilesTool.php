<?php

namespace Levi\Agent\AI\Tools;

class ListPluginFilesTool implements ToolInterface {

    public function getName(): string {
        return 'list_plugin_files';
    }

    public function getDescription(): string {
        return 'List files and directories inside a plugin. Supports fuzzy slug matching. '
            . 'ALWAYS call this before read_plugin_file or write_plugin_file when you have not yet seen the plugin file structure in this session.';
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
            'include_symbols' => [
                'type' => 'boolean',
                'description' => 'Include top-level PHP symbols (functions, classes, hooks) per file. Slower but gives a code map.',
                'default' => false,
            ],
        ];
    }

    public function getInputExamples(): array {
        return [
            ['plugin_slug' => 'my-plugin'],
            ['plugin_slug' => 'my-plugin', 'relative_dir' => 'includes', 'max_depth' => 2],
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
        
        // If exact directory doesn't exist, try fuzzy matching
        if (!is_dir($pluginRoot)) {
            $fuzzyMatch = $this->findSimilarPluginDirectory($slug);
            if ($fuzzyMatch !== null) {
                $pluginRoot = $fuzzyMatch;
                $slug = basename($fuzzyMatch);
            } else {
                return [
                    'success' => false, 
                    'error' => 'Plugin directory does not exist.',
                    'suggestion' => 'Use get_plugins first to find the correct plugin_slug (directory name).',
                ];
            }
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

        $includeSymbols = (bool) ($params['include_symbols'] ?? false);
        if ($includeSymbols) {
            $this->enrichWithSymbols($pagedEntries, $pluginRootReal);
        }

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

    /**
     * Try to find a similar plugin directory when exact match fails.
     * Handles common slug normalization differences (hyphens, underscores, etc.)
     */
    private function findSimilarPluginDirectory(string $requestedSlug): ?string {
        if (!is_dir(WP_PLUGIN_DIR)) {
            return null;
        }
        
        $entries = scandir(WP_PLUGIN_DIR);
        if ($entries === false) {
            return null;
        }
        
        $normalizedRequested = $this->normalizeSlug($requestedSlug);
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir(WP_PLUGIN_DIR . '/' . $entry)) {
                continue;
            }
            
            // Exact match already handled, skip
            if ($entry === $requestedSlug) {
                continue;
            }
            
            if ($this->normalizeSlug($entry) === $normalizedRequested) {
                return WP_PLUGIN_DIR . '/' . $entry;
            }
        }
        
        return null;
    }
    
    /**
     * Normalize a slug for comparison (remove hyphens, underscores, lowercase)
     */
    private function normalizeSlug(string $slug): string {
        return strtolower(str_replace(['-', '_'], '', $slug));
    }

    private const SYMBOL_MAX_PHP_FILES = 30;
    private const SYMBOL_MAX_FILE_SIZE = 200 * 1024;

    private function enrichWithSymbols(array &$entries, string $rootReal): void {
        $phpCount = 0;
        foreach ($entries as &$entry) {
            if ($entry['type'] !== 'file') {
                continue;
            }
            $ext = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
            if ($ext !== 'php') {
                continue;
            }
            if ($phpCount >= self::SYMBOL_MAX_PHP_FILES) {
                break;
            }

            $filePath = $rootReal . '/' . $entry['path'];
            if (!is_file($filePath) || filesize($filePath) > self::SYMBOL_MAX_FILE_SIZE) {
                continue;
            }

            $content = @file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            $phpCount++;
            $symbols = $this->extractPhpSymbols($content);
            $entry['symbols'] = !empty($symbols) ? $symbols : null;
        }
        unset($entry);
    }

    private function extractPhpSymbols(string $code): array {
        $symbols = [];

        if (preg_match_all('/\bfunction\s+(\w+)\s*\(/m', $code, $m)) {
            $symbols['functions'] = array_values(array_unique($m[1]));
        }
        if (preg_match_all('/\bclass\s+(\w+)/m', $code, $m)) {
            $symbols['classes'] = array_values(array_unique($m[1]));
        }

        $hooks = [];
        if (preg_match_all('/\badd_action\(\s*[\'"]([^\'"]+)[\'"]/m', $code, $m)) {
            $hooks = array_merge($hooks, $m[1]);
        }
        if (preg_match_all('/\badd_filter\(\s*[\'"]([^\'"]+)[\'"]/m', $code, $m)) {
            $hooks = array_merge($hooks, $m[1]);
        }
        if (!empty($hooks)) {
            $symbols['hooks'] = array_values(array_unique($hooks));
        }

        if (preg_match_all('/\bregister_post_type\(\s*[\'"]([^\'"]+)[\'"]/m', $code, $m)) {
            $symbols['post_types'] = array_values(array_unique($m[1]));
        }
        if (preg_match_all('/\badd_shortcode\(\s*[\'"]([^\'"]+)[\'"]/m', $code, $m)) {
            $symbols['shortcodes'] = array_values(array_unique($m[1]));
        }

        return $symbols;
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
