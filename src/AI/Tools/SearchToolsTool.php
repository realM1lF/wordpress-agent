<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\Memory\VectorStore;

/**
 * Meta-tool that lets the model discover available tools on-demand.
 *
 * Only core tools (~18) are sent in every API call. When the model needs
 * a capability beyond the core set, it searches here. Discovered tools
 * are injected into subsequent API calls (deferred loading pattern).
 *
 * Uses BM25 keyword matching with semantic search fallback via VectorStore.
 * Tool definitions are indexed in vector DB during memory sync (MemoryLoader).
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
        return 'Search for additional WordPress tools beyond the currently loaded core set. '
            . 'Not all tools are loaded by default — use this when you need a capability you do not see in your available tools. '
            . 'Common categories: WooCommerce (products, orders, coupons), Elementor (layouts, widgets), '
            . 'theme editing (create, write, switch theme), cron/scheduling, user management, taxonomy, delete operations, '
            . 'media upload, code execution, option updates. '
            . 'Returns matching tools with descriptions and parameter summaries that you can then call directly in the next step.';
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'What you need to do — use English or German keywords (e.g. "woocommerce product", "theme bearbeiten", "cron schedule", "elementor widget")',
                'required' => true,
            ],
        ];
    }

    public function getInputExamples(): array
    {
        return [
            ['query' => 'woocommerce product erstellen'],
            ['query' => 'theme file edit write'],
            ['query' => 'cron schedule task'],
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

        $bm25Results = $this->registry->searchTools($query, 5);

        $topBm25Score = !empty($bm25Results) ? $bm25Results[0]['score'] : 0;
        $results = $bm25Results;

        if ($topBm25Score < 3.0) {
            $semanticResults = $this->semanticSearch($query, 5);
            if (!empty($semanticResults)) {
                $results = $this->mergeResults($bm25Results, $semanticResults);
            }
        }

        if (empty($results)) {
            return [
                'success' => true,
                'tools_found' => 0,
                'message' => 'No matching tools found. Try different keywords or broader search terms.',
            ];
        }

        $toolList = [];
        foreach ($results as $r) {
            $entry = [
                'name' => $r['name'],
                'description' => $r['description'],
            ];
            if (!empty($r['parameters_summary'])) {
                $entry['parameters'] = $r['parameters_summary'];
            }
            $toolList[] = $entry;
        }

        return [
            'success' => true,
            'tools_found' => count($toolList),
            'tools' => $toolList,
            'hint' => 'These tools are now available for you to call. Use the parameter info to construct your call.',
        ];
    }

    /**
     * Semantic search against pre-indexed tool definitions in vector DB.
     * Returns empty array if VectorStore is unavailable or tools aren't indexed.
     */
    private function semanticSearch(string $query, int $limit): array
    {
        try {
            $vectorStore = new VectorStore();
            if (!$vectorStore->isAvailable()) {
                return [];
            }

            $embedding = $vectorStore->generateEmbedding($query);
            if (is_wp_error($embedding) || empty($embedding)) {
                return [];
            }

            $matches = $vectorStore->searchSimilar($embedding, 'tool_definition', $limit, 0.5);
            if (empty($matches)) {
                return [];
            }

            $results = [];
            foreach ($matches as $match) {
                $toolName = $match['source_file'] ?? '';
                $tool = $this->registry->get($toolName);
                if ($tool === null || !$tool->checkPermission() || $toolName === 'search_tools') {
                    continue;
                }
                $results[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters_summary' => $this->getParameterSummary($tool),
                    'score' => $match['similarity'] * 10,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            error_log('SearchToolsTool semantic search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Merge BM25 and semantic results, deduplicating by tool name.
     * Keeps the entry with the higher score.
     */
    private function mergeResults(array $bm25, array $semantic): array
    {
        $merged = [];
        foreach ($bm25 as $r) {
            $merged[$r['name']] = $r;
        }
        foreach ($semantic as $r) {
            $name = $r['name'];
            if (!isset($merged[$name]) || $r['score'] > $merged[$name]['score']) {
                $merged[$name] = $r;
            }
        }

        $list = array_values($merged);
        usort($list, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return array_slice($list, 0, 5);
    }

    private function getParameterSummary(ToolInterface $tool): string
    {
        $params = $tool->getParameters();
        $parts = [];
        foreach ($params as $name => $config) {
            $req = ($config['required'] ?? false) ? '*' : '';
            $type = $config['type'] ?? 'string';
            $parts[] = "{$name}{$req} ({$type})";
        }
        return implode(', ', $parts);
    }
}
