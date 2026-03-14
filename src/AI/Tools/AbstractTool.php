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
