<?php

namespace Levi\Agent\Testing;

use Levi\Agent\API\ChatController;
use WP_REST_Request;

abstract class TestCase {
    protected string $sessionId;
    protected TestResult $result;
    protected bool $verbose;

    abstract public function name(): string;
    abstract public function description(): string;
    abstract protected function message(): string;
    abstract protected function validate(): void;

    public function __construct(bool $verbose = false) {
        $this->verbose = $verbose;
        $this->sessionId = 'levi_test_' . wp_generate_uuid4();
        $this->result = new TestResult($this->name());
    }

    protected function setUp(): void {}
    protected function tearDown(): void {}

    /**
     * Additional messages sent after confirmations are resolved,
     * e.g. follow-up instructions. Override in subclass to send multi-turn conversations.
     * @return string[]
     */
    protected function followUpMessages(): array {
        return [];
    }

    public function run(): TestResult {
        $start = microtime(true);
        $this->log('info', ['message' => 'Starting: ' . $this->name()]);

        try {
            $this->setUp();
            $this->log('info', ['message' => 'Setup complete']);

            $response = $this->sendMessage($this->message());

            foreach ($this->followUpMessages() as $msg) {
                $response = $this->sendMessage($msg);
            }

            $this->validate();
            $this->result->status = $this->result->passed() ? 'passed' : 'failed';
        } catch (\Throwable $e) {
            $this->result->status = 'error';
            $this->result->error = $e->getMessage() . "\n" . $e->getTraceAsString();
            $this->log('error', ['message' => $e->getMessage()]);
        }

        try {
            $this->tearDown();
            $this->log('info', ['message' => 'Teardown complete']);
        } catch (\Throwable $e) {
            $this->log('warning', ['message' => 'Teardown error: ' . $e->getMessage()]);
        }

        $this->result->durationSeconds = microtime(true) - $start;
        return $this->result;
    }

    // ── Interaction helpers ──────────────────────────────────────────

    protected function sendMessage(string $message): array {
        $this->log('send', ['message' => $message]);

        $request = new WP_REST_Request('POST', '/levi-agent/v1/chat');
        $request->set_param('message', $message);
        $request->set_param('session_id', $this->sessionId);

        $controller = $this->getController();
        $response = $controller->sendMessage($request);
        $data = $response->get_data();
        $status = $response->get_status();

        $this->log('response', [
            'status' => $status,
            'message' => mb_substr($data['message'] ?? $data['error'] ?? '', 0, 500),
            'tools_used' => $data['tools_used'] ?? [],
        ]);

        if ($status >= 400) {
            throw new \RuntimeException('API error (' . $status . '): ' . ($data['error'] ?? 'unknown'));
        }

        return $data;
    }

    // ── Assertions ───────────────────────────────────────────────────

    protected function assertTrue(bool $condition, string $label, string $detail = ''): void {
        $this->result->addAssertion($label, $condition, $detail ?: ($condition ? 'OK' : 'FAIL'));
        $this->log($condition ? 'assert_pass' : 'assert_fail', ['label' => $label, 'detail' => $detail]);
    }

    protected function assertFalse(bool $condition, string $label, string $detail = ''): void {
        $this->assertTrue(!$condition, $label, $detail);
    }

    protected function assertEquals($expected, $actual, string $label): void {
        $passed = $expected === $actual;
        $detail = $passed ? "OK: {$expected}" : "Expected: {$expected}, Got: {$actual}";
        $this->result->addAssertion($label, $passed, $detail);
        $this->log($passed ? 'assert_pass' : 'assert_fail', ['label' => $label, 'detail' => $detail]);
    }

    protected function assertPluginExists(string $slug): void {
        $dir = WP_PLUGIN_DIR . '/' . $slug;
        $this->assertTrue(is_dir($dir), "Plugin '{$slug}' directory exists", $dir);
    }

    protected function assertPluginNotExists(string $slug): void {
        $dir = WP_PLUGIN_DIR . '/' . $slug;
        $this->assertFalse(is_dir($dir), "Plugin '{$slug}' directory does not exist", $dir);
    }

    protected function assertPluginActive(string $pluginFile): void {
        $this->assertTrue(
            is_plugin_active($pluginFile),
            "Plugin '{$pluginFile}' is active"
        );
    }

    protected function assertPluginInactive(string $pluginFile): void {
        $this->assertFalse(
            is_plugin_active($pluginFile),
            "Plugin '{$pluginFile}' is inactive"
        );
    }

    protected function assertPageExistsByTitle(string $title): void {
        $page = get_page_by_title($title, OBJECT, 'page');
        if ($page === null) {
            $pages = get_posts([
                'post_type' => 'page',
                'title' => $title,
                'post_status' => 'any',
                'numberposts' => 1,
            ]);
            $page = $pages[0] ?? null;
        }
        $this->assertTrue($page !== null, "Page '{$title}' exists", $page ? "ID: {$page->ID}" : 'Not found');
    }

    protected function assertFileContains(string $path, string $needle, string $label = ''): void {
        $label = $label ?: "File contains '{$needle}'";
        if (!file_exists($path)) {
            $this->result->addAssertion($label, false, "File not found: {$path}");
            return;
        }
        $content = file_get_contents($path);
        $this->assertTrue(
            str_contains($content, $needle),
            $label,
            "In: {$path}"
        );
    }

    protected function assertNoFatalError(string $url, string $label = ''): void {
        $label = $label ?: "No fatal error at {$url}";
        $response = wp_remote_get($url, ['timeout' => 30, 'sslverify' => false]);
        if (is_wp_error($response)) {
            $this->result->addAssertion($label, false, 'Request failed: ' . $response->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $hasFatal = str_contains($body, 'Fatal error') || str_contains($body, 'Parse error');
        $this->assertTrue(
            $code < 500 && !$hasFatal,
            $label,
            "HTTP {$code}" . ($hasFatal ? ' (fatal error in body)' : '')
        );
    }

    // ── Utilities ────────────────────────────────────────────────────

    protected function log(string $type, array $data): void {
        $this->result->appendLog($type, $data);
        if ($this->verbose) {
            $prefix = match ($type) {
                'send' => '  📤',
                'response' => '  📥',
                'confirm' => '  🔒',
                'confirm_response' => '  🔓',
                'assert_pass' => '  ✅',
                'assert_fail' => '  ❌',
                'error' => '  💥',
                'warning' => '  ⚠️',
                default => '  ℹ️',
            };
            $msg = $data['message'] ?? $data['label'] ?? wp_json_encode($data);
            \WP_CLI::log($prefix . ' ' . $msg);
        }
    }

    private function getController(): ChatController {
        $controller = ChatController::getInstance();
        if ($controller === null) {
            $controller = new ChatController();
            $controller = ChatController::getInstance();
        }
        if ($controller === null) {
            throw new \RuntimeException('ChatController could not be initialized.');
        }
        return $controller;
    }

    /**
     * Clean up a plugin completely: deactivate, delete files.
     */
    protected function cleanupPlugin(string $slug): void {
        $pluginDir = WP_PLUGIN_DIR . '/' . $slug;
        if (!is_dir($pluginDir)) {
            return;
        }

        $allPlugins = get_plugins();
        foreach ($allPlugins as $pluginFile => $pluginData) {
            if (str_starts_with($pluginFile, $slug . '/')) {
                deactivate_plugins($pluginFile, true);
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $filesystem = \WP_Filesystem();
        if (function_exists('delete_plugins')) {
            $matching = [];
            foreach ($allPlugins as $pluginFile => $pluginData) {
                if (str_starts_with($pluginFile, $slug . '/')) {
                    $matching[] = $pluginFile;
                }
            }
            if (!empty($matching)) {
                delete_plugins($matching);
            }
        }

        if (is_dir($pluginDir)) {
            $this->recursiveDelete($pluginDir);
        }
    }

    protected function cleanupPage(string $title): void {
        $pages = get_posts([
            'post_type' => 'page',
            'title' => $title,
            'post_status' => 'any',
            'numberposts' => -1,
        ]);
        foreach ($pages as $page) {
            wp_delete_post($page->ID, true);
        }
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
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

    /**
     * Create a minimal dummy plugin for deletion tests.
     */
    protected function createDummyPlugin(string $slug, string $name): void {
        $dir = WP_PLUGIN_DIR . '/' . $slug;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $mainFile = $dir . '/' . $slug . '.php';
        file_put_contents($mainFile, "<?php\n/**\n * Plugin Name: {$name}\n * Description: Dummy plugin for testing\n * Version: 1.0.0\n */\n");
        activate_plugin($slug . '/' . $slug . '.php');
    }
}
