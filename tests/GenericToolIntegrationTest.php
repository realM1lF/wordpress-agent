<?php
/**
 * Generic Tool Integration Test
 *
 * Directly exercises all 12 generic tools against the live WordPress instance.
 * No AI calls — fast, deterministic, validates actual tool behavior.
 *
 * Run inside DDEV:
 *   ddev exec php wp-content/plugins/levi-agent/tests/GenericToolIntegrationTest.php
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

if (!class_exists(\Levi\Agent\AI\Tools\GenericRegistry::class)) {
    fwrite(STDERR, "ERROR: Levi Agent plugin is not active or autoloading failed.\n");
    exit(1);
}

// Ensure we're running as admin
$userId = get_current_user_id();
if ($userId === 0) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    if (!empty($admins)) {
        wp_set_current_user($admins[0]->ID);
    } else {
        fwrite(STDERR, "ERROR: No administrator found. Tests need admin privileges.\n");
        exit(1);
    }
}

class GenericToolIntegrationTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private array $created = []; // Track artifacts for cleanup

    private \Levi\Agent\AI\Tools\GenericRegistry $registry;

    public function __construct() {
        $this->registry = new \Levi\Agent\AI\Tools\GenericRegistry(
            \Levi\Agent\AI\Tools\GenericRegistry::PROFILE_FULL
        );
    }

    public function run(): int
    {
        echo "=== Generic Tool Integration Test ===\n";
        echo "WP User: " . wp_get_current_user()->user_login . "\n";
        echo "Tools: " . count($this->registry->getAll()) . "\n\n";

        try {
            $this->testReadTool();
            $this->testListTool();
            $this->testWriteAndEditTool();
            $this->testGrepTool();
            $this->testExecuteTool();
            $this->testHealthCheckTool();
            $this->testFetchTool();
            $this->testInstallTool();
            $this->testManageTool();
            $this->testManageWooTool();
            $this->testManageElementorTool();
        } finally {
            $this->cleanup();
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

    // =====================================================================
    // read
    // =====================================================================

    private function testReadTool(): void
    {
        echo "--- read ---\n";

        // Read a WordPress option
        $result = $this->registry->execute('read', ['type' => 'option', 'name' => 'blogname']);
        if ($result['success'] && $result['name'] === 'blogname' && !empty($result['value'])) {
            $this->pass("read option 'blogname': got value '" . substr($result['value'], 0, 30) . "'");
        } else {
            $this->fail("read option 'blogname': " . ($result['error'] ?? 'unexpected response'));
        }

        // Read current user
        $currentUser = wp_get_current_user();
        $result = $this->registry->execute('read', ['type' => 'user', 'id' => $currentUser->ID]);
        if ($result['success'] && $result['data']['login'] === $currentUser->user_login) {
            $this->pass("read user id={$currentUser->ID}: matched login");
        } else {
            $this->fail("read user: " . ($result['error'] ?? 'unexpected response'));
        }

        // Error case: missing params
        $result = $this->registry->execute('read', []);
        if (!$result['success'] && !empty($result['error'])) {
            $this->pass("read with empty params: correctly returned error");
        } else {
            $this->fail("read with empty params: should have returned error");
        }
    }

    // =====================================================================
    // list
    // =====================================================================

    private function testListTool(): void
    {
        echo "--- list ---\n";

        $result = $this->registry->execute('list', ['type' => 'plugin']);
        if ($result['success'] && is_array($result['data'] ?? null) && count($result['data']) > 0) {
            $this->pass("list plugins: found " . count($result['data']) . " plugins");
        } else {
            $this->fail("list plugins: " . ($result['error'] ?? 'unexpected response'));
        }

        $result = $this->registry->execute('list', ['type' => 'theme']);
        if ($result['success'] && is_array($result['data'] ?? null) && count($result['data']) > 0) {
            $this->pass("list themes: found " . count($result['data']) . " themes");
        } else {
            $this->fail("list themes: " . ($result['error'] ?? 'unexpected response'));
        }
    }

    // =====================================================================
    // write + edit
    // =====================================================================

    private function testWriteAndEditTool(): void
    {
        echo "--- write + edit ---\n";

        $testDir = WP_CONTENT_DIR . '/uploads/levi-test-' . wp_generate_uuid4();
        $testFile = $testDir . '/test.php';
        wp_mkdir_p($testDir);

        // Write a file
        $content = "<?php\n// Test file generated by Levi Integration Test\n\$foo = 'bar';\n";
        $result = $this->registry->execute('write', [
            'type' => 'file',
            'path' => $testFile,
            'content' => $content,
        ]);

        if ($result['success'] && file_exists($testFile)) {
            $this->pass("write file: created {$testFile}");
            $this->created[] = ['type' => 'file', 'path' => $testFile];
        } else {
            $this->fail("write file: " . ($result['error'] ?? 'file not created'));
            return;
        }

        // Edit the file
        $result = $this->registry->execute('edit', [
            'path' => $testFile,
            'replacements' => [
                ['search' => "\$foo = 'bar';", 'replace' => "\$foo = 'baz';"],
            ],
        ]);

        if ($result['success']) {
            $fileContent = file_get_contents($testFile);
            if (str_contains($fileContent, "\$foo = 'baz';")) {
                $this->pass("edit file: replacement applied correctly");
            } else {
                $this->fail("edit file: replacement not found in file");
            }
        } else {
            $this->fail("edit file: " . ($result['error'] ?? 'edit failed'));
        }

        // Cleanup
        $this->recursiveDelete($testDir);
    }

    // =====================================================================
    // grep
    // =====================================================================

    private function testGrepTool(): void
    {
        echo "--- grep ---\n";

        $result = $this->registry->execute('grep', [
            'pattern' => 'wp-content',
            'directory' => WP_CONTENT_DIR,
        ]);

        if ($result['success'] && is_array($result['data'] ?? null)) {
            $this->pass("grep 'wp-content' in wp-content: found matches");
        } else {
            $this->fail("grep: " . ($result['error'] ?? 'no matches'));
        }
    }

    // =====================================================================
    // execute
    // =====================================================================

    private function testExecuteTool(): void
    {
        echo "--- execute ---\n";

        // Execute simple PHP
        $result = $this->registry->execute('execute', [
            'type' => 'php',
            'code' => 'return "hello from execute tool";',
        ]);

        if ($result['success'] && str_contains($result['output'] ?? '', 'hello from execute tool')) {
            $this->pass("execute php: got expected output");
        } else {
            $this->fail("execute php: " . ($result['error'] ?? ($result['output'] ?? 'no output')));
        }

        // Execute WP code
        $result = $this->registry->execute('execute', [
            'type' => 'wp',
            'code' => 'return get_bloginfo("name");',
        ]);

        if ($result['success'] && !empty($result['output'])) {
            $this->pass("execute wp: got blog name");
        } else {
            $this->fail("execute wp: " . ($result['error'] ?? 'no output'));
        }
    }

    // =====================================================================
    // health_check
    // =====================================================================

    private function testHealthCheckTool(): void
    {
        echo "--- health_check ---\n";

        $result = $this->registry->execute('health_check', []);

        if ($result['success'] && isset($result['score']) && $result['score'] >= 0 && $result['score'] <= 100) {
            $this->pass("health_check: score = {$result['score']}/100");
        } else {
            $this->fail("health_check: " . ($result['error'] ?? 'unexpected response'));
        }

        // With specific checks
        $result = $this->registry->execute('health_check', ['checks' => ['core', 'plugins']]);
        if ($result['success'] && isset($result['results']['core']) && isset($result['results']['plugins'])) {
            $this->pass("health_check with checks=['core','plugins']: got specific results");
        } else {
            $this->fail("health_check with specific checks: " . ($result['error'] ?? 'unexpected response'));
        }
    }

    // =====================================================================
    // fetch
    // =====================================================================

    private function testFetchTool(): void
    {
        echo "--- fetch ---\n";

        $result = $this->registry->execute('fetch', [
            'url' => home_url('/wp-json/'),
            'method' => 'GET',
        ]);

        if ($result['success'] && ($result['status_code'] ?? 0) === 200) {
            $this->pass("fetch wp-json: HTTP 200");
        } else {
            $this->fail("fetch wp-json: " . ($result['error'] ?? "status {$result['status_code']}"));
        }
    }

    // =====================================================================
    // install
    // =====================================================================

    private function testInstallTool(): void
    {
        echo "--- install ---\n";

        // We won't actually install/delete plugins in integration test —
        // instead verify the tool validates parameters correctly.
        $result = $this->registry->execute('install', []);
        if (!$result['success'] && !empty($result['error'])) {
            $this->pass("install with empty params: correctly rejected");
        } else {
            $this->fail("install with empty params: should have been rejected");
        }

        $result = $this->registry->execute('install', ['target' => 'plugin', 'action' => 'invalid']);
        if (!$result['success'] && !empty($result['error'])) {
            $this->pass("install with invalid action: correctly rejected");
        } else {
            $this->fail("install with invalid action: should have been rejected");
        }
    }

    // =====================================================================
    // manage
    // =====================================================================

    private function testManageTool(): void
    {
        echo "--- manage ---\n";

        $result = $this->registry->execute('manage', []);
        if (!$result['success'] && !empty($result['error'])) {
            $this->pass("manage with empty params: correctly rejected");
        } else {
            $this->fail("manage with empty params: should have been rejected");
        }

        // List taxonomies
        $result = $this->registry->execute('manage', [
            'entity' => 'taxonomy',
            'action' => 'list',
        ]);
        if ($result['success'] && is_array($result['data'] ?? null)) {
            $this->pass("manage taxonomy list: got " . count($result['data']) . " taxonomies");
        } else {
            $this->fail("manage taxonomy list: " . ($result['error'] ?? 'unexpected'));
        }
    }

    // =====================================================================
    // manage_woo
    // =====================================================================

    private function testManageWooTool(): void
    {
        echo "--- manage_woo ---\n";

        $result = $this->registry->execute('manage_woo', []);
        if (!$result['success'] && !empty($result['error'])) {
            $this->pass("manage_woo with empty params: correctly rejected");
        } else {
            $this->fail("manage_woo with empty params: should have been rejected");
        }

        // If WooCommerce is not active, expect an informative error
        if (!class_exists('WooCommerce')) {
            $this->pass("manage_woo: WooCommerce not active — skipping functional tests");
            return;
        }

        $result = $this->registry->execute('manage_woo', [
            'entity' => 'product',
            'action' => 'list',
        ]);
        if ($result['success'] && is_array($result['data'] ?? null)) {
            $this->pass("manage_woo product list: got " . count($result['data']) . " products");
        } else {
            $this->fail("manage_woo product list: " . ($result['error'] ?? 'unexpected'));
        }
    }

    // =====================================================================
    // manage_elementor
    // =====================================================================

    private function testManageElementorTool(): void
    {
        echo "--- manage_elementor ---\n";

        $result = $this->registry->execute('manage_elementor', []);
        if (!$result['success'] && !empty($result['error'])) {
            $this->pass("manage_elementor with empty params: correctly rejected");
        } else {
            $this->fail("manage_elementor with empty params: should have been rejected");
        }

        if (!class_exists('Elementor\Plugin')) {
            $this->pass("manage_elementor: Elementor not active — skipping functional tests");
            return;
        }

        $result = $this->registry->execute('manage_elementor', [
            'entity' => 'template',
            'action' => 'list',
        ]);
        if ($result['success'] && is_array($result['data'] ?? null)) {
            $this->pass("manage_elementor template list: got " . count($result['data']) . " templates");
        } else {
            $this->fail("manage_elementor template list: " . ($result['error'] ?? 'unexpected'));
        }
    }

    // =====================================================================
    // Cleanup
    // =====================================================================

    private function cleanup(): void
    {
        foreach ($this->created as $item) {
            if ($item['type'] === 'file' && file_exists($item['path'])) {
                @unlink($item['path']);
            }
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

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

$test = new GenericToolIntegrationTest();
exit($test->run());
