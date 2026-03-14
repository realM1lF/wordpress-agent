<?php

namespace Levi\Agent\API\Concerns;

use Levi\Agent\Agent\Identity;
use Levi\Agent\Memory\EmbeddingCache;
use Levi\Agent\Memory\StateSnapshotService;
use Levi\Agent\Memory\VectorStore;

trait BuildsContext
{
    private function getLatestUserMessage(string $sessionId): string {
        $history = $this->conversationRepo->getHistory($sessionId, 10);
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'user') {
                return (string) ($history[$i]['content'] ?? '');
            }
        }
        return '';
    }

    private function buildMessages(string $sessionId, string $newMessage, bool $includeUploadedContext = true): array {
        $messages = [];

        [$stablePrompt, $dynamicPrompt] = $this->getSystemPromptParts($newMessage, $sessionId, $includeUploadedContext);
        $messages[] = [
            'role' => 'system',
            'content' => $stablePrompt,
        ];
        if ($dynamicPrompt !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $dynamicPrompt,
            ];
        }

        $runtimeSettings = $this->settings->getSettings();
        $historyLimit = max(10, (int) ($runtimeSettings['history_context_limit'] ?? 20));
        $history = $this->conversationRepo->getHistory($sessionId, $historyLimit);

        $summary = $this->conversationRepo->getLatestSummary($sessionId);
        if ($summary !== null) {
            $messages[] = [
                'role' => 'system',
                'content' => "[SESSION-ZUSAMMENFASSUNG – aeltere Nachrichten komprimiert]\n\n" . $summary['content'],
            ];
        }

        $lastChatRole = null;
        foreach ($history as $msg) {
            if (!in_array($msg['role'], ['user', 'assistant'], true)) {
                continue;
            }
            if ($msg['role'] === $lastChatRole) {
                if ($lastChatRole === 'user') {
                    $messages[] = ['role' => 'assistant', 'content' => '[Vorherige Antwort nicht verfuegbar]'];
                } else {
                    $messages[] = ['role' => 'user', 'content' => '(Fortsetzen)'];
                }
            }
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            $lastChatRole = $msg['role'];
        }

        if ($lastChatRole === 'user') {
            $messages[] = ['role' => 'assistant', 'content' => '[Vorherige Antwort nicht verfuegbar]'];
        }

        $userId = get_current_user_id();
        $sessionImages = $includeUploadedContext ? $this->getSessionImages($sessionId, $userId) : [];

        if (!empty($sessionImages)) {
            $contentParts = [['type' => 'text', 'text' => $newMessage]];
            foreach ($sessionImages as $img) {
                $contentParts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $img['base64']],
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $contentParts];
        } else {
            $messages[] = ['role' => 'user', 'content' => $newMessage];
        }

        return $this->trimMessagesToBudget($messages, $sessionId);
    }

    /**
     * Legacy single-string system prompt.
     */
    private function getSystemPrompt(string $query = '', ?string $sessionId = null, bool $includeUploadedContext = true): string {
        [$stable, $dynamic] = $this->getSystemPromptParts($query, $sessionId, $includeUploadedContext);
        if ($dynamic !== '') {
            return $stable . "\n\n---\n\n" . $dynamic;
        }
        return $stable;
    }

    /**
     * All rule modules — always loaded so the model always has full context.
     * Domain modules (elementor, woocommerce, cron) are tiny and cost <1% of context.
     */
    private const ALL_RULE_MODULES = ['core', 'tools', 'coding', 'planning', 'elementor', 'woocommerce', 'cron'];

    /**
     * Returns [stablePrompt, dynamicPrompt].
     *
     * The stable part (identity + all rules) is identical across roundtrips and
     * benefits from provider-side prompt caching. The dynamic part (memories,
     * state baseline, uploads) changes per request.
     *
     * No query classification — the model gets everything and decides itself.
     */
    private function getSystemPromptParts(string $query = '', ?string $sessionId = null, bool $includeUploadedContext = true): array {
        // ---- STABLE PART (cacheable): identity + ALL rule modules ----
        try {
            $stablePrompt = $this->getCachedIdentity(self::ALL_RULE_MODULES);
        } catch (\Throwable $e) {
            error_log('Levi Identity Error: ' . $e->getMessage());
            $stablePrompt = "You are Levi, a helpful AI assistant for WordPress.";
        }

        // ---- DYNAMIC PART (changes per request) ----
        $dynamicParts = [];

        try {
            $dynamicParts[] = Identity::getDynamicContext();
        } catch (\Throwable $e) {
            // non-critical
        }

        // Always search vector DB for relevant context (model benefits from having it)
        if (!empty($query)) {
            $fullStrategy = ['identity' => true, 'reference' => true, 'snapshot' => true, 'full_tools' => true];
            try {
                $contextMemories = $this->getContextMemories($query, $fullStrategy);
                if (!empty($contextMemories)) {
                    $dynamicParts[] = "# Relevant Context\n\n" . $contextMemories;
                }
            } catch (\Throwable $e) {
                error_log('Levi Memory Error: ' . $e->getMessage());
            }
        }

        $stateBaseline = StateSnapshotService::getPromptContext();
        if ($stateBaseline !== '') {
            $dynamicParts[] = "# Daily WordPress State Baseline\n\n" . $stateBaseline;
        }

        if ($includeUploadedContext && !empty($sessionId)) {
            $uploadedContext = $this->buildUploadedFilesContext($sessionId, get_current_user_id());
            if ($uploadedContext !== '') {
                $dynamicParts[] = "# Session File Context\n\n" . $uploadedContext;
            }
        }

        return [$stablePrompt, implode("\n\n", $dynamicParts)];
    }
    
    /**
     * Lightweight message builder for conversational messages (no tools needed).
     * Uses minimal system prompt + limited history. Saves ~90% tokens.
     */
    private function buildMessagesLight(string $sessionId, string $newMessage): array {
        $messages = [];

        $messages[] = [
            'role' => 'system',
            'content' => $this->getMinimalSystemPrompt(),
        ];

        $history = $this->conversationRepo->getHistory($sessionId, 6);
        $lastChatRole = null;
        foreach ($history as $msg) {
            if (!in_array($msg['role'], ['user', 'assistant'], true)) {
                continue;
            }
            if ($msg['role'] === $lastChatRole && $lastChatRole === 'user') {
                $messages[] = ['role' => 'assistant', 'content' => '[Vorherige Antwort nicht verfuegbar]'];
            }
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            $lastChatRole = $msg['role'];
        }

        if ($lastChatRole === 'user') {
            $messages[] = ['role' => 'assistant', 'content' => '[Vorherige Antwort nicht verfuegbar]'];
        }

        $messages[] = ['role' => 'user', 'content' => $newMessage];

        return $messages;
    }

    /**
     * Get minimal system prompt for simple queries
     * Reduces 19KB -> ~500 bytes for ~95% token savings
     */
    private function getMinimalSystemPrompt(): string {
        return <<<'PROMPT'
Du bist Levi, ein KI-Assistent direkt in WordPress. Freundlich, per Du, mindestens 1 Emoji pro Antwort.

## Regeln
- Tool-Ergebnisse = einzige Wahrheit. NUR zeigen was das Tool zurückgibt, nie ergänzen/halluzinieren.
- Alle Einträge mit exakten IDs/Titeln zeigen, nie Platzhalter.
- Neue Inhalte als Draft. Destruktive Aktionen: Direkt ausführen, Backend zeigt Button.
- Globale WP-Einstellungen nie eigenmächtig ändern.
PROMPT;
    }

    /**
     * Get cached identity text from WordPress transient.
     * When rule modules are provided and modular rules exist, only the specified
     * modules are loaded — resulting in a smaller prompt for simple queries.
     *
     * @param string[]|null $ruleModules Specific rule module names, or null for full rules.md
     */
    private function getCachedIdentity(?array $ruleModules = null): string {
        $this->ensureMemoryFreshness();

        $cacheKey = 'levi_identity_cache';
        if ($ruleModules !== null) {
            sort($ruleModules);
            $cacheKey .= '_' . implode('_', $ruleModules);
        }

        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $identity = new Identity();
        $content = $identity->getFullContent($ruleModules);
        $hash = $identity->getContentHash();

        if ($content !== '') {
            set_transient($cacheKey, $content, HOUR_IN_SECONDS);
            set_transient('levi_identity_hash', $hash, HOUR_IN_SECONDS);
        }

        return $content !== '' ? $content : "You are Levi, a helpful AI assistant for WordPress.";
    }

    /**
     * Check if identity files changed (throttled to once per 60 seconds).
     * If changed: invalidate cache and re-sync identity to Vector DB.
     */
    private function ensureMemoryFreshness(): void {
        if (get_transient('levi_identity_freshness_checked')) {
            return;
        }
        set_transient('levi_identity_freshness_checked', '1', 60);

        try {
            $identity = new Identity();
            $currentHash = $identity->getContentHash();
            $storedHash = get_transient('levi_identity_hash');

            if ($storedHash !== false && $storedHash === $currentHash) {
                return;
            }

            delete_transient('levi_identity_cache');

            $loader = new \Levi\Agent\Memory\MemoryLoader();
            $loader->loadIdentityFiles();

            set_transient('levi_identity_hash', $currentHash, HOUR_IN_SECONDS);
        } catch (\Throwable $e) {
            error_log('Levi ensureMemoryFreshness: ' . $e->getMessage());
        }
    }

    
    /**
     * Fetch context memories from Vector DB using hybrid search (Semantic + BM25),
     * confidence-based reranking with the primary model, and retrieval gating.
     */
    private function getContextMemories(string $query, array $strategy): string {
        try {
            $vectorStore = new VectorStore();
        } catch (\Throwable $e) {
            error_log('Levi VectorStore init: ' . $e->getMessage());
            return '';
        }

        $cache = new EmbeddingCache();
        $expander = new \Levi\Agent\AI\QueryExpander();
        $searchQueries = $expander->expand($query);
        $isComplex = $expander->isLastQueryComplex();
        $needsReferenceRetrieval = $expander->needsRetrieval();

        $embeddings = [];
        foreach ($searchQueries as $q) {
            $emb = $cache->get($q);
            if ($emb === null) {
                $emb = $vectorStore->generateEmbedding($q);
                if (!is_wp_error($emb) && !empty($emb)) {
                    $cache->set($q, $emb);
                }
            }
            if (!is_wp_error($emb) && !empty($emb)) {
                $embeddings[] = $emb;
            }
        }

        if (empty($embeddings)) {
            return '';
        }

        // Initialize BM25 for hybrid search
        $bm25 = null;
        $db = $vectorStore->getDatabase();
        if ($db !== null) {
            try {
                $bm25 = new \Levi\Agent\Memory\BM25Index($db);
            } catch (\Throwable $e) {
                error_log('Levi BM25 init: ' . $e->getMessage());
            }
        }

        $memories = [];
        try {
            $runtimeSettings = $this->settings->getSettings();
            $referenceK = max(1, (int) ($runtimeSettings['memory_reference_k'] ?? 8));
            $similarity = (float) ($runtimeSettings['memory_min_similarity'] ?? 0.5);

            if ($isComplex) {
                $referenceK = (int) ceil($referenceK * 1.6);
            }

            // Retrieval gating: skip reference docs for simple CRUD/status queries.
            // Snapshots and episodic memory are always fetched.
            if (!empty($strategy['reference']) && $needsReferenceRetrieval) {
                // Overfetch 2x for reranking, then let the reranker filter down
                $overfetchK = $referenceK * 2;
                $candidates = $this->hybridSearch(
                    $vectorStore, $bm25, $embeddings, $searchQueries, 'reference', $overfetchK, $similarity
                );

                if (!empty($candidates)) {
                    $reranker = new \Levi\Agent\AI\ChunkReranker();
                    $reranked = $reranker->rerank($query, $candidates, $referenceK);
                    if (!empty($reranked)) {
                        $memories[] = "## Reference Knowledge\n" . implode("\n", array_map(fn($r) => $r['content'], $reranked));
                    }
                }
            }

            if (!empty($strategy['snapshot'])) {
                $snapshotSimilarity = max(0.5, $similarity - 0.1);
                $mergedSnapshots = $this->multiQuerySearch(
                    $vectorStore, $embeddings, 'state_snapshot', 2, $snapshotSimilarity
                );
                if (!empty($mergedSnapshots)) {
                    $snapshotTexts = array_map(function ($r) {
                        $content = (string) ($r['content'] ?? '');
                        if (mb_strlen($content) > 1500) {
                            $content = mb_substr($content, 0, 1500) . "\n...[truncated]";
                        }
                        return $content;
                    }, $mergedSnapshots);
                    $memories[] = "## Historical System Snapshots\n" . implode("\n\n", $snapshotTexts);
                }
            }

            $userId = get_current_user_id();
            if ($userId > 0 && !empty($embeddings[0])) {
                $episodic = $vectorStore->searchEpisodicMemories($embeddings[0], $userId, 5, 0.70);
                if (!empty($episodic)) {
                    $facts = array_map(fn($r) => '- ' . $r['fact'], $episodic);
                    $memories[] = "## Learnings from previous sessions\n" . implode("\n", $facts);
                }
            }
        } catch (\Throwable $e) {
            error_log('Levi Memory search: ' . $e->getMessage());
        }

        return implode("\n\n", $memories);
    }

    /**
     * Hybrid search: combine semantic vector search with BM25 keyword search
     * using Reciprocal Rank Fusion (RRF).
     *
     * Falls back to vector-only when BM25 is unavailable.
     */
    private function hybridSearch(
        VectorStore $store,
        ?\Levi\Agent\Memory\BM25Index $bm25,
        array $embeddings,
        array $searchQueries,
        string $memoryType,
        int $limit,
        float $minSimilarity
    ): array {
        // Each source fetches slightly more than $limit so RRF has enough
        // overlap to merge effectively. The caller controls the total
        // overfetch (e.g. 2x for reranking) via $limit itself.
        $sourceLimit = (int) ceil($limit * 1.5);

        $vectorResults = $this->multiQuerySearch($store, $embeddings, $memoryType, $sourceLimit, $minSimilarity);

        if ($bm25 === null) {
            return array_slice($vectorResults, 0, $limit);
        }

        $bm25Merged = [];
        foreach ($searchQueries as $q) {
            $bm25Results = $bm25->search($q, $memoryType, $sourceLimit);
            foreach ($bm25Results as $r) {
                $key = $r['chunk_id'] ?? md5($r['content'] ?? '');
                if (!isset($bm25Merged[$key]) || ($r['score'] ?? 0) > ($bm25Merged[$key]['score'] ?? 0)) {
                    $bm25Merged[$key] = $r;
                }
            }
        }
        usort($bm25Merged, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $bm25Top = array_slice($bm25Merged, 0, $sourceLimit);

        if (empty($bm25Top)) {
            return array_slice($vectorResults, 0, $limit);
        }

        return \Levi\Agent\Memory\BM25Index::reciprocalRankFusion($vectorResults, $bm25Top, $limit);
    }

    /**
     * Search with multiple embeddings and merge results, keeping the highest similarity per chunk.
     */
    private function multiQuerySearch(VectorStore $store, array $embeddings, string $memoryType, int $limit, float $minSimilarity): array {
        $merged = [];

        foreach ($embeddings as $emb) {
            $results = $store->searchSimilar($emb, $memoryType, $limit, $minSimilarity);
            foreach ($results as $r) {
                $key = $r['id'] ?? md5($r['content'] ?? '');
                if (!isset($merged[$key]) || ($r['similarity'] ?? 0) > ($merged[$key]['similarity'] ?? 0)) {
                    $merged[$key] = $r;
                }
            }
        }

        usort($merged, fn($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));

        return array_slice($merged, 0, $limit);
    }
}
