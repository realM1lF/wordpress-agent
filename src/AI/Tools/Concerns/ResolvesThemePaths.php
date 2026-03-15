<?php

namespace Levi\Agent\AI\Tools\Concerns;

/**
 * Path resolution and validation for theme file tools.
 */
trait ResolvesThemePaths {

    /**
     * Resolve the theme root directory, with fuzzy slug matching fallback.
     *
     * @return array{root: string, slug: string}|array{error: string, suggestion?: string}
     */
    protected function resolveThemeRoot(string $slug): array {
        $themeRoot = trailingslashit(get_theme_root()) . $slug;

        if (is_dir($themeRoot)) {
            return ['root' => $themeRoot, 'slug' => $slug];
        }

        $resolved = $this->fuzzyResolveThemeDir($slug);
        if ($resolved !== null) {
            return ['root' => $resolved, 'slug' => basename($resolved)];
        }

        return [
            'error' => "Theme directory '{$slug}' does not exist.",
            'suggestion' => 'Use create_theme to create it first, or check installed themes.',
        ];
    }

    /**
     * Validate that a relative path is safe and confined to the theme directory.
     *
     * @return array{target_path: string, target_dir: string}|array{error: string}
     */
    protected function validateThemeFilePath(string $themeRoot, string $relativePath): array {
        if ($relativePath === '') {
            return ['error' => 'relative_path is required.'];
        }

        if (str_contains($relativePath, '..')) {
            return ['error' => 'Path traversal (..) is not allowed.'];
        }

        $targetPath = $themeRoot . '/' . $relativePath;
        $targetDir = dirname($targetPath);

        $themeRootReal = realpath($themeRoot);
        if ($themeRootReal === false) {
            return ['error' => 'Theme root directory could not be resolved.'];
        }

        $targetDirReal = realpath($targetDir);
        if ($targetDirReal !== false && !str_starts_with($targetDirReal, $themeRootReal)) {
            return ['error' => 'Resolved path is outside theme directory.'];
        }

        return ['target_path' => $targetPath, 'target_dir' => $targetDir];
    }

    /**
     * Check if a relative path points to the theme stylesheet (style.css).
     */
    protected function isThemeStylesheet(string $relativePath): bool {
        return $relativePath === 'style.css';
    }

    private function fuzzyResolveThemeDir(string $requestedSlug): ?string {
        $themesDir = get_theme_root();
        if (!is_dir($themesDir)) {
            return null;
        }
        $entries = scandir($themesDir);
        if ($entries === false) {
            return null;
        }
        $normalized = $this->normalizeSlug($requestedSlug);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($themesDir . '/' . $entry)) {
                continue;
            }
            if ($entry === $requestedSlug) {
                continue;
            }
            if ($this->normalizeSlug($entry) === $normalized) {
                return $themesDir . '/' . $entry;
            }
        }
        return null;
    }
}
