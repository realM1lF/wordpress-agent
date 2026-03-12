<?php

namespace Levi\Agent\API\Concerns;

trait ManagesUploads {

    private function getSessionUploadsKey(string $sessionId, int $userId): string {
        return 'levi_files_' . md5($sessionId . '|' . $userId);
    }

    private function getSessionUploads(string $sessionId, int $userId): array {
        $value = get_transient($this->getSessionUploadsKey($sessionId, $userId));
        return is_array($value) ? $value : [];
    }

    private function setSessionUploads(string $sessionId, int $userId, array $files): void {
        set_transient($this->getSessionUploadsKey($sessionId, $userId), $files, HOUR_IN_SECONDS);
    }

    private function clearSessionUploads(string $sessionId, int $userId): void {
        delete_transient($this->getSessionUploadsKey($sessionId, $userId));
    }

    private function filesToMeta(array $files): array {
        return array_map(function ($f) {
            return [
                'id' => (string) ($f['id'] ?? ''),
                'name' => (string) ($f['name'] ?? ''),
                'type' => (string) ($f['type'] ?? ''),
                'size' => (int) ($f['size'] ?? 0),
                'preview' => (string) ($f['preview'] ?? ''),
                'uploaded_at' => (string) ($f['uploaded_at'] ?? ''),
            ];
        }, array_values(array_filter($files, 'is_array')));
    }

    private function normalizeUploadedFiles(array $fileParams): array {
        $normalized = [];
        foreach ($fileParams as $fieldValue) {
            if (!is_array($fieldValue)) {
                continue;
            }
            if (isset($fieldValue['name']) && is_array($fieldValue['name'])) {
                $count = count($fieldValue['name']);
                for ($i = 0; $i < $count; $i++) {
                    $normalized[] = [
                        'name' => (string) ($fieldValue['name'][$i] ?? ''),
                        'type' => (string) ($fieldValue['type'][$i] ?? ''),
                        'tmp_name' => (string) ($fieldValue['tmp_name'][$i] ?? ''),
                        'error' => (int) ($fieldValue['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        'size' => (int) ($fieldValue['size'][$i] ?? 0),
                    ];
                }
                continue;
            }
            $normalized[] = $fieldValue;
        }
        return $normalized;
    }

    private function processUploadedFile(array $file): array {
        $name = (string) ($file['name'] ?? '');
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => sprintf('Upload failed for %s (code %d).', $name, $error)];
        }
        if ($name === '' || $tmpName === '') {
            return ['success' => false, 'error' => 'Invalid upload payload.'];
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $textExtensions = ['txt', 'md', 'csv', 'json', 'xml', 'log'];
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $isText = in_array($ext, $textExtensions, true);
        $isImage = in_array($ext, $imageExtensions, true);

        if (!$isText && !$isImage) {
            return ['success' => false, 'error' => sprintf('Unsupported file type: %s', $name)];
        }
        if ($size <= 0) {
            return ['success' => false, 'error' => sprintf('Empty file: %s', $name)];
        }

        $maxSize = $isImage ? 5 * 1024 * 1024 : 2 * 1024 * 1024;
        if ($size > $maxSize) {
            $label = $isImage ? '5 MB' : '2 MB';
            return ['success' => false, 'error' => sprintf('File too large (max %s): %s', $label, $name)];
        }
        if (!is_uploaded_file($tmpName) && !file_exists($tmpName)) {
            return ['success' => false, 'error' => sprintf('Temporary file missing: %s', $name)];
        }

        if ($isImage) {
            return $this->processUploadedImage($tmpName, $name, $ext, $size);
        }

        $content = file_get_contents($tmpName);
        if (!is_string($content)) {
            return ['success' => false, 'error' => sprintf('Could not read file: %s', $name)];
        }

        if ($ext === 'csv') {
            $content = $this->csvToMarkdownTable($content);
        }

        $content = mb_substr($content, 0, 12000);
        $preview = mb_substr(trim($content), 0, 280);

        return [
            'success' => true,
            'file' => [
                'id' => 'f_' . wp_generate_uuid4(),
                'name' => sanitize_file_name($name),
                'type' => $ext,
                'size' => $size,
                'content' => $content,
                'preview' => $preview,
                'uploaded_at' => current_time('mysql'),
            ],
        ];
    }

    private function processUploadedImage(string $tmpName, string $name, string $ext, int $size): array {
        $raw = file_get_contents($tmpName);
        if (!is_string($raw) || $raw === '') {
            return ['success' => false, 'error' => sprintf('Could not read image: %s', $name)];
        }

        $mimeMap = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        ];
        $mime = $mimeMap[$ext] ?? 'image/jpeg';
        $base64 = 'data:' . $mime . ';base64,' . base64_encode($raw);
        $preview = '[Bild: ' . $name . ' (' . size_format($size) . ')]';

        return [
            'success' => true,
            'file' => [
                'id' => 'f_' . wp_generate_uuid4(),
                'name' => sanitize_file_name($name),
                'type' => $ext,
                'size' => $size,
                'content' => '',
                'image_base64' => $base64,
                'preview' => $preview,
                'uploaded_at' => current_time('mysql'),
            ],
        ];
    }

    private function csvToMarkdownTable(string $csv, int $maxRows = 200): string {
        $lines = preg_split('/\R/', $csv, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($lines)) {
            return $csv;
        }

        $rows = [];
        foreach (array_slice($lines, 0, $maxRows + 1) as $line) {
            $parsed = str_getcsv($line);
            if ($parsed !== false) {
                $rows[] = $parsed;
            }
        }
        if (count($rows) < 2) {
            return $csv;
        }

        $header = array_shift($rows);
        $md = '| ' . implode(' | ', $header) . " |\n";
        $md .= '| ' . implode(' | ', array_fill(0, count($header), '---')) . " |\n";
        foreach ($rows as $row) {
            $padded = array_pad($row, count($header), '');
            $md .= '| ' . implode(' | ', $padded) . " |\n";
        }

        $totalLines = count($lines);
        if ($totalLines > $maxRows + 1) {
            $md .= "\n*(" . ($totalLines - $maxRows - 1) . " weitere Zeilen nicht angezeigt)*\n";
        }

        return $md;
    }

    private function buildUploadedFilesContext(string $sessionId, int $userId): string {
        $files = $this->getSessionUploads($sessionId, $userId);
        if (empty($files)) {
            return '';
        }

        $parts = [];
        $remainingBudget = 12000;
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = (string) ($file['name'] ?? 'unknown');
            $type = (string) ($file['type'] ?? 'txt');

            if (!empty($file['image_base64'])) {
                $parts[] = "## Bild: {$name}\nDieses Bild wird dir als Vision-Input mitgeschickt. Du kannst es sehen und analysieren. Session-File-ID: " . ($file['id'] ?? '?');
                continue;
            }

            $content = (string) ($file['content'] ?? '');
            if ($content === '' || $remainingBudget <= 0) {
                continue;
            }
            $chunk = mb_substr($content, 0, min(4000, $remainingBudget));
            $remainingBudget -= mb_strlen($chunk);
            $parts[] = "## File: {$name} ({$type})\n" . $chunk;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return array<array{name: string, base64: string}>
     */
    private function getSessionImages(string $sessionId, int $userId): array {
        $files = $this->getSessionUploads($sessionId, $userId);
        $images = [];
        foreach ($files as $file) {
            if (!is_array($file) || empty($file['image_base64'])) {
                continue;
            }
            $images[] = [
                'name' => (string) ($file['name'] ?? 'image'),
                'base64' => (string) $file['image_base64'],
            ];
        }
        return $images;
    }
}
