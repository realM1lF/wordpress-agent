<?php

namespace Levi\Agent\AI\Tools;

class InstallPluginTool implements ToolInterface {

    public function getName(): string {
        return 'install_plugin';
    }

    public function getDescription(): string {
        return 'Install and activate a WordPress plugin from the repository.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Plugin slug (e.g., "wordpress-seo" for Yoast)',
                'required' => true,
            ],
            'activate' => [
                'type' => 'boolean',
                'description' => 'Activate after install',
                'default' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_file_name($params['plugin_slug']);
        $activate = $params['activate'] ?? true;

        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (!function_exists('install_plugin_install_status')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Check if already installed
        $installed = validate_plugin($slug);
        if (!is_wp_error($installed)) {
            // Already installed, maybe activate
            if ($activate && !is_plugin_active($slug)) {
                activate_plugin($slug);
                return [
                    'success' => true,
                    'plugin' => $slug,
                    'message' => 'Plugin was already installed and is now activated.',
                ];
            }
            return [
                'success' => false,
                'error' => 'Plugin already installed.',
            ];
        }

        // Install from repo
        $api = plugins_api('plugin_information', [
            'slug' => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($api)) {
            return [
                'success' => false,
                'error' => 'Plugin not found: ' . $api->get_error_message(),
            ];
        }

        $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message(),
            ];
        }

        // Activate
        if ($activate) {
            activate_plugin($slug);
        }

        return [
            'success' => true,
            'plugin' => $slug,
            'version' => $api->version,
            'activated' => $activate,
            'message' => $activate ? 'Plugin installed and activated.' : 'Plugin installed.',
        ];
    }
}
