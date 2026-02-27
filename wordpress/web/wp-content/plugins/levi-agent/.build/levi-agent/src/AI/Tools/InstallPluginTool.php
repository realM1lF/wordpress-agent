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
        if (!class_exists('\Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        // Check if already installed by slug directory
        $installedPlugins = get_plugins('/' . $slug);
        if (!empty($installedPlugins)) {
            $pluginFile = $slug . '/' . array_key_first($installedPlugins);

            if ($activate && !is_plugin_active($pluginFile)) {
                $activationResult = activate_plugin($pluginFile);
                if (is_wp_error($activationResult)) {
                    return [
                        'success' => false,
                        'error' => $activationResult->get_error_message(),
                    ];
                }
                return [
                    'success' => true,
                    'plugin' => $pluginFile,
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

        // Find installed plugin file in the slug directory
        $installedPlugins = get_plugins('/' . $slug);
        $pluginFile = !empty($installedPlugins) ? $slug . '/' . array_key_first($installedPlugins) : null;

        if ($pluginFile === null) {
            return [
                'success' => false,
                'error' => 'Plugin installed, but main file could not be determined.',
            ];
        }

        // Activate
        if ($activate) {
            $activationResult = activate_plugin($pluginFile);
            if (is_wp_error($activationResult)) {
                return [
                    'success' => false,
                    'error' => $activationResult->get_error_message(),
                ];
            }
        }

        return [
            'success' => true,
            'plugin' => $pluginFile,
            'version' => $api->version,
            'activated' => $activate,
            'message' => $activate ? 'Plugin installed and activated.' : 'Plugin installed.',
        ];
    }
}
