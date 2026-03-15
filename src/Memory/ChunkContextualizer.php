<?php

namespace Levi\Agent\Memory;

use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\AI\AIClientFactory;

/**
 * Generates contextual descriptions for document chunks using an LLM.
 *
 * Implements Anthropic's "Contextual Retrieval" pattern: each chunk gets a
 * short LLM-generated description prepended before embedding.  This enriches
 * semantic representations so that a JSON schema chunk can be found by queries
 * about "what fields does a cart item have?" instead of only by queries that
 * mention the exact header path.
 *
 * Uses the cheap compact_model to keep costs at ~$0.01 per large document.
 * Falls back gracefully: if the LLM call fails, the original chunk is returned.
 */
class ChunkContextualizer
{
    private const COMPACT_MODEL_DEFAULTS = [
        'openrouter' => 'google/gemini-2.5-flash-lite',
        'openai' => 'gpt-4.1-mini',
        'anthropic' => 'claude-haiku-4-5-20250414',
    ];

    private const CONTEXT_PROMPT = <<<'PROMPT'
You enrich documentation chunks for a search index. Given a section of a larger document and a specific chunk from that section, write a 1-3 sentence context that:
1. Names the product/API/system the chunk documents
2. Summarizes what the chunk contains (data fields, hooks, endpoints, code patterns)
3. Notes important ABSENCES if relevant (e.g. "does NOT include X field")

Answer ONLY with the context sentences. No preamble.
PROMPT;

    private const MAX_CONSECUTIVE_ERRORS = 5;
    private const RETRY_DELAY_MS = 1500;

    private ?\Levi\Agent\AI\AIClientInterface $client = null;
    private bool $available = true;
    private int $consecutiveErrors = 0;

    /**
     * Contextualize a batch of chunks from the same document section.
     *
     * @param string $sectionContext  Surrounding document section (max ~6000 words)
     * @param string[] $chunks        Raw chunk texts to contextualize
     * @return array{contextualized: string[], originals: string[]}
     */
    public function contextualizeBatch(string $sectionContext, array $chunks): array
    {
        $originals = $chunks;
        $contextualized = [];

        if (!$this->available || empty($chunks)) {
            return ['contextualized' => $chunks, 'originals' => $originals];
        }

        $client = $this->getClient();
        if ($client === null) {
            $this->available = false;
            return ['contextualized' => $chunks, 'originals' => $originals];
        }

        $truncatedSection = $this->truncateToTokenBudget($sectionContext, 6000);

        foreach ($chunks as $i => $chunk) {
            if (!$this->available) {
                $contextualized[$i] = $chunk;
                continue;
            }

            $context = $this->generateContext($client, $truncatedSection, $chunk);
            if ($context !== null) {
                $contextualized[$i] = $context . "\n\n" . $chunk;
                $this->consecutiveErrors = 0;
            } else {
                $contextualized[$i] = $chunk;
            }
        }

        return ['contextualized' => $contextualized, 'originals' => $originals];
    }

    /**
     * Generate context for a single chunk.
     * Retries once on transient errors; only gives up after MAX_CONSECUTIVE_ERRORS.
     */
    private function generateContext(
        \Levi\Agent\AI\AIClientInterface $client,
        string $sectionContext,
        string $chunk
    ): ?string {
        $userMessage = "<section>\n{$sectionContext}\n</section>\n\n"
            . "<chunk>\n{$chunk}\n</chunk>";

        $messages = [
            ['role' => 'system', 'content' => self::CONTEXT_PROMPT],
            ['role' => 'user', 'content' => $userMessage],
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                usleep(self::RETRY_DELAY_MS * 1000);
            }

            try {
                $response = $client->chat($messages);
            } catch (\Throwable $e) {
                error_log('Levi ChunkContextualizer error (attempt ' . ($attempt + 1) . '): ' . $e->getMessage());
                continue;
            }

            if (is_wp_error($response)) {
                error_log('Levi ChunkContextualizer API error (attempt ' . ($attempt + 1) . '): ' . $response->get_error_message());
                continue;
            }

            $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            if ($text === '' || mb_strlen($text) > 1000) {
                return null;
            }

            return $text;
        }

        $this->consecutiveErrors++;
        if ($this->consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
            error_log('Levi ChunkContextualizer: ' . self::MAX_CONSECUTIVE_ERRORS . ' consecutive errors, stopping.');
            $this->available = false;
        }

        return null;
    }

    private function getClient(): ?\Levi\Agent\AI\AIClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        try {
            $settings = new SettingsPage();
            $provider = $settings->getProvider();

            if (!$settings->getApiKeyForProvider($provider)) {
                return null;
            }

            $model = $this->getCompactModel($settings, $provider);
            $this->client = AIClientFactory::createWithModel($provider, $model);
            return $this->client;
        } catch (\Throwable $e) {
            error_log('Levi ChunkContextualizer init error: ' . $e->getMessage());
            return null;
        }
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

    /**
     * Rough truncation to stay within token budget.
     * Uses ~4 chars/token as heuristic for English text.
     */
    private function truncateToTokenBudget(string $text, int $maxTokens): string
    {
        $maxChars = $maxTokens * 4;
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, $maxChars) . "\n...[truncated]";
    }
}
