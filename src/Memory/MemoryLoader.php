<?php

namespace Mohami\Agent\Memory;

use WP_Error;

class MemoryLoader {
    private VectorStore $vectorStore;
    private int $chunkSize = 500; // Words per chunk
    private int $chunkOverlap = 50; // Overlap between chunks

    public function __construct() {
        $this->vectorStore = new VectorStore();
    }

    /**
     * Load all memory files (identity + memories)
     */
    public function loadAllMemories(): array {
        $results = [
            'identity' => $this->loadIdentityFiles(),
            'reference' => $this->loadReferenceMemories(),
            'errors' => [],
        ];

        return $results;
    }

    /**
     * Load identity files (soul.md, rules.md, knowledge.md)
     */
    public function loadIdentityFiles(): array {
        $identityDir = MOHAMI_AGENT_PLUGIN_DIR . 'identity/';
        $files = ['soul.md', 'rules.md', 'knowledge.md'];
        
        $loaded = [];
        $errors = [];

        // Clear existing identity vectors
        $this->vectorStore->clearMemory('identity');

        foreach ($files as $file) {
            $path = $identityDir . $file;
            
            if (!file_exists($path)) {
                $errors[] = "File not found: $file";
                continue;
            }

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
     * Load reference memories from memories/ folder
     */
    public function loadReferenceMemories(): array {
        $memoriesDir = MOHAMI_AGENT_PLUGIN_DIR . 'memories/';
        
        if (!is_dir($memoriesDir)) {
            return ['loaded' => [], 'errors' => ['Memories directory does not exist']];
        }

        $files = array_merge(
            glob($memoriesDir . '*.md') ?: [],
            glob($memoriesDir . '*.txt') ?: []
        );
        $loaded = [];
        $errors = [];

        // Clear existing reference vectors
        $this->vectorStore->clearMemory('reference');

        foreach ($files as $file) {
            $filename = basename($file);
            
            // Skip README
            if ($filename === 'README.md') {
                continue;
            }

            $result = $this->processFile($file, 'reference');
            
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
     * Process a single file
     */
    private function processFile(string $filePath, string $memoryType): array|WP_Error {
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read file');
        }

        $chunks = $this->splitIntoChunks($content);
        $vectorsCreated = 0;

        foreach ($chunks as $index => $chunk) {
            // Generate embedding
            $embedding = $this->vectorStore->generateEmbedding($chunk);
            
            if (is_wp_error($embedding)) {
                return $embedding;
            }

            if (empty($embedding)) {
                continue;
            }

            // Store vector
            $stored = $this->vectorStore->storeVector(
                $chunk,
                $embedding,
                $memoryType,
                basename($filePath),
                $index
            );

            if ($stored) {
                $vectorsCreated++;
            }
        }

        // Mark file as loaded
        $fileHash = $this->vectorStore->getFileHash($filePath);
        $this->vectorStore->markFileLoaded($filePath, $fileHash);

        return [
            'chunks' => count($chunks),
            'vectors' => $vectorsCreated,
        ];
    }

    /**
     * Split text into chunks with overlap
     */
    private function splitIntoChunks(string $text): array {
        // Clean up text
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Split by paragraphs first
        $paragraphs = preg_split('/\n\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        $chunks = [];
        $currentChunk = '';
        $currentWordCount = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }

            $wordCount = str_word_count($paragraph);

            // If adding this paragraph would exceed chunk size, save current chunk
            if ($currentWordCount + $wordCount > $this->chunkSize && $currentWordCount > 0) {
                $chunks[] = trim($currentChunk);
                
                // Keep last sentences for overlap
                $sentences = preg_split('/(?<=[.!?])\s+/', $currentChunk, -1, PREG_SPLIT_NO_EMPTY);
                $overlapText = '';
                $overlapWords = 0;
                
                for ($i = count($sentences) - 1; $i >= 0; $i--) {
                    $sentenceWords = str_word_count($sentences[$i]);
                    if ($overlapWords + $sentenceWords <= $this->chunkOverlap) {
                        $overlapText = $sentences[$i] . ' ' . $overlapText;
                        $overlapWords += $sentenceWords;
                    } else {
                        break;
                    }
                }
                
                $currentChunk = trim($overlapText);
                $currentWordCount = $overlapWords;
            }

            $currentChunk .= "\n\n" . $paragraph;
            $currentWordCount += $wordCount;
        }

        // Don't forget the last chunk
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Check if any files have changed and need reloading
     */
    public function checkForChanges(): array {
        $changes = [
            'identity' => [],
            'reference' => [],
        ];

        // Check identity files
        $identityDir = MOHAMI_AGENT_PLUGIN_DIR . 'identity/';
        $files = ['soul.md', 'rules.md', 'knowledge.md'];
        
        foreach ($files as $file) {
            $path = $identityDir . $file;
            if (file_exists($path)) {
                $hash = $this->vectorStore->getFileHash($path);
                if (!$this->vectorStore->isFileLoaded($path, $hash)) {
                    $changes['identity'][] = $file;
                }
            }
        }

        // Check reference files
        $memoriesDir = MOHAMI_AGENT_PLUGIN_DIR . 'memories/';
        if (is_dir($memoriesDir)) {
            $files = array_merge(
                glob($memoriesDir . '*.md') ?: [],
                glob($memoriesDir . '*.txt') ?: []
            );
            foreach ($files as $file) {
                if (basename($file) === 'README.md') {
                    continue;
                }
                
                $hash = $this->vectorStore->getFileHash($file);
                if (!$this->vectorStore->isFileLoaded($file, $hash)) {
                    $changes['reference'][] = basename($file);
                }
            }
        }

        return $changes;
    }

    /**
     * Get statistics about loaded memories
     */
    public function getStats(): array {
        return $this->vectorStore->getStats();
    }
}
