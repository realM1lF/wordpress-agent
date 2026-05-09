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

    /**
     * Tools always sent to the model (covers ~80% of common tasks).
     * Everything else is discoverable via search_tools (deferred loading).
     */
    private const CORE_TOOL_NAMES = [
        'get_posts', 'get_post', 'get_pages', 'get_plugins',
        'get_options', 'get_users', 'get_media',
        'create_plugin', 'list_plugin_files', 'read_plugin_file',
        'write_plugin_file', 'patch_plugin_file', 'grep_plugin_files',
        'create_post', 'create_page', 'update_post',
        'read_error_log', 'http_fetch',
        'check_plugin_health',
        'search_tools',
    ];

    private const DEFERRED_LOADING_THRESHOLD = 20;

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
                    'description' => $this->buildDescription($tool),
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
                'description' => $this->buildDescription($tool),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $this->getRequiredParameters($rawParams),
                ],
            ],
        ];
    }

    /**
     * Get definitions for core tools + explicitly discovered tools only.
     * When total tools <= DEFERRED_LOADING_THRESHOLD, returns all (no benefit from deferring).
     *
     * @param string[] $discoveredNames Tool names discovered via search_tools
     */
    public function getCoreAndDiscoveredDefinitions(array $discoveredNames = []): array {
        $totalAvailable = count(array_filter($this->tools, fn($t) => $t->checkPermission()));
        $useDeferred = $totalAvailable > self::DEFERRED_LOADING_THRESHOLD;

        if (!$useDeferred) {
            return $this->getDefinitions();
        }

        $allowedNames = array_unique(array_merge(self::CORE_TOOL_NAMES, $discoveredNames));
        $definitions = [];

        foreach ($this->tools as $tool) {
            if (!$tool->checkPermission()) {
                continue;
            }
            if (!in_array($tool->getName(), $allowedNames, true)) {
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
                    'description' => $this->buildDescription($tool),
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
     * Search tools by query (BM25-like scoring on name + description + parameters + examples).
     * Excludes search_tools itself from results.
     *
     * @return array<array{name: string, description: string, parameters_summary: string, score: float}>
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

            $corpus = $this->getSearchCorpus($tool);
            $name = mb_strtolower($tool->getName());

            $score = 0.0;
            foreach ($queryWords as $word) {
                if (mb_strlen($word) < 2) {
                    continue;
                }
                if (str_contains($name, $word)) {
                    $score += 3.0;
                }
                if (str_contains($corpus, $word)) {
                    $score += 1.0;
                }
            }

            if ($score > 0) {
                $scored[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters_summary' => $this->getParameterSummary($tool),
                    'score' => $score,
                ];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /**
     * Build a rich search corpus for BM25 matching.
     * Includes name, description, parameter names/descriptions/enums, and input examples.
     */
    private function getSearchCorpus(ToolInterface $tool): string {
        $parts = [
            $tool->getName(),
            $tool->getDescription(),
        ];

        foreach ($tool->getParameters() as $name => $config) {
            $parts[] = $name;
            if (!empty($config['description'])) {
                $parts[] = $config['description'];
            }
            if (!empty($config['enum']) && is_array($config['enum'])) {
                $parts[] = implode(' ', $config['enum']);
            }
        }

        if (method_exists($tool, 'getInputExamples')) {
            foreach ($tool->getInputExamples() as $example) {
                $parts[] = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * Compact parameter summary for search results (e.g. "action* (string), product_id (integer)").
     */
    private function getParameterSummary(ToolInterface $tool): string {
        $params = $tool->getParameters();
        $parts = [];
        foreach ($params as $name => $config) {
            $req = ($config['required'] ?? false) ? '*' : '';
            $type = $config['type'] ?? 'string';
            $parts[] = "{$name}{$req} ({$type})";
        }
        return implode(', ', $parts);
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

        $unknownParams = $this->detectUnknownParams($tool, $params);

        try {
            $result = $tool->execute($params);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        if (!empty($unknownParams)) {
            $known = array_keys($tool->getParameters());
            $result['_warnings'] = [
                'unknown_params' => $unknownParams,
                'hint' => 'These parameters were sent but are not defined in this tool\'s schema and were ignored. Valid parameters: ' . implode(', ', $known) . '.',
            ];
        }

        return $result;
    }

    /**
     * Detect parameters not declared in the tool's schema.
     *
     * @return string[] List of unknown parameter names (empty if all valid)
     */
    private function detectUnknownParams(ToolInterface $tool, array $params): array {
        $known = array_keys($tool->getParameters());
        $sent = array_keys($params);

        return array_values(array_diff($sent, $known));
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
            new GrepPluginFilesTool(),
            new CheckPluginHealthTool(),
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
            new ManageTaxonomyTool(),
            new HttpFetchTool(),
            new DiscoverContentTypesTool(),
            new DiscoverRestApiTool(),
            new WooCommerceProductTool(),
            new WooCommerceShopTool(),
            new WooCommerceManageTool(),
            new ElementorReadTool(),
            new ElementorBuildTool(),
            new ElementorManageTool(),
            new ManageUserTool(),
            new ManageCronTool(),
            new UpdateAnyOptionTool(),
            new SwitchThemeTool(),
            new CreateThemeTool(),
            new WriteThemeFileTool(),
            new PatchThemeFileTool(),
            new GrepThemeFilesTool(),
            new DeleteThemeFileTool(),
            new RevertFileTool(),
            new RenameInPluginTool(),
            new StoreSessionImageTool(),
        ];

        $advancedTools = [
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
     * Build the full description, appending input_examples when available.
     * Works with any LLM provider (OpenAI, Anthropic via OpenRouter, etc.).
     */
    private function buildDescription(ToolInterface $tool): string {
        $description = $tool->getDescription();

        if (!method_exists($tool, 'getInputExamples')) {
            return $description;
        }

        $examples = $tool->getInputExamples();
        if (empty($examples)) {
            return $description;
        }

        $lines = [];
        foreach ($examples as $example) {
            $lines[] = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $description . "\n\nExample inputs:\n" . implode("\n", $lines);
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
