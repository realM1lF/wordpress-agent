<?php

namespace Levi\Agent\AI\Tools\Concerns;

/**
 * Path resolution and validation for plugin file tools.
 */
trait ResolvesPluginPaths {

    /**
     * Resolve the plugin root directory, with fuzzy slug matching fallback.
     *
     * @return array{root: string, slug: string}|array{error: string, suggestion?: string}
     */
    protected function resolvePluginRoot(string $slug): array {
        $pluginRoot = trailingslashit(WP_PLUGIN_DIR) . $slug;

        if (is_dir($pluginRoot)) {
            return ['root' => $pluginRoot, 'slug' => $slug];
        }

        $resolved = $this->fuzzyResolvePluginDir($slug);
        if ($resolved !== null) {
            return ['root' => $resolved, 'slug' => basename($resolved)];
        }

        $similar = $this->findSimilarPluginSlugs($slug);
        $result = [
            'error' => "Plugin directory '{$slug}' does not exist.",
            'suggestion' => 'Use get_plugins to find the correct plugin_slug (directory name).',
        ];
        if (!empty($similar)) {
            $result['similar_slugs'] = $similar;
            $result['suggestion'] = "Similar plugins found: " . implode(', ', $similar) . ". Use get_plugins to verify.";
        }

        return $result;
    }

    /**
     * Validate that a relative path is safe and confined to the plugin directory.
     *
     * @return array{target_path: string, target_dir: string}|array{error: string}
     */
    protected function validatePluginFilePath(string $pluginRoot, string $relativePath): array {
        if ($relativePath === '') {
            return ['error' => 'relative_path is required.'];
        }

        if (str_contains($relativePath, '..')) {
            return ['error' => 'Path traversal (..) is not allowed.'];
        }

        $targetPath = $pluginRoot . '/' . $relativePath;
        $targetDir = dirname($targetPath);

        $pluginRootReal = realpath($pluginRoot);
        if ($pluginRootReal === false) {
            return ['error' => 'Plugin root directory could not be resolved.'];
        }

        $targetDirReal = realpath($targetDir);
        if ($targetDirReal !== false && !str_starts_with($targetDirReal, $pluginRootReal)) {
            return ['error' => 'Resolved path is outside plugin directory.'];
        }

        return ['target_path' => $targetPath, 'target_dir' => $targetDir];
    }

    /**
     * Check if a relative path points to the main plugin file (slug.php).
     */
    protected function isMainPluginFile(string $slug, string $relativePath): bool {
        return $relativePath === $slug . '.php';
    }

    private function fuzzyResolvePluginDir(string $requestedSlug): ?string {
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

    private function findSimilarPluginSlugs(string $requestedSlug): array {
        if (!is_dir(WP_PLUGIN_DIR)) {
            return [];
        }
        $entries = scandir(WP_PLUGIN_DIR);
        if ($entries === false) {
            return [];
        }

        $similar = [];
        $normalized = $this->normalizeSlug($requestedSlug);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir(WP_PLUGIN_DIR . '/' . $entry)) {
                continue;
            }
            if (str_contains($entry, $requestedSlug) || str_contains($requestedSlug, $entry)) {
                $similar[] = $entry;
            } elseif (levenshtein($normalized, $this->normalizeSlug($entry)) <= 3) {
                $similar[] = $entry;
            }
        }
        return array_slice($similar, 0, 5);
    }
}
