<?php

namespace Levi\Agent\AI\Tools\Concerns;

/**
 * Shared file operation helpers for generic tools.
 */
trait HasFileOperations {

    /**
     * Resolve a path to an absolute path within allowed directories.
     * Blocks path traversal and restricts to wp-content + uploads.
     */
    protected function resolvePath(string $path): array {
        $normalized = realpath($path);
        if ($normalized === false) {
            $normalized = $path;
        }

        $allowedRoots = [
            realpath(WP_CONTENT_DIR),
            realpath(ABSPATH . 'wp-admin'),
            realpath(ABSPATH . 'wp-includes'),
            realpath(wp_upload_dir()['basedir']),
        ];

        $inAllowed = false;
        foreach ($allowedRoots as $root) {
            if ($root !== false && str_starts_with($normalized, $root)) {
                $inAllowed = true;
                break;
            }
        }

        if (!$inAllowed) {
            return [
                'success' => false,
                'error' => "Pfad außerhalb erlaubter Verzeichnisse: {$path}",
            ];
        }

        return ['success' => true, 'path' => $normalized];
    }

    /**
     * Get WordPress filesystem.
     */
    protected function getFilesystem(): ?\WP_Filesystem_Base {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!WP_Filesystem()) {
            return null;
        }
        global $wp_filesystem;
        return ($wp_filesystem instanceof \WP_Filesystem_Base) ? $wp_filesystem : null;
    }

    /**
     * Read a file safely.
     */
    protected function readFile(string $path, ?int $startLine = null, ?int $endLine = null): array {
        $resolved = $this->resolvePath($path);
        if (!($resolved['success'] ?? false)) {
            return $resolved;
        }
        $path = $resolved['path'];

        $fs = $this->getFilesystem();
        if ($fs === null) {
            return ['success' => false, 'error' => 'WordPress filesystem not available'];
        }

        if (!$fs->exists($path)) {
            return ['success' => false, 'error' => "Datei nicht gefunden: {$path}"];
        }

        $content = $fs->get_contents($path);
        if (!is_string($content)) {
            return ['success' => false, 'error' => "Konnte Datei nicht lesen: {$path}"];
        }

        $allLines = explode("\n", $content);
        $totalLines = count($allLines);

        if ($startLine !== null || $endLine !== null) {
            $start = max(0, ($startLine ?? 1) - 1);
            $end = min($totalLines, ($endLine ?? $totalLines));
            $allLines = array_slice($allLines, $start, $end - $start);
            $content = implode("\n", $allLines);
        }

        return [
            'success' => true,
            'content' => $content,
            'path' => $path,
            'line_count' => $totalLines,
            'returned_lines' => count($allLines),
        ];
    }

    /**
     * Write a file safely with backup.
     */
    protected function writeFile(string $path, string $content, bool $allowOverwrite = false): array {
        $resolved = $this->resolvePath($path);
        if (!($resolved['success'] ?? false)) {
            return $resolved;
        }
        $path = $resolved['path'];

        $fs = $this->getFilesystem();
        if ($fs === null) {
            return ['success' => false, 'error' => 'WordPress filesystem not available'];
        }

        $hadExisting = $fs->exists($path);
        $previousContent = null;

        if ($hadExisting) {
            if (!$allowOverwrite) {
                return [
                    'success' => false,
                    'error' => "Datei existiert bereits. Nutze overwrite=true zum Überschreiben: {$path}",
                ];
            }
            $previousContent = $fs->get_contents($path);
        }

        $written = $fs->put_contents($path, $content, FS_CHMOD_FILE);
        if (!$written) {
            return [
                'success' => false,
                'error' => "Konnte Datei nicht schreiben: {$path}",
                'previous_content' => $previousContent,
            ];
        }

        // Syntax check for PHP files
        $syntaxError = null;
        if (str_ends_with($path, '.php')) {
            $syntaxError = $this->checkPhpSyntax($content);
        }

        return [
            'success' => $syntaxError === null,
            'path' => $path,
            'had_existing' => $hadExisting,
            'previous_content' => $previousContent,
            'line_count' => count(explode("\n", $content)),
            'syntax_error' => $syntaxError,
        ];
    }

    /**
     * Apply search-and-replace edits atomically.
     */
    protected function editFile(string $path, array $replacements, bool $dryRun = false): array {
        $readResult = $this->readFile($path);
        if (!($readResult['success'] ?? false)) {
            return $readResult;
        }

        $content = $readResult['content'];
        $originalContent = $content;
        $applied = [];
        $failed = [];

        foreach ($replacements as $index => $replacement) {
            $search = (string) ($replacement['search'] ?? '');
            $replace = (string) ($replacement['replace'] ?? '');

            if ($search === '') {
                $failed[] = ['index' => $index, 'error' => 'search darf nicht leer sein'];
                continue;
            }

            $count = 0;
            $newContent = str_replace($search, $replace, $content, $count);

            if ($count === 0) {
                $failed[] = ['index' => $index, 'error' => 'search nicht gefunden', 'search' => mb_substr($search, 0, 100)];
                continue;
            }

            $content = $newContent;
            $applied[] = [
                'index' => $index,
                'matches' => $count,
                'search_preview' => mb_substr($search, 0, 80),
                'replace_preview' => mb_substr($replace, 0, 80),
            ];
        }

        // Atomar: wenn auch nur ein Replacement fehlgeschlagen ist, nichts schreiben
        if (!empty($failed) && !$dryRun) {
            return [
                'success' => false,
                'error' => 'Atomare Editierung fehlgeschlagen. Nichts wurde geschrieben.',
                'applied' => $applied,
                'failed' => $failed,
                'would_have_applied' => $applied,
            ];
        }

        if (!$dryRun) {
            $writeResult = $this->writeFile($path, $content, true);
            if (!($writeResult['success'] ?? false)) {
                return $writeResult;
            }
        }

        return [
            'success' => true,
            'path' => $path,
            'dry_run' => $dryRun,
            'applied' => $applied,
            'failed' => $failed,
            'changes_count' => count($applied),
            'old_lines' => count(explode("\n", $originalContent)),
            'new_lines' => count(explode("\n", $content)),
        ];
    }

    /**
     * Grep-like search in file contents.
     */
    protected function grepInFiles(string $pattern, array $paths, bool $regex = false): array {
        $results = [];
        foreach ($paths as $path) {
            $readResult = $this->readFile($path);
            if (!($readResult['success'] ?? false)) {
                continue;
            }

            $lines = explode("\n", $readResult['content']);
            $matches = [];
            foreach ($lines as $lineNum => $line) {
                if ($regex) {
                    $found = @preg_match($pattern, $line);
                    if ($found === false) {
                        return ['success' => false, 'error' => 'Ungültiger Regex: ' . $pattern];
                    }
                    if ($found) {
                        $matches[] = ['line' => $lineNum + 1, 'content' => $line];
                    }
                } else {
                    if (str_contains($line, $pattern)) {
                        $matches[] = ['line' => $lineNum + 1, 'content' => $line];
                    }
                }
            }
            if (!empty($matches)) {
                $results[] = [
                    'path' => $path,
                    'matches' => $matches,
                    'match_count' => count($matches),
                ];
            }
        }
        return ['success' => true, 'results' => $results, 'total_matches' => array_sum(array_column($results, 'match_count'))];
    }

    /**
     * Check PHP syntax.
     */
    protected function checkPhpSyntax(string $code): ?string {
        $output = [];
        $returnVar = 0;
        $tempFile = tempnam(sys_get_temp_dir(), 'levi_syntax_');
        if ($tempFile === false) {
            return null;
        }
        file_put_contents($tempFile, $code);
        exec('php -l ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnVar);
        unlink($tempFile);
        if ($returnVar !== 0) {
            return implode("\n", $output);
        }
        return null;
    }
}
