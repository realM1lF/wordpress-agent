<?php

namespace Levi\Agent\Memory;

use WP_Error;

class VectorStore {
    private string $dbPath;
    private ?\SQLite3 $db = null;
    private string $embeddingModel = 'text-embedding-3-small';
    private int $embeddingDimensions = 1536;

    public function __construct() {
        $this->dbPath = LEVI_AGENT_PLUGIN_DIR . 'data/vector-memory.sqlite';
        $this->init();
    }

    public function isAvailable(): bool {
        return $this->db !== null;
    }

    private function init(): void {
        if (!class_exists('\SQLite3')) {
            error_log('Levi Agent: SQLite3 PHP extension not available. Vector memory disabled.');
            return;
        }

        $dataDir = dirname($this->dbPath);
        if (!is_dir($dataDir)) {
            wp_mkdir_p($dataDir);
        }
        if (!is_dir($dataDir) || !is_writable($dataDir)) {
            error_log('Levi Agent: data directory not writable: ' . $dataDir);
            return;
        }

        try {
            $this->db = new \SQLite3($this->dbPath);
            $this->db->enableExceptions(true);
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA busy_timeout=30000');
            $this->createTables();
        } catch (\Throwable $e) {
            error_log('Levi Agent: SQLite3 init failed: ' . $e->getMessage());
            $this->db = null;
        }
    }

    private function createTables(): void {
        // Create base table (without bucket_hash for backward compatibility)
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS memory_vectors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_file VARCHAR(255),
                content TEXT NOT NULL,
                embedding BLOB NOT NULL,
                memory_type VARCHAR(50) NOT NULL,
                chunk_index INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_memory_type ON memory_vectors(memory_type)
        ');

        // Migration: Add bucket_hash column if not exists
        $this->migrateAddBucketHashColumn();

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_bucket_hash ON memory_vectors(bucket_hash)
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_memory_type_bucket ON memory_vectors(memory_type, bucket_hash)
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS episodic_memory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                fact TEXT NOT NULL,
                embedding BLOB NOT NULL,
                context VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_episodic_user ON episodic_memory(user_id)
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS loaded_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path VARCHAR(500) UNIQUE NOT NULL,
                file_hash VARCHAR(64) NOT NULL,
                loaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        // Migration: Calculate bucket_hash for existing vectors
        $this->migrateAddBucketHash();

        // Migration: Add original_content column for contextual retrieval
        $this->migrateAddOriginalContentColumn();
    }
    
    /**
     * Migration: Add bucket_hash column if it doesn't exist
     */
    private function migrateAddBucketHashColumn(): void {
        try {
            // Check if column exists
            $result = $this->db->query("PRAGMA table_info(memory_vectors)");
            $hasBucketHash = false;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'bucket_hash') {
                    $hasBucketHash = true;
                    break;
                }
            }
            
            if (!$hasBucketHash) {
                $this->db->exec('ALTER TABLE memory_vectors ADD COLUMN bucket_hash VARCHAR(32) DEFAULT NULL');
                error_log('Levi VectorStore: Added bucket_hash column');
            }
        } catch (\Throwable $e) {
            error_log('Levi VectorStore migration error: ' . $e->getMessage());
        }
    }
    
    /**
     * Migration: Calculate bucket_hash for existing vectors without it
     */
    private function migrateAddBucketHash(): void {
        try {
            $result = $this->db->query("
                SELECT id, embedding FROM memory_vectors 
                WHERE bucket_hash IS NULL 
                LIMIT 100
            ");
            
            $updated = 0;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $vector = json_decode($row['embedding'], true);
                if ($vector) {
                    $bucketHash = $this->calculateBucketHash($vector);
                    $stmt = $this->db->prepare('
                        UPDATE memory_vectors 
                        SET bucket_hash = :bucket 
                        WHERE id = :id
                    ');
                    $stmt->bindValue(':bucket', $bucketHash, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
                    $stmt->execute();
                    $updated++;
                }
            }
            
            if ($updated > 0) {
                error_log("Levi VectorStore: Migrated {$updated} vectors with bucket_hash");
            }
        } catch (\Throwable $e) {
            // Silent fail - will retry next time
        }
    }

    /**
     * Migration: Add original_content column for Contextual Retrieval.
     * Stores the raw chunk text so the system prompt receives the original
     * (shorter) content while embeddings use the context-enriched version.
     */
    private function migrateAddOriginalContentColumn(): void
    {
        try {
            $result = $this->db->query("PRAGMA table_info(memory_vectors)");
            $hasOriginalContent = false;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'original_content') {
                    $hasOriginalContent = true;
                    break;
                }
            }

            if (!$hasOriginalContent) {
                $this->db->exec('ALTER TABLE memory_vectors ADD COLUMN original_content TEXT DEFAULT NULL');
                error_log('Levi VectorStore: Added original_content column');
            }
        } catch (\Throwable $e) {
            error_log('Levi VectorStore original_content migration error: ' . $e->getMessage());
        }
    }

    /**
     * Calculate bucket hash from first 32 dimensions of vector
     * This enables fast pre-filtering (HNSW-like behavior)
     * 
     * @param array $vector Full embedding vector
     * @return string Bucket hash for indexing
     */
    private function calculateBucketHash(array $vector): string {
        // Take first 32 dimensions
        $bucketDimensions = array_slice($vector, 0, 32);
        
        // Quantize: convert floats to 4-bit values (0-15)
        $quantized = [];
        foreach ($bucketDimensions as $value) {
            // Normalize to 0-15 range
            $normalized = (int) ((($value + 1) / 2) * 15);
            $normalized = max(0, min(15, $normalized));
            $quantized[] = dechex($normalized);
        }
        
        return implode('', $quantized);
    }

    /**
     * Generate embedding via configured provider (OpenRouter/OpenAI).
     * Uses EmbeddingCache to avoid repeated API calls.
     */
    public function generateEmbedding(string $text): array|WP_Error {
        // Check cache first
        $cache = new EmbeddingCache();
        $cached = $cache->get($text);
        if ($cached !== null) {
            return $cached;
        }

        $config = $this->getEmbeddingRequestConfig();
        if (is_wp_error($config)) {
            return $config;
        }

        $text = substr($text, 0, 8000);

        $response = wp_remote_post($config['endpoint'], [
            'headers' => $config['headers'],
            'body' => json_encode([
                'input' => $text,
                'model' => $config['model'],
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['error']['message'] ?? ('Embedding generation failed (' . $config['provider'] . ')');
            return new WP_Error('embedding_failed', $error);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $embedding = $body['data'][0]['embedding'] ?? [];

        // Store in cache for future use
        if (!empty($embedding)) {
            $cache->set($text, $embedding);
        }

        return $embedding;
    }

    public function storeVector(
        string $content,
        array $embedding,
        string $memoryType,
        string $sourceFile = '',
        int $chunkIndex = 0,
        ?string $originalContent = null
    ): bool {
        if (!$this->db) {
            return false;
        }

        try {
            $bucketHash = $this->calculateBucketHash($embedding);
            
            $stmt = $this->db->prepare('
                INSERT INTO memory_vectors (source_file, content, embedding, memory_type, chunk_index, bucket_hash, original_content)
                VALUES (:source_file, :content, :embedding, :memory_type, :chunk_index, :bucket_hash, :original_content)
            ');

            $stmt->bindValue(':source_file', $sourceFile, SQLITE3_TEXT);
            $stmt->bindValue(':content', $content, SQLITE3_TEXT);
            $stmt->bindValue(':embedding', json_encode($embedding), SQLITE3_BLOB);
            $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            $stmt->bindValue(':chunk_index', $chunkIndex, SQLITE3_INTEGER);
            $stmt->bindValue(':bucket_hash', $bucketHash, SQLITE3_TEXT);
            $stmt->bindValue(':original_content', $originalContent, $originalContent !== null ? SQLITE3_TEXT : SQLITE3_NULL);

            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk insert multiple vectors in a single transaction (much faster)
     * 
     * @param array $vectors Array of ['content', 'embedding', 'memory_type', 'source_file', 'chunk_index', 'original_content'?]
     * @return int Number of inserted vectors
     */
    public function bulkInsertVectors(array $vectors): int {
        if (!$this->db || empty($vectors)) {
            return 0;
        }

        try {
            $this->db->exec('BEGIN TRANSACTION');
            
            $stmt = $this->db->prepare('
                INSERT INTO memory_vectors (source_file, content, embedding, memory_type, chunk_index, bucket_hash, original_content)
                VALUES (:source_file, :content, :embedding, :memory_type, :chunk_index, :bucket_hash, :original_content)
            ');

            $inserted = 0;
            foreach ($vectors as $vector) {
                $bucketHash = $this->calculateBucketHash($vector['embedding']);
                
                $stmt->bindValue(':source_file', $vector['source_file'], SQLITE3_TEXT);
                $stmt->bindValue(':content', $vector['content'], SQLITE3_TEXT);
                $stmt->bindValue(':embedding', json_encode($vector['embedding']), SQLITE3_BLOB);
                $stmt->bindValue(':memory_type', $vector['memory_type'], SQLITE3_TEXT);
                $stmt->bindValue(':chunk_index', $vector['chunk_index'], SQLITE3_INTEGER);
                $stmt->bindValue(':bucket_hash', $bucketHash, SQLITE3_TEXT);
                $origContent = $vector['original_content'] ?? null;
                $stmt->bindValue(':original_content', $origContent, $origContent !== null ? SQLITE3_TEXT : SQLITE3_NULL);
                
                $stmt->execute();
                $inserted++;
            }
            
            $this->db->exec('COMMIT');
            return $inserted;
        } catch (\Exception $e) {
            $this->db->exec('ROLLBACK');
            error_log('VectorStore bulk insert error: ' . $e->getMessage());
            return 0;
        }
    }

    public function storeEpisodicMemory(
        string $fact,
        array $embedding,
        ?int $userId = null,
        string $context = ''
    ): bool {
        if (!$this->db) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO episodic_memory (user_id, fact, embedding, context)
                VALUES (:user_id, :fact, :embedding, :context)
            ');

            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':fact', $fact, SQLITE3_TEXT);
            $stmt->bindValue(':embedding', json_encode($embedding), SQLITE3_BLOB);
            $stmt->bindValue(':context', $context, SQLITE3_TEXT);

            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore error: ' . $e->getMessage());
            return false;
        }
    }

    public function searchSimilar(
        array $queryEmbedding,
        string $memoryType = '',
        int $limit = 5,
        float $minSimilarity = 0.7
    ): array {
        if (!$this->db) {
            return [];
        }

        // For small datasets (< 3000 vectors), brute-force is fast enough
        // and avoids recall loss from bucket hash pre-filtering.
        $totalVectors = $this->getTotalVectorCount($memoryType);
        $useBucketFilter = $totalVectors > 3000;

        if ($useBucketFilter) {
            $queryBucket = $this->calculateBucketHash($queryEmbedding);
            $similarBuckets = $this->getSimilarBucketPatterns($queryBucket);

            $sql = 'SELECT id, content, original_content, embedding, memory_type, source_file FROM memory_vectors WHERE (';
            $bucketConditions = [];
            foreach ($similarBuckets as $i => $pattern) {
                $bucketConditions[] = "bucket_hash LIKE :bucket{$i}";
            }
            $bucketConditions[] = "bucket_hash IS NULL";
            $sql .= implode(' OR ', $bucketConditions) . ')';
            if ($memoryType) {
                $sql .= ' AND memory_type = :memory_type';
            }
            $sql .= ' LIMIT 500';

            $stmt = $this->db->prepare($sql);
            foreach ($similarBuckets as $i => $pattern) {
                $stmt->bindValue(":bucket{$i}", $pattern, SQLITE3_TEXT);
            }
            if ($memoryType) {
                $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            }
        } else {
            $sql = 'SELECT id, content, original_content, embedding, memory_type, source_file FROM memory_vectors';
            if ($memoryType) {
                $sql .= ' WHERE memory_type = :memory_type';
            }

            $stmt = $this->db->prepare($sql);
            if ($memoryType) {
                $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            }
        }

        $result = $stmt->execute();
        $similarities = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $vector = json_decode($row['embedding'], true);
            if (!$vector) continue;

            $similarity = $this->cosineSimilarity($queryEmbedding, $vector);
            
            if ($similarity >= $minSimilarity) {
                $displayContent = $row['original_content'] ?? $row['content'];
                $similarities[] = [
                    'id' => $row['id'],
                    'content' => $displayContent,
                    'memory_type' => $row['memory_type'],
                    'source_file' => $row['source_file'],
                    'similarity' => $similarity,
                ];
            }
        }

        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($similarities, 0, $limit);
    }

    private function getTotalVectorCount(string $memoryType = ''): int {
        try {
            if ($memoryType) {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM memory_vectors WHERE memory_type = :type');
                $stmt->bindValue(':type', $memoryType, SQLITE3_TEXT);
                $result = $stmt->execute();
            } else {
                $result = $this->db->query('SELECT COUNT(*) FROM memory_vectors');
            }
            $row = $result->fetchArray(SQLITE3_NUM);
            return (int) ($row[0] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Generate similar bucket patterns for approximate matching
     * This creates wildcard patterns that match similar quantized vectors
     * 
     * @param string $bucketHash Original bucket hash
     * @return array Array of LIKE patterns
     */
    private function getSimilarBucketPatterns(string $bucketHash): array {
        $patterns = [$bucketHash]; // Exact match
        
        // Add patterns with single wildcard (16 possibilities)
        // This covers 1/16th variation in each position
        for ($i = 0; $i < 8; $i++) {
            $pos = $i * 4;
            $prefix = substr($bucketHash, 0, $pos);
            $suffix = substr($bucketHash, $pos + 1);
            $patterns[] = $prefix . '_' . $suffix;
        }
        
        // Add patterns with double wildcard at key positions
        $patterns[] = substr($bucketHash, 0, 4) . '__' . substr($bucketHash, 6);
        $patterns[] = substr($bucketHash, 0, 12) . '__' . substr($bucketHash, 14);
        $patterns[] = substr($bucketHash, 0, 20) . '__' . substr($bucketHash, 22);
        $patterns[] = substr($bucketHash, 0, 28) . '__' . substr($bucketHash, 30);
        
        return array_unique($patterns);
    }

    public function searchEpisodicMemories(
        array $queryEmbedding,
        ?int $userId = null,
        int $limit = 3,
        float $minSimilarity = 0.75
    ): array {
        if (!$this->db) {
            return [];
        }

        // OPTIMIZED: Single query with embedding included (no N+1 problem)
        $sql = 'SELECT id, fact, context, embedding FROM episodic_memory';

        if ($userId) {
            $sql .= ' WHERE user_id = :user_id';
        }
        
        $sql .= ' ORDER BY id DESC LIMIT 500'; // Only check recent memories for performance

        $stmt = $this->db->prepare($sql);
        
        if ($userId) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }

        $result = $stmt->execute();
        $similarities = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $vector = json_decode($row['embedding'], true);
            if (!$vector) continue;

            $similarity = $this->cosineSimilarity($queryEmbedding, $vector);
            
            if ($similarity >= $minSimilarity) {
                $similarities[] = [
                    'id' => $row['id'],
                    'fact' => $row['fact'],
                    'context' => $row['context'],
                    'similarity' => $similarity,
                ];
            }
        }

        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($similarities, 0, $limit);
    }

    /**
     * Keep only the N most recent episodic memories, delete the rest.
     */
    public function pruneOldEpisodicMemories(int $keepLatest = 100): bool {
        if (!$this->db || $keepLatest <= 0) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('
                DELETE FROM episodic_memory
                WHERE id NOT IN (
                    SELECT id FROM episodic_memory
                    ORDER BY created_at DESC, id DESC
                    LIMIT :keep
                )
            ');
            $stmt->bindValue(':keep', $keepLatest, SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore pruneOldEpisodicMemories error: ' . $e->getMessage());
            return false;
        }
    }

    private function cosineSimilarity(array $a, array $b): float {
        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA == 0 || $normB == 0) {
            return 0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    public function clearMemory(string $memoryType): bool {
        if (!$this->db) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('DELETE FROM memory_vectors WHERE memory_type = :memory_type');
            $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear vectors for a specific file only (incremental update).
     */
    public function clearFileVectors(string $sourceFile, string $memoryType): bool {
        if (!$this->db) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'DELETE FROM memory_vectors WHERE source_file = :source_file AND memory_type = :memory_type'
            );
            $stmt->bindValue(':source_file', $sourceFile, SQLITE3_TEXT);
            $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore clearFileVectors error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a file from the loaded_files tracking table.
     * Tries both normalized and original path for compatibility.
     */
    public function unmarkFileLoaded(string $filePath): bool {
        if (!$this->db) {
            return false;
        }

        $pathsToTry = array_unique([$this->normalizePath($filePath), $filePath]);
        foreach ($pathsToTry as $path) {
            if ($path === '') {
                continue;
            }
            try {
                $stmt = $this->db->prepare('DELETE FROM loaded_files WHERE file_path = :file_path');
                $stmt->bindValue(':file_path', $path, SQLITE3_TEXT);
                $stmt->execute();
            } catch (\Exception $e) {
                error_log('VectorStore unmarkFileLoaded error: ' . $e->getMessage());
            }
        }
        return true;
    }

    public function pruneMemoryType(string $memoryType, int $keepLatest = 60): bool {
        if (!$this->db) {
            return false;
        }

        if ($keepLatest <= 0) {
            return $this->clearMemory($memoryType);
        }

        try {
            $stmt = $this->db->prepare('
                DELETE FROM memory_vectors
                WHERE memory_type = :memory_type
                  AND id NOT IN (
                    SELECT id
                    FROM memory_vectors
                    WHERE memory_type = :memory_type_inner
                    ORDER BY created_at DESC, id DESC
                    LIMIT :keep_latest
                  )
            ');
            $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            $stmt->bindValue(':memory_type_inner', $memoryType, SQLITE3_TEXT);
            $stmt->bindValue(':keep_latest', $keepLatest, SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore prune error: ' . $e->getMessage());
            return false;
        }
    }

    private function normalizePath(string $filePath): string {
        $real = realpath($filePath);
        return ($real !== false) ? $real : $filePath;
    }

    public function isFileLoaded(string $filePath, string $fileHash): bool {
        if (!$this->db) {
            return false;
        }

        $pathsToTry = array_unique([$this->normalizePath($filePath), $filePath]);
        foreach ($pathsToTry as $path) {
            if ($path === '') {
                continue;
            }
            try {
                $stmt = $this->db->prepare('SELECT file_hash FROM loaded_files WHERE file_path = :file_path');
                $stmt->bindValue(':file_path', $path, SQLITE3_TEXT);
                $result = $stmt->execute();
                $row = $result->fetchArray(SQLITE3_ASSOC);
                if ($row && $row['file_hash'] === $fileHash) {
                    return true;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        return false;
    }

    public function markFileLoaded(string $filePath, string $fileHash): bool {
        if (!$this->db) {
            return false;
        }

        $path = $this->normalizePath($filePath);
        try {
            $stmt = $this->db->prepare('
                INSERT OR REPLACE INTO loaded_files (file_path, file_hash, loaded_at)
                VALUES (:file_path, :file_hash, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':file_path', $path, SQLITE3_TEXT);
            $stmt->bindValue(':file_hash', $fileHash, SQLITE3_TEXT);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore error: ' . $e->getMessage());
            return false;
        }
    }

    public function getFileHash(string $filePath): string {
        return hash_file('sha256', $filePath);
    }

    /**
     * Get the stored hash for a file from loaded_files table.
     * Returns null if the file is not in the database.
     */
    public function getStoredFileHash(string $filePath): ?string {
        if (!$this->db) {
            return null;
        }

        $pathsToTry = array_unique([$this->normalizePath($filePath), $filePath]);
        foreach ($pathsToTry as $path) {
            if ($path === '') {
                continue;
            }
            try {
                $stmt = $this->db->prepare('SELECT file_hash FROM loaded_files WHERE file_path = :file_path');
                $stmt->bindValue(':file_path', $path, SQLITE3_TEXT);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($row) {
                    return $row['file_hash'];
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        return null;
    }

    /**
     * Get the highest chunk index already stored for a file.
     * Used for resume functionality in partial imports.
     * 
     * @param string $sourceFile The source filename
     * @param string $memoryType The memory type ('reference' or 'identity')
     * @return int The highest chunk index (-1 if no chunks exist)
     */
    public function getHighestChunkIndex(string $sourceFile, string $memoryType): int {
        if (!$this->db) {
            return -1;
        }

        try {
            $stmt = $this->db->prepare('
                SELECT MAX(chunk_index) as max_index 
                FROM memory_vectors 
                WHERE source_file = :source_file AND memory_type = :memory_type
            ');
            $stmt->bindValue(':source_file', $sourceFile, SQLITE3_TEXT);
            $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            return $row['max_index'] ?? -1;
        } catch (\Exception $e) {
            error_log('VectorStore getHighestChunkIndex error: ' . $e->getMessage());
            return -1;
        }
    }

    public function getStats(): array {
        $stats = [
            'identity_vectors' => 0,
            'reference_vectors' => 0,
            'episodic_memories' => 0,
        ];

        if (!$this->db) {
            return $stats;
        }

        try {
            $result = $this->db->query('
                SELECT memory_type, COUNT(*) as count 
                FROM memory_vectors 
                GROUP BY memory_type
            ');

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $stats[$row['memory_type'] . '_vectors'] = $row['count'];
            }

            $result = $this->db->query('SELECT COUNT(*) as count FROM episodic_memory');
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $stats['episodic_memories'] = $row['count'];
        } catch (\Exception $e) {
            error_log('VectorStore stats error: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Batch-generate embeddings for multiple texts in one API call.
     * Far more efficient than calling generateEmbedding() in a loop.
     *
     * @param string[] $texts
     * @return array[]|WP_Error Array of embedding vectors (same order as input)
     */
    public function generateEmbeddingBatch(array $texts): array|WP_Error {
        if (empty($texts)) {
            return [];
        }

        $cache = new EmbeddingCache();
        $results = array_fill(0, count($texts), null);
        $uncached = [];

        foreach ($texts as $i => $text) {
            $cached = $cache->get($text);
            if ($cached !== null) {
                $results[$i] = $cached;
            } else {
                $uncached[$i] = substr($text, 0, 8000);
            }
        }

        if (empty($uncached)) {
            return $results;
        }

        $config = $this->getEmbeddingRequestConfig();
        if (is_wp_error($config)) {
            return $config;
        }

        $batchTexts = array_values($uncached);
        $batchIndices = array_keys($uncached);

        $response = wp_remote_post($config['endpoint'], [
            'headers' => $config['headers'],
            'body' => json_encode([
                'input' => $batchTexts,
                'model' => $config['model'],
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['error']['message'] ?? ('Batch embedding failed (' . $config['provider'] . ')');
            return new WP_Error('embedding_batch_failed', $error);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $data = $body['data'] ?? [];

        foreach ($data as $item) {
            $idx = $item['index'] ?? null;
            $embedding = $item['embedding'] ?? [];
            if ($idx !== null && isset($batchIndices[$idx]) && !empty($embedding)) {
                $originalIdx = $batchIndices[$idx];
                $results[$originalIdx] = $embedding;
                $cache->set($texts[$originalIdx], $embedding);
            }
        }

        $missing = array_filter($results, fn($r) => $r === null);
        if (!empty($missing)) {
            return new WP_Error('embedding_batch_incomplete', count($missing) . ' embeddings missing from batch response');
        }

        return $results;
    }

    private function getEmbeddingRequestConfig(): array|WP_Error {
        $settings = new \Levi\Agent\Admin\SettingsPage();
        $provider = $settings->getProvider();
        $apiKey = $settings->getApiKeyForProvider($provider);

        if (!$apiKey) {
            return new WP_Error('no_api_key', sprintf('Kein API-Key für den ausgewählten Provider (%s) hinterlegt.', $provider));
        }

        if ($provider === 'anthropic') {
            return new WP_Error(
                'embedding_provider_unsupported',
                'Embeddings werden aktuell für OpenRouter und OpenAI unterstützt. Bitte Provider auf OpenRouter/OpenAI stellen oder Embeddings separat konfigurieren.'
            );
        }

        $endpoint = $provider === 'openrouter'
            ? 'https://openrouter.ai/api/v1/embeddings'
            : 'https://api.openai.com/v1/embeddings';
        $model = $provider === 'openrouter'
            ? 'openai/text-embedding-3-small'
            : $this->embeddingModel;

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];
        if ($provider === 'openrouter') {
            $headers['HTTP-Referer'] = home_url('/');
            $headers['X-Title'] = 'Levi AI Agent';
        }

        return [
            'provider' => $provider,
            'endpoint' => $endpoint,
            'model' => $model,
            'headers' => $headers,
        ];
    }

    /**
     * Expose the underlying SQLite3 handle for BM25Index (shares the same DB).
     */
    public function getDatabase(): ?\SQLite3
    {
        return $this->db;
    }

    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}
