<?php
/**
 * Performance Benchmark Test
 *
 * Compares V1 (specialized) vs V2 (generic) tools on:
 *   - Tool definition token count (~60% reduction claimed)
 *   - Single-tool execution latency
 *   - Memory footprint
 *   - Registry initialization time
 *
 * Run inside DDEV:
 *   ddev exec php wp-content/plugins/levi-agent/tests/PerformanceBenchmarkTest.php
 */

$wpLoadCandidates = [
    dirname(__DIR__, 4) . '/wp-load.php',
    dirname(__DIR__, 4) . '/web/wp-load.php',
    dirname(__DIR__) . '/wordpress/web/wp-load.php',
];

$wpLoaded = false;
foreach ($wpLoadCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $wpLoaded = true;
        break;
    }
}

if (!$wpLoaded) {
    fwrite(STDERR, "ERROR: Could not find wp-load.php. Run this inside the DDEV container.\n");
    exit(1);
}

if (!class_exists(\Levi\Agent\AI\Tools\Registry::class)) {
    fwrite(STDERR, "ERROR: Levi Agent plugin is not active or autoloading failed.\n");
    exit(1);
}

// Ensure admin context
$userId = get_current_user_id();
if ($userId === 0) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    if (!empty($admins)) {
        wp_set_current_user($admins[0]->ID);
    } else {
        fwrite(STDERR, "ERROR: No administrator found.\n");
        exit(1);
    }
}

class PerformanceBenchmarkTest
{
    private array $results = [];

    public function run(): int
    {
        echo "=== Performance Benchmark: V1 vs V2 Tools ===\n";
        echo "WP User: " . wp_get_current_user()->user_login . "\n\n";

        $this->benchmarkRegistryInit();
        $this->benchmarkDefinitionSize();
        $this->benchmarkToolLatency();
        $this->benchmarkMemoryFootprint();

        $this->printSummary();

        return 0;
    }

    // ========================================================================
    // Benchmark 1: Registry initialization time
    // ========================================================================

    private function benchmarkRegistryInit(): void
    {
        echo "--- Benchmark 1: Registry Initialization ---\n";

        $iterations = 100;

        // V1 (specialized)
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $reg = new \Levi\Agent\AI\Tools\Registry();
            unset($reg);
        }
        $v1Time = (hrtime(true) - $start) / 1e6; // ms

        // V2 (generic)
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $reg = new \Levi\Agent\AI\Tools\GenericRegistry();
            unset($reg);
        }
        $v2Time = (hrtime(true) - $start) / 1e6; // ms

        $this->results['registry_init'] = [
            'v1_ms' => round($v1Time, 3),
            'v2_ms' => round($v2Time, 3),
            'diff_pct' => round((($v1Time - $v2Time) / max($v1Time, 0.001)) * 100, 1),
        ];

        echo "  V1 (specialized): {$this->results['registry_init']['v1_ms']} ms ({$iterations}x)\n";
        echo "  V2 (generic):     {$this->results['registry_init']['v2_ms']} ms ({$iterations}x)\n";
        echo "  Difference:       {$this->results['registry_init']['diff_pct']}%\n\n";
    }

    // ========================================================================
    // Benchmark 2: Tool definition size (proxy for token count)
    // ========================================================================

    private function benchmarkDefinitionSize(): void
    {
        echo "--- Benchmark 2: Tool Definition Size ---\n";

        $v1Registry = new \Levi\Agent\AI\Tools\Registry();
        $v2Registry = new \Levi\Agent\AI\Tools\GenericRegistry();

        $v1Defs = $v1Registry->getDefinitions();
        $v2Defs = $v2Registry->getDefinitions();

        $v1Json = json_encode($v1Defs, JSON_UNESCAPED_UNICODE);
        $v2Json = json_encode($v2Defs, JSON_UNESCAPED_UNICODE);

        $v1Bytes = strlen($v1Json);
        $v2Bytes = strlen($v2Json);

        // Rough token estimate: ~4 chars per token for English text
        $v1Tokens = (int) ceil($v1Bytes / 4);
        $v2Tokens = (int) ceil($v2Bytes / 4);

        $this->results['definition_size'] = [
            'v1_tools' => count($v1Defs),
            'v2_tools' => count($v2Defs),
            'v1_bytes' => $v1Bytes,
            'v2_bytes' => $v2Bytes,
            'v1_tokens_est' => $v1Tokens,
            'v2_tokens_est' => $v2Tokens,
            'reduction_pct' => round((($v1Bytes - $v2Bytes) / max($v1Bytes, 1)) * 100, 1),
        ];

        echo "  V1: {$this->results['definition_size']['v1_tools']} tools, " .
             "{$this->results['definition_size']['v1_bytes']} bytes, ~{$v1Tokens} tokens\n";
        echo "  V2: {$this->results['definition_size']['v2_tools']} tools, " .
             "{$this->results['definition_size']['v2_bytes']} bytes, ~{$v2Tokens} tokens\n";
        echo "  Reduction: {$this->results['definition_size']['reduction_pct']}%\n\n";
    }

    // ========================================================================
    // Benchmark 3: Single-tool execution latency
    // ========================================================================

    private function benchmarkToolLatency(): void
    {
        echo "--- Benchmark 3: Tool Execution Latency ---\n";

        $v1Registry = new \Levi\Agent\AI\Tools\Registry();
        $v2Registry = new \Levi\Agent\AI\Tools\GenericRegistry();

        $iterations = 50;

        // V1: get_posts
        $v1Tool = $v1Registry->get('get_posts');
        $v1Latencies = [];
        if ($v1Tool) {
            for ($i = 0; $i < $iterations; $i++) {
                $start = hrtime(true);
                $v1Tool->execute(['limit' => 5]);
                $v1Latencies[] = (hrtime(true) - $start) / 1e6; // ms
            }
        }

        // V2: list (type=post)
        $v2Tool = $v2Registry->get('list');
        $v2Latencies = [];
        if ($v2Tool) {
            for ($i = 0; $i < $iterations; $i++) {
                $start = hrtime(true);
                $v2Tool->execute(['type' => 'post', 'limit' => 5]);
                $v2Latencies[] = (hrtime(true) - $start) / 1e6; // ms
            }
        }

        $v1Avg = empty($v1Latencies) ? 0 : array_sum($v1Latencies) / count($v1Latencies);
        $v2Avg = empty($v2Latencies) ? 0 : array_sum($v2Latencies) / count($v2Latencies);
        $v1P95 = empty($v1Latencies) ? 0 : $this->percentile($v1Latencies, 0.95);
        $v2P95 = empty($v2Latencies) ? 0 : $this->percentile($v2Latencies, 0.95);

        $this->results['tool_latency'] = [
            'v1_avg_ms' => round($v1Avg, 3),
            'v2_avg_ms' => round($v2Avg, 3),
            'v1_p95_ms' => round($v1P95, 3),
            'v2_p95_ms' => round($v2P95, 3),
            'iterations' => $iterations,
        ];

        echo "  V1 get_posts ({$iterations}x): avg={$this->results['tool_latency']['v1_avg_ms']}ms, p95={$this->results['tool_latency']['v1_p95_ms']}ms\n";
        echo "  V2 list-post ({$iterations}x): avg={$this->results['tool_latency']['v2_avg_ms']}ms, p95={$this->results['tool_latency']['v2_p95_ms']}ms\n\n";
    }

    // ========================================================================
    // Benchmark 4: Memory footprint
    // ========================================================================

    private function benchmarkMemoryFootprint(): void
    {
        echo "--- Benchmark 4: Memory Footprint ---\n";

        // V1
        $memBefore = memory_get_usage(true);
        $v1Registry = new \Levi\Agent\AI\Tools\Registry();
        $v1Defs = $v1Registry->getDefinitions();
        $memAfterV1 = memory_get_usage(true);
        $v1Memory = $memAfterV1 - $memBefore;

        // V2
        $memBefore = memory_get_usage(true);
        $v2Registry = new \Levi\Agent\AI\Tools\GenericRegistry();
        $v2Defs = $v2Registry->getDefinitions();
        $memAfterV2 = memory_get_usage(true);
        $v2Memory = $memAfterV2 - $memBefore;

        $this->results['memory'] = [
            'v1_kb' => round($v1Memory / 1024, 2),
            'v2_kb' => round($v2Memory / 1024, 2),
            'diff_pct' => round((($v1Memory - $v2Memory) / max($v1Memory, 1)) * 100, 1),
        ];

        echo "  V1 registry + defs: {$this->results['memory']['v1_kb']} KB\n";
        echo "  V2 registry + defs: {$this->results['memory']['v2_kb']} KB\n";
        echo "  Difference: {$this->results['memory']['diff_pct']}%\n\n";
    }

    // ========================================================================
    // Summary
    // ========================================================================

    private function printSummary(): void
    {
        echo "=== Summary ===\n";

        $size = $this->results['definition_size'];
        echo "Token Reduction:\n";
        echo "  V1  ~{$size['v1_tokens_est']} tokens ({$size['v1_tools']} tools)\n";
        echo "  V2  ~{$size['v2_tokens_est']} tokens ({$size['v2_tools']} tools)\n";
        echo "  →   {$size['reduction_pct']}% fewer tokens\n";

        if ($size['reduction_pct'] >= 50) {
            echo "  ✅ Target (≥60%) likely reached with deferred loading + shorter schemas\n";
        } elseif ($size['reduction_pct'] >= 30) {
            echo "  ⚠️  Moderate reduction; check if deferred loading is active\n";
        } else {
            echo "  ❌ Reduction below target; investigate schema bloat\n";
        }

        echo "\n";
        echo "Latency:\n";
        echo "  V1 avg: {$this->results['tool_latency']['v1_avg_ms']}ms\n";
        echo "  V2 avg: {$this->results['tool_latency']['v2_avg_ms']}ms\n";

        echo "\n";
        echo "Memory:\n";
        echo "  V1: {$this->results['memory']['v1_kb']} KB\n";
        echo "  V2: {$this->results['memory']['v2_kb']} KB\n";

        echo "\n";
        echo "Registry Init (100x):\n";
        echo "  V1: {$this->results['registry_init']['v1_ms']}ms\n";
        echo "  V2: {$this->results['registry_init']['v2_ms']}ms\n";
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function percentile(array $values, float $p): float
    {
        sort($values);
        $index = (count($values) - 1) * $p;
        $floor = (int) floor($index);
        $ceil = (int) ceil($index);
        if ($floor === $ceil) {
            return $values[$floor];
        }
        $weight = $index - $floor;
        return $values[$floor] * (1 - $weight) + $values[$ceil] * $weight;
    }
}

// Run
$test = new PerformanceBenchmarkTest();
exit($test->run());
