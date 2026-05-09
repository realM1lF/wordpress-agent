<?php
/**
 * Generic Tool Workflow Test
 *
 * End-to-end workflows using generic tools (0.9.0).
 * Tests realistic multi-step agent workflows:
 *   1. Read-Analyze-Write: read post → list posts → edit post
 *   2. Plugin Dev: write plugin file → grep → edit → health_check
 *   3. Search-Replace: grep → read → edit in theme
 *   4. Site Audit: health_check → list plugins → read option
 *
 * Run inside DDEV:
 *   ddev exec php wp-content/plugins/levi-agent/tests/GenericToolWorkflowTest.php
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

class GenericToolWorkflowTest
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
        echo "=== Generic Tool Workflow Test ===\n";
        echo "WP User: " . wp_get_current_user()->user_login . "\n";
        echo "Profile: " . $this->registry->getProfile() . "\n\n";

        try {
            $this->workflowReadAnalyzeWrite();
            $this->workflowPluginDev();
            $this->workflowSearchAndReplace();
            $this->workflowSiteAudit();
            $this->workflowFallbackChain();
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

    // ========================================================================
    // Workflow 1: Read → Analyze → Write (Post CRUD)
    // ========================================================================

    private function workflowReadAnalyzeWrite(): void
    {
        echo "--- Workflow 1: Read-Analyze-Write Post ---\n";

        $read = $this->registry->get('read');
        $list = $this->registry->get('list');
        $edit = $this->registry->get('edit');

        // Step 1a: List posts to find one
        $result = $list->execute(['type' => 'post', 'limit' => 1]);
        $this->assertTrue($result['success'] ?? false, 'W1: list posts');
        $posts = $result['items'] ?? [];
        if (empty($posts)) {
            $this->skip('W1: no posts to test against');
            return;
        }
        $postId = $posts[0]['id'] ?? 0;

        // Step 1b: Read the post
        $result = $read->execute(['type' => 'post', 'id' => $postId]);
        $this->assertTrue($result['success'] ?? false, 'W1: read post #' . $postId);

        $originalTitle = $result['data']['post_title'] ?? '';

        // Step 1c: Edit the post title
        $newTitle = $originalTitle . ' [Workflow-Test]';
        $result = $edit->execute([
            'type' => 'post',
            'id' => $postId,
            'fields' => ['post_title' => $newTitle],
        ]);
        $this->assertTrue($result['success'] ?? false, 'W1: edit post title');

        // Step 1d: Verify the change
        $result = $read->execute(['type' => 'post', 'id' => $postId]);
        $this->assertTrue($result['success'] ?? false, 'W1: verify read after edit');
        $this->assertEquals($newTitle, $result['data']['post_title'] ?? '', 'W1: title changed');

        // Cleanup: restore original title
        $edit->execute([
            'type' => 'post',
            'id' => $postId,
            'fields' => ['post_title' => $originalTitle],
        ]);

        echo "  ✓ Read-Analyze-Write completed\n";
    }

    // ========================================================================
    // Workflow 2: Plugin Development (write → grep → edit → health_check)
    // ========================================================================

    private function workflowPluginDev(): void
    {
        echo "--- Workflow 2: Plugin Development ---\n";

        $write = $this->registry->get('write');
        $read  = $this->registry->get('read');
        $grep  = $this->registry->get('grep');
        $edit  = $this->registry->get('edit');
        $health = $this->registry->get('health_check');

        $slug = 'levi-workflow-test-' . substr(md5((string)time()), 0, 6);
        $pluginDir = WP_PLUGIN_DIR . '/' . $slug;
        $mainFile = $pluginDir . '/' . $slug . '.php';

        // Step 2a: Write main plugin file
        $pluginCode = "<?php\n/**\n * Plugin Name: Levi Workflow Test\n */\n\nfunction levi_workflow_hello() {\n    return 'Hello World';\n}\n";
        $result = $write->execute([
            'type' => 'file',
            'path' => $mainFile,
            'content' => $pluginCode,
        ]);
        $this->assertTrue($result['success'] ?? false, 'W2: write plugin file');
        $this->created[] = ['type' => 'plugin_dir', 'path' => $pluginDir];

        // Step 2b: Grep for the function
        $result = $grep->execute([
            'type' => 'plugin_file',
            'plugin' => $slug,
            'pattern' => 'levi_workflow_hello',
        ]);
        $this->assertTrue($result['success'] ?? false, 'W2: grep plugin function');
        $this->assertNotEmpty($result['matches'] ?? [], 'W2: found matches');

        // Step 2c: Edit the function
        $result = $edit->execute([
            'type' => 'file',
            'path' => $mainFile,
            'old_string' => "return 'Hello World';",
            'new_string' => "return 'Hello Workflow';",
        ]);
        $this->assertTrue($result['success'] ?? false, 'W2: edit plugin function');

        // Step 2d: Verify the edit
        $result = $read->execute([
            'type' => 'file',
            'path' => $mainFile,
        ]);
        $this->assertTrue($result['success'] ?? false, 'W2: read after edit');
        $this->assertStringContains('Hello Workflow', $result['content'] ?? '', 'W2: edit applied');

        // Step 2e: Health check the plugin
        $result = $health->execute(['plugin' => $slug]);
        $this->assertTrue($result['success'] ?? false, 'W2: health_check plugin');

        echo "  ✓ Plugin Development workflow completed\n";
    }

    // ========================================================================
    // Workflow 3: Search-and-Replace in active theme
    // ========================================================================

    private function workflowSearchAndReplace(): void
    {
        echo "--- Workflow 3: Search-and-Replace ---\n";

        $grep = $this->registry->get('grep');
        $read = $this->registry->get('read');
        $edit = $this->registry->get('edit');

        $theme = wp_get_theme()->get_stylesheet();

        // Step 3a: Grep for a common WordPress function in theme
        $result = $grep->execute([
            'type' => 'theme_file',
            'theme' => $theme,
            'pattern' => 'get_header',
        ]);
        $this->assertTrue($result['success'] ?? false, 'W3: grep theme');

        $matches = $result['matches'] ?? [];
        if (empty($matches)) {
            $this->skip('W3: no get_header found in theme');
            return;
        }
        $targetFile = $matches[0]['file'] ?? '';
        if (!$targetFile || !file_exists($targetFile)) {
            $this->skip('W3: target file not found');
            return;
        }

        // Step 3b: Read the file
        $result = $read->execute([
            'type' => 'file',
            'path' => $targetFile,
        ]);
        $this->assertTrue($result['success'] ?? false, 'W3: read target file');
        $original = $result['content'] ?? '';

        // Step 3c: Perform a safe no-op edit (add comment then remove)
        // We use a comment that doesn't affect functionality
        if (!str_contains($original, '// Levi workflow test marker')) {
            $result = $edit->execute([
                'type' => 'file',
                'path' => $targetFile,
                'old_string' => '<?php',
                'new_string' => "<?php\n// Levi workflow test marker (safe to remove)",
            ]);
            $this->assertTrue($result['success'] ?? false, 'W3: add marker comment');

            // Step 3d: Verify
            $result = $read->execute([
                'type' => 'file',
                'path' => $targetFile,
            ]);
            $this->assertTrue($result['success'] ?? false, 'W3: verify marker');
            $this->assertStringContains('Levi workflow test marker', $result['content'] ?? '', 'W3: marker present');

            // Step 3e: Revert
            $result = $edit->execute([
                'type' => 'file',
                'path' => $targetFile,
                'old_string' => "<?php\n// Levi workflow test marker (safe to remove)",
                'new_string' => '<?php',
            ]);
            $this->assertTrue($result['success'] ?? false, 'W3: revert marker');
        } else {
            echo "  (skipping edit — marker already present)\n";
        }

        echo "  ✓ Search-and-Replace workflow completed\n";
    }

    // ========================================================================
    // Workflow 4: Site Audit (health_check → list → read option)
    // ========================================================================

    private function workflowSiteAudit(): void
    {
        echo "--- Workflow 4: Site Audit ---\n";

        $health = $this->registry->get('health_check');
        $list   = $this->registry->get('list');
        $read   = $this->registry->get('read');

        // Step 4a: Health check all plugins
        $result = $health->execute([]);
        $this->assertTrue($result['success'] ?? false, 'W4: health_check all');
        $this->assertIsArray($result['checks'] ?? [], 'W4: checks array');

        // Step 4b: List plugins
        $result = $list->execute([
            'type' => 'plugin',
            'status' => 'active',
        ]);
        $this->assertTrue($result['success'] ?? false, 'W4: list active plugins');
        $plugins = $result['items'] ?? [];
        $this->assertNotEmpty($plugins, 'W4: has active plugins');

        // Step 4c: Read a core option
        $result = $read->execute([
            'type' => 'option',
            'name' => 'blogname',
        ]);
        $this->assertTrue($result['success'] ?? false, 'W4: read blogname option');
        $this->assertNotEmpty($result['value'] ?? '', 'W4: blogname has value');

        // Step 4d: Read a user
        $users = get_users(['number' => 1]);
        if (!empty($users)) {
            $result = $read->execute([
                'type' => 'user',
                'id' => $users[0]->ID,
            ]);
            $this->assertTrue($result['success'] ?? false, 'W4: read user');
        }

        echo "  ✓ Site Audit workflow completed\n";
    }

    // ========================================================================
    // Workflow 5: Fallback chain (test tool-level fallback hints)
    // ========================================================================

    private function workflowFallbackChain(): void
    {
        echo "--- Workflow 5: Fallback Chain ---\n";

        $read = $this->registry->get('read');

        // Intentionally call read with a non-existent post to trigger failure
        $result = $read->execute([
            'type' => 'post',
            'id' => 999999999,
        ]);
        $this->assertFalse($result['success'] ?? true, 'W5: read non-existent post should fail');

        // The fallback hint should suggest get_post
        $hint = $result['fallback_hint'] ?? null;
        if ($hint !== null) {
            $this->assertStringContains('get_post', $hint, 'W5: fallback hint mentions get_post');
            echo "  ✓ Fallback hint present: " . substr($hint, 0, 80) . "...\n";
        } else {
            echo "  (no fallback hint — read tool may not have mapping for post type)\n";
        }

        echo "  ✓ Fallback Chain workflow completed\n";
    }

    // ========================================================================
    // Cleanup
    // ========================================================================

    private function cleanup(): void
    {
        echo "\n--- Cleanup ---\n";
        foreach (array_reverse($this->created) as $item) {
            $path = $item['path'] ?? '';
            if ($item['type'] === 'plugin_dir' && is_dir($path)) {
                $this->recursiveDelete($path);
                echo "  Deleted plugin dir: " . basename($path) . "\n";
            }
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    // ========================================================================
    // Assertions
    // ========================================================================

    private function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = $message;
            echo "    ✗ $message\n";
        }
    }

    private function assertFalse(bool $condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        if ($expected === $actual) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = "$message (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")";
            echo "    ✗ $message\n";
        }
    }

    private function assertNotEmpty($value, string $message): void
    {
        if (!empty($value)) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = "$message (was empty)";
            echo "    ✗ $message\n";
        }
    }

    private function assertIsArray($value, string $message): void
    {
        if (is_array($value)) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = "$message (expected array, got: " . gettype($value) . ")";
            echo "    ✗ $message\n";
        }
    }

    private function assertStringContains(string $needle, string $haystack, string $message): void
    {
        if (str_contains($haystack, $needle)) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = "$message (expected to contain: $needle)";
            echo "    ✗ $message\n";
        }
    }

    private function skip(string $message): void
    {
        echo "    ⊘ SKIP: $message\n";
    }
}

// Run
$test = new GenericToolWorkflowTest();
exit($test->run());
