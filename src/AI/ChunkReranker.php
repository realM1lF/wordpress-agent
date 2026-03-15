<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;

/**
 * Reranks retrieved document chunks using the primary LLM model.
 *
 * Sits between hybrid search (Vector + BM25 + RRF) and the system prompt.
 * Each candidate chunk is scored 1-5 for relevance to the user's query.
 * Only chunks scoring >= RELEVANCE_THRESHOLD are kept.
 *
 * Uses the primary_model (strongest available) because judging relevance
 * requires genuine comprehension -- cheap models produce unreliable scores.
 *
 * Fallback: on any failure the original unranked results pass through unchanged.
 */
class ChunkReranker
{
    private const RELEVANCE_THRESHOLD = 3;
    private const MIN_GUARANTEED = 2;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a relevance judge for a WordPress/WooCommerce documentation search system.

You will receive a user query and numbered documentation chunks. Score EACH chunk's relevance to the query.

Scoring scale:
5 = Directly answers the query or contains the exact API/hook/schema needed
4 = Highly relevant, contains closely related technical details
3 = Partially relevant, provides useful background or related patterns
2 = Tangentially related, unlikely to help with the specific query
1 = Irrelevant to the query

Output ONLY a JSON array. Each element: {"i": <chunk_number>, "s": <score>}
Example: [{"i":0,"s":5},{"i":1,"s":2},{"i":2,"s":4}]

No explanation, no markdown, no text outside the JSON array.
PROMPT;

    /**
     * Rerank chunks by relevance to the user query.
     *
     * @param string $query         The original user message
     * @param array  $chunks        Search results, each with at least a 'content' key
     * @param int    $limit         Max chunks to return after filtering
     * @return array Filtered+sorted subset of $chunks (or original on failure)
     */
    public function rerank(string $query, array $chunks, int $limit): array
    {
        if (count($chunks) <= self::MIN_GUARANTEED) {
            return $chunks;
        }

        try {
            $scores = $this->callLLM($query, $chunks);
        } catch (\Throwable $e) {
            error_log('Levi ChunkReranker error: ' . $e->getMessage());
            return array_slice($chunks, 0, $limit);
        }

        if ($scores === null) {
            return array_slice($chunks, 0, $limit);
        }

        return $this->applyScores($chunks, $scores, $limit);
    }

    /**
     * @return array<array{i: int, s: int}>|null  Parsed scores or null on failure
     */
    private function callLLM(string $query, array $chunks): ?array
    {
        $settings = new SettingsPage();
        $provider = $settings->getProvider();

        if (!$settings->getApiKeyForProvider($provider)) {
            return null;
        }

        $primaryModel = $settings->getModelForProvider($provider);
        $client = AIClientFactory::createWithModel($provider, $primaryModel);

        $userMessage = "Query: {$query}\n\n";
        foreach ($chunks as $i => $chunk) {
            $content = $chunk['content'] ?? '';
            if (mb_strlen($content) > 800) {
                $content = mb_substr($content, 0, 800) . '...';
            }
            $userMessage .= "--- Chunk {$i} ---\n{$content}\n\n";
        }

        $response = $client->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $userMessage],
        ]);

        if (is_wp_error($response)) {
            error_log('Levi ChunkReranker API: ' . $response->get_error_message());
            return null;
        }

        $raw = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        return $this->parseScores($raw, count($chunks));
    }

    /**
     * Parse the JSON scores array from the LLM response.
     * Tolerant: strips markdown fences, handles minor formatting variations.
     */
    private function parseScores(string $raw, int $chunkCount): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded)) {
            error_log('Levi ChunkReranker parse failed: ' . mb_substr($raw, 0, 200));
            return null;
        }

        $scores = [];
        foreach ($decoded as $entry) {
            if (!isset($entry['i'], $entry['s'])) {
                continue;
            }
            $idx = (int) $entry['i'];
            $score = (int) $entry['s'];
            if ($idx >= 0 && $idx < $chunkCount && $score >= 1 && $score <= 5) {
                $scores[] = ['i' => $idx, 's' => $score];
            }
        }

        if (empty($scores)) {
            error_log('Levi ChunkReranker: no valid scores parsed');
            return null;
        }

        return $scores;
    }

    /**
     * Apply scores: filter by threshold, sort by score desc, guarantee minimum results.
     */
    private function applyScores(array $chunks, array $scores, int $limit): array
    {
        usort($scores, fn($a, $b) => $b['s'] <=> $a['s']);

        $relevant = [];
        $allSorted = [];

        foreach ($scores as $entry) {
            $idx = $entry['i'];
            if (!isset($chunks[$idx])) {
                continue;
            }

            $chunk = $chunks[$idx];
            $chunk['rerank_score'] = $entry['s'];
            $allSorted[] = $chunk;

            if ($entry['s'] >= self::RELEVANCE_THRESHOLD) {
                $relevant[] = $chunk;
            }
        }

        // Safety net: if nothing passed the threshold, keep the top MIN_GUARANTEED
        if (empty($relevant) && !empty($allSorted)) {
            $relevant = array_slice($allSorted, 0, self::MIN_GUARANTEED);
            error_log('Levi ChunkReranker: no chunks above threshold, keeping top-' . self::MIN_GUARANTEED);
        }

        $result = array_slice($relevant, 0, $limit);

        // Log for diagnostics
        $scoreLog = array_map(fn($e) => "#{$e['i']}={$e['s']}", $scores);
        error_log('Levi ChunkReranker: ' . count($chunks) . ' candidates → ' . count($result) . ' kept [' . implode(', ', $scoreLog) . ']');

        return $result;
    }
}
