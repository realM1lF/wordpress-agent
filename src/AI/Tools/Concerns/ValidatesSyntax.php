<?php

namespace Levi\Agent\AI\Tools\Concerns;

/**
 * Shared PHP and JavaScript syntax validation for file-writing tools.
 */
trait ValidatesSyntax {

    /**
     * @return array{valid: bool, error?: string, warning?: string}
     */
    protected function validatePhpSyntax(string $path): array {
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            return ['valid' => true];
        }

        if (!function_exists('exec')) {
            return [
                'valid' => true,
                'warning' => 'PHP lint skipped (exec unavailable).',
            ];
        }

        $output = [];
        $exitCode = 0;
        @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'valid' => false,
                'error' => trim(implode("\n", $output)) ?: 'PHP lint failed.',
            ];
        }

        return ['valid' => true];
    }

    /**
     * Validate standalone JS files and inline <script> blocks in PHP files.
     *
     * @return array{valid: bool, error?: string, warning?: string}
     */
    protected function validateJsSyntax(string $path): array {
        if (!function_exists('exec')) {
            return ['valid' => true, 'warning' => 'JS validation skipped (exec unavailable).'];
        }

        $exitCode = 0;
        @exec('which node 2>/dev/null', $whichOut, $exitCode);
        if ($exitCode !== 0) {
            return ['valid' => true, 'warning' => 'JS validation skipped (node not found).'];
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'js') {
            $output = [];
            @exec('node --check ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                return [
                    'valid' => false,
                    'error' => 'JavaScript syntax error: ' . $this->extractNodeError($output),
                ];
            }
            return ['valid' => true];
        }

        if ($ext !== 'php') {
            return ['valid' => true];
        }

        return $this->validateInlineScriptBlocks($path);
    }

    private function validateInlineScriptBlocks(string $path): array {
        $content = file_get_contents($path);
        if ($content === false || !str_contains($content, '<script')) {
            return ['valid' => true];
        }

        if (!preg_match_all('/<script(\s[^>]*)?>(.+?)<\/script>/si', $content, $matches)) {
            return ['valid' => true];
        }

        $errors = [];
        foreach ($matches[2] as $i => $jsBlock) {
            $attrs = $matches[1][$i] ?? '';
            if ($attrs !== '' && preg_match('/type\s*=\s*["\'](.*?)["\']/i', $attrs, $typeMatch)) {
                $type = strtolower(trim($typeMatch[1]));
                if ($type !== '' && $type !== 'text/javascript' && $type !== 'module') {
                    continue;
                }
            }

            $js = trim($jsBlock);
            if (strlen($js) < 20) {
                continue;
            }

            $cleaned = preg_replace('/<\?(?:php|=)\s.*?\?>/s', '0', $js);
            if (substr_count($cleaned, '0') - substr_count($js, '0') > 8) {
                continue;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'levi_js_');
            if ($tmpFile === false) {
                continue;
            }
            file_put_contents($tmpFile, $cleaned);
            $output = [];
            $exitCode = 0;
            @exec('node --check ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
            @unlink($tmpFile);

            if ($exitCode !== 0) {
                $errors[] = 'Script block #' . ($i + 1) . ': ' . $this->extractNodeError($output);
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'error' => 'JavaScript syntax error(s): ' . implode(' | ', $errors),
            ];
        }

        return ['valid' => true];
    }

    private function extractNodeError(array $output): string {
        foreach ($output as $line) {
            if (str_contains($line, 'SyntaxError')) {
                return trim($line);
            }
        }
        $filtered = array_filter(
            $output,
            fn($l) => trim($l) !== '' && !str_starts_with(trim($l), 'at ') && !str_starts_with(trim($l), 'Node.js')
        );
        return implode(' — ', array_slice($filtered, 0, 3)) ?: 'Unknown JS error.';
    }
}
