<?php

namespace Levi\Agent\Memory;

use WP_Error;

class MemoryLoader {
    private VectorStore $vectorStore;
    private int $chunkSize = 500;

    /**
     * Bump this when the chunking algorithm changes to force re-indexing
     * of all files on the next sync (cron or manual).
     */
    private const CHUNK_VERSION = 'v2-markdown';

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
        
        // Mark file as loaded (include CHUNK_VERSION so algorithm changes trigger re-index)
        $fileHash = $this->vectorStore->getFileHash($filePath) . ':' . self::CHUNK_VERSION;
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
     * Split text into chunks using markdown-aware splitting.
     *
     * Strategy:
     * 1. Parse markdown structure (headers, code blocks)
     * 2. Each section = header + content until next same/higher-level header
     * 3. Prepend header hierarchy path to each chunk for semantic context
     * 4. Pack small consecutive sections into one chunk
     * 5. Sub-split oversized sections at paragraph/code-block boundaries
     *    (code blocks are never split mid-block)
     */
    private function splitIntoChunks(string $text): array {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $sections = $this->parseMarkdownSections($text);

        if (empty($sections)) {
            $trimmed = trim($text);
            return $trimmed !== '' ? [$trimmed] : [];
        }

        return $this->packSectionsIntoChunks($sections);
    }

    /**
     * Parse markdown text into sections delimited by headers.
     * Correctly handles code blocks (# inside ``` is not a header).
     *
     * @return array<array{header_path: string, content: string}>
     */
    private function parseMarkdownSections(string $text): array {
        $lines = explode("\n", $text);
        $sections = [];
        $headerStack = [];
        $currentContent = '';
        $inCodeBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                $inCodeBlock = !$inCodeBlock;
                $currentContent .= $line . "\n";
                continue;
            }

            if ($inCodeBlock) {
                $currentContent .= $line . "\n";
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                $trimmed = trim($currentContent);
                if ($trimmed !== '') {
                    $sections[] = [
                        'header_path' => $this->buildHeaderPath($headerStack),
                        'content' => $trimmed,
                    ];
                }

                $level = strlen($matches[1]);
                $title = trim($matches[2]);

                $headerStack = array_values(array_filter(
                    $headerStack,
                    fn($h) => $h['level'] < $level
                ));
                $headerStack[] = ['level' => $level, 'title' => $title];

                $currentContent = '';
            } else {
                $currentContent .= $line . "\n";
            }
        }

        $trimmed = trim($currentContent);
        if ($trimmed !== '') {
            $sections[] = [
                'header_path' => $this->buildHeaderPath($headerStack),
                'content' => $trimmed,
            ];
        }

        return $sections;
    }

    private function buildHeaderPath(array $headerStack): string {
        if (empty($headerStack)) {
            return '';
        }
        return implode(' > ', array_map(fn($h) => $h['title'], $headerStack));
    }

    /**
     * Greedily pack sections into chunks up to chunkSize words.
     * Sections that exceed chunkSize on their own are sub-split.
     */
    private function packSectionsIntoChunks(array $sections): array {
        $chunks = [];
        $currentParts = [];
        $currentWords = 0;

        foreach ($sections as $section) {
            $formatted = $this->formatSectionText($section);
            $words = str_word_count($formatted);

            if ($words === 0) {
                continue;
            }

            if ($words > $this->chunkSize && empty($currentParts)) {
                $subChunks = $this->splitLargeSection($section['content'], $section['header_path']);
                array_push($chunks, ...$subChunks);
                continue;
            }

            if ($currentWords + $words > $this->chunkSize && !empty($currentParts)) {
                $chunks[] = implode("\n\n", $currentParts);

                if ($words > $this->chunkSize) {
                    $subChunks = $this->splitLargeSection($section['content'], $section['header_path']);
                    array_push($chunks, ...$subChunks);
                    $currentParts = [];
                    $currentWords = 0;
                    continue;
                }

                $currentParts = [];
                $currentWords = 0;
            }

            $currentParts[] = $formatted;
            $currentWords += $words;
        }

        if (!empty($currentParts)) {
            $chunks[] = implode("\n\n", $currentParts);
        }

        return array_filter($chunks, fn($c) => trim($c) !== '');
    }

    private function formatSectionText(array $section): string {
        $content = trim($section['content']);
        if ($content === '') {
            return '';
        }
        $path = $section['header_path'];
        return $path !== '' ? "[{$path}]\n\n{$content}" : $content;
    }

    /**
     * Sub-split a section that exceeds chunkSize at paragraph / code-block
     * boundaries. Code blocks are never split mid-block.
     */
    private function splitLargeSection(string $content, string $headerPath): array {
        $prefix = $headerPath !== '' ? "[{$headerPath}]\n\n" : '';
        $prefixWords = str_word_count($prefix);

        $segments = $this->parseContentSegments($content);

        $chunks = [];
        $currentText = $prefix;
        $currentWords = $prefixWords;

        foreach ($segments as $segment) {
            $segWords = str_word_count($segment);

            if ($segWords > $this->chunkSize && $currentWords <= $prefixWords) {
                $chunks[] = trim($prefix . $segment);
                $currentText = $prefix;
                $currentWords = $prefixWords;
                continue;
            }

            if ($currentWords + $segWords > $this->chunkSize && $currentWords > $prefixWords) {
                $chunks[] = trim($currentText);
                $currentText = $prefix;
                $currentWords = $prefixWords;
            }

            $currentText .= $segment . "\n\n";
            $currentWords += $segWords;
        }

        if ($currentWords > $prefixWords) {
            $chunks[] = trim($currentText);
        }

        return array_filter($chunks, fn($c) => trim($c) !== '');
    }

    /**
     * Parse section content into atomic segments: text paragraphs and
     * complete fenced code blocks. Code blocks stay intact.
     *
     * @return string[]
     */
    private function parseContentSegments(string $content): array {
        $lines = explode("\n", $content);
        $segments = [];
        $currentText = '';
        $inCodeBlock = false;
        $codeBlock = '';

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                if (!$inCodeBlock) {
                    $this->flushTextAsSegments($currentText, $segments);
                    $currentText = '';
                    $inCodeBlock = true;
                    $codeBlock = $line . "\n";
                } else {
                    $codeBlock .= $line;
                    $segments[] = trim($codeBlock);
                    $codeBlock = '';
                    $inCodeBlock = false;
                }
            } elseif ($inCodeBlock) {
                $codeBlock .= $line . "\n";
            } else {
                $currentText .= $line . "\n";
            }
        }

        $this->flushTextAsSegments($currentText, $segments);

        if ($codeBlock !== '') {
            $segments[] = trim($codeBlock);
        }

        return $segments;
    }

    /**
     * Split a block of plain text at double-newline paragraph boundaries
     * and append each non-empty paragraph to $segments.
     */
    private function flushTextAsSegments(string $text, array &$segments): void {
        $paragraphs = preg_split('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($paragraphs as $p) {
            $trimmed = trim($p);
            if ($trimmed !== '') {
                $segments[] = $trimmed;
            }
        }
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
                $hash = $this->vectorStore->getFileHash($path) . ':' . self::CHUNK_VERSION;
                if (!$this->vectorStore->isFileLoaded($path, $hash)) {
                    $changes['identity'][] = $file;
                }
            }
        }

        $referenceFiles = $this->getResolvedReferenceFiles();
        foreach ($referenceFiles as $filename => $path) {
            $hash = $this->vectorStore->getFileHash($path) . ':' . self::CHUNK_VERSION;
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
