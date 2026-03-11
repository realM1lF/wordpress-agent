<?php

namespace Levi\Agent\Testing;

use Levi\Agent\Testing\Cases\SalesProductBadgesTest;
use Levi\Agent\Testing\Cases\CreatePageTest;
use Levi\Agent\Testing\Cases\DeletePluginTest;
use Levi\Agent\Testing\Cases\InstallPluginTest;
use Levi\Agent\Testing\Cases\ElementorWidgetTest;

class TestRunner {
    /** @var array<string, class-string<TestCase>> */
    private array $registry = [
        'sales-badges' => SalesProductBadgesTest::class,
        'create-page' => CreatePageTest::class,
        'delete-plugin' => DeletePluginTest::class,
        'install-plugin' => InstallPluginTest::class,
        'elementor-widget' => ElementorWidgetTest::class,
    ];

    private bool $verbose;

    public function __construct(bool $verbose = false) {
        $this->verbose = $verbose;
    }

    public function getAvailableTests(): array {
        $list = [];
        foreach ($this->registry as $key => $class) {
            $instance = new $class($this->verbose);
            $list[$key] = [
                'name' => $instance->name(),
                'description' => $instance->description(),
            ];
        }
        return $list;
    }

    /**
     * @param string|null $caseKey Specific test to run, or null for all
     * @return TestResult[]
     */
    public function run(?string $caseKey = null): array {
        $this->prepareEnvironment();

        $cases = $caseKey !== null
            ? [$caseKey => $this->registry[$caseKey] ?? null]
            : $this->registry;

        $results = [];

        foreach ($cases as $key => $class) {
            if ($class === null) {
                \WP_CLI::warning("Unknown test case: {$key}");
                continue;
            }

            \WP_CLI::log('');
            \WP_CLI::log("━━━ Running: {$key} ━━━");

            /** @var TestCase $test */
            $test = new $class($this->verbose);
            $result = $test->run();
            $results[$key] = $result;

            $this->printResult($key, $result);
        }

        $this->printSummary($results);
        return $results;
    }

    private function prepareEnvironment(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $userId = get_current_user_id();
        if ($userId === 0) {
            $admins = get_users(['role' => 'administrator', 'number' => 1]);
            if (!empty($admins)) {
                wp_set_current_user($admins[0]->ID);
                \WP_CLI::log('Authenticated as: ' . $admins[0]->user_login . ' (ID ' . $admins[0]->ID . ')');
            } else {
                \WP_CLI::error('No administrator found. Tests need admin privileges.');
            }
        }
    }

    private function printResult(string $key, TestResult $result): void {
        $icon = match ($result->status) {
            'passed' => '✅',
            'failed' => '❌',
            'error' => '💥',
            default => '❓',
        };

        \WP_CLI::log("{$icon} {$result->name} ({$result->status})");
        \WP_CLI::log("   Duration: " . round($result->durationSeconds, 1) . 's');
        \WP_CLI::log("   Assertions: {$result->passedCount()} passed, {$result->failedCount()} failed");

        if ($result->error) {
            \WP_CLI::log("   Error: " . mb_substr($result->error, 0, 300));
        }

        foreach ($result->assertions as $a) {
            $aIcon = $a['passed'] ? '  ✓' : '  ✗';
            \WP_CLI::log("   {$aIcon} {$a['assertion']}" . ($a['detail'] ? " – {$a['detail']}" : ''));
        }
    }

    /**
     * @param TestResult[] $results
     */
    private function printSummary(array $results): void {
        \WP_CLI::log('');
        \WP_CLI::log('═══ Test Summary ═══');

        $passed = 0;
        $failed = 0;
        $errors = 0;
        $totalDuration = 0.0;

        foreach ($results as $r) {
            $totalDuration += $r->durationSeconds;
            if ($r->status === 'passed') {
                $passed++;
            } elseif ($r->status === 'error') {
                $errors++;
            } else {
                $failed++;
            }
        }

        \WP_CLI::log("Total: " . count($results) . " tests, {$passed} passed, {$failed} failed, {$errors} errors");
        \WP_CLI::log("Duration: " . round($totalDuration, 1) . 's');

        if ($failed === 0 && $errors === 0) {
            \WP_CLI::success('All tests passed!');
        } else {
            \WP_CLI::error("Some tests failed.", false);
        }
    }
}
