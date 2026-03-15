<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;

/**
 * LLM-based query expansion for vector retrieval.
 *
 * Replaces static German→English dictionaries with a single LLM call that
 * generates diverse search queries in the right language for any user input.
 * Uses the cheap compact_model to keep costs negligible (~$0.0001/call).
 *
 * Returns: [original_query, ...expanded_english_queries]
 * Also provides a complexity signal for adaptive K retrieval.
 */
class QueryExpander
{
    private const MAX_EXPANDED = 3;

    private const COMPACT_MODEL_DEFAULTS = [
        'openrouter' => 'google/gemini-2.5-flash-lite',
        'openai' => 'gpt-4.1-mini',
        'anthropic' => 'claude-haiku-4-5-20250414',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You generate search queries for a WordPress/WooCommerce/Elementor technical documentation vector database.

Rules:
- Output EXACTLY 3 short English search queries, one per line, no numbering, no explanation.
- Each query should target a DIFFERENT aspect: one for API schemas/data structures, one for hooks/filters/code patterns, one for conceptual/architectural docs.
- Include specific technical terms: function names, hook names, REST endpoint paths, class names when inferrable.
- If the user wants to BUILD something (plugin, feature), include queries for the response schemas and data structures they'll need to consume.
- Last line: write one of COMPLEX, SIMPLE, or NO_RETRIEVAL:
  - COMPLEX: Task requires deep technical documentation (plugin development, API integration, complex hooks)
  - SIMPLE: Task benefits from some documentation lookup
  - NO_RETRIEVAL: Task needs NO documentation at all (e.g. "list my products", "create a page", "what plugins do I have", simple CRUD operations, greetings, status questions)
PROMPT;

    private ?bool $lastComplexity = null;
    private bool $lastNeedsRetrieval = true;

    /**
     * Expand a user query into multiple search queries via LLM.
     *
     * @return string[] Original query + up to 3 LLM-generated English queries.
     */
    public function expand(string $query): array
    {
        $trimmed = trim($query);
        if ($trimmed === '' || mb_strlen($trimmed) < 10) {
            return [$trimmed];
        }

        $this->lastComplexity = null;
        $this->lastNeedsRetrieval = true;

        try {
            $expanded = $this->callLLM($trimmed);
            if (!empty($expanded)) {
                return array_values(array_unique(array_merge([$trimmed], $expanded)));
            }
        } catch (\Throwable $e) {
            error_log('Levi QueryExpander LLM error: ' . $e->getMessage());
        }

        return [$trimmed];
    }

    /**
     * Whether the last expanded query was classified as complex.
     * Used for adaptive K (more chunks for complex queries).
     */
    public function isLastQueryComplex(): bool
    {
        return $this->lastComplexity === true;
    }

    /**
     * Whether the last query needs reference documentation retrieval.
     * Returns false for simple CRUD operations, status questions, etc.
     */
    public function needsRetrieval(): bool
    {
        return $this->lastNeedsRetrieval;
    }

    private function callLLM(string $query): array
    {
        $settings = new SettingsPage();
        $provider = $settings->getProvider();
        $apiKey = $settings->getApiKeyForProvider($provider);

        if (!$apiKey) {
            return [];
        }

        $model = $this->getCompactModel($settings, $provider);
        $client = AIClientFactory::createWithModel($provider, $model);

        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $query],
        ];

        $response = $client->chat($messages);

        if (is_wp_error($response)) {
            error_log('Levi QueryExpander: ' . $response->get_error_message());
            return [];
        }

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            return [];
        }

        return $this->parseResponse($content);
    }

    private function parseResponse(string $content): array
    {
        $lines = array_filter(
            array_map('trim', explode("\n", $content)),
            fn(string $line) => $line !== ''
        );

        $queries = [];
        foreach ($lines as $line) {
            $upper = strtoupper(trim($line));
            if ($upper === 'COMPLEX' || $upper === 'SIMPLE' || $upper === 'NO_RETRIEVAL') {
                $this->lastComplexity = ($upper === 'COMPLEX');
                $this->lastNeedsRetrieval = ($upper !== 'NO_RETRIEVAL');
                continue;
            }

            $cleaned = preg_replace('/^\d+[\.\)]\s*/', '', $line);
            $cleaned = trim($cleaned, '- ');

            if ($cleaned !== '' && mb_strlen($cleaned) >= 5 && mb_strlen($cleaned) <= 200) {
                $queries[] = $cleaned;
            }
        }

        return array_slice($queries, 0, self::MAX_EXPANDED);
    }

    private function getCompactModel(SettingsPage $settings, string $provider): ?string
    {
        $allSettings = $settings->getSettings();

        $model = trim((string) ($allSettings['compact_model'] ?? $allSettings['summary_model'] ?? ''));
        if ($model !== '') {
            return $model;
        }

        return self::COMPACT_MODEL_DEFAULTS[$provider] ?? null;
    }
}
