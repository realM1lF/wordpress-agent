<?php

namespace Levi\Agent\AI\Tools;

class InstallPluginTool implements ToolInterface {

    public function getName(): string {
        return 'install_plugin';
    }

    public function getDescription(): string {
        return 'Manage WordPress plugins: install, delete, activate, deactivate, or bulk-update. '
            . 'Use action "delete" to fully remove a plugin (deactivates first, runs uninstall hooks, deletes all files). '
            . 'Use action "update_outdated" to update all plugins with available updates.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action: "install" (default), "delete", "activate", "deactivate", or "update_outdated".',
                'enum' => ['install', 'delete', 'activate', 'deactivate', 'update_outdated'],
            ],
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Plugin slug (directory name, e.g. "debug-bar"). Required for install/delete/activate/deactivate.',
            ],
            'activate' => [
                'type' => 'boolean',
                'description' => 'Activate after install (only for action "install")',
                'default' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins');
    }

    public function execute(array $params): array {
        $action = (string) ($params['action'] ?? 'install');

        if ($action === 'update_outdated') {
            return $this->updateOutdated();
        }

        $slug = sanitize_file_name($params['plugin_slug'] ?? '');
        if ($slug === '') {
            return ['success' => false, 'error' => 'plugin_slug is required.'];
        }

        if ($action === 'delete') {
            return $this->deletePlugin($slug);
        }
        if ($action === 'activate') {
            return $this->activatePlugin($slug);
        }
        if ($action === 'deactivate') {
            return $this->deactivatePlugin($slug);
        }

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
                'suggestion' => 'Check the exact plugin slug on wordpress.org/plugins/{slug}. Use get_plugins to see already installed plugins.',
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

    private function updateOutdated(): array {
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }
        if (!class_exists('\Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        WP_Filesystem();

        wp_update_plugins();

        $updateData = get_site_transient('update_plugins');
        if (empty($updateData->response)) {
            return [
                'success' => true,
                'updated' => [],
                'total' => 0,
                'message' => 'All plugins are up to date.',
            ];
        }

        $updated = [];
        $failed = [];
        $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());

        foreach ($updateData->response as $pluginFile => $pluginInfo) {
            $pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . $pluginFile, false, false);
            $name = $pluginData['Name'] ?? $pluginFile;
            $oldVersion = $pluginData['Version'] ?? '?';
            $newVersion = $pluginInfo->new_version ?? '?';

            $result = $upgrader->upgrade($pluginFile);

            if (is_wp_error($result) || $result === false) {
                $error = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                $failed[] = ['plugin' => $name, 'file' => $pluginFile, 'error' => $error];
            } else {
                $updated[] = ['plugin' => $name, 'file' => $pluginFile, 'from' => $oldVersion, 'to' => $newVersion];
            }
        }

        return [
            'success' => true,
            'updated' => $updated,
            'failed' => $failed,
            'total_updated' => count($updated),
            'total_failed' => count($failed),
            'message' => count($updated) . ' plugin(s) updated' . (count($failed) > 0 ? ', ' . count($failed) . ' failed' : '') . '.',
        ];
    }

    private function deletePlugin(string $slug): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        $installedPlugins = get_plugins('/' . $slug);
        if (empty($installedPlugins)) {
            return [
                'success' => false,
                'error' => "Plugin '$slug' is not installed.",
                'suggestion' => 'Use get_plugins to find the correct slug.',
            ];
        }

        $pluginFile = $slug . '/' . array_key_first($installedPlugins);

        if (is_plugin_active($pluginFile)) {
            deactivate_plugins($pluginFile);
        }

        $result = delete_plugins([$pluginFile]);

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'plugin_slug' => $slug,
            'message' => "Plugin '$slug' fully deleted (files removed, uninstall hooks executed).",
        ];
    }

    private function activatePlugin(string $slug): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installedPlugins = get_plugins('/' . $slug);
        if (empty($installedPlugins)) {
            return ['success' => false, 'error' => "Plugin '$slug' is not installed."];
        }

        $pluginFile = $slug . '/' . array_key_first($installedPlugins);
        if (is_plugin_active($pluginFile)) {
            return ['success' => true, 'message' => "Plugin '$slug' is already active."];
        }

        $result = activate_plugin($pluginFile);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return ['success' => true, 'plugin_slug' => $slug, 'message' => "Plugin '$slug' activated."];
    }

    private function deactivatePlugin(string $slug): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installedPlugins = get_plugins('/' . $slug);
        if (empty($installedPlugins)) {
            return ['success' => false, 'error' => "Plugin '$slug' is not installed."];
        }

        $pluginFile = $slug . '/' . array_key_first($installedPlugins);
        if (!is_plugin_active($pluginFile)) {
            return ['success' => true, 'message' => "Plugin '$slug' is already inactive."];
        }

        deactivate_plugins($pluginFile);

        return ['success' => true, 'plugin_slug' => $slug, 'message' => "Plugin '$slug' deactivated."];
    }
}
