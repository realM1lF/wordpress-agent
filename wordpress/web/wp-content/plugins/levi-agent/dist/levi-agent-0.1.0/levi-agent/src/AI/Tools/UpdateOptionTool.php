<?php

namespace Levi\Agent\AI\Tools;

class UpdateOptionTool implements ToolInterface {

    // Whitelist of safe options that can be modified
    private array $allowedOptions = [
        'blogname',
        'blogdescription',
        'date_format',
        'time_format',
        'start_of_week',
        'posts_per_page',
        'posts_per_rss',
        'default_category',
        'default_post_format',
    ];

    public function getName(): string {
        return 'update_option';
    }

    public function getDescription(): string {
        return 'Update WordPress site options. Only safe options from whitelist.';
    }

    public function getParameters(): array {
        return [
            'option' => [
                'type' => 'string',
                'description' => 'Option name (from whitelist)',
                'required' => true,
                'enum' => [
                    'blogname',
                    'blogdescription', 
                    'date_format',
                    'time_format',
                    'posts_per_page',
                ],
            ],
            'value' => [
                'type' => 'string',
                'description' => 'New value',
                'required' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $option = sanitize_key($params['option']);

        if (!in_array($option, $this->allowedOptions, true)) {
            return [
                'success' => false,
                'error' => "Option '$option' is not in whitelist. Allowed: " . implode(', ', $this->allowedOptions),
            ];
        }

        $oldValue = get_option($option);
        $newValue = sanitize_text_field($params['value']);

        update_option($option, $newValue);

        return [
            'success' => true,
            'option' => $option,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'message' => "Option '$option' updated successfully.",
        ];
    }
}
