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
        'show_on_front',
        'page_on_front',
        'page_for_posts',
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
                    'posts_per_rss',
                    'start_of_week',
                    'default_category',
                    'default_post_format',
                    'show_on_front',
                    'page_on_front',
                    'page_for_posts',
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
        $rawValue = $params['value'] ?? '';
        $newValue = $this->normalizeOptionValue($option, $rawValue);

        if ($option === 'show_on_front' && !in_array($newValue, ['posts', 'page'], true)) {
            return [
                'success' => false,
                'error' => "Invalid value for 'show_on_front'. Allowed: posts, page.",
            ];
        }

        if (in_array($option, ['page_on_front', 'page_for_posts'], true) && !is_int($newValue)) {
            return [
                'success' => false,
                'error' => "Invalid value for '$option'. Expected integer page ID.",
            ];
        }

        $updated = update_option($option, $newValue);
        $actualValue = get_option($option);
        $verified = $this->valuesEqual($option, $actualValue, $newValue);

        return [
            'success' => $verified,
            'option' => $option,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'actual_value' => $actualValue,
            'updated' => (bool) $updated,
            'verified' => $verified,
            'message' => $verified
                ? "Option '$option' updated and verified successfully."
                : "Option '$option' write attempted, but verification failed.",
        ];
    }

    private function normalizeOptionValue(string $option, mixed $value): mixed {
        if ($option === 'show_on_front') {
            return sanitize_key((string) $value);
        }

        if (in_array($option, ['page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'start_of_week', 'default_category'], true)) {
            return (int) $value;
        }

        return sanitize_text_field((string) $value);
    }

    private function valuesEqual(string $option, mixed $actual, mixed $expected): bool {
        if (in_array($option, ['page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'start_of_week', 'default_category'], true)) {
            return (int) $actual === (int) $expected;
        }

        return (string) $actual === (string) $expected;
    }
}
