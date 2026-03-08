<?php

namespace Levi\Agent\Memory;

use WP_Error;

class MemoryLoader {
    private VectorStore $vectorStore;
    private int $chunkSize = 500;
    private int $chunkOverlap = 50;

    public function __construct() {
        $this->vectorStore = new VectorStore();
    }

    /**
     * Load all memory files (identity + memories).
     * Legacy method - prefer reloadChangedFiles() for incremental updates.
     */
    public function loadAllMemories(): array {
        return [
            'identity' => $this->loadIdentityFiles(),
            'reference' => $this->loadReferenceMemories(),
            'errors' => [],
        ];
    }

    /**
     * Incremental reload: only re-embed files that have actually changed.
     * Safe against timeouts - already-processed files survive a crash.
     */
    public function reloadChangedFiles(): array {
        $changes = $this->checkForChanges();
        $loaded = [];
        $errors = [];

        foreach ($changes['identity'] as $filename) {
            $path = LEVI_AGENT_PLUGIN_DIR . 'identity/' . $filename;
            if (!file_exists($path)) {
                $errors[] = "Identity file not found: $filename";
                continue;
            }
            $this->vectorStore->clearFileVectors($filename, 'identity');
            $this->vectorStore->unmarkFileLoaded($path);

            $result = $this->processFile($path, 'identity');
            if (is_wp_error($result)) {
                $errors[] = $filename . ': ' . $result->get_error_message();
            } else {
                $loaded[$filename] = $result;
            }
        }

        $referenceFiles = $this->getResolvedReferenceFiles();
        foreach ($changes['reference'] as $filename) {
            $path = $referenceFiles[$filename] ?? null;
            if (!$path || !file_exists($path)) {
                $errors[] = "Reference file not found: $filename";
                continue;
            }
            $this->vectorStore->clearFileVectors($filename, 'reference');
            $this->vectorStore->unmarkFileLoaded($path);

            $result = $this->processFile($path, 'reference');
            if (is_wp_error($result)) {
                $errors[] = $filename . ': ' . $result->get_error_message();
            } else {
                $loaded[$filename] = $result;
            }
        }

        return [
            'changed_identity' => $changes['identity'],
            'changed_reference' => $changes['reference'],
            'loaded' => $loaded,
            'errors' => $errors,
        ];
    }

    /**
     * Load identity files (soul.md, rules.md, knowledge.md)
     */
    public function loadIdentityFiles(): array {
        $identityDir = LEVI_AGENT_PLUGIN_DIR . 'identity/';
        $files = ['soul.md', 'rules.md', 'knowledge.md'];
        
        $loaded = [];
        $errors = [];

        foreach ($files as $file) {
            $path = $identityDir . $file;
            
            if (!file_exists($path)) {
                $errors[] = "File not found: $file";
                continue;
            }

            $this->vectorStore->clearFileVectors($file, 'identity');
            $this->vectorStore->unmarkFileLoaded($path);

            $result = $this->processFile($path, 'identity');
            
            if (is_wp_error($result)) {
                $errors[] = $file . ': ' . $result->get_error_message();
            } else {
                $loaded[$file] = $result;
            }
        }

        return [
            'loaded' => $loaded,
            'errors' => $errors,
        ];
    }

    /**
     * Load reference memories from memories/ folders (plugin dir + uploads dir).
     */
    public function loadReferenceMemories(): array {
        $files = $this->getResolvedReferenceFiles();
        
        if (empty($files)) {
            return ['loaded' => [], 'errors' => ['No reference memory directories found']];
        }

        $loaded = [];
        $errors = [];

        foreach ($files as $filename => $filePath) {
            $this->vectorStore->clearFileVectors($filename, 'reference');
            $this->vectorStore->unmarkFileLoaded($filePath);

            $result = $this->processFile($filePath, 'reference');
            
            if (is_wp_error($result)) {
                $errors[] = $filename . ': ' . $result->get_error_message();
            } else {
                $loaded[$filename] = $result;
            }
        }

        return [
            'loaded' => $loaded,
            'errors' => $errors,
        ];
    }

    /**
     * Get all reference files from both plugin dir and uploads dir.
     * Uploads dir takes precedence (newer docs fetched by DocsFetcher).
     * @return array<string, string> filename => absolute path
     */
    private function getResolvedReferenceFiles(): array {
        $files = [];

        foreach ($this->getReferenceDirectories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $found = array_merge(
                glob($dir . '*.md') ?: [],
                glob($dir . '*.txt') ?: []
            );
            foreach ($found as $path) {
                $name = basename($path);
                if ($name === 'README.md') {
                    continue;
                }
                // Later dirs (uploads) override earlier dirs (plugin)
                $files[$name] = $path;
            }
        }

        return $files;
    }

    /**
     * Both the bundled plugin memories and the fetched docs in uploads.
     * @return string[]
     */
    private function getReferenceDirectories(): array {
        $dirs = [];

        $pluginDir = LEVI_AGENT_PLUGIN_DIR . 'memories/';
        if (is_dir($pluginDir)) {
            $dirs[] = $pluginDir;
        }

        $uploadsDir = DocsFetcher::getDocsDirectory() . '/';
        if (is_dir($uploadsDir) && $uploadsDir !== $pluginDir) {
            $dirs[] = $uploadsDir;
        }

        return $dirs;
    }

    /**
     * Process a single file with batch processing and resume support
     */
    private function processFile(string $filePath, string $memoryType): array|WP_Error {
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read file');
        }

        $chunks = $this->splitIntoChunks($content);
        $totalChunks = count($chunks);
        
        // Check for resume: get highest already-stored chunk index
        $sourceFile = basename($filePath);
        $highestStoredIndex = $this->vectorStore->getHighestChunkIndex($sourceFile, $memoryType);
        $resumeFromIndex = $highestStoredIndex + 1;
        
        if ($highestStoredIndex >= 0) {
            error_log("MemoryLoader: Resuming $sourceFile from chunk $resumeFromIndex (already have 0-$highestStoredIndex)");
        }
        
        $vectorsCreated = 0;
        $skippedChunks = 0;
        $batch = [];
        $batchSize = 10;

        foreach ($chunks as $index => $chunk) {
            // Skip already-stored chunks (resume support)
            if ($index <= $highestStoredIndex) {
                $skippedChunks++;
                continue;
            }
            
            // Generate embedding (uses cache if available)
            $embedding = $this->vectorStore->generateEmbedding($chunk);
            
            if (is_wp_error($embedding)) {
                return $embedding;
            }

            if (empty($embedding)) {
                continue;
            }

            // Collect for batch insert
            $batch[] = [
                'content' => $chunk,
                'embedding' => $embedding,
                'memory_type' => $memoryType,
                'source_file' => $sourceFile,
                'chunk_index' => $index,
            ];

            // Bulk insert when batch is full
            if (count($batch) >= $batchSize) {
                $inserted = $this->vectorStore->bulkInsertVectors($batch);
                $vectorsCreated += $inserted;
                $batch = [];
            }
        }

        // Insert remaining batch
        if (!empty($batch)) {
            $inserted = $this->vectorStore->bulkInsertVectors($batch);
            $vectorsCreated += $inserted;
        }

        // Calculate total vectors now in DB
        $totalVectorsNow = $highestStoredIndex + 1 + $vectorsCreated;
        $isComplete = ($totalVectorsNow >= $totalChunks);
        
        // Mark file as loaded
        $fileHash = $this->vectorStore->getFileHash($filePath);
        if (!$isComplete) {
            $fileHash .= '_partial_' . $totalVectorsNow . '_' . $totalChunks;
        }
        
        $this->vectorStore->markFileLoaded($filePath, $fileHash);

        return [
            'chunks' => $totalChunks,
            'vectors' => $totalVectorsNow,
            'new_vectors' => $vectorsCreated,
            'skipped' => $skippedChunks,
            'complete' => $isComplete,
        ];
    }

    /**
     * Split text into chunks with overlap
     * 
     * Uses a robust approach that works with various line ending formats.
     * Splits by sentences first, then groups into word-count-based chunks.
     */
    private function splitIntoChunks(string $text): array {
        // Normalize line endings and clean up whitespace
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        // Split text into sentences (handles various sentence endings)
        // This regex splits on . ! ? followed by space or newline, but keeps the punctuation
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        $chunks = [];
        $currentChunk = '';
        $currentWordCount = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) {
                continue;
            }

            $wordCount = str_word_count($sentence);

            // If adding this sentence would exceed chunk size, save current chunk
            if ($currentWordCount + $wordCount > $this->chunkSize && $currentWordCount > 0) {
                $chunks[] = trim($currentChunk);
                
                // Keep last sentences for overlap (up to chunkOverlap words)
                $currentChunkSentences = preg_split('/(?<=[.!?])\s+/', $currentChunk, -1, PREG_SPLIT_NO_EMPTY);
                $overlapText = '';
                $overlapWords = 0;
                
                for ($i = count($currentChunkSentences) - 1; $i >= 0; $i--) {
                    $sentenceWords = str_word_count($currentChunkSentences[$i]);
                    if ($overlapWords + $sentenceWords <= $this->chunkOverlap) {
                        $overlapText = $currentChunkSentences[$i] . ' ' . $overlapText;
                        $overlapWords += $sentenceWords;
                    } else {
                        break;
                    }
                }
                
                $currentChunk = trim($overlapText);
                $currentWordCount = $overlapWords;
            }

            $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
            $currentWordCount += $wordCount;
        }

        // Don't forget the last chunk
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Check if any files have changed and need reloading.
     * Checks identity files and reference files from both plugin and uploads dirs.
     */
    public function checkForChanges(): array {
        $changes = [
            'identity' => [],
            'reference' => [],
        ];

        $identityDir = LEVI_AGENT_PLUGIN_DIR . 'identity/';
        foreach (['soul.md', 'rules.md', 'knowledge.md'] as $file) {
            $path = $identityDir . $file;
            if (file_exists($path)) {
                $hash = $this->vectorStore->getFileHash($path);
                if (!$this->vectorStore->isFileLoaded($path, $hash)) {
                    $changes['identity'][] = $file;
                }
            }
        }

        $referenceFiles = $this->getResolvedReferenceFiles();
        foreach ($referenceFiles as $filename => $path) {
            $hash = $this->vectorStore->getFileHash($path);
            if (!$this->vectorStore->isFileLoaded($path, $hash)) {
                $changes['reference'][] = $filename;
            }
        }

        return $changes;
    }

    /**
     * Get statistics about loaded memories (file counts + episodic from DB)
     */
    public function getStats(): array {
        $vectorStats = $this->vectorStore->getStats();
        $identityFiles = $this->getIdentityFileNames();
        $referenceFiles = $this->getReferenceFileNames();

        return [
            'identity_files' => count($identityFiles),
            'identity_file_names' => $identityFiles,
            'reference_files' => count($referenceFiles),
            'reference_file_names' => $referenceFiles,
            'episodic_memories' => $vectorStats['episodic_memories'] ?? 0,
        ];
    }

    /**
     * Get list of existing identity file names (soul.md, rules.md, knowledge.md)
     */
    private function getIdentityFileNames(): array {
        $identityDir = LEVI_AGENT_PLUGIN_DIR . 'identity/';
        $candidates = ['soul.md', 'rules.md', 'knowledge.md'];
        $found = [];
        foreach ($candidates as $file) {
            if (file_exists($identityDir . $file)) {
                $found[] = $file;
            }
        }
        return $found;
    }

    /**
     * Get list of reference file names from all memories directories.
     */
    private function getReferenceFileNames(): array {
        $names = array_keys($this->getResolvedReferenceFiles());
        sort($names);
        return $names;
    }
}
