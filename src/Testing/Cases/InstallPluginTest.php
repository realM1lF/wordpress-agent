<?php

namespace Levi\Agent\Testing\Cases;

use Levi\Agent\Testing\TestCase;

class InstallPluginTest extends TestCase {
    private const PLUGIN_SLUG = 'jesuspended';
    private const PLUGIN_FILE = 'jesuspended/jesuspended.php';

    public function name(): string {
        return 'Install & Activate Plugin';
    }

    public function description(): string {
        return 'Levi soll das Plugin "jeSuspended" aus dem WordPress-Repository installieren und aktivieren.';
    }

    protected function message(): string {
        return 'Kannst du mir bitte das Plugin "jeSuspended" installieren und aktivieren?';
    }

    protected function setUp(): void {
        $this->removePlugin();
    }

    protected function validate(): void {
        $this->assertPluginExists(self::PLUGIN_SLUG);

        $mainFile = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
        $this->assertTrue(
            file_exists($mainFile),
            'Plugin main file exists',
            $mainFile
        );

        wp_cache_flush();
        wp_cache_delete('plugins', 'plugins');
        $allPlugins = get_plugins();

        $found = false;
        foreach ($allPlugins as $file => $data) {
            if (str_starts_with($file, self::PLUGIN_SLUG . '/')) {
                $found = true;
                $this->assertTrue(
                    is_plugin_active($file),
                    'Plugin is active',
                    "File: {$file}"
                );
                break;
            }
        }

        if (!$found) {
            $this->assertTrue(false, 'Plugin found in WordPress plugin list', 'Not found');
        }
    }

    protected function tearDown(): void {
        $this->removePlugin();
    }

    private function removePlugin(): void {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $allPlugins = get_plugins();
        foreach ($allPlugins as $file => $data) {
            if (str_starts_with($file, self::PLUGIN_SLUG . '/')) {
                deactivate_plugins($file, true);
            }
        }

        if (is_dir(WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG)) {
            if (function_exists('delete_plugins')) {
                $matching = [];
                foreach ($allPlugins as $file => $data) {
                    if (str_starts_with($file, self::PLUGIN_SLUG . '/')) {
                        $matching[] = $file;
                    }
                }
                if (!empty($matching)) {
                    delete_plugins($matching);
                }
            }

            $dir = WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG;
            if (is_dir($dir)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($dir);
            }
        }
    }
}
