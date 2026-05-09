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
        // Read
        'get_posts', 'get_post', 'get_pages', 'get_plugins',
        'get_options', 'get_users', 'get_media',
        // CRUD mutations
        'create_post', 'create_page', 'update_post', 'delete_post',
        'upload_media', 'update_media',
        'install_plugin', 'manage_post_meta', 'manage_taxonomy',
        // Plugin/Theme dev
        'create_plugin', 'list_plugin_files', 'read_plugin_file',
        'write_plugin_file', 'patch_plugin_file', 'grep_plugin_files',
        'delete_plugin_file',
        // Utility
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

        return $this->runPostWriteVerification($result);
    }

    /**
     * Run post-write verification checks declared by tools via _verify.
     * Clears relevant caches before re-reading to avoid stale data.
     * On failure: sets success=false with verification_failed details.
     * Always strips _verify from the result.
     */
    private function runPostWriteVerification(array $result): array {
        $checks = $result['_verify'] ?? [];
        unset($result['_verify']);

        if (empty($checks) || ($result['success'] ?? false) !== true) {
            return $result;
        }

        $failures = [];
        foreach ($checks as $check) {
            $failure = $this->verifySingleCheck($check);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        if (!empty($failures)) {
            $result['success'] = false;
            $result['verification_failed'] = $failures;
            $result['message'] = 'Operation reported success but verification failed. The change may not have been applied.';
        } else {
            $result['verified'] = true;
        }

        return $result;
    }

    /**
     * @return array|null Failure description, or null on success.
     */
    private function verifySingleCheck(array $check): ?array {
        $type = $check['type'] ?? '';

        try {
            return match ($type) {
                'post_field'       => $this->verifyPostField($check),
                'post_deleted'     => $this->verifyPostDeleted($check),
                'post_meta'        => $this->verifyPostMeta($check),
                'option_value'     => $this->verifyOptionValue($check),
                'user_field'       => $this->verifyUserField($check),
                'theme_active'     => $this->verifyThemeActive($check),
                'plugin_active'    => $this->verifyPluginActive($check),
                'file_exists'      => $this->verifyFileExists($check),
                'wc_product_field' => $this->verifyWcProductField($check),
                'term_exists'      => $this->verifyTermExists($check),
                default            => null,
            };
        } catch (\Throwable $e) {
            return ['check' => $type, 'error' => $e->getMessage()];
        }
    }

    private function verifyPostField(array $c): ?array {
        $postId = (int) ($c['post_id'] ?? 0);
        $field = (string) ($c['field'] ?? '');
        $expected = $c['expected'] ?? null;
        if ($postId <= 0 || $field === '' || $expected === null) {
            return null;
        }
        clean_post_cache($postId);
        $post = get_post($postId);
        if (!$post) {
            return ['check' => 'post_field', 'field' => $field, 'expected' => $expected, 'actual' => null, 'reason' => 'Post not found after write'];
        }
        $actual = $post->$field ?? null;
        if ((string) $actual !== (string) $expected) {
            return ['check' => 'post_field', 'field' => $field, 'expected' => $expected, 'actual' => $actual];
        }
        return null;
    }

    private function verifyPostDeleted(array $c): ?array {
        $postId = (int) ($c['post_id'] ?? 0);
        $force = (bool) ($c['force'] ?? false);
        if ($postId <= 0) {
            return null;
        }
        clean_post_cache($postId);
        $post = get_post($postId);
        if ($force) {
            if ($post !== null) {
                return ['check' => 'post_deleted', 'post_id' => $postId, 'expected' => 'permanently deleted', 'actual' => 'still exists (status: ' . $post->post_status . ')'];
            }
        } else {
            if ($post !== null && $post->post_status !== 'trash') {
                return ['check' => 'post_deleted', 'post_id' => $postId, 'expected' => 'trash', 'actual' => $post->post_status];
            }
        }
        return null;
    }

    private function verifyPostMeta(array $c): ?array {
        $postId = (int) ($c['post_id'] ?? 0);
        $key = (string) ($c['meta_key'] ?? '');
        $expected = $c['expected'] ?? null;
        if ($postId <= 0 || $key === '') {
            return null;
        }
        clean_post_cache($postId);
        $actual = get_post_meta($postId, $key, true);
        if ($expected === null || $expected === '') {
            if ($actual !== '' && $actual !== false) {
                return ['check' => 'post_meta', 'meta_key' => $key, 'expected' => 'empty/deleted', 'actual' => $actual];
            }
        } elseif ($actual != $expected) {
            return ['check' => 'post_meta', 'meta_key' => $key, 'expected' => $expected, 'actual' => $actual];
        }
        return null;
    }

    private function verifyOptionValue(array $c): ?array {
        $option = (string) ($c['option'] ?? '');
        $expected = $c['expected'] ?? null;
        if ($option === '' || $expected === null) {
            return null;
        }
        wp_cache_delete($option, 'options');
        $actual = get_option($option);
        if ($actual != $expected) {
            return ['check' => 'option_value', 'option' => $option, 'expected' => $expected, 'actual' => $actual];
        }
        return null;
    }

    private function verifyUserField(array $c): ?array {
        $userId = (int) ($c['user_id'] ?? 0);
        $field = (string) ($c['field'] ?? '');
        $expected = $c['expected'] ?? null;
        if ($userId <= 0 || $field === '' || $expected === null) {
            return null;
        }
        clean_user_cache($userId);
        $user = get_user_by('id', $userId);
        if (!$user) {
            return ['check' => 'user_field', 'field' => $field, 'expected' => $expected, 'actual' => null, 'reason' => 'User not found'];
        }
        $actual = $field === 'role'
            ? ((!empty($user->roles)) ? $user->roles[0] : '')
            : ($user->$field ?? null);
        if ((string) $actual !== (string) $expected) {
            return ['check' => 'user_field', 'field' => $field, 'expected' => $expected, 'actual' => $actual];
        }
        return null;
    }

    private function verifyThemeActive(array $c): ?array {
        $expected = (string) ($c['expected'] ?? '');
        if ($expected === '') {
            return null;
        }
        $actual = get_stylesheet();
        if ($actual !== $expected) {
            return ['check' => 'theme_active', 'expected' => $expected, 'actual' => $actual];
        }
        return null;
    }

    private function verifyPluginActive(array $c): ?array {
        $pluginFile = (string) ($c['plugin_file'] ?? '');
        $expectedActive = (bool) ($c['expected_active'] ?? true);
        if ($pluginFile === '') {
            return null;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $isActive = is_plugin_active($pluginFile);
        if ($isActive !== $expectedActive) {
            return ['check' => 'plugin_active', 'plugin' => $pluginFile, 'expected' => $expectedActive ? 'active' : 'inactive', 'actual' => $isActive ? 'active' : 'inactive'];
        }
        return null;
    }

    private function verifyFileExists(array $c): ?array {
        $path = (string) ($c['path'] ?? '');
        $expected = (bool) ($c['expected'] ?? true);
        if ($path === '') {
            return null;
        }
        if (function_exists('clearstatcache')) {
            clearstatcache(true, $path);
        }
        $exists = file_exists($path);
        if ($exists !== $expected) {
            return ['check' => 'file_exists', 'path' => basename($path), 'expected' => $expected ? 'exists' : 'deleted', 'actual' => $exists ? 'exists' : 'deleted'];
        }
        return null;
    }

    private function verifyWcProductField(array $c): ?array {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        $productId = (int) ($c['product_id'] ?? 0);
        $field = (string) ($c['field'] ?? '');
        $expected = $c['expected'] ?? null;
        if ($productId <= 0 || $field === '' || $expected === null) {
            return null;
        }
        clean_post_cache($productId);
        $product = wc_get_product($productId);
        if (!$product) {
            return ['check' => 'wc_product_field', 'field' => $field, 'expected' => $expected, 'actual' => null, 'reason' => 'Product not found'];
        }
        $getter = 'get_' . $field;
        $actual = method_exists($product, $getter) ? $product->$getter() : null;
        if ($actual === null) {
            return null;
        }
        if ((string) $actual != (string) $expected) {
            return ['check' => 'wc_product_field', 'field' => $field, 'expected' => $expected, 'actual' => $actual];
        }
        return null;
    }

    private function verifyTermExists(array $c): ?array {
        $termId = (int) ($c['term_id'] ?? 0);
        $expectedExists = (bool) ($c['expected'] ?? true);
        if ($termId <= 0) {
            return null;
        }
        $term = get_term($termId);
        $exists = ($term !== null && !is_wp_error($term));
        if ($exists !== $expectedExists) {
            return ['check' => 'term_exists', 'term_id' => $termId, 'expected' => $expectedExists ? 'exists' : 'deleted', 'actual' => $exists ? 'exists' : 'deleted'];
        }
        return null;
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
            new UpdateMediaTool(),
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
