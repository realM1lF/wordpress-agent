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

    private function init(): void {
        // Ensure data directory exists
        $dataDir = dirname($this->dbPath);
        if (!is_dir($dataDir)) {
            wp_mkdir_p($dataDir);
        }

        // Open database
        $this->db = new \SQLite3($this->dbPath);
        $this->db->enableExceptions(true);

        // Create tables if not exist
        $this->createTables();
    }

    private function createTables(): void {
        // Memory vectors table
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

        // Create index for memory type
        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_memory_type ON memory_vectors(memory_type)
        ');

        // Episodic memory table (learned facts)
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

        // Track which files are loaded
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS loaded_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path VARCHAR(500) UNIQUE NOT NULL,
                file_hash VARCHAR(64) NOT NULL,
                loaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    /**
     * Generate embedding via OpenAI API
     */
    public function generateEmbedding(string $text): array|WP_Error {
        $apiKey = $this->getOpenAIKey();
        
        if (!$apiKey) {
            return new WP_Error('no_api_key', 'OpenAI API key not configured');
        }

        // Trim text to token limit (approx 8000 chars for 2000 tokens)
        $text = substr($text, 0, 8000);

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'input' => $text,
                'model' => $this->embeddingModel,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['error']['message'] ?? 'Embedding generation failed';
            return new WP_Error('embedding_failed', $error);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'][0]['embedding'] ?? [];
    }

    /**
     * Store a memory vector
     */
    public function storeVector(
        string $content,
        array $embedding,
        string $memoryType,
        string $sourceFile = '',
        int $chunkIndex = 0
    ): bool {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO memory_vectors (source_file, content, embedding, memory_type, chunk_index)
                VALUES (:source_file, :content, :embedding, :memory_type, :chunk_index)
            ');

            $stmt->bindValue(':source_file', $sourceFile, SQLITE3_TEXT);
            $stmt->bindValue(':content', $content, SQLITE3_TEXT);
            $stmt->bindValue(':embedding', json_encode($embedding), SQLITE3_BLOB);
            $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
            $stmt->bindValue(':chunk_index', $chunkIndex, SQLITE3_INTEGER);

            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Store episodic memory (learned fact)
     */
    public function storeEpisodicMemory(
        string $fact,
        array $embedding,
        ?int $userId = null,
        string $context = ''
    ): bool {
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

    /**
     * Search similar vectors using cosine similarity
     */
    public function searchSimilar(
        array $queryEmbedding,
        string $memoryType = '',
        int $limit = 5,
        float $minSimilarity = 0.7
    ): array {
        // Get all vectors of the specified type
        $sql = 'SELECT id, content, embedding, memory_type, source_file FROM memory_vectors';
        $params = [];

        if ($memoryType) {
            $sql .= ' WHERE memory_type = :memory_type';
            $params[':memory_type'] = $memoryType;
        }

        $stmt = $this->db->prepare($sql);
        
        if ($memoryType) {
            $stmt->bindValue(':memory_type', $memoryType, SQLITE3_TEXT);
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
                    'content' => $row['content'],
                    'memory_type' => $row['memory_type'],
                    'source_file' => $row['source_file'],
                    'similarity' => $similarity,
                ];
            }
        }

        // Sort by similarity (highest first)
        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($similarities, 0, $limit);
    }

    /**
     * Search episodic memories for a user
     */
    public function searchEpisodicMemories(
        array $queryEmbedding,
        ?int $userId = null,
        int $limit = 3,
        float $minSimilarity = 0.75
    ): array {
        $sql = 'SELECT id, fact, context FROM episodic_memory';
        $params = [];

        if ($userId) {
            $sql .= ' WHERE user_id = :user_id';
        }

        $stmt = $this->db->prepare($sql);
        
        if ($userId) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }

        $result = $stmt->execute();

        $similarities = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Note: We need to get embedding for each row
            $embedStmt = $this->db->prepare('SELECT embedding FROM episodic_memory WHERE id = :id');
            $embedStmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $embedResult = $embedStmt->execute();
            $embedRow = $embedResult->fetchArray(SQLITE3_ASSOC);
            
            if (!$embedRow) continue;
            
            $vector = json_decode($embedRow['embedding'], true);
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
     * Calculate cosine similarity between two vectors
     */
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

    /**
     * Clear all memories of a type
     */
    public function clearMemory(string $memoryType): bool {
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
     * Check if file was already loaded (by hash)
     */
    public function isFileLoaded(string $filePath, string $fileHash): bool {
        $stmt = $this->db->prepare('SELECT file_hash FROM loaded_files WHERE file_path = :file_path');
        $stmt->bindValue(':file_path', $filePath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row && $row['file_hash'] === $fileHash;
    }

    /**
     * Mark file as loaded
     */
    public function markFileLoaded(string $filePath, string $fileHash): bool {
        try {
            $stmt = $this->db->prepare('
                INSERT OR REPLACE INTO loaded_files (file_path, file_hash, loaded_at)
                VALUES (:file_path, :file_hash, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':file_path', $filePath, SQLITE3_TEXT);
            $stmt->bindValue(':file_hash', $fileHash, SQLITE3_TEXT);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            error_log('VectorStore error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get file hash
     */
    public function getFileHash(string $filePath): string {
        return hash_file('sha256', $filePath);
    }

    /**
     * Get statistics
     */
    public function getStats(): array {
        $stats = [
            'identity_vectors' => 0,
            'reference_vectors' => 0,
            'episodic_memories' => 0,
        ];

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

        return $stats;
    }

    /**
     * Get OpenAI API key
     */
    private function getOpenAIKey(): ?string {
        // Try to get from settings (or .env for dev)
        $settings = new \Levi\Agent\Admin\SettingsPage();
        return $settings->getApiKey();
    }

    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}
