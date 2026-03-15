<?php
/**
 * Plugin Scaffold Scenario Test
 *
 * End-to-end workflow test: creates a plugin, writes a sub-file,
 * validates constants/warnings, then cleans up.
 *
 * Run inside DDEV: ddev exec php wp-content/plugins/levi-agent/tests/PluginScaffoldScenarioTest.php
 */

// Bootstrap WordPress
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

class PluginScaffoldScenarioTest
{
    private const TEST_SLUG = 'levi-test-scaffold-' . PHP_INT_SIZE;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): int
    {
        $slug = 'levi-test-scaffold-' . substr(md5((string)time()), 0, 6);
        $constPrefix = strtoupper(str_replace('-', '_', $slug));

        echo "=== Plugin Scaffold Scenario Test ===\n";
        echo "Test slug: $slug\n\n";

        $registry = new \Levi\Agent\AI\Tools\Registry();

        try {
            $this->stepCreatePlugin($registry, $slug, $constPrefix);
            $this->stepWriteSubFileWithWarning($registry, $slug, $constPrefix);
            $this->stepWriteSubFileWithoutWarning($registry, $slug);
        } finally {
            $this->cleanup($slug);
        }

        echo "\n=== Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n\n";

        if (!empty($this->failures)) {
            echo "--- FAILURES ---\n";
            foreach ($this->failures as $f) {
                echo "  FAIL: $f\n";
            }
            echo "\n";
        }

        return $this->failed > 0 ? 1 : 0;
    }

    /**
     * Step 1: Create plugin scaffold and verify _FILE constant is defined.
     */
    private function stepCreatePlugin(\Levi\Agent\AI\Tools\Registry $registry, string $slug, string $constPrefix): void
    {
        echo "--- Step 1: create_plugin ---\n";

        $tool = $registry->get('create_plugin');
        if ($tool === null) {
            $this->fail('create_plugin tool not found');
            return;
        }

        $result = $tool->execute([
            'plugin_name' => 'Levi Test Scaffold',
            'slug' => $slug,
            'description' => 'Automated scaffold test',
            'plugin_type' => 'plain',
        ]);

        if (!($result['success'] ?? false)) {
            $this->fail('create_plugin failed: ' . ($result['error'] ?? 'unknown'));
            return;
        }

        $this->pass('create_plugin returned success');

        $mainFile = WP_PLUGIN_DIR . "/$slug/$slug.php";
        if (!file_exists($mainFile)) {
            $this->fail("Main file not created at $mainFile");
            return;
        }

        $content = file_get_contents($mainFile);
        $expectedConst = "{$constPrefix}_FILE";

        if (str_contains($content, "define('{$expectedConst}'")) {
            $this->pass("Main file defines {$expectedConst}");
        } else {
            $this->fail("Main file does NOT define {$expectedConst}");
        }
    }

    /**
     * Step 2: Write a sub-file referencing an undefined constant -> expect constant_warning.
     */
    private function stepWriteSubFileWithWarning(\Levi\Agent\AI\Tools\Registry $registry, string $slug, string $constPrefix): void
    {
        echo "\n--- Step 2: write_plugin_file (expect constant_warning) ---\n";

        $tool = $registry->get('write_plugin_file');
        if ($tool === null) {
            $this->fail('write_plugin_file tool not found');
            return;
        }

        $fakeConstant = "{$constPrefix}_NONEXISTENT_CONST";
        $content = "<?php\n\$path = {$fakeConstant};\necho \$path;\n";

        $result = $tool->execute([
            'plugin_slug' => $slug,
            'relative_path' => 'includes/test-sub.php',
            'content' => $content,
        ]);

        if (!($result['success'] ?? false)) {
            $this->fail('write_plugin_file failed: ' . ($result['error'] ?? 'unknown'));
            return;
        }

        $this->pass('write_plugin_file returned success');

        if (!empty($result['read_back'])) {
            $this->pass('Response includes read_back');
        } else {
            $this->fail('Response missing read_back');
        }

        $hasConstantWarning = !empty($result['constant_warnings']);
        if ($hasConstantWarning) {
            $this->pass('Response includes constant_warnings for undefined constant');
        } else {
            $this->fail('Response missing constant_warnings (expected warning for ' . $fakeConstant . ')');
        }
    }

    /**
     * Step 3: Write a sub-file without undefined constants -> no constant_warning expected.
     */
    private function stepWriteSubFileWithoutWarning(\Levi\Agent\AI\Tools\Registry $registry, string $slug): void
    {
        echo "\n--- Step 3: write_plugin_file (no constant_warning expected) ---\n";

        $tool = $registry->get('write_plugin_file');
        if ($tool === null) {
            $this->fail('write_plugin_file tool not found');
            return;
        }

        $content = "<?php\necho 'Hello World';\n";

        $result = $tool->execute([
            'plugin_slug' => $slug,
            'relative_path' => 'includes/test-clean.php',
            'content' => $content,
        ]);

        if (!($result['success'] ?? false)) {
            $this->fail('write_plugin_file (clean) failed: ' . ($result['error'] ?? 'unknown'));
            return;
        }

        $this->pass('write_plugin_file (clean) returned success');

        if (empty($result['constant_warnings'])) {
            $this->pass('No constant_warnings for clean file (correct)');
        } else {
            $this->fail('Unexpected constant_warnings for clean file');
        }
    }

    private function cleanup(string $slug): void
    {
        echo "\n--- Cleanup ---\n";
        $pluginDir = WP_PLUGIN_DIR . "/$slug";
        if (is_dir($pluginDir)) {
            $this->recursiveDelete($pluginDir);
            echo "  Deleted $pluginDir\n";
        } else {
            echo "  Nothing to clean up\n";
        }
    }

    private function recursiveDelete(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function pass(string $msg): void
    {
        $this->passed++;
        echo "  PASS: $msg\n";
    }

    private function fail(string $msg): void
    {
        $this->failed++;
        $this->failures[] = $msg;
        echo "  FAIL: $msg\n";
    }
}

$test = new PluginScaffoldScenarioTest();
exit($test->run());
