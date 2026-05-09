<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Health check tool — diagnose WordPress system state.
 */
class HealthCheckTool extends AbstractTool {

    public function getName(): string { return 'health_check'; }

    public function getDescription(): string {
        return "Prüft den Systemzustand: WordPress-Version, PHP-Version, aktive Plugins, Theme, "
            . "Datenbank-Größe, verfügbarer Speicher, kritische Fehler. "
            . "`checks` array um spezifische Checks auszuwählen: "
            . "`core`, `plugins`, `theme`, `database`, `memory`, `errors`, `woocommerce`, `elementor`, `all` (default). "
            . "Gibt Gesamt-Health-Score (0-100) zurück.";
    }

    public function getParameters(): array {
        return [
            'checks' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'default' => ['all'],
                'description' => 'Welche Checks durchführen',
            ],
        ];
    }

    public function execute(array $params): array {
        $requestedChecks = $params['checks'] ?? ['all'];
        $all = in_array('all', $requestedChecks, true);

        $results = [];
        $score = 100;

        if ($all || in_array('core', $requestedChecks, true)) {
            $results['core'] = $this->checkCore();
            if (!$results['core']['healthy']) $score -= 15;
        }

        if ($all || in_array('plugins', $requestedChecks, true)) {
            $results['plugins'] = $this->checkPlugins();
            if (!$results['plugins']['healthy']) $score -= 10;
        }

        if ($all || in_array('theme', $requestedChecks, true)) {
            $results['theme'] = $this->checkTheme();
            if (!$results['theme']['healthy']) $score -= 5;
        }

        if ($all || in_array('database', $requestedChecks, true)) {
            $results['database'] = $this->checkDatabase();
            if (!$results['database']['healthy']) $score -= 10;
        }

        if ($all || in_array('memory', $requestedChecks, true)) {
            $results['memory'] = $this->checkMemory();
            if (!$results['memory']['healthy']) $score -= 10;
        }

        if ($all || in_array('errors', $requestedChecks, true)) {
            $results['errors'] = $this->checkErrors();
            if (!$results['errors']['healthy']) $score -= 20;
        }

        if (($all || in_array('woocommerce', $requestedChecks, true)) && class_exists('WooCommerce')) {
            $results['woocommerce'] = $this->checkWooCommerce();
            if (!$results['woocommerce']['healthy']) $score -= 10;
        }

        if (($all || in_array('elementor', $requestedChecks, true)) && did_action('elementor/loaded')) {
            $results['elementor'] = $this->checkElementor();
            if (!$results['elementor']['healthy']) $score -= 5;
        }

        return [
            'success' => true,
            'score' => max(0, $score),
            'healthy' => $score >= 80,
            'checks' => $results,
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    private function checkCore(): array {
        global $wp_version;
        $latest = $this->getLatestWpVersion();
        $needsUpdate = $latest !== null && version_compare($wp_version, $latest, '<');

        return [
            'healthy' => !$needsUpdate,
            'version' => $wp_version,
            'latest' => $latest,
            'needs_update' => $needsUpdate,
        ];
    }

    private function checkPlugins(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $active = get_option('active_plugins', []);
        $outdated = [];

        foreach ($plugins as $file => $data) {
            if (!empty($data['UpdateURI']) || !empty($data['PluginURI'])) {
                // Simple heuristic: check if plugin has known vulnerabilities
                // In production, this would call a vulnerability API
            }
        }

        return [
            'healthy' => true,
            'total' => count($plugins),
            'active' => count($active),
            'inactive' => count($plugins) - count($active),
            'outdated' => $outdated,
        ];
    }

    private function checkTheme(): array {
        $theme = wp_get_theme();
        return [
            'healthy' => $theme->exists(),
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'parent' => $theme->get_template() !== $theme->get_stylesheet() ? $theme->get_template() : null,
        ];
    }

    private function checkDatabase(): array {
        global $wpdb;
        $size = $wpdb->get_var("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()");
        $tables = $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");

        return [
            'healthy' => true,
            'size_mb' => round((float) $size / 1024 / 1024, 2),
            'tables' => (int) $tables,
            'prefix' => $wpdb->prefix,
        ];
    }

    private function checkMemory(): array {
        $limit = ini_get('memory_limit');
        $usage = memory_get_usage(true);
        $limitBytes = $this->parseBytes($limit);
        $percentUsed = $limitBytes > 0 ? round($usage / $limitBytes * 100, 1) : 0;

        return [
            'healthy' => $percentUsed < 80,
            'limit' => $limit,
            'usage_mb' => round($usage / 1024 / 1024, 2),
            'percent_used' => $percentUsed,
        ];
    }

    private function checkErrors(): array {
        $logFile = ini_get('error_log');
        $recent = [];
        $hasErrors = false;

        if ($logFile && is_readable($logFile)) {
            $lines = file($logFile);
            if ($lines !== false) {
                $lastLines = array_slice($lines, -50);
                foreach ($lastLines as $line) {
                    if (str_contains($line, 'Fatal') || str_contains($line, 'Parse error') || str_contains($line, 'Uncaught')) {
                        $recent[] = trim($line);
                        $hasErrors = true;
                    }
                }
            }
        }

        // Check WordPress debug log
        $wpLog = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($wpLog) && is_readable($wpLog)) {
            $lines = file($wpLog);
            if ($lines !== false) {
                $lastLines = array_slice($lines, -20);
                foreach ($lastLines as $line) {
                    if (str_contains($line, 'Fatal') || str_contains($line, 'Parse error') || str_contains($line, 'Uncaught')) {
                        $recent[] = trim($line);
                        $hasErrors = true;
                    }
                }
            }
        }

        return [
            'healthy' => !$hasErrors,
            'recent_errors' => array_slice($recent, 0, 5),
            'error_count' => count($recent),
        ];
    }

    private function checkWooCommerce(): array {
        if (!class_exists('WooCommerce')) {
            return ['healthy' => false, 'error' => 'WooCommerce nicht aktiv'];
        }

        $wcVersion = WC()->version;
        $orders = wc_get_orders(['limit' => 1, 'return' => 'ids']);

        return [
            'healthy' => true,
            'version' => $wcVersion,
            'has_orders' => !empty($orders),
            'currency' => get_woocommerce_currency(),
        ];
    }

    private function checkElementor(): array {
        if (!did_action('elementor/loaded')) {
            return ['healthy' => false, 'error' => 'Elementor nicht aktiv'];
        }

        $version = \Elementor\Plugin::instance()->get_version();
        $templates = wp_count_posts(\Elementor\TemplateLibrary\Source_Local::CPT);

        return [
            'healthy' => true,
            'version' => $version,
            'templates' => (int) ($templates->publish ?? 0),
        ];
    }

    private function getLatestWpVersion(): ?string {
        $response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/', ['timeout' => 5]);
        if (is_wp_error($response)) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['offers'][0]['version'] ?? null;
    }

    private function parseBytes(string $value): int {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;
        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
