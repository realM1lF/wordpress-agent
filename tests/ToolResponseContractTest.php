<?php
/**
 * Tool Response Contract Test
 *
 * Standalone script that validates all registered tools conform to Anthropic's
 * quality standards and our internal response contracts.
 *
 * Run inside DDEV: ddev exec php wp-content/plugins/levi-agent/tests/ToolResponseContractTest.php
 */

// Bootstrap WordPress
$wpLoadCandidates = [
    dirname(__DIR__, 4) . '/wp-load.php',          // standard: plugins/levi-agent/tests -> wp root
    dirname(__DIR__, 4) . '/web/wp-load.php',       // bedrock-like
    dirname(__DIR__) . '/wordpress/web/wp-load.php', // local dev symlink
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

class ToolResponseContractTest
{
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;
    private array $failures = [];
    private array $warningMessages = [];

    private const MIN_DESCRIPTION_SENTENCES = 3;
    private const WRITE_TOOL_NAMES = [
        'write_plugin_file', 'patch_plugin_file', 'write_theme_file',
        'create_plugin', 'create_theme',
    ];

    public function run(): int
    {
        $registry = new \Levi\Agent\AI\Tools\Registry();
        $tools = $registry->getAll();

        echo "=== Tool Response Contract Test ===\n";
        echo "Tools registered: " . count($tools) . "\n\n";

        foreach ($tools as $tool) {
            $this->testTool($tool, $registry);
        }

        echo "\n=== Results ===\n";
        echo "Passed:   {$this->passed}\n";
        echo "Failed:   {$this->failed}\n";
        echo "Warnings: {$this->warnings}\n\n";

        if (!empty($this->failures)) {
            echo "--- FAILURES ---\n";
            foreach ($this->failures as $f) {
                echo "  FAIL: $f\n";
            }
            echo "\n";
        }

        if (!empty($this->warningMessages)) {
            echo "--- WARNINGS ---\n";
            foreach ($this->warningMessages as $w) {
                echo "  WARN: $w\n";
            }
            echo "\n";
        }

        return $this->failed > 0 ? 1 : 0;
    }

    private function testTool(\Levi\Agent\AI\Tools\ToolInterface $tool, \Levi\Agent\AI\Tools\Registry $registry): void
    {
        $name = $tool->getName();
        echo "Testing: $name\n";

        $this->assertDescriptionLength($name, $tool->getDescription());
        $this->assertParameterDescriptions($name, $tool->getParameters());
        $this->assertErrorResponseShape($name, $tool);
        $this->assertDefinitionShape($name, $registry->getDefinitionForTool($name));
        $this->checkInputExamples($name, $tool);
    }

    /**
     * Description must have at least MIN_DESCRIPTION_SENTENCES sentences.
     */
    private function assertDescriptionLength(string $toolName, string $description): void
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($description));
        $sentences = array_filter($sentences, fn($s) => strlen(trim($s)) > 5);
        $count = count($sentences);

        if ($count >= self::MIN_DESCRIPTION_SENTENCES) {
            $this->pass("$toolName: description has $count sentences (>= " . self::MIN_DESCRIPTION_SENTENCES . ')');
        } else {
            $this->fail("$toolName: description has only $count sentences, need >= " . self::MIN_DESCRIPTION_SENTENCES);
        }
    }

    /**
     * Every parameter should have a 'description' key.
     */
    private function assertParameterDescriptions(string $toolName, array $params): void
    {
        $missing = [];
        foreach ($params as $paramName => $config) {
            if (empty($config['description'])) {
                $missing[] = $paramName;
            }
        }

        if (empty($missing)) {
            $this->pass("$toolName: all " . count($params) . ' parameters have descriptions');
        } else {
            $this->fail("$toolName: parameters missing description: " . implode(', ', $missing));
        }
    }

    /**
     * Calling execute() with empty params should return an error response with correct shape.
     */
    private function assertErrorResponseShape(string $toolName, \Levi\Agent\AI\Tools\ToolInterface $tool): void
    {
        try {
            $result = $tool->execute([]);
        } catch (\Throwable $e) {
            $this->fail("$toolName: execute([]) threw " . get_class($e) . ': ' . $e->getMessage());
            return;
        }

        if (!is_array($result)) {
            $this->fail("$toolName: execute([]) did not return an array");
            return;
        }

        if (!array_key_exists('success', $result)) {
            $this->fail("$toolName: error response missing 'success' key");
            return;
        }

        if ($result['success'] === true) {
            $this->warn("$toolName: execute([]) returned success=true (might accept empty params)");
            return;
        }

        if (empty($result['error']) && empty($result['message'])) {
            $this->fail("$toolName: error response has success=false but no 'error' or 'message'");
            return;
        }

        $this->pass("$toolName: error response shape is correct");

        if (!empty($result['suggestion'])) {
            $this->pass("$toolName: error response includes 'suggestion'");
        }
    }

    /**
     * The Registry definition must have valid JSON Schema structure.
     */
    private function assertDefinitionShape(string $toolName, ?array $def): void
    {
        if ($def === null) {
            $this->warn("$toolName: no definition returned (permission denied?)");
            return;
        }

        $fn = $def['function'] ?? [];
        if (empty($fn['name']) || empty($fn['description']) || empty($fn['parameters'])) {
            $this->fail("$toolName: definition missing name, description, or parameters");
            return;
        }

        $params = $fn['parameters'];
        if (($params['type'] ?? '') !== 'object' || !isset($params['properties'])) {
            $this->fail("$toolName: parameters schema is not type=object with properties");
            return;
        }

        $this->pass("$toolName: definition shape is valid");
    }

    /**
     * Check if tool has input_examples (warning, not failure).
     */
    private function checkInputExamples(string $toolName, \Levi\Agent\AI\Tools\ToolInterface $tool): void
    {
        if (!method_exists($tool, 'getInputExamples')) {
            $this->warn("$toolName: no getInputExamples() method");
            return;
        }

        $examples = $tool->getInputExamples();
        if (empty($examples)) {
            $this->warn("$toolName: getInputExamples() returns empty array");
        } else {
            $this->pass("$toolName: has " . count($examples) . ' input example(s)');
        }
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

    private function warn(string $msg): void
    {
        $this->warnings++;
        $this->warningMessages[] = $msg;
        echo "  WARN: $msg\n";
    }
}

$test = new ToolResponseContractTest();
exit($test->run());
