<?php

namespace Levi\Agent\AI\Tools;

class SwitchThemeTool implements ToolInterface {

    public function getName(): string {
        return 'switch_theme';
    }

    public function getDescription(): string {
        return 'Switch the active WordPress theme.';
    }

    public function getParameters(): array {
        return [
            'theme' => [
                'type' => 'string',
                'description' => 'Theme slug (e.g., "twentytwentyfour")',
                'required' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('switch_themes');
    }

    public function execute(array $params): array {
        $theme = sanitize_text_field($params['theme']);
        
        $themeObj = wp_get_theme($theme);
        if (!$themeObj->exists()) {
            return [
                'success' => false,
                'error' => "Theme '$theme' not found.",
            ];
        }

        $oldTheme = get_stylesheet();
        switch_theme($theme);

        return [
            'success' => true,
            'old_theme' => $oldTheme,
            'new_theme' => $theme,
            'theme_name' => $themeObj->get('Name'),
            'message' => "Switched from '$oldTheme' to '$theme'.",
        ];
    }
}
