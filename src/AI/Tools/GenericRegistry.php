<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Generic\ReadTool;
use Levi\Agent\AI\Tools\Generic\WriteTool;
use Levi\Agent\AI\Tools\Generic\EditTool;
use Levi\Agent\AI\Tools\Generic\ListTool;
use Levi\Agent\AI\Tools\Generic\GrepTool;
use Levi\Agent\AI\Tools\Generic\ExecuteTool;
use Levi\Agent\AI\Tools\Generic\InstallTool;
use Levi\Agent\AI\Tools\Generic\ManageTool;
use Levi\Agent\AI\Tools\Generic\ManageWooTool;
use Levi\Agent\AI\Tools\Generic\ManageElementorTool;
use Levi\Agent\AI\Tools\Generic\FetchTool;
use Levi\Agent\AI\Tools\Generic\HealthCheckTool;

/**
 * Generic Tool Registry — 12 unified tools replacing 44 specialized ones.
 *
 * This registry is used in the 0.9.0 architecture. It provides:
 * - No deferred loading needed (all 12 tools always available)
 * - ~60% fewer tokens per request vs. the old registry
 * - Higher tool choice accuracy via clear, focused schemas
 */
class GenericRegistry
{
    public const PROFILE_MINIMAL = "minimal";
    public const PROFILE_STANDARD = "standard";
    public const PROFILE_FULL = "full";
    public const VALID_PROFILES = [
        self::PROFILE_MINIMAL,
        self::PROFILE_STANDARD,
        self::PROFILE_FULL,
    ];

    /** @var ToolInterface[] */
    private array $tools = [];
    private string $profile;

    /**
     * Minimal profile — read-only + diagnostics.
     */
    private const MINIMAL_TOOLS = [
        ReadTool::class,
        ListTool::class,
        GrepTool::class,
        FetchTool::class,
        HealthCheckTool::class,
    ];

    /**
     * Core generic tools — standard profile and above.
     */
    private const CORE_TOOLS = [
        WriteTool::class,
        EditTool::class,
        ExecuteTool::class,
        InstallTool::class,
        ManageTool::class,
    ];

    /**
     * Extended tools — only in full profile.
     */
    private const EXTENDED_TOOLS = [
        ManageWooTool::class,
        ManageElementorTool::class,
    ];

    public function __construct(string $profile = self::PROFILE_STANDARD)
    {
        $this->profile = in_array($profile, self::VALID_PROFILES, true)
            ? $profile
            : self::PROFILE_STANDARD;
        $this->registerTools();
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function getAll(): array
    {
        return $this->tools;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Get all tool definitions for OpenAI/OpenRouter function calling.
     * No deferred loading — all tools are always sent (only 12 total).
     */
    public function getDefinitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            if (!$tool->checkPermission()) {
                continue;
            }

            $rawParams = $tool->getParameters();
            $properties = [];
            foreach ($rawParams as $name => $config) {
                $properties[$name] = array_intersect_key(
                    $config,
                    array_flip(["type", "description", "enum", "items"]),
                );
            }

            $definitions[] = [
                "type" => "function",
                "function" => [
                    "name" => $tool->getName(),
                    "description" => $this->buildDescription($tool),
                    "parameters" => [
                        "type" => "object",
                        "properties" => $properties,
                        "required" => $this->getRequiredParameters($rawParams),
                    ],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Get definition for a single tool.
     */
    public function getDefinitionForTool(string $toolName): ?array
    {
        $tool = $this->get($toolName);
        if ($tool === null || !$tool->checkPermission()) {
            return null;
        }

        $rawParams = $tool->getParameters();
        $properties = [];
        foreach ($rawParams as $name => $config) {
            $properties[$name] = array_intersect_key(
                $config,
                array_flip(["type", "description", "enum", "items"]),
            );
        }

        return [
            "type" => "function",
            "function" => [
                "name" => $tool->getName(),
                "description" => $this->buildDescription($tool),
                "parameters" => [
                    "type" => "object",
                    "properties" => $properties,
                    "required" => $this->getRequiredParameters($rawParams),
                ],
            ],
        ];
    }

    /**
     * Execute a tool by name.
     */
    public function execute(string $name, array $params): array
    {
        $tool = $this->get($name);

        if (!$tool) {
            return [
                "success" => false,
                "error" => "Tool '{$name}' not found",
            ];
        }

        if (!$tool->checkPermission()) {
            return [
                "success" => false,
                "error" => "Permission denied for this tool",
            ];
        }

        try {
            $result = $tool->execute($params);
        } catch (\Exception $e) {
            return [
                "success" => false,
                "error" => $e->getMessage(),
            ];
        }

        return $result;
    }

    /**
     * Count total available tools.
     */
    public function count(): int
    {
        return count(
            array_filter($this->tools, fn($t) => $t->checkPermission()),
        );
    }

    /**
     * Profile labels for UI.
     */
    public static function getProfileLabels(): array
    {
        return [
            self::PROFILE_MINIMAL => [
                "label" => "Minimal",
                "description" =>
                    "5 generische Tools (nur Lesen + Diagnose): Lesen, Listen, Suchen, HTTP, Health-Check.",
            ],
            self::PROFILE_STANDARD => [
                "label" => "Standard",
                "description" =>
                    "10 generische Tools: Lesen, Schreiben, Editieren, Listen, Suchen, Ausführen, Installieren, Verwalten, HTTP, Health-Check.",
            ],
            self::PROFILE_FULL => [
                "label" => "Voll (Entwickler)",
                "description" =>
                    "12 generische Tools inkl. WooCommerce und Elementor-Verwaltung.",
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function registerTools(): void
    {
        // Minimal: read-only + diagnostics
        foreach (self::MINIMAL_TOOLS as $class) {
            $this->register(new $class());
        }

        if (
            $this->profile === self::PROFILE_STANDARD ||
            $this->profile === self::PROFILE_FULL
        ) {
            foreach (self::CORE_TOOLS as $class) {
                $this->register(new $class());
            }
        }

        if ($this->profile === self::PROFILE_FULL) {
            foreach (self::EXTENDED_TOOLS as $class) {
                $this->register(new $class());
            }
        }
    }

    private function buildDescription(ToolInterface $tool): string
    {
        $description = $tool->getDescription();

        if (!method_exists($tool, "getInputExamples")) {
            return $description;
        }

        $examples = $tool->getInputExamples();
        if (empty($examples)) {
            return $description;
        }

        $lines = [];
        foreach ($examples as $example) {
            $lines[] = json_encode(
                $example,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $description . "\n\nExample inputs:\n" . implode("\n", $lines);
    }

    private function getRequiredParameters(array $parameters): array
    {
        $required = [];
        foreach ($parameters as $name => $config) {
            if ($config["required"] ?? false) {
                $required[] = $name;
            }
        }
        return $required;
    }
}
