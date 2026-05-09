<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Generic install tool — install/activate/deactivate plugins and themes.
 */
class InstallTool extends AbstractTool {

    public function getName(): string { return 'install'; }

    public function getDescription(): string {
        return "Installiert, aktiviert oder deaktiviert Plugins und Themes. "
            . "`target=plugin|theme`. "
            . "`action=install|activate|deactivate|delete`. "
            . "`source` für Install: WordPress.org Slug (z.B. 'woocommerce') oder ZIP-URL. "
            . "`slug` für activate/deactivate/delete (z.B. 'woocommerce/woocommerce.php'). "
            . "VORSICHT: `delete` entfernt Daten unwiderruflich.";
    }

    public function getParameters(): array {
        return [
            'target' => [
                'type' => 'string',
                'enum' => ['plugin', 'theme'],
                'description' => 'Was installiert/verwaltet werden soll',
            ],
            'action' => [
                'type' => 'string',
                'enum' => ['install', 'activate', 'deactivate', 'delete'],
                'description' => 'Aktion',
            ],
            'source' => [
                'type' => 'string',
                'description' => 'WordPress.org Slug oder ZIP-URL (für action=install)',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'Plugin/Theme Slug (für activate/deactivate/delete)',
            ],
        ];
    }

    public function execute(array $params): array {
        $target = (string) ($params['target'] ?? 'plugin');
        $action = (string) ($params['action'] ?? 'install');

        if (!current_user_can('install_plugins') && !current_user_can('activate_plugins')) {
            return ['success' => false, 'error' => 'Keine Berechtigung für Plugin/Theme-Verwaltung'];
        }

        return match ($target) {
            'plugin' => $this->handlePlugin($action, $params),
            'theme' => $this->handleTheme($action, $params),
            default => ['success' => false, 'error' => "Unbekannter target: {$target}"],
        };
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('activate_plugins');
    }

    private function handlePlugin(string $action, array $params): array {
        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        switch ($action) {
            case 'install':
                return $this->installPlugin((string) ($params['source'] ?? ''));
            case 'activate':
                return $this->activatePlugin((string) ($params['slug'] ?? ''));
            case 'deactivate':
                return $this->deactivatePlugin((string) ($params['slug'] ?? ''));
            case 'delete':
                return $this->deletePlugin((string) ($params['slug'] ?? ''));
            default:
                return ['success' => false, 'error' => "Unbekannte Aktion: {$action}"];
        }
    }

    private function installPlugin(string $source): array {
        if ($source === '') {
            return ['success' => false, 'error' => 'Erforderlich: source'];
        }

        if (str_starts_with($source, 'http')) {
            // ZIP URL
            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);
            $result = $upgrader->install($source);
        } else {
            // WordPress.org slug
            $api = plugins_api('plugin_information', ['slug' => $source, 'fields' => ['sections' => false]]);
            if (is_wp_error($api)) {
                return ['success' => false, 'error' => $api->get_error_message()];
            }
            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);
            $result = $upgrader->install($api->download_link);
        }

        if (is_wp_error($result) || $result === false) {
            $error = is_wp_error($result) ? $result->get_error_message() : 'Installation fehlgeschlagen';
            return ['success' => false, 'error' => $error];
        }

        return [
            'success' => true,
            'message' => 'Plugin installiert',
            'slug' => $source,
        ];
    }

    private function activatePlugin(string $slug): array {
        if ($slug === '') {
            return ['success' => false, 'error' => 'Erforderlich: slug'];
        }

        if (!current_user_can('activate_plugins')) {
            return ['success' => false, 'error' => 'Keine Berechtigung zum Aktivieren von Plugins'];
        }

        $result = activate_plugin($slug);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'message' => 'Plugin aktiviert',
            'slug' => $slug,
        ];
    }

    private function deactivatePlugin(string $slug): array {
        if ($slug === '') {
            return ['success' => false, 'error' => 'Erforderlich: slug'];
        }

        if (!current_user_can('activate_plugins')) {
            return ['success' => false, 'error' => 'Keine Berechtigung zum Deaktivieren von Plugins'];
        }

        deactivate_plugins($slug);

        return [
            'success' => true,
            'message' => 'Plugin deaktiviert',
            'slug' => $slug,
        ];
    }

    private function deletePlugin(string $slug): array {
        if ($slug === '') {
            return ['success' => false, 'error' => 'Erforderlich: slug'];
        }

        if (!current_user_can('delete_plugins')) {
            return ['success' => false, 'error' => 'Keine Berechtigung zum Löschen von Plugins'];
        }

        $result = delete_plugins([$slug]);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'message' => 'Plugin gelöscht',
            'slug' => $slug,
        ];
    }

    private function handleTheme(string $action, array $params): array {
        if (!function_exists('themes_api')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        switch ($action) {
            case 'install':
                return $this->installTheme((string) ($params['source'] ?? ''));
            case 'activate':
                return $this->activateTheme((string) ($params['slug'] ?? ''));
            case 'delete':
                return $this->deleteTheme((string) ($params['slug'] ?? ''));
            default:
                return ['success' => false, 'error' => "Theme-Aktion '{$action}' nicht unterstützt"];
        }
    }

    private function installTheme(string $source): array {
        if ($source === '') {
            return ['success' => false, 'error' => 'Erforderlich: source'];
        }

        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader($skin);

        if (str_starts_with($source, 'http')) {
            $result = $upgrader->install($source);
        } else {
            $api = themes_api('theme_information', ['slug' => $source]);
            if (is_wp_error($api)) {
                return ['success' => false, 'error' => $api->get_error_message()];
            }
            $result = $upgrader->install($api->download_link);
        }

        if (is_wp_error($result) || $result === false) {
            return ['success' => false, 'error' => is_wp_error($result) ? $result->get_error_message() : 'Installation fehlgeschlagen'];
        }

        return ['success' => true, 'message' => 'Theme installiert', 'slug' => $source];
    }

    private function activateTheme(string $slug): array {
        if ($slug === '') {
            return ['success' => false, 'error' => 'Erforderlich: slug'];
        }

        if (!current_user_can('switch_themes')) {
            return ['success' => false, 'error' => 'Keine Berechtigung zum Theme-Wechsel'];
        }

        switch_theme($slug);
        return ['success' => true, 'message' => 'Theme aktiviert', 'slug' => $slug];
    }

    private function deleteTheme(string $slug): array {
        if ($slug === '') {
            return ['success' => false, 'error' => 'Erforderlich: slug'];
        }

        if (!current_user_can('delete_themes')) {
            return ['success' => false, 'error' => 'Keine Berechtigung zum Löschen von Themes'];
        }

        $result = delete_theme($slug);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return ['success' => true, 'message' => 'Theme gelöscht', 'slug' => $slug];
    }
}
