<?php

namespace Levi\Agent\API\Concerns;

trait TracksWorkingSet {

    private const WORKING_SET_MAX_ENTRIES = 30;

    private array $workingSet = [];
    private int $workingSetIteration = 0;

    protected function recordFileAccess(string $toolName, string $slug, string $relativePath, string $action): void {
        $key = $slug . '/' . $relativePath;

        $this->workingSet[$key] = [
            'slug' => $slug,
            'path' => $relativePath,
            'action' => $action,
            'tool' => $toolName,
            'iteration' => $this->workingSetIteration,
        ];

        if (count($this->workingSet) > self::WORKING_SET_MAX_ENTRIES) {
            $this->workingSet = array_slice($this->workingSet, -self::WORKING_SET_MAX_ENTRIES, null, true);
        }
    }

    protected function trackFileAccessFromToolResult(string $toolName, array $args, array $result): void {
        if (!($result['success'] ?? false)) {
            return;
        }

        $slug = $args['plugin_slug'] ?? $args['theme_slug']
            ?? $result['plugin_slug'] ?? $result['theme_slug'] ?? null;
        $relPath = $args['relative_path'] ?? $result['relative_path'] ?? null;

        if ($slug === null || $relPath === null || $relPath === '') {
            if (isset($result['results']) && is_array($result['results'])) {
                foreach ($result['results'] as $batchResult) {
                    $batchPath = $batchResult['relative_path'] ?? null;
                    if ($batchPath !== null && ($batchResult['success'] ?? false)) {
                        $batchSlug = $slug ?? $args['plugin_slug'] ?? $args['theme_slug'] ?? '';
                        if ($batchSlug !== '') {
                            $this->recordFileAccess($toolName, $batchSlug, $batchPath, 'read');
                        }
                    }
                }
            }
            return;
        }

        $action = 'read';
        if (str_starts_with($toolName, 'write_') || str_starts_with($toolName, 'patch_')) {
            $action = str_starts_with($toolName, 'patch_') ? 'patch' : 'write';
        } elseif ($toolName === 'rename_in_plugin' || $toolName === 'revert_file') {
            $action = 'write';
        }

        $this->recordFileAccess($toolName, $slug, $relPath, $action);
    }

    protected function setWorkingSetIteration(int $iteration): void {
        $this->workingSetIteration = $iteration;
    }

    protected function getWorkingSetSummary(): string {
        if (empty($this->workingSet)) {
            return '';
        }

        $lines = [];
        $written = [];
        $read = [];

        foreach ($this->workingSet as $entry) {
            $prefix = $entry['action'] === 'read' ? 'R' : 'W';
            $line = " {$prefix} {$entry['slug']}/{$entry['path']} ({$entry['tool']}, Iteration {$entry['iteration']})";
            if ($prefix === 'W') {
                $written[] = $line;
            } else {
                $read[] = $line;
            }
        }

        $lines = array_merge($written, $read);

        return "[SESSION-KONTEXT] Dateien in dieser Session bearbeitet:\n" . implode("\n", $lines);
    }

    protected function getWorkingSetFiles(): array {
        return array_values($this->workingSet);
    }
}
