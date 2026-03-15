<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\ResolvesThemePaths;
use Levi\Agent\AI\Tools\Concerns\GrepsFiles;

class GrepThemeFilesTool extends AbstractTool {

    use ResolvesThemePaths;
    use GrepsFiles;

    public function getName(): string {
        return 'grep_theme_files';
    }

    public function getDescription(): string {
        return 'Search for text or regex patterns across all files in a theme directory. '
            . 'Returns matching lines with file path, line number, and optional context. '
            . 'Use this BEFORE editing theme code to find where functions, variables, CSS classes, or hooks are used. '
            . 'Skips binary and minified files automatically.';
    }

    public function getParameters(): array {
        return [
            'theme_slug' => [
                'type' => 'string',
                'description' => 'Theme slug (directory in wp-content/themes)',
                'required' => true,
            ],
        ] + $this->getGrepParameters();
    }

    public function getInputExamples(): array {
        return [
            ['theme_slug' => 'my-theme', 'pattern' => 'get_template_part', 'file_glob' => '*.php'],
            ['theme_slug' => 'my-theme', 'pattern' => '.site-header', 'file_glob' => '*.css'],
            ['theme_slug' => 'my-theme', 'pattern' => 'add_theme_support', 'file_glob' => '*.php'],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_themes') || current_user_can('switch_themes') || current_user_can('read');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['theme_slug'] ?? '');
        if ($slug === '') {
            return ['success' => false, 'error' => 'theme_slug is required.'];
        }

        $resolved = $this->resolveThemeRoot($slug);
        if (isset($resolved['error'])) {
            return ['success' => false] + $resolved;
        }

        $result = $this->executeGrep($resolved['root'], $params);
        if (($result['success'] ?? true) === false) {
            return $result;
        }

        return ['success' => true, 'theme_slug' => $resolved['slug']] + $result;
    }
}
