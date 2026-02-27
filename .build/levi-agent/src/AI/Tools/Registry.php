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
     * minimal  = read-only / diagnostic tools (safe for non-technical users)
     * standard = minimal + all write tools (default for most users)
     * full     = standard + power tools like execute_wp_code / http_fetch
     */
    private function registerDefaultTools(): void {
        $readTools = [
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
            new DiscoverContentTypesTool(),
            new DiscoverRestApiTool(),
            new ReadErrorLogTool(),
            new WooCommerceProductTool(),
            new WooCommerceShopTool(),
        ];

        $writeTools = [
            new CreatePostTool(),
            new UpdatePostTool(),
            new CreatePageTool(),
            new UpdateOptionTool(),
            new UpdateAnyOptionTool(),
            new DeletePostTool(),
            new InstallPluginTool(),
            new SwitchThemeTool(),
            new ManageUserTool(),
            new CreatePluginTool(),
            new WritePluginFileTool(),
            new DeletePluginFileTool(),
            new WriteThemeFileTool(),
            new CreateThemeTool(),
            new DeleteThemeFileTool(),
            new PostMetaTool(),
            new ManageTaxonomyTool(),
            new WooCommerceManageTool(),
            new ManageMenuTool(),
            new ManageCronTool(),
            new UploadMediaTool(),
        ];

        $powerTools = [
            new ExecuteWPCodeTool(),
            new HttpFetchTool(),
        ];

        $tools = $readTools;

        if ($this->profile === self::PROFILE_STANDARD || $this->profile === self::PROFILE_FULL) {
            $tools = array_merge($tools, $writeTools);
        }

        if ($this->profile === self::PROFILE_FULL) {
            $tools = array_merge($tools, $powerTools);
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
                'description' => 'Nur Lese- und Diagnose-Tools. Levi kann nichts veraendern – ideal zum Kennenlernen.',
            ],
            self::PROFILE_STANDARD => [
                'label'       => 'Standard',
                'description' => 'Lesen + Schreiben. Levi kann Inhalte, Plugins und Themes erstellen und bearbeiten.',
            ],
            self::PROFILE_FULL => [
                'label'       => 'Voll (Entwickler)',
                'description' => 'Alle Tools inkl. PHP-Code-Ausfuehrung und HTTP-Fetch. Nur fuer erfahrene Nutzer.',
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
