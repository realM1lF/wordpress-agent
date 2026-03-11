<?php

namespace Levi\Agent\Testing\Cases;

use Levi\Agent\Testing\TestCase;

class SalesProductBadgesTest extends TestCase {
    private const PLUGIN_SLUG = 'sales-product-badges';

    public function name(): string {
        return 'Sales Product Badges Plugin';
    }

    public function description(): string {
        return 'Levi soll ein Plugin erstellen, das reduzierte Produkte mit einem Prozent-Badge versieht, '
            . 'konfigurierbar ueber WooCommerce-Einstellungen.';
    }

    protected function message(): string {
        return 'Hi Levi, bitte erstelle ein Plugin mit dem Namen "Sales Product Badges", '
            . 'dass macht, dass reduzierte Produkte einen Sales-Badge erhalten, der anzeigt, '
            . 'zu viel viel Prozent der Artikel reduziert ist. Dieser Badge soll dann im Listing '
            . 'an den entsprechenden Produktboxen und auf der Produktdetailseite sichtbar sein. '
            . 'Die Hintergrund- und Textfarbe des Badges moechte ich global irgendwo in den '
            . 'WooCommerce-Einstellungen jederzeit aendern koennen.';
    }

    protected function setUp(): void {
        $this->cleanupPlugin(self::PLUGIN_SLUG);
    }

    protected function validate(): void {
        $this->assertPluginExists(self::PLUGIN_SLUG);

        $mainFile = $this->findMainPluginFile(self::PLUGIN_SLUG);
        $this->assertTrue($mainFile !== null, 'Main plugin file found', $mainFile ?? 'not found');

        if ($mainFile) {
            $fullPath = WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG . '/' . $mainFile;

            $this->assertFileContains(
                $fullPath,
                'Plugin Name:',
                'Plugin header present'
            );

            $content = file_exists($fullPath) ? file_get_contents($fullPath) : '';

            $this->assertTrue(
                str_contains($content, 'sale') || str_contains($content, 'Sale')
                    || str_contains($content, 'badge') || str_contains($content, 'Badge'),
                'Plugin code references sale/badge logic'
            );

            $this->assertTrue(
                str_contains($content, 'woocommerce') || str_contains($content, 'WooCommerce')
                    || str_contains($content, 'wc_') || str_contains($content, 'add_filter'),
                'Plugin integrates with WooCommerce'
            );

            $hasColorConfig = str_contains($content, 'color')
                || str_contains($content, 'background')
                || str_contains($content, 'farbe')
                || str_contains($content, 'settings');
            $this->assertTrue($hasColorConfig, 'Plugin has color configuration');

            $hasPercentage = str_contains($content, '%')
                || str_contains($content, 'percent')
                || str_contains($content, 'prozent')
                || str_contains($content, 'discount')
                || str_contains($content, 'round(');
            $this->assertTrue($hasPercentage, 'Plugin calculates percentage discount');
        }

        $this->assertNoSyntaxError(self::PLUGIN_SLUG);
    }

    protected function tearDown(): void {
        $this->cleanupPlugin(self::PLUGIN_SLUG);
    }

    private function findMainPluginFile(string $slug): ?string {
        $dir = WP_PLUGIN_DIR . '/' . $slug;
        if (!is_dir($dir)) {
            return null;
        }

        $candidates = [
            $slug . '.php',
            'plugin.php',
            'index.php',
        ];

        foreach ($candidates as $file) {
            if (file_exists($dir . '/' . $file)) {
                return $file;
            }
        }

        $files = glob($dir . '/*.php');
        foreach ($files ?: [] as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'Plugin Name:')) {
                return basename($file);
            }
        }

        return null;
    }

    private function assertNoSyntaxError(string $slug): void {
        $dir = WP_PLUGIN_DIR . '/' . $slug;
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.php') ?: [];
        foreach ($files as $file) {
            $output = [];
            $returnCode = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $returnCode);
            $this->assertTrue(
                $returnCode === 0,
                'No PHP syntax error: ' . basename($file),
                implode(' ', $output)
            );
        }
    }
}
