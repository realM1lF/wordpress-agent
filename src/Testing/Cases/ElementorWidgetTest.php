<?php

namespace Levi\Agent\Testing\Cases;

use Levi\Agent\Testing\TestCase;

class ElementorWidgetTest extends TestCase {
    private const PLUGIN_SLUG = 'levi-live-clock-widget';

    public function name(): string {
        return 'Elementor Live Clock Widget';
    }

    public function description(): string {
        return 'Levi soll ein Elementor-Widget erstellen, das die aktuelle Uhrzeit anzeigt '
            . 'und per Elementor-UI gestylt werden kann.';
    }

    protected function message(): string {
        return 'Hi Levi, ich benoetige ein Elementor-Widget, das die echte Uhrzeit im Frontend '
            . 'anzeigt (Stunden, Minuten, Sekunden, live aktualisierend). '
            . 'Waere cool, wenn ich das ueber die Elementor-Oberflaeche dann noch stylen koennte '
            . '(Hintergrund- und Textfarbe). Erstelle das bitte als eigenes Plugin.';
    }

    protected function setUp(): void {
        $this->cleanupPlugin(self::PLUGIN_SLUG);
        $this->cleanupPlugin('live-clock-widget');
        $this->cleanupPlugin('elementor-live-clock');

        $this->assertTrue(
            $this->isElementorAvailable(),
            'Elementor is available (precondition)',
            function_exists('\\Elementor\\Plugin') || defined('ELEMENTOR_PATH') ? 'yes' : 'Elementor not detected'
        );
    }

    protected function validate(): void {
        $slug = $this->findCreatedWidgetPlugin();
        $this->assertTrue($slug !== null, 'Widget plugin created', $slug ?? 'not found');

        if ($slug === null) {
            return;
        }

        $dir = WP_PLUGIN_DIR . '/' . $slug;
        $mainFile = $this->findMainPluginFile($dir);
        $this->assertTrue($mainFile !== null, 'Main plugin file found');

        if ($mainFile === null) {
            return;
        }

        $fullPath = $dir . '/' . $mainFile;
        $content = file_get_contents($fullPath);

        $this->assertFileContains($fullPath, 'Plugin Name:', 'Valid plugin header');

        $allPhpFiles = $this->getAllPhpFiles($dir);
        $allContent = '';
        foreach ($allPhpFiles as $f) {
            $allContent .= file_get_contents($f) . "\n";
        }

        $hasElementorIntegration = str_contains($allContent, 'elementor')
            || str_contains($allContent, 'Elementor')
            || str_contains($allContent, 'Widget_Base')
            || str_contains($allContent, 'widgets/register');
        $this->assertTrue($hasElementorIntegration, 'Code references Elementor widget system');

        $hasTimeLogic = str_contains($allContent, 'Date')
            || str_contains($allContent, 'time')
            || str_contains($allContent, 'clock')
            || str_contains($allContent, 'setInterval')
            || str_contains($allContent, 'toLocaleTimeString');
        $this->assertTrue($hasTimeLogic, 'Code contains live time/clock logic');

        $hasStyling = str_contains($allContent, 'color')
            || str_contains($allContent, 'Color')
            || str_contains($allContent, 'background')
            || str_contains($allContent, 'add_control');
        $this->assertTrue($hasStyling, 'Code has styling controls');

        $this->assertNoSyntaxErrors($dir);
    }

    protected function tearDown(): void {
        $slug = $this->findCreatedWidgetPlugin();
        if ($slug) {
            $this->cleanupPlugin($slug);
        }
        $this->cleanupPlugin(self::PLUGIN_SLUG);
        $this->cleanupPlugin('live-clock-widget');
        $this->cleanupPlugin('elementor-live-clock');
    }

    private function isElementorAvailable(): bool {
        return defined('ELEMENTOR_PATH')
            || class_exists('\\Elementor\\Plugin')
            || is_dir(WP_PLUGIN_DIR . '/elementor');
    }

    /**
     * Levi might pick a different slug than our default constant.
     * Look for recently-created plugin dirs that match clock/time/widget patterns.
     */
    private function findCreatedWidgetPlugin(): ?string {
        $candidates = [
            self::PLUGIN_SLUG,
            'live-clock-widget',
            'elementor-live-clock',
            'levi-elementor-clock',
            'elementor-clock-widget',
            'live-time-widget',
        ];

        foreach ($candidates as $slug) {
            if (is_dir(WP_PLUGIN_DIR . '/' . $slug)) {
                return $slug;
            }
        }

        $dirs = glob(WP_PLUGIN_DIR . '/*clock*') ?: [];
        $dirs = array_merge($dirs, glob(WP_PLUGIN_DIR . '/*live-time*') ?: []);
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                return basename($dir);
            }
        }

        return null;
    }

    private function findMainPluginFile(string $dir): ?string {
        $slug = basename($dir);
        $candidates = [$slug . '.php', 'plugin.php', 'index.php'];

        foreach ($candidates as $file) {
            if (file_exists($dir . '/' . $file)) {
                return $file;
            }
        }

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            if (str_contains(file_get_contents($file), 'Plugin Name:')) {
                return basename($file);
            }
        }

        return null;
    }

    private function getAllPhpFiles(string $dir): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private function assertNoSyntaxErrors(string $dir): void {
        foreach ($this->getAllPhpFiles($dir) as $file) {
            $output = [];
            $returnCode = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $returnCode);
            $this->assertTrue(
                $returnCode === 0,
                'No syntax error: ' . basename($file),
                implode(' ', $output)
            );
        }
    }
}
