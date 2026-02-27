<?php

namespace Levi\Agent\AI\Tools;

class UpdateAnyOptionTool implements ToolInterface {

    public function getName(): string {
        return 'update_any_option';
    }

    public function getDescription(): string {
        return 'Update ANY WordPress option. Use with extreme caution! Restrictions should be defined in your rules.md file.';
    }

    public function getParameters(): array {
        return [
            'option' => [
                'type' => 'string',
                'description' => 'Option name',
                'required' => true,
            ],
            'value' => [
                'type' => 'string',
                'description' => 'New value (JSON for complex values)',
                'required' => true,
            ],
            'is_json' => [
                'type' => 'boolean',
                'description' => 'Whether value is JSON encoded',
                'default' => false,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $option = sanitize_key($params['option']);
        $isJson = $params['is_json'] ?? false;
        
        // Decode JSON if needed
        $value = $isJson ? json_decode($params['value'], true) : $params['value'];
        
        if ($isJson && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg(),
            ];
        }

        $oldValue = get_option($option);
        update_option($option, $value);

        return [
            'success' => true,
            'option' => $option,
            'old_value' => $oldValue,
            'new_value' => $value,
            'message' => "Option '$option' updated.",
        ];
    }
}
