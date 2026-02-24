<?php

namespace Levi\Agent\AI\Tools;

class Registry {
    /** @var ToolInterface[] */
    private array $tools = [];

    public function __construct() {
        $this->registerDefaultTools();
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
     * Register default tools
     */
    private function registerDefaultTools(): void {
        $tools = [
            new GetPostsTool(),
            new GetPostTool(),
            new GetPagesTool(),
            new GetUsersTool(),
            new GetPluginsTool(),
            new GetOptionsTool(),
            new GetMediaTool(),
        ];

        foreach ($tools as $tool) {
            $this->register($tool);
        }
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
