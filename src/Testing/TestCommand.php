<?php

namespace Levi\Agent\Testing;

use WP_CLI;

class TestCommand {

    /**
     * Run Levi E2E tests.
     *
     * Sends real messages to Levi, auto-confirms destructive actions,
     * and validates the WordPress state after each test.
     *
     * ## OPTIONS
     *
     * [--case=<case>]
     * : Run a specific test case by key. Omit to run all.
     * ---
     * options:
     *   - sales-badges
     *   - create-page
     *   - delete-plugin
     *   - install-plugin
     *   - elementor-widget
     * ---
     *
     * [--verbose]
     * : Show detailed log output during test execution.
     *
     * [--json]
     * : Output results as JSON instead of human-readable text.
     *
     * ## EXAMPLES
     *
     *     wp levi test run
     *     wp levi test run --case=create-page --verbose
     *     wp levi test run --json
     *
     * @when after_wp_load
     */
    public function run(array $args, array $assoc_args): void {
        $case = $assoc_args['case'] ?? null;
        $verbose = isset($assoc_args['verbose']);
        $json = isset($assoc_args['json']);

        if (!$json) {
            WP_CLI::log('');
            WP_CLI::log('╔══════════════════════════════════════╗');
            WP_CLI::log('║      Levi E2E Test Runner v1.0       ║');
            WP_CLI::log('╚══════════════════════════════════════╝');
            WP_CLI::log('');
            WP_CLI::log('⚠️  Tests make real AI calls and modify WordPress state.');
            WP_CLI::log('   They will clean up after themselves.');
            WP_CLI::log('');
        }

        $runner = new TestRunner($verbose);
        $results = $runner->run($case);

        if ($json) {
            $output = [];
            foreach ($results as $key => $r) {
                $output[$key] = [
                    'name' => $r->name,
                    'status' => $r->status,
                    'duration' => round($r->durationSeconds, 2),
                    'assertions' => $r->assertions,
                    'error' => $r->error,
                ];
            }
            WP_CLI::log(wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * List available test cases.
     *
     * ## EXAMPLES
     *
     *     wp levi test list
     *
     * @when after_wp_load
     */
    public function list(array $args, array $assoc_args): void {
        $runner = new TestRunner();
        $tests = $runner->getAvailableTests();

        WP_CLI::log('');
        WP_CLI::log('Available test cases:');
        WP_CLI::log('');

        foreach ($tests as $key => $info) {
            WP_CLI::log("  {$key}");
            WP_CLI::log("    {$info['name']}: {$info['description']}");
            WP_CLI::log('');
        }

        WP_CLI::log("Run with: wp levi test run [--case=<key>]");
    }
}
