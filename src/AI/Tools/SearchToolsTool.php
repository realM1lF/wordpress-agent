<?php

namespace Levi\Agent\AI\Tools;

/**
 * Meta-tool that lets the model discover available tools on-demand.
 *
 * Instead of sending all 26-44 tool definitions in every API call,
 * only this tool is sent initially. The model searches when it needs
 * a capability, and discovered tools are injected into subsequent calls.
 *
 * Pattern pioneered by Anthropic (Nov 2025), adopted by OpenAI & Spring AI.
 */
class SearchToolsTool implements ToolInterface
{
    private Registry $registry;

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    public function getName(): string
    {
        return 'search_tools';
    }

    public function getDescription(): string
    {
        return 'Search for available WordPress tools by describing what you need to do. '
            . 'Returns matching tools you can then call directly. '
            . 'Use English keywords (e.g. "delete plugin", "create page", "manage user", "read error log").';
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'What you want to do, in English keywords',
                'required' => true,
            ],
        ];
    }

    public function checkPermission(): bool
    {
        return true;
    }

    public function execute(array $params): array
    {
        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            return ['success' => false, 'error' => 'query is required.'];
        }

        $results = $this->registry->searchTools($query, 5);

        if (empty($results)) {
            return [
                'success' => true,
                'tools_found' => 0,
                'message' => 'No matching tools found. Try different keywords.',
            ];
        }

        $toolList = [];
        foreach ($results as $r) {
            $toolList[] = [
                'name' => $r['name'],
                'description' => $r['description'],
            ];
        }

        return [
            'success' => true,
            'tools_found' => count($toolList),
            'tools' => $toolList,
            'hint' => 'These tools are now available. Call them directly.',
        ];
    }
}
