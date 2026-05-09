<?php
/**
 * Generic Tool Contract Test
 *
 * Validates all 12 generic tools from the 0.9.0 architecture conform to
 * the ToolInterface contract, produce correct error responses, and have
 * valid JSON Schema definitions.
 *
 * Run inside DDEV: ddev exec php wp-content/plugins/levi-agent/tests/GenericToolContractTest.php
 */

$wpLoadCandidates = [
    dirname(__DIR__, 4) . "/wp-load.php",
    dirname(__DIR__, 4) . "/web/wp-load.php",
    dirname(__DIR__) . "/wordpress/web/wp-load.php",
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
    fwrite(
        STDERR,
        "ERROR: Could not find wp-load.php. Run this inside the DDEV container.\n",
    );
    exit(1);
}

if (!class_exists(\Levi\Agent\AI\Tools\GenericRegistry::class)) {
    fwrite(
        STDERR,
        "ERROR: Levi Agent plugin is not active or autoloading failed.\n",
    );
    exit(1);
}

class GenericToolContractTest
{
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;
    private array $failures = [];
    private array $warningMessages = [];

    private const MIN_DESCRIPTION_SENTENCES = 3;
    private const EXPECTED_MINIMAL_TOOLS = 5;
    private const EXPECTED_STANDARD_TOOLS = 10;
    private const EXPECTED_FULL_TOOLS = 12;

    public function run(): int
    {
        echo "=== Generic Tool Contract Test (0.9.0) ===\n\n";

        // --- Registry-level tests ---
        $this->testRegistryProfiles();
        $this->testRegistryExecuteUnknownTool();
        $this->testRegistryGetDefinitionUnknownTool();
        $this->testRegistryToolNameUniqueness();

        // --- Individual tool tests ---
        $registry = new \Levi\Agent\AI\Tools\GenericRegistry(
            \Levi\Agent\AI\Tools\GenericRegistry::PROFILE_FULL,
        );
        $tools = $registry->getAll();

        echo "Tools registered: " . count($tools) . "\n\n";

        foreach ($tools as $tool) {
            $this->testTool($tool, $registry);
        }

        // --- Summary ---
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

    // =====================================================================
    // Registry-level tests
    // =====================================================================

    private function testRegistryProfiles(): void
    {
        $minimal = new \Levi\Agent\AI\Tools\GenericRegistry(
            \Levi\Agent\AI\Tools\GenericRegistry::PROFILE_MINIMAL,
        );
        $standard = new \Levi\Agent\AI\Tools\GenericRegistry(
            \Levi\Agent\AI\Tools\GenericRegistry::PROFILE_STANDARD,
        );
        $full = new \Levi\Agent\AI\Tools\GenericRegistry(
            \Levi\Agent\AI\Tools\GenericRegistry::PROFILE_FULL,
        );

        $minimalCount = count($minimal->getAll());
        $standardCount = count($standard->getAll());
        $fullCount = count($full->getAll());

        if ($minimalCount === self::EXPECTED_MINIMAL_TOOLS) {
            $this->pass(
                "Registry MINIMAL profile has {$minimalCount} tools (expected " .
                    self::EXPECTED_MINIMAL_TOOLS .
                    ")",
            );
        } else {
            $this->fail(
                "Registry MINIMAL profile has {$minimalCount} tools, expected " .
                    self::EXPECTED_MINIMAL_TOOLS,
            );
        }

        if ($standardCount === self::EXPECTED_STANDARD_TOOLS) {
            $this->pass(
                "Registry STANDARD profile has {$standardCount} tools (expected " .
                    self::EXPECTED_STANDARD_TOOLS .
                    ")",
            );
        } else {
            $this->fail(
                "Registry STANDARD profile has {$standardCount} tools, expected " .
                    self::EXPECTED_STANDARD_TOOLS,
            );
        }

        if ($fullCount === self::EXPECTED_FULL_TOOLS) {
            $this->pass(
                "Registry FULL profile has {$fullCount} tools (expected " .
                    self::EXPECTED_FULL_TOOLS .
                    ")",
            );
        } else {
            $this->fail(
                "Registry FULL profile has {$fullCount} tools, expected " .
                    self::EXPECTED_FULL_TOOLS,
            );
        }

        // Verify standard contains all minimal tools
        foreach ($minimal->getAll() as $name => $tool) {
            if ($standard->get($name) !== null) {
                $this->pass("STANDARD profile contains minimal tool '{$name}'");
            } else {
                $this->fail("STANDARD profile missing minimal tool '{$name}'");
            }
        }

        // Verify full contains all standard tools
        foreach ($standard->getAll() as $name => $tool) {
            if ($full->get($name) !== null) {
                $this->pass("FULL profile contains standard tool '{$name}'");
            } else {
                $this->fail("FULL profile missing standard tool '{$name}'");
            }
        }
    }

    private function testRegistryExecuteUnknownTool(): void
    {
        $registry = new \Levi\Agent\AI\Tools\GenericRegistry();
        $result = $registry->execute("nonexistent_tool", []);

        if (
            ($result["success"] ?? true) === false &&
            !empty($result["error"])
        ) {
            $this->pass("Registry execute() returns error for unknown tool");
        } else {
            $this->fail(
                "Registry execute() did not return proper error for unknown tool",
            );
        }
    }

    private function testRegistryGetDefinitionUnknownTool(): void
    {
        $registry = new \Levi\Agent\AI\Tools\GenericRegistry();
        $def = $registry->getDefinitionForTool("nonexistent_tool");

        if ($def === null) {
            $this->pass(
                "Registry getDefinitionForTool() returns null for unknown tool",
            );
        } else {
            $this->fail(
                "Registry getDefinitionForTool() did not return null for unknown tool",
            );
        }
    }

    private function testRegistryToolNameUniqueness(): void
    {
        $registry = new \Levi\Agent\AI\Tools\GenericRegistry(
            \Levi\Agent\AI\Tools\GenericRegistry::PROFILE_FULL,
        );
        $tools = $registry->getAll();
        $names = array_map(fn($t) => $t->getName(), $tools);

        if (count($names) === count(array_unique($names))) {
            $this->pass("All tool names are unique");
        } else {
            $duplicates = array_diff_assoc($names, array_unique($names));
            $this->fail(
                "Duplicate tool names found: " . implode(", ", $duplicates),
            );
        }
    }

    // =====================================================================
    // Individual tool tests
    // =====================================================================

    private function testTool(
        \Levi\Agent\AI\Tools\ToolInterface $tool,
        \Levi\Agent\AI\Tools\GenericRegistry $registry,
    ): void {
        $name = $tool->getName();
        echo "Testing: $name\n";

        $this->assertNameValid($name);
        $this->assertDescriptionQuality($name, $tool->getDescription());
        $this->assertParameterDescriptions($name, $tool->getParameters());
        $this->assertParameterTypes($name, $tool->getParameters());
        $this->assertErrorResponseShape($name, $tool);
        $this->assertDefinitionShape(
            $name,
            $registry->getDefinitionForTool($name),
        );
        $this->assertCheckPermissionExists($name, $tool);
        $this->assertInputExamples($name, $tool);

        // Tool-specific validations
        $this->assertToolSpecifics($name, $tool);
    }

    private function assertNameValid(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $this->pass("{$name}: name is valid snake_case");
        } else {
            $this->fail("{$name}: name '{$name}' is not valid snake_case");
        }
    }

    private function assertDescriptionQuality(
        string $toolName,
        string $description,
    ): void {
        $trimmed = trim($description);

        if ($trimmed === "") {
            $this->fail("{$toolName}: description is empty");
            return;
        }

        // Count sentences (simplistic: periods, exclamation marks, question marks)
        $sentences = preg_split("/(?<=[.!?])\s+/", $trimmed);
        $sentences = array_filter($sentences, fn($s) => strlen(trim($s)) > 5);
        $count = count($sentences);

        if ($count >= self::MIN_DESCRIPTION_SENTENCES) {
            $this->pass(
                "{$toolName}: description has {$count} sentences (>= " .
                    self::MIN_DESCRIPTION_SENTENCES .
                    ")",
            );
        } else {
            $this->warn(
                "{$toolName}: description has only {$count} sentences, recommend >= " .
                    self::MIN_DESCRIPTION_SENTENCES,
            );
        }

        // Check for German instructions (Levi is German)
        if (
            str_contains($trimmed, "Verwende") ||
            str_contains($trimmed, "Nutze") ||
            str_contains($trimmed, "Gib")
        ) {
            $this->pass(
                "{$toolName}: description contains German instructional phrases",
            );
        }
    }

    private function assertParameterDescriptions(
        string $toolName,
        array $params,
    ): void {
        if (empty($params)) {
            $this->warn("{$toolName}: has no parameters");
            return;
        }

        $missing = [];
        foreach ($params as $paramName => $config) {
            if (empty($config["description"])) {
                $missing[] = $paramName;
            }
        }

        if (empty($missing)) {
            $this->pass(
                "{$toolName}: all " .
                    count($params) .
                    " parameters have descriptions",
            );
        } else {
            $this->fail(
                "{$toolName}: parameters missing description: " .
                    implode(", ", $missing),
            );
        }
    }

    private function assertParameterTypes(string $toolName, array $params): void
    {
        $validTypes = [
            "string",
            "integer",
            "boolean",
            "array",
            "object",
            "number",
        ];
        $invalid = [];

        foreach ($params as $paramName => $config) {
            $type = $config["type"] ?? null;
            if ($type === null) {
                $invalid[] = "{$paramName}: missing type";
            } elseif (!in_array($type, $validTypes, true)) {
                $invalid[] = "{$paramName}: invalid type '{$type}'";
            }
        }

        if (empty($invalid)) {
            $this->pass("{$toolName}: all parameters have valid types");
        } else {
            $this->fail("{$toolName}: " . implode("; ", $invalid));
        }
    }

    private function assertErrorResponseShape(
        string $toolName,
        \Levi\Agent\AI\Tools\ToolInterface $tool,
    ): void {
        try {
            $result = $tool->execute([]);
        } catch (\Throwable $e) {
            $this->fail(
                "{$toolName}: execute([]) threw " .
                    get_class($e) .
                    ": " .
                    $e->getMessage(),
            );
            return;
        }

        if (!is_array($result)) {
            $this->fail("{$toolName}: execute([]) did not return an array");
            return;
        }

        if (!array_key_exists("success", $result)) {
            $this->fail("{$toolName}: response missing 'success' key");
            return;
        }

        if ($result["success"] === true) {
            $this->warn(
                "{$toolName}: execute([]) returned success=true (might accept empty params)",
            );
            return;
        }

        if (empty($result["error"]) && empty($result["message"])) {
            $this->fail(
                "{$toolName}: error response has success=false but no 'error' or 'message'",
            );
            return;
        }

        $this->pass("{$toolName}: error response shape is correct");
    }

    private function assertDefinitionShape(string $toolName, ?array $def): void
    {
        if ($def === null) {
            $this->warn(
                "{$toolName}: no definition returned (permission denied?)",
            );
            return;
        }

        $fn = $def["function"] ?? [];
        if (
            empty($fn["name"]) ||
            empty($fn["description"]) ||
            empty($fn["parameters"])
        ) {
            $this->fail(
                "{$toolName}: definition missing name, description, or parameters",
            );
            return;
        }

        $params = $fn["parameters"];
        if (
            ($params["type"] ?? "") !== "object" ||
            !isset($params["properties"])
        ) {
            $this->fail(
                "{$toolName}: parameters schema is not type=object with properties",
            );
            return;
        }

        if (!isset($params["required"]) || !is_array($params["required"])) {
            $this->warn("{$toolName}: definition missing 'required' array");
        }

        $this->pass("{$toolName}: definition shape is valid");
    }

    private function assertCheckPermissionExists(
        string $toolName,
        \Levi\Agent\AI\Tools\ToolInterface $tool,
    ): void {
        try {
            $hasPermission = $tool->checkPermission();
            $this->pass(
                "{$toolName}: checkPermission() returns " .
                    ($hasPermission ? "true" : "false"),
            );
        } catch (\Throwable $e) {
            $this->fail(
                "{$toolName}: checkPermission() threw " .
                    get_class($e) .
                    ": " .
                    $e->getMessage(),
            );
        }
    }

    private function assertInputExamples(
        string $toolName,
        \Levi\Agent\AI\Tools\ToolInterface $tool,
    ): void {
        if (!method_exists($tool, "getInputExamples")) {
            $this->warn("{$toolName}: no getInputExamples() method");
            return;
        }

        $examples = $tool->getInputExamples();
        if (empty($examples)) {
            $this->warn("{$toolName}: getInputExamples() returns empty array");
        } else {
            $this->pass(
                "{$toolName}: has " . count($examples) . " input example(s)",
            );
        }
    }

    // =====================================================================
    // Tool-specific contract assertions
    // =====================================================================

    private function assertToolSpecifics(
        string $name,
        \Levi\Agent\AI\Tools\ToolInterface $tool,
    ): void {
        switch ($name) {
            case "read":
                $this->assertHasEnumParameter($name, $tool, "type", [
                    "file",
                    "post",
                    "page",
                    "option",
                    "user",
                    "media",
                ]);
                break;
            case "write":
                $this->assertHasEnumParameter($name, $tool, "type", [
                    "file",
                    "post",
                    "page",
                    "option",
                    "user",
                ]);
                break;
            case "edit":
                $this->assertHasParameter($name, $tool, "path");
                $this->assertHasParameter($name, $tool, "replacements");
                break;
            case "list":
                $this->assertHasEnumParameter($name, $tool, "type", [
                    "file",
                    "post",
                    "page",
                    "plugin",
                    "theme",
                    "user",
                    "media",
                ]);
                break;
            case "grep":
                $this->assertHasParameter($name, $tool, "pattern");
                break;
            case "execute":
                $this->assertHasEnumParameter($name, $tool, "type", [
                    "php",
                    "wp",
                    "cli",
                ]);
                break;
            case "install":
                $this->assertHasEnumParameter($name, $tool, "target", [
                    "plugin",
                    "theme",
                ]);
                break;
            case "manage":
                $this->assertHasEnumParameter($name, $tool, "entity", [
                    "taxonomy",
                    "menu",
                    "cron",
                    "media",
                    "meta",
                ]);
                break;
            case "manage_woo":
                $this->assertHasEnumParameter($name, $tool, "entity", [
                    "product",
                    "order",
                    "coupon",
                    "setting",
                ]);
                break;
            case "manage_elementor":
                $this->assertHasEnumParameter($name, $tool, "entity", [
                    "template",
                    "page",
                    "widget",
                    "setting",
                ]);
                break;
            case "fetch":
                $this->assertHasParameter($name, $tool, "url");
                break;
            case "health_check":
                // No params expected
                break;
        }
    }

    private function assertHasParameter(
        string $toolName,
        \Levi\Agent\AI\Tools\ToolInterface $tool,
        string $paramName,
    ): void {
        $params = $tool->getParameters();
        if (isset($params[$paramName])) {
            $this->pass("{$toolName}: has required parameter '{$paramName}'");
        } else {
            $this->fail(
                "{$toolName}: missing required parameter '{$paramName}'",
            );
        }
    }

    private function assertHasEnumParameter(
        string $toolName,
        \Levi\Agent\AI\Tools\ToolInterface $tool,
        string $paramName,
        array $expectedValues,
    ): void {
        $params = $tool->getParameters();
        if (!isset($params[$paramName])) {
            $this->fail("{$toolName}: missing enum parameter '{$paramName}'");
            return;
        }

        $enum = $params[$paramName]["enum"] ?? [];
        $missing = array_diff($expectedValues, $enum);
        $extra = array_diff($enum, $expectedValues);

        if (empty($missing) && empty($extra)) {
            $this->pass(
                "{$toolName}: parameter '{$paramName}' has correct enum values",
            );
        } else {
            $details = [];
            if (!empty($missing)) {
                $details[] = "missing: " . implode(", ", $missing);
            }
            if (!empty($extra)) {
                $details[] = "unexpected: " . implode(", ", $extra);
            }
            $this->fail(
                "{$toolName}: parameter '{$paramName}' enum mismatch (" .
                    implode("; ", $details) .
                    ")",
            );
        }
    }

    // =====================================================================
    // Result helpers
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

    private function warn(string $msg): void
    {
        $this->warnings++;
        $this->warningMessages[] = $msg;
        echo "  WARN: $msg\n";
    }
}

$test = new GenericToolContractTest();
exit($test->run());
