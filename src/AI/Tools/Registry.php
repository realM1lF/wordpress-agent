<?php

namespace Levi\Agent\AI\Tools;

class Registry {
    public const PROFILE_MINIMAL  = 'minimal';
    public const PROFILE_STANDARD = 'standard';
    public const PROFILE_FULL     = 'full';

    public const VALID_PROFILES = [
        self::PROFILE_MINIMAL,
        self::PROFILE_STANDARD,
        self::PROFILE_FULL,
    ];

    /** @var ToolInterface[] */
    private array $tools = [];

    private string $profile;

    public function __construct(string $profile = self::PROFILE_STANDARD) {
        $this->profile = in_array($profile, self::VALID_PROFILES, true) ? $profile : self::PROFILE_STANDARD;
        $this->registerDefaultTools();
    }

    public function getProfile(): string {
        return $this->profile;
    }

    /**
     * Register a tool
     */
    public function register(ToolInterface $tool): void {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Get all registered tools
     * 
     * @return ToolInterface[]
     */
    public function getAll(): array {
        return $this->tools;
    }

    /**
     * Get a specific tool by name
     */
    public function get(string $name): ?ToolInterface {
        return $this->tools[$name] ?? null;
    }

    /**
     * Get tool definitions for OpenAI/OpenRouter function calling
     * Outputs valid JSON Schema (strips invalid keys like 'default', 'required' from properties)
     */
    public function getDefinitions(): array {
        $definitions = [];

        foreach ($this->tools as $tool) {
            if (!$tool->checkPermission()) {
                continue;
            }

            $rawParams = $tool->getParameters();
            $properties = [];
            foreach ($rawParams as $name => $config) {
                $properties[$name] = array_intersect_key($config, array_flip(['type', 'description', 'enum', 'items']));
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $this->getRequiredParameters($rawParams),
                    ],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Get the OpenAI function-calling definition for a single tool.
     */
    public function getDefinitionForTool(string $toolName): ?array {
        $tool = $this->get($toolName);
        if ($tool === null || !$tool->checkPermission()) {
            return null;
        }

        $rawParams = $tool->getParameters();
        $properties = [];
        foreach ($rawParams as $name => $config) {
            $properties[$name] = array_intersect_key($config, array_flip(['type', 'description', 'enum', 'items']));
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $this->getRequiredParameters($rawParams),
                ],
            ],
        ];
    }

    /**
     * Search tools by query (BM25-like scoring on name + description).
     * Excludes search_tools itself from results.
     *
     * @return array<array{name: string, description: string, score: float}>
     */
    public function searchTools(string $query, int $limit = 5): array {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $queryWords = array_filter(preg_split('/[\s_\-]+/', $query));
        if (empty($queryWords)) {
            return [];
        }

        $scored = [];
        foreach ($this->tools as $tool) {
            if (!$tool->checkPermission() || $tool->getName() === 'search_tools') {
                continue;
            }

            $name = mb_strtolower($tool->getName());
            $desc = mb_strtolower($tool->getDescription());

            $score = 0.0;
            foreach ($queryWords as $word) {
                if (mb_strlen($word) < 2) {
                    continue;
                }
                if (str_contains($name, $word)) {
                    $score += 3.0;
                }
                if (str_contains($desc, $word)) {
                    $score += 1.0;
                }
            }

            if ($score > 0) {
                $scored[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'score' => $score,
                ];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /**
     * Execute a tool by name
     */
    public function execute(string $name, array $params): array {
        $tool = $this->get($name);

        if (!$tool) {
            return [
                'success' => false,
                'error' => "Tool '$name' not found",
            ];
        }

        if (!$tool->checkPermission()) {
            return [
                'success' => false,
                'error' => 'Permission denied for this tool',
            ];
        }

        try {
            return $tool->execute($params);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Register tools based on the active profile.
     *
     * minimal  = read-only core tools (~12, safe for non-technical users)
     * standard = core read + common write (~26, covers 95% of daily tasks)
     * full     = everything incl. niche/advanced tools (~44, for power users)
     */
    private function registerDefaultTools(): void {
        $coreReadTools = [
            new GetPostsTool(),
            new GetPostTool(),
            new GetPagesTool(),
            new GetUsersTool(),
            new GetPluginsTool(),
            new GetOptionsTool(),
            new GetMediaTool(),
            new ListPluginFilesTool(),
            new ReadPluginFileTool(),
            new ListThemeFilesTool(),
            new ReadThemeFileTool(),
            new ReadErrorLogTool(),
        ];

        $commonWriteTools = [
            new CreatePostTool(),
            new UpdatePostTool(),
            new CreatePageTool(),
            new DeletePostTool(),
            new InstallPluginTool(),
            new CreatePluginTool(),
            new WritePluginFileTool(),
            new PatchPluginFileTool(),
            new DeletePluginFileTool(),
            new PostMetaTool(),
            new UpdateOptionTool(),
            new UploadMediaTool(),
            new ManageMenuTool(),
            new HttpFetchTool(),
        ];

        $advancedTools = [
            new ManageUserTool(),
            new ManageCronTool(),
            new UpdateAnyOptionTool(),
            new SwitchThemeTool(),
            new CreateThemeTool(),
            new WriteThemeFileTool(),
            new DeleteThemeFileTool(),
            new ManageTaxonomyTool(),
            new StoreSessionImageTool(),
            new DiscoverContentTypesTool(),
            new DiscoverRestApiTool(),
            new WooCommerceProductTool(),
            new WooCommerceShopTool(),
            new WooCommerceManageTool(),
            new ElementorReadTool(),
            new ElementorBuildTool(),
            new ElementorManageTool(),
            new ExecuteWPCodeTool(),
        ];

        $tools = $coreReadTools;

        if ($this->profile === self::PROFILE_STANDARD || $this->profile === self::PROFILE_FULL) {
            $tools = array_merge($tools, $commonWriteTools);
        }

        if ($this->profile === self::PROFILE_FULL) {
            $tools = array_merge($tools, $advancedTools);
        }

        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    /**
     * Human-readable labels for profiles (DE/EN).
     * @return array<string, array{label: string, description: string}>
     */
    public static function getProfileLabels(): array {
        return [
            self::PROFILE_MINIMAL => [
                'label'       => 'Minimal (nur lesen)',
                'description' => 'Nur Lese-Tools (~12). Levi kann nichts veraendern – ideal zum Kennenlernen.',
            ],
            self::PROFILE_STANDARD => [
                'label'       => 'Standard',
                'description' => 'Lesen + Schreiben (~26 Tools). Inhalte, Plugins, Menues – deckt 95% des Alltags ab.',
            ],
            self::PROFILE_FULL => [
                'label'       => 'Voll (Entwickler)',
                'description' => 'Alle Tools (~44) inkl. User-Management, Cron, WooCommerce, Elementor, PHP-Ausfuehrung.',
            ],
        ];
    }

    /**
     * Extract required parameters from schema
     */
    private function getRequiredParameters(array $parameters): array {
        $required = [];

        foreach ($parameters as $name => $config) {
            if ($config['required'] ?? false) {
                $required[] = $name;
            }
        }

        return $required;
    }
}
