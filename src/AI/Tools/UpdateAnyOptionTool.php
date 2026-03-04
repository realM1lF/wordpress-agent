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
        $option = sanitize_key($params['option'] ?? '');
        if ($option === '') {
            return ['success' => false, 'error' => 'option is required.'];
        }

        $isJson = $params['is_json'] ?? false;

        $value = $isJson ? json_decode($params['value'] ?? '', true) : ($params['value'] ?? '');
        
        if ($isJson && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg(),
            ];
        }

        if (!$isJson) {
            $value = $this->normalizeOptionValue($option, $value);
        }

        if ($option === 'show_on_front' && !in_array((string) $value, ['posts', 'page'], true)) {
            return [
                'success' => false,
                'error' => "Invalid value for 'show_on_front'. Allowed: posts, page.",
            ];
        }

        if (in_array($option, ['page_on_front', 'page_for_posts'], true) && !is_int($value)) {
            return [
                'success' => false,
                'error' => "Invalid value for '$option'. Expected integer page ID.",
            ];
        }

        $oldValue = get_option($option);
        $updated = update_option($option, $value);
        $actualValue = get_option($option);
        $verified = $this->valuesEqual($option, $actualValue, $value);

        return [
            'success' => $verified,
            'option' => $option,
            'old_value' => $oldValue,
            'new_value' => $value,
            'actual_value' => $actualValue,
            'updated' => (bool) $updated,
            'verified' => $verified,
            'message' => $verified
                ? "Option '$option' updated and verified."
                : "Option '$option' write attempted, but verification failed.",
        ];
    }

    private function normalizeOptionValue(string $option, mixed $value): mixed {
        if ($option === 'show_on_front') {
            return sanitize_key((string) $value);
        }

        if (in_array($option, ['page_on_front', 'page_for_posts'], true)) {
            return (int) $value;
        }

        return is_scalar($value) ? sanitize_text_field((string) $value) : $value;
    }

    private function valuesEqual(string $option, mixed $actual, mixed $expected): bool {
        if (in_array($option, ['page_on_front', 'page_for_posts'], true)) {
            return (int) $actual === (int) $expected;
        }

        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }
}
