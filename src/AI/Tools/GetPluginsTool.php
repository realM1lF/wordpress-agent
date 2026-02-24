<?php

namespace Mohami\Agent\AI\Tools;

class GetPluginsTool implements ToolInterface {

    public function getName(): string {
        return 'get_plugins';
    }

    public function getDescription(): string {
        return 'Get a list of installed WordPress plugins with their status.';
    }

    public function getParameters(): array {
        return [
            'status' => [
                'type' => 'string',
                'description' => 'Filter by status: active, inactive, all',
                'enum' => ['active', 'inactive', 'all'],
                'default' => 'all',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('activate_plugins');
    }

    public function execute(array $params): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $allPlugins = get_plugins();
        $activePlugins = get_option('active_plugins', []);
        $muPlugins = get_mu_plugins();

        $plugins = [];

        foreach ($allPlugins as $pluginFile => $pluginData) {
            $isActive = in_array($pluginFile, $activePlugins);
            $status = $isActive ? 'active' : 'inactive';

            // Filter by status
            $filter = $params['status'] ?? 'all';
            if ($filter !== 'all' && $filter !== $status) {
                continue;
            }

            $plugins[] = [
                'name' => $pluginData['Name'],
                'version' => $pluginData['Version'],
                'description' => $pluginData['Description'],
                'author' => $pluginData['Author'],
                'status' => $status,
                'file' => $pluginFile,
            ];
        }

        // Add must-use plugins
        foreach ($muPlugins as $pluginFile => $pluginData) {
            $plugins[] = [
                'name' => $pluginData['Name'],
                'version' => $pluginData['Version'],
                'description' => $pluginData['Description'],
                'author' => $pluginData['Author'],
                'status' => 'must-use',
                'file' => $pluginFile,
            ];
        }

        return [
            'success' => true,
            'count' => count($plugins),
            'plugins' => $plugins,
        ];
    }
}
