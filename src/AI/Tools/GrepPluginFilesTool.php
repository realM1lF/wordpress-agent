<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\ResolvesPluginPaths;
use Levi\Agent\AI\Tools\Concerns\GrepsFiles;

class GrepPluginFilesTool extends AbstractTool {

    use ResolvesPluginPaths;
    use GrepsFiles;

    public function getName(): string {
        return 'grep_plugin_files';
    }

    public function getDescription(): string {
        return 'Search for text or regex patterns across all files in a plugin directory. '
            . 'Returns matching lines with file path, line number, and optional context. '
            . 'Use this BEFORE editing code to find where functions, variables, CSS classes, or hooks are used. '
            . 'Skips binary and minified files automatically.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Plugin slug (directory in wp-content/plugins)',
                'required' => true,
            ],
        ] + $this->getGrepParameters();
    }

    public function getInputExamples(): array {
        return [
            ['plugin_slug' => 'my-plugin', 'pattern' => 'get_post_meta', 'file_glob' => '*.php'],
            ['plugin_slug' => 'my-plugin', 'pattern' => '.event-card', 'file_glob' => '*.css'],
            ['plugin_slug' => 'my-plugin', 'pattern' => 'add_action.*init', 'is_regex' => true],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('edit_plugins') || current_user_can('read');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        if ($slug === '') {
            return ['success' => false, 'error' => 'plugin_slug is required.'];
        }

        $resolved = $this->resolvePluginRoot($slug);
        if (isset($resolved['error'])) {
            return ['success' => false] + $resolved;
        }

        $result = $this->executeGrep($resolved['root'], $params);
        if (($result['success'] ?? true) === false) {
            return $result;
        }

        return ['success' => true, 'plugin_slug' => $resolved['slug']] + $result;
    }
}
