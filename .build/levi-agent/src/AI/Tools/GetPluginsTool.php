<?php

namespace Levi\Agent\AI\Tools;

class GetPluginsTool implements ToolInterface {

    public function getName(): string {
        return 'get_plugins';
    }

    public function getDescription(): string {
        return 'List all installed WordPress plugins with their activation status and update availability. '
            . 'Returns plugin name, version, author, active/inactive state, and whether updates are pending. '
            . 'Use this before creating or modifying plugins to check for slug conflicts. '
            . 'Also includes must-use plugins. Does not return plugin file contents — use read_plugin_file for that.';
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
        $updateData = get_site_transient('update_plugins');
        $updatesAvailable = (is_object($updateData) && !empty($updateData->response)) ? $updateData->response : [];

        $plugins = [];
        $outdatedCount = 0;

        foreach ($allPlugins as $pluginFile => $pluginData) {
            $isActive = in_array($pluginFile, $activePlugins);
            $status = $isActive ? 'active' : 'inactive';

            $filter = $params['status'] ?? 'all';
            if ($filter !== 'all' && $filter !== $status) {
                continue;
            }

            $entry = [
                'name' => $pluginData['Name'],
                'version' => $pluginData['Version'],
                'description' => $pluginData['Description'],
                'author' => $pluginData['Author'],
                'status' => $status,
                'file' => $pluginFile,
            ];

            if (isset($updatesAvailable[$pluginFile])) {
                $entry['update_available'] = true;
                $entry['new_version'] = $updatesAvailable[$pluginFile]->new_version ?? null;
                $outdatedCount++;
            }

            $plugins[] = $entry;
        }

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
            'outdated_count' => $outdatedCount,
            'plugins' => $plugins,
        ];
    }
}
