<?php

namespace Mohami\Agent\AI\Tools;

class GetOptionsTool implements ToolInterface {

    // Whitelist of safe options that can be read
    private array $allowedOptions = [
        'blogname',
        'blogdescription',
        'siteurl',
        'home',
        'admin_email',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'permalink_structure',
        'default_category',
        'default_post_format',
        'posts_per_page',
        'posts_per_rss',
        'rss_use_excerpt',
        'blog_charset',
        'active_theme',
    ];

    public function getName(): string {
        return 'get_option';
    }

    public function getDescription(): string {
        return 'Get WordPress site options/settings. Only safe options are accessible.';
    }

    public function getParameters(): array {
        return [
            'option' => [
                'type' => 'string',
                'description' => 'The option name to retrieve',
                'required' => true,
            ],
            'default' => [
                'type' => 'string',
                'description' => 'Default value if option not found',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $option = sanitize_key($params['option'] ?? '');

        if (empty($option)) {
            return [
                'success' => false,
                'error' => 'Option name required',
            ];
        }

        // Check if option is in whitelist
        if (!in_array($option, $this->allowedOptions, true)) {
            return [
                'success' => false,
                'error' => "Option '$option' is not accessible. Allowed options: " . implode(', ', $this->allowedOptions),
            ];
        }

        $value = get_option($option, $params['default'] ?? false);

        // Handle special cases
        if ($option === 'active_theme') {
            $theme = wp_get_theme();
            $value = [
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'author' => $theme->get('Author'),
                'stylesheet' => $theme->get_stylesheet(),
            ];
        }

        return [
            'success' => true,
            'option' => $option,
            'value' => $value,
        ];
    }

    /**
     * Get all available options (for documentation)
     */
    public function getAvailableOptions(): array {
        return $this->allowedOptions;
    }
}
