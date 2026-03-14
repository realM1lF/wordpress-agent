<?php

namespace Levi\Agent\Memory;

use WP_Error;

class MemoryLoader {
    private VectorStore $vectorStore;
    private ChunkContextualizer $contextualizer;
    private int $chunkSize = 500;

    /**
     * Bump this when the chunking algorithm changes to force re-indexing
     * of all files on the next sync (cron or manual).
     */
    private const CHUNK_VERSION = 'v3-ctx-r3';

    public function __construct() {
        $this->vectorStore = new VectorStore();
        $this->contextualizer = new ChunkContextualizer();
    }

    /**
     * Load all memory files (identity + memories).
     * Legacy method - prefer reloadChangedFiles() for incremental updates.
     */
    public function loadAllMemories(): array {
        $result = [
            'identity' => $this->loadIdentityFiles(),
            'reference' => $this->loadReferenceMemories(),
            'errors' => [],
        ];
        $this->rebuildBM25Index();
        return $result;
    }

    /**
     * Incremental reload: only re-embed files that have actually changed.
     * Safe against timeouts - already-processed files survive a crash.
     * Also re-indexes tool definitions when identity files change (tool descriptions may have been updated).
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

            $fileHashChanged = $this->hasFileContentChanged($path, $filename, 'identity');
            $versionChanged = $this->hasChunkVersionChanged($path);

            if ($fileHashChanged || $versionChanged) {
                $this->vectorStore->clearFileVectors($filename, 'identity');
                $this->vectorStore->unmarkFileLoaded($path);

                if ($versionChanged && !$fileHashChanged) {
                    $bm25 = new BM25Index($this->vectorStore->getDatabase());
                    $bm25->clearFileEntries($filename, 'identity');
                }
            }

            $result = $this->processFile($path, 'identity', $filename);
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

            $fileHashChanged = $this->hasFileContentChanged($path, $filename, 'reference');
            $versionChanged = $this->hasChunkVersionChanged($path);

            if ($fileHashChanged || $versionChanged) {
                $this->vectorStore->clearFileVectors($filename, 'reference');
                $this->vectorStore->unmarkFileLoaded($path);

                if ($versionChanged && !$fileHashChanged) {
                    $bm25 = new BM25Index($this->vectorStore->getDatabase());
                    $bm25->clearFileEntries($filename, 'reference');
                }
            }

            $result = $this->processFile($path, 'reference', $filename);
            if (is_wp_error($result)) {
                $errors[] = $filename . ': ' . $result->get_error_message();
            } else {
                $loaded[$filename] = $result;
            }
        }

        $toolIndexResult = $this->indexToolDefinitions();
        if (!empty($toolIndexResult['errors'])) {
            $errors = array_merge($errors, $toolIndexResult['errors']);
        }

        // Rebuild BM25 keyword index for hybrid search
        $bm25Indexed = $this->rebuildBM25Index();

        return [
            'changed_identity' => $changes['identity'],
            'changed_reference' => $changes['reference'],
            'loaded' => $loaded,
            'tool_definitions' => $toolIndexResult,
            'bm25_indexed' => $bm25Indexed,
            'errors' => $errors,
        ];
    }

    /**
     * Index all tool definitions in the vector DB for semantic search.
     * Uses batch embedding (single API call) for efficiency.
     * Only re-indexes when tool definitions have changed (hash-based check).
     */
    public function indexToolDefinitions(): array {
        if (!$this->vectorStore->isAvailable()) {
            return ['indexed' => 0, 'skipped' => true, 'reason' => 'VectorStore not available'];
        }

        try {
            $registry = new \Levi\Agent\AI\Tools\Registry('full');
            $tools = $registry->getAll();
        } catch (\Throwable $e) {
            return ['indexed' => 0, 'errors' => ['Registry init failed: ' . $e->getMessage()]];
        }

        $texts = [];
        $toolNames = [];
        foreach ($tools as $tool) {
            $name = $tool->getName();
            if ($name === 'search_tools') {
                continue;
            }
            $corpus = $name . ': ' . $tool->getDescription();
            foreach ($tool->getParameters() as $paramName => $config) {
                $corpus .= ' | ' . $paramName;
                if (!empty($config['description'])) {
                    $corpus .= ': ' . $config['description'];
                }
            }
            $texts[] = $corpus;
            $toolNames[] = $name;
        }

        if (empty($texts)) {
            return ['indexed' => 0, 'reason' => 'No tools to index'];
        }

        $currentHash = md5(implode('|', $texts));
        $storedHash = get_transient('levi_tool_definitions_hash');
        if ($storedHash === $currentHash) {
            return ['indexed' => count($texts), 'skipped' => true, 'reason' => 'Tool definitions unchanged'];
        }

        $this->vectorStore->clearMemory('tool_definition');

        $embeddings = $this->vectorStore->generateEmbeddingBatch($texts);
        if (is_wp_error($embeddings)) {
            return ['indexed' => 0, 'errors' => ['Batch embedding failed: ' . $embeddings->get_error_message()]];
        }

        $vectors = [];
        foreach ($texts as $i => $text) {
            if (!isset($embeddings[$i]) || empty($embeddings[$i])) {
                continue;
            }
            $vectors[] = [
                'content' => $text,
                'embedding' => $embeddings[$i],
                'memory_type' => 'tool_definition',
                'source_file' => $toolNames[$i],
                'chunk_index' => 0,
            ];
        }

        $inserted = $this->vectorStore->bulkInsertVectors($vectors);
        set_transient('levi_tool_definitions_hash', $currentHash, WEEK_IN_SECONDS);

        return ['indexed' => $inserted, 'total' => count($texts)];
    }

    /**
     * Build/update the BM25 keyword index for all vectors that don't have entries yet.
     */
    public function rebuildBM25Index(): int
    {
        $db = $this->vectorStore->getDatabase();
        if ($db === null) {
            return 0;
        }

        try {
            $bm25 = new BM25Index($db);
            $indexed = $bm25->rebuildFromVectors();
            if ($indexed > 0) {
                error_log("MemoryLoader: BM25 indexed {$indexed} new chunks");
            }
            return $indexed;
        } catch (\Throwable $e) {
            error_log('MemoryLoader BM25 rebuild error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Load identity files (soul.md, rules.md, knowledge.md)
     */
    public function loadIdentityFiles(): array {
        $identityDir = LEVI_AGENT_PLUGIN_DIR . 'identity/';
        $files = $this->getAllIdentityFiles();
        
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

            $result = $this->processFile($path, 'identity', $file);
            
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

            $result = $this->processFile($filePath, 'reference', $filename);
            
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
     * Check if the actual file content (sha256) changed compared to what was (partially) loaded.
     * Returns true if the content hash differs, meaning the file was modified
     * and a full re-index is needed (not just a resume of a partial sync).
     */
    private function hasFileContentChanged(string $path, string $filename, string $memoryType): bool {
        $storedHash = $this->vectorStore->getStoredFileHash($path);
        if ($storedHash === null) {
            return true;
        }

        $currentContentHash = $this->vectorStore->getFileHash($path);

        // Stored hash format: <sha256>:<chunk_version>[_partial_X_Y]
        $storedContentHash = explode(':', $storedHash)[0] ?? '';

        return $storedContentHash !== $currentContentHash;
    }

    /**
     * Check if the stored CHUNK_VERSION differs from the current one.
     * Returns true when the chunking/contextualization algorithm changed,
     * requiring a full re-index even though the file content is identical.
     */
    private function hasChunkVersionChanged(string $path): bool {
        $storedHash = $this->vectorStore->getStoredFileHash($path);
        if ($storedHash === null) {
            return false;
        }

        $parts = explode(':', $storedHash, 2);
        $storedVersion = $parts[1] ?? '';
        // Strip partial suffix: "v3-ctx-r2_partial_10_100" → "v3-ctx-r2"
        $storedVersion = explode('_partial_', $storedVersion)[0];

        return $storedVersion !== self::CHUNK_VERSION;
    }

    /**
     * Process a single file with batch processing, resume support, and
     * Contextual Retrieval (LLM-generated chunk context at index time).
     */
    private function processFile(string $filePath, string $memoryType, ?string $sourceFileOverride = null): array|WP_Error {
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            return new WP_Error('read_error', 'Could not read file');
        }

        $chunks = $this->splitIntoChunks($content);
        $totalChunks = count($chunks);
        
        $sourceFile = $sourceFileOverride ?? basename($filePath);
        $highestStoredIndex = $this->vectorStore->getHighestChunkIndex($sourceFile, $memoryType);
        $resumeFromIndex = $highestStoredIndex + 1;
        
        if ($highestStoredIndex >= 0) {
            error_log("MemoryLoader: Resuming $sourceFile from chunk $resumeFromIndex (already have 0-$highestStoredIndex)");
        }

        // Only contextualize chunks that actually need indexing (skip already-stored ones).
        // This avoids wasting LLM calls on chunks that will be skipped during resume.
        $chunksToProcess = [];
        $skippedChunks = 0;
        foreach ($chunks as $index => $chunk) {
            if ($index <= $highestStoredIndex) {
                $skippedChunks++;
            } else {
                $chunksToProcess[$index] = $chunk;
            }
        }

        $contextualizedChunks = !empty($chunksToProcess)
            ? $this->contextualizeChunks($content, $chunksToProcess)
            : [];

        $vectorsCreated = 0;
        $batch = [];
        $batchSize = 10;

        foreach ($chunksToProcess as $index => $originalChunk) {
            $embeddingText = $contextualizedChunks[$index] ?? $originalChunk;
            $embedding = $this->vectorStore->generateEmbedding($embeddingText);
            
            if (is_wp_error($embedding)) {
                return $embedding;
            }

            if (empty($embedding)) {
                continue;
            }

            $hasContext = ($embeddingText !== $originalChunk);
            $batch[] = [
                'content' => $embeddingText,
                'embedding' => $embedding,
                'memory_type' => $memoryType,
                'source_file' => $sourceFile,
                'chunk_index' => $index,
                'original_content' => $hasContext ? $originalChunk : null,
            ];

            if (count($batch) >= $batchSize) {
                $inserted = $this->vectorStore->bulkInsertVectors($batch);
                $vectorsCreated += $inserted;
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $inserted = $this->vectorStore->bulkInsertVectors($batch);
            $vectorsCreated += $inserted;
        }

        $totalVectorsNow = $highestStoredIndex + 1 + $vectorsCreated;
        $isComplete = ($totalVectorsNow >= $totalChunks);
        
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
     * Contextual Retrieval: enrich chunks with LLM-generated descriptions.
     *
     * For large documents, sends the surrounding section (not the whole doc)
     * as context to stay within token limits. Processes in batches of 5.
     *
     * @return array<int, string> Contextualized chunks keyed by original index
     */
    private function contextualizeChunks(string $fullContent, array $chunks): array
    {
        if (empty($chunks) || count($chunks) < 2) {
            return $chunks;
        }

        $sections = $this->parseMarkdownSections($fullContent);
        $sectionTexts = array_map(
            fn($s) => ($s['header_path'] !== '' ? "[{$s['header_path']}]\n\n" : '') . $s['content'],
            $sections
        );
        $fullSectionText = implode("\n\n", $sectionTexts);

        // For each chunk, find the best surrounding context from the document.
        // Strategy: use a sliding window over sections to build context per chunk.
        $contextualized = [];
        $contextBatchSize = 5;
        $pendingBatch = [];
        $pendingIndices = [];

        foreach ($chunks as $index => $chunk) {
            $sectionContext = $this->findSurroundingContext($chunk, $fullSectionText, $sections);
            $pendingBatch[] = ['context' => $sectionContext, 'chunk' => $chunk];
            $pendingIndices[] = $index;

            if (count($pendingBatch) >= $contextBatchSize) {
                $this->processContextBatch($pendingBatch, $pendingIndices, $contextualized);
                $pendingBatch = [];
                $pendingIndices = [];
            }
        }

        if (!empty($pendingBatch)) {
            $this->processContextBatch($pendingBatch, $pendingIndices, $contextualized);
        }

        return $contextualized;
    }

    private function processContextBatch(array $batch, array $indices, array &$contextualized): void
    {
        foreach ($batch as $i => $item) {
            $result = $this->contextualizer->contextualizeBatch(
                $item['context'],
                [$item['chunk']]
            );
            $contextualized[$indices[$i]] = $result['contextualized'][0] ?? $item['chunk'];
        }
    }

    /**
     * Find the most relevant section context for a given chunk.
     * Returns 2-3 surrounding sections (~6000 words max).
     */
    private function findSurroundingContext(string $chunk, string $fullText, array $sections): string
    {
        // Quick: find which section(s) contain parts of this chunk
        $chunkStart = mb_substr($chunk, 0, 100);
        $bestPos = mb_strpos($fullText, $chunkStart);

        if ($bestPos === false) {
            // Fallback: use first 6000 words of doc
            $words = explode(' ', $fullText);
            return implode(' ', array_slice($words, 0, 6000));
        }

        // Build context: gather sections around the match position
        $contextParts = [];
        $contextWords = 0;
        $maxContextWords = 6000;
        $currentPos = 0;
        $collecting = false;

        foreach ($sections as $section) {
            $sectionText = ($section['header_path'] !== '' ? "[{$section['header_path']}]\n\n" : '') . $section['content'];
            $sectionLen = mb_strlen($sectionText);
            $sectionEnd = $currentPos + $sectionLen + 2;

            $isNearChunk = ($bestPos >= $currentPos - 3000 && $bestPos <= $sectionEnd + 3000);
            if ($isNearChunk) {
                $collecting = true;
            }

            if ($collecting) {
                $words = str_word_count($sectionText);
                if ($contextWords + $words > $maxContextWords && $contextWords > 0) {
                    break;
                }
                $contextParts[] = $sectionText;
                $contextWords += $words;
            }

            $currentPos = $sectionEnd;
        }

        if (empty($contextParts)) {
            $words = explode(' ', $fullText);
            return implode(' ', array_slice($words, 0, $maxContextWords));
        }

        return implode("\n\n", $contextParts);
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
        foreach ($this->getAllIdentityFiles() as $file) {
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
     * Get the full list of identity file paths (relative to identity/).
     * Includes both the base files and any modular rule files.
     */
    private function getAllIdentityFiles(): array {
        $identityDir = LEVI_AGENT_PLUGIN_DIR . 'identity/';
        $files = ['soul.md', 'rules.md', 'knowledge.md'];

        $rulesDir = $identityDir . 'rules/';
        if (is_dir($rulesDir)) {
            foreach (glob($rulesDir . '*.md') as $path) {
                $files[] = 'rules/' . basename($path);
            }
        }

        return $files;
    }

    /**
     * Get list of existing identity file names (for stats display).
     */
    private function getIdentityFileNames(): array {
        $identityDir = LEVI_AGENT_PLUGIN_DIR . 'identity/';
        $found = [];
        foreach ($this->getAllIdentityFiles() as $file) {
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
