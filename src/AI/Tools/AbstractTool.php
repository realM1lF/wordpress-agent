<?php

namespace Levi\Agent\AI\Tools;

abstract class AbstractTool implements ToolInterface {

    abstract public function getName(): string;

    abstract public function getDescription(): string;

    abstract public function getParameters(): array;

    abstract public function execute(array $params): array;

    abstract public function checkPermission(): bool;

    /**
     * Optional input examples for the AI (Anthropic best practice).
     * Each example is an associative array matching the tool's parameters.
     * Registry appends these to the description automatically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInputExamples(): array {
        return [];
    }

    protected function getFilesystem(): ?\WP_Filesystem_Base {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem()) {
            return null;
        }

        global $wp_filesystem;
        if (!($wp_filesystem instanceof \WP_Filesystem_Base)) {
            return null;
        }

        return $wp_filesystem;
    }

    protected function normalizeSlug(string $slug): string {
        return strtolower(str_replace(['-', '_'], '', $slug));
    }

    /**
     * Safely write content with automatic backup and rollback on validation failure.
     *
     * @return array{success: bool, previous_content: ?string, error?: string}
     */
    protected function safeWrite(
        \WP_Filesystem_Base $filesystem,
        string $targetPath,
        string $content
    ): array {
        $hadExisting = $filesystem->exists($targetPath);
        $previousContent = null;

        if ($hadExisting) {
            $previousContent = $filesystem->get_contents($targetPath);
            if (!is_string($previousContent)) {
                return [
                    'success' => false,
                    'previous_content' => null,
                    'error' => 'Could not read existing file content for safety backup.',
                ];
            }
        }

        $written = $filesystem->put_contents($targetPath, $content, FS_CHMOD_FILE);
        if (!$written) {
            return [
                'success' => false,
                'previous_content' => $previousContent,
                'error' => 'Could not write file content via WordPress filesystem.',
            ];
        }

        return [
            'success' => true,
            'previous_content' => $previousContent,
            'had_existing' => $hadExisting,
        ];
    }

    /**
     * Read back a written file and build verification data for the AI response.
     * Includes line count, a preview of the first lines, and a size warning
     * when the file exceeds 300 lines. This replaces the manual read-after-write
     * step the model would otherwise need to perform.
     *
     * @return array{read_back: array{line_count: int, preview: string}, size_warning?: string}
     */
    protected function buildReadBackData(\WP_Filesystem_Base $filesystem, string $targetPath): array {
        $data = [];

        $readBack = $filesystem->get_contents($targetPath);
        if (!is_string($readBack)) {
            return $data;
        }

        $allLines = explode("\n", $readBack);
        $lineCount = count($allLines);
        $data['read_back'] = [
            'line_count' => $lineCount,
            'preview' => implode("\n", array_slice($allLines, 0, 15)),
        ];

        if ($lineCount > 300) {
            $data['size_warning'] = "File has {$lineCount} lines. Consider splitting into separate include files for better maintainability.";
        }

        return $data;
    }

    /**
     * Build a compact diff summary between old and new file content.
     * Not a full diff algorithm — compares line-by-line and reports changed regions.
     *
     * @return array{old_lines: int, new_lines: int, added: int, removed: int, changed_regions: array}|null
     */
    protected function buildCompactDiff(string $old, string $new): ?array {
        if ($old === $new) {
            return null;
        }

        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);
        $oldCount = count($oldLines);
        $newCount = count($newLines);
        $maxLen = max($oldCount, $newCount);

        $regions = [];
        $currentRegion = null;
        $added = 0;
        $removed = 0;

        for ($i = 0; $i < $maxLen; $i++) {
            $oldLine = $oldLines[$i] ?? null;
            $newLine = $newLines[$i] ?? null;

            $changed = false;
            if ($oldLine === null) {
                $added++;
                $changed = true;
            } elseif ($newLine === null) {
                $removed++;
                $changed = true;
            } elseif ($oldLine !== $newLine) {
                $added++;
                $removed++;
                $changed = true;
            }

            if ($changed) {
                if ($currentRegion === null) {
                    $currentRegion = ['start' => $i + 1, 'end' => $i + 1];
                } else {
                    $currentRegion['end'] = $i + 1;
                }
            } else {
                if ($currentRegion !== null) {
                    $regions[] = $currentRegion;
                    $currentRegion = null;
                }
            }
        }
        if ($currentRegion !== null) {
            $regions[] = $currentRegion;
        }

        $regionsSummary = array_slice($regions, 0, 10);
        $type = function (array $region) use ($oldCount, $newCount): string {
            if ($region['end'] > $oldCount) {
                return 'added';
            }
            if ($region['end'] > $newCount) {
                return 'removed';
            }
            return 'modified';
        };

        return [
            'old_lines' => $oldCount,
            'new_lines' => $newCount,
            'added' => $added,
            'removed' => $removed,
            'changed_regions' => array_map(
                fn($r) => $r + ['type' => $type($r)],
                $regionsSummary
            ),
            'regions_truncated' => count($regions) > 10,
        ];
    }

    /**
     * Find the closest matching line(s) in content for a search string that wasn't found.
     * Uses similar_text() percentage scoring. For multi-line searches, uses a sliding window.
     *
     * @return array<array{line: int, content: string, similarity: float}>|null
     */
    protected function findClosestMatch(string $search, string $content, int $maxCandidates = 3): ?array {
        $lines = explode("\n", $content);
        $totalLines = count($lines);
        if ($totalLines === 0) {
            return null;
        }

        $searchLines = explode("\n", $search);
        $searchLineCount = count($searchLines);
        $searchTrimmed = mb_substr(trim($search), 0, 200);
        if ($searchTrimmed === '') {
            return null;
        }

        $candidates = [];
        $scanLimit = min($totalLines, 500);

        if ($searchLineCount <= 1) {
            for ($i = 0; $i < $scanLimit; $i++) {
                $lineTrimmed = mb_substr(trim($lines[$i]), 0, 200);
                if ($lineTrimmed === '') {
                    continue;
                }
                $percent = 0.0;
                similar_text($searchTrimmed, $lineTrimmed, $percent);
                if ($percent >= 50.0) {
                    $candidates[] = [
                        'line' => $i + 1,
                        'content' => mb_substr(trim($lines[$i]), 0, 120),
                        'similarity' => round($percent, 1),
                    ];
                }
            }
        } else {
            $windowSize = $searchLineCount;
            for ($i = 0; $i <= $scanLimit - $windowSize; $i++) {
                $window = implode("\n", array_slice($lines, $i, $windowSize));
                $windowTrimmed = mb_substr(trim($window), 0, 200);
                $percent = 0.0;
                similar_text($searchTrimmed, $windowTrimmed, $percent);
                if ($percent >= 50.0) {
                    $candidates[] = [
                        'line' => $i + 1,
                        'content' => mb_substr(trim($lines[$i]), 0, 120),
                        'similarity' => round($percent, 1),
                    ];
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($candidates, 0, $maxCandidates);
    }

    /**
     * Revert a file to its previous state (or delete if it didn't exist before).
     */
    protected function rollbackWrite(
        \WP_Filesystem_Base $filesystem,
        string $targetPath,
        bool $hadExisting,
        ?string $previousContent
    ): void {
        if ($hadExisting && is_string($previousContent)) {
            $filesystem->put_contents($targetPath, $previousContent, FS_CHMOD_FILE);
        } else {
            $filesystem->delete($targetPath, false, 'f');
        }
    }
}
