<?php

namespace Levi\Agent\Memory;

/**
 * BM25 keyword search over memory_vectors chunks stored in SQLite.
 *
 * Implements the Okapi BM25 ranking function for hybrid search alongside
 * vector similarity.  All data lives in the same SQLite database as the
 * vectors so there's no extra infrastructure.
 *
 * Tables:
 *   bm25_terms(chunk_id, term, tf)   – term frequencies per chunk
 *   bm25_stats(term, df)             – document frequencies per term
 *   bm25_meta(key, value)            – corpus statistics (total docs, avg length)
 *
 * The index is rebuilt whenever the CHUNK_VERSION changes (same trigger as
 * vector re-indexing).
 */
class BM25Index
{
    private const K1 = 1.2;
    private const B = 0.75;
    private const RRF_K = 60;

    private ?\SQLite3 $db;

    public function __construct(\SQLite3 $db)
    {
        $this->db = $db;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS bm25_terms (
                chunk_id INTEGER NOT NULL,
                term VARCHAR(100) NOT NULL,
                tf REAL NOT NULL,
                PRIMARY KEY (chunk_id, term)
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_bm25_term ON bm25_terms(term)
        ');

        // Stores the raw token count per document for BM25 length normalization
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS bm25_doc_len (
                chunk_id INTEGER PRIMARY KEY,
                doc_len INTEGER NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS bm25_stats (
                term VARCHAR(100) PRIMARY KEY,
                df INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS bm25_meta (
                key VARCHAR(50) PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');
    }

    /**
     * Index a single chunk.  Call after inserting the vector.
     *
     * @param int    $chunkId  The memory_vectors.id
     * @param string $text     The (contextualized) chunk text
     */
    public function indexChunk(int $chunkId, string $text): void
    {
        $terms = $this->tokenize($text);
        if (empty($terms)) {
            return;
        }

        $freq = array_count_values($terms);
        $totalTerms = count($terms);

        // Store raw term frequency (count) per term
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO bm25_terms (chunk_id, term, tf) VALUES (:cid, :term, :tf)');
        foreach ($freq as $term => $count) {
            $stmt->bindValue(':cid', $chunkId, SQLITE3_INTEGER);
            $stmt->bindValue(':term', (string) $term, SQLITE3_TEXT);
            $stmt->bindValue(':tf', (float) $count, SQLITE3_FLOAT);
            $stmt->execute();
            $stmt->reset();
        }

        // Store raw document length for BM25 normalization
        $lenStmt = $this->db->prepare('INSERT OR REPLACE INTO bm25_doc_len (chunk_id, doc_len) VALUES (:cid, :len)');
        $lenStmt->bindValue(':cid', $chunkId, SQLITE3_INTEGER);
        $lenStmt->bindValue(':len', $totalTerms, SQLITE3_INTEGER);
        $lenStmt->execute();
    }

    /**
     * Bulk-index all existing chunks from memory_vectors that don't have BM25 entries yet.
     * Should be called once after the vector index is built.
     */
    public function rebuildFromVectors(string $memoryType = ''): int
    {
        $sql = 'SELECT id, content FROM memory_vectors WHERE id NOT IN (SELECT DISTINCT chunk_id FROM bm25_terms)';
        if ($memoryType !== '') {
            $sql .= ' AND memory_type = :type';
        }

        $stmt = $this->db->prepare($sql);
        if ($memoryType !== '') {
            $stmt->bindValue(':type', $memoryType, SQLITE3_TEXT);
        }

        $result = $stmt->execute();
        $indexed = 0;

        $this->db->exec('BEGIN IMMEDIATE');
        try {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $this->indexChunk((int) $row['id'], (string) $row['content']);
                $indexed++;
            }
            $this->rebuildStats();
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (\Throwable $_) {}
            error_log('BM25Index rebuild error: ' . $e->getMessage());
        }

        return $indexed;
    }

    /**
     * Recalculate document frequencies and corpus stats.
     */
    public function rebuildStats(): void
    {
        $this->db->exec('DELETE FROM bm25_stats');
        $this->db->exec('
            INSERT INTO bm25_stats (term, df)
            SELECT term, COUNT(DISTINCT chunk_id)
            FROM bm25_terms
            GROUP BY term
        ');

        $totalDocs = (int) ($this->db->querySingle('SELECT COUNT(DISTINCT chunk_id) FROM bm25_doc_len') ?? 0);
        $avgLen = (float) ($this->db->querySingle('SELECT AVG(doc_len) FROM bm25_doc_len') ?? 1);

        $this->setMeta('total_docs', (string) $totalDocs);
        $this->setMeta('avg_doc_len', (string) max(1.0, $avgLen));
    }

    /**
     * Remove BM25 entries for chunks belonging to a specific file.
     */
    public function clearFileEntries(string $sourceFile, string $memoryType): void
    {
        $subquery = 'SELECT id FROM memory_vectors WHERE source_file = :sf AND memory_type = :mt';

        $stmt = $this->db->prepare("DELETE FROM bm25_terms WHERE chunk_id IN ({$subquery})");
        $stmt->bindValue(':sf', $sourceFile, SQLITE3_TEXT);
        $stmt->bindValue(':mt', $memoryType, SQLITE3_TEXT);
        $stmt->execute();

        $stmt2 = $this->db->prepare("DELETE FROM bm25_doc_len WHERE chunk_id IN ({$subquery})");
        $stmt2->bindValue(':sf', $sourceFile, SQLITE3_TEXT);
        $stmt2->bindValue(':mt', $memoryType, SQLITE3_TEXT);
        $stmt2->execute();
    }

    /**
     * Search chunks using BM25 scoring.
     *
     * @param string $query       Raw search query
     * @param string $memoryType  Filter by memory type ('' = all)
     * @param int    $limit       Max results
     * @return array<array{chunk_id: int, score: float, content: string}>
     */
    public function search(string $query, string $memoryType = '', int $limit = 20): array
    {
        $queryTerms = $this->tokenize($query);
        if (empty($queryTerms)) {
            return [];
        }

        $queryTerms = array_values(array_unique($queryTerms));
        $totalDocs = (int) ($this->getMeta('total_docs') ?? '0');
        $avgDocLen = (float) ($this->getMeta('avg_doc_len') ?? '1');

        if ($totalDocs === 0) {
            return [];
        }

        // Fetch DF for query terms
        $placeholders = implode(',', array_fill(0, count($queryTerms), '?'));
        $dfStmt = $this->db->prepare("SELECT term, df FROM bm25_stats WHERE term IN ({$placeholders})");
        foreach ($queryTerms as $i => $term) {
            $dfStmt->bindValue($i + 1, $term, SQLITE3_TEXT);
        }
        $dfResult = $dfStmt->execute();
        $dfMap = [];
        while ($row = $dfResult->fetchArray(SQLITE3_ASSOC)) {
            $dfMap[$row['term']] = (int) $row['df'];
        }

        // Fetch matching chunks with their TF values
        $sql = "SELECT bt.chunk_id, bt.term, bt.tf, mv.content, mv.original_content
                FROM bm25_terms bt
                JOIN memory_vectors mv ON mv.id = bt.chunk_id
                WHERE bt.term IN ({$placeholders})";
        if ($memoryType !== '') {
            $sql .= ' AND mv.memory_type = ?';
        }

        $tfStmt = $this->db->prepare($sql);
        $paramIdx = 1;
        foreach ($queryTerms as $term) {
            $tfStmt->bindValue($paramIdx++, $term, SQLITE3_TEXT);
        }
        if ($memoryType !== '') {
            $tfStmt->bindValue($paramIdx, $memoryType, SQLITE3_TEXT);
        }

        $tfResult = $tfStmt->execute();

        $chunkData = [];
        while ($row = $tfResult->fetchArray(SQLITE3_ASSOC)) {
            $cid = (int) $row['chunk_id'];
            if (!isset($chunkData[$cid])) {
                $chunkData[$cid] = [
                    'terms' => [],
                    'content' => $row['original_content'] ?? $row['content'],
                ];
            }
            $chunkData[$cid]['terms'][$row['term']] = (float) $row['tf'];
        }

        // Fetch raw document lengths for BM25 normalization
        if (!empty($chunkData)) {
            $cidList = implode(',', array_keys($chunkData));
            $lenResult = $this->db->query("SELECT chunk_id, doc_len FROM bm25_doc_len WHERE chunk_id IN ({$cidList})");
            $docLens = [];
            while ($row = $lenResult->fetchArray(SQLITE3_ASSOC)) {
                $docLens[(int) $row['chunk_id']] = (int) $row['doc_len'];
            }
        }

        // Score each chunk using BM25 with proper length normalization
        $scores = [];
        foreach ($chunkData as $cid => $data) {
            $score = 0.0;
            $docLen = $docLens[$cid] ?? 1;

            foreach ($queryTerms as $term) {
                $tf = $data['terms'][$term] ?? 0.0;
                if ($tf <= 0) {
                    continue;
                }

                $df = $dfMap[$term] ?? 0;
                $idf = log(($totalDocs - $df + 0.5) / ($df + 0.5) + 1.0);
                $tfNorm = ($tf * (self::K1 + 1)) / ($tf + self::K1 * (1 - self::B + self::B * ($docLen / $avgDocLen)));
                $score += $idf * $tfNorm;
            }

            if ($score > 0) {
                $scores[] = [
                    'chunk_id' => $cid,
                    'score' => $score,
                    'content' => $data['content'],
                ];
            }
        }

        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scores, 0, $limit);
    }

    /**
     * Merge vector search and BM25 search results using Reciprocal Rank Fusion.
     *
     * @param array $vectorResults  From VectorStore::searchSimilar()
     * @param array $bm25Results    From BM25Index::search()
     * @param int   $limit          Max merged results
     * @return array Merged results sorted by RRF score
     */
    public static function reciprocalRankFusion(array $vectorResults, array $bm25Results, int $limit = 10): array
    {
        $rrfScores = [];
        $contentMap = [];

        foreach ($vectorResults as $rank => $result) {
            $id = $result['id'] ?? $result['chunk_id'] ?? md5($result['content'] ?? '');
            $rrfScores[$id] = ($rrfScores[$id] ?? 0) + (1.0 / (self::RRF_K + $rank));
            $contentMap[$id] = $result;
        }

        foreach ($bm25Results as $rank => $result) {
            $id = $result['chunk_id'] ?? $result['id'] ?? md5($result['content'] ?? '');
            $rrfScores[$id] = ($rrfScores[$id] ?? 0) + (1.0 / (self::RRF_K + $rank));
            if (!isset($contentMap[$id])) {
                $contentMap[$id] = $result;
            }
        }

        arsort($rrfScores);

        $merged = [];
        foreach (array_slice($rrfScores, 0, $limit, true) as $id => $score) {
            $entry = $contentMap[$id];
            $entry['rrf_score'] = $score;
            $merged[] = $entry;
        }

        return $merged;
    }

    /**
     * Tokenize text into lowercase terms, filtering short/stop words.
     *
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text);
        $lower = preg_replace('/[^\p{L}\p{N}\s_\-]/u', ' ', $lower);
        $words = preg_split('/\s+/', $lower, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, function (string $w) {
            return mb_strlen($w) >= 2 && mb_strlen($w) <= 80;
        }));
    }

    private function setMeta(string $key, string $value): void
    {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO bm25_meta (key, value) VALUES (:k, :v)');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':v', $value, SQLITE3_TEXT);
        $stmt->execute();
    }

    private function getMeta(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM bm25_meta WHERE key = :k');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['value'] : null;
    }
}
