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

    /**
     * Basic CSS syntax validation: brace balance and unclosed strings.
     *
     * @return array{valid: bool, error?: string}
     */
    protected function validateCssSyntax(string $path): array {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'php') {
            return $this->validateInlineStyleBlocks($path);
        }

        if ($ext !== 'css') {
            return ['valid' => true];
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return ['valid' => true];
        }

        return $this->checkCssBalance($content);
    }

    private function checkCssBalance(string $css, string $label = ''): array {
        $contentNoComments = preg_replace('#/\*.*?\*/#s', '', $css);

        $openBraces = substr_count($contentNoComments, '{');
        $closeBraces = substr_count($contentNoComments, '}');
        if ($openBraces !== $closeBraces) {
            $prefix = $label !== '' ? "{$label}: " : '';
            return [
                'valid' => false,
                'error' => "{$prefix}CSS brace mismatch: {$openBraces} opening vs {$closeBraces} closing braces.",
            ];
        }

        $openParens = substr_count($contentNoComments, '(');
        $closeParens = substr_count($contentNoComments, ')');
        if ($openParens !== $closeParens) {
            $prefix = $label !== '' ? "{$label}: " : '';
            return [
                'valid' => false,
                'error' => "{$prefix}CSS parenthesis mismatch: {$openParens} opening vs {$closeParens} closing.",
            ];
        }

        return ['valid' => true];
    }

    private function validateInlineStyleBlocks(string $path): array {
        $content = @file_get_contents($path);
        if ($content === false || !str_contains($content, '<style')) {
            return ['valid' => true];
        }

        if (!preg_match_all('/<style(\s[^>]*)?>(.+?)<\/style>/si', $content, $matches)) {
            return ['valid' => true];
        }

        $errors = [];
        foreach ($matches[2] as $i => $cssBlock) {
            $css = trim($cssBlock);
            if (strlen($css) < 10) {
                continue;
            }

            $cleaned = preg_replace('/<\?(?:php|=)\s.*?\?>/s', '', $css);

            $result = $this->checkCssBalance($cleaned, 'Style block #' . ($i + 1));
            if (($result['valid'] ?? true) === false) {
                $errors[] = $result['error'];
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'error' => 'Inline CSS error(s): ' . implode(' | ', $errors),
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

    /**
     * Build a map of known functions for a plugin (WordPress core, PHP builtins, plugin-defined).
     * Reusable across multiple calls to avoid O(N²) scanning when checking many files.
     *
     * @return array<string, true>
     */
    protected function buildKnownFunctionsForPlugin(string $pluginRoot): array {
        $knownFunctions = [];

        if (method_exists($this, 'getWordPressCoreFunctions')) {
            $knownFunctions = $this->getWordPressCoreFunctions();
        }
        if (method_exists($this, 'getPhpBuiltinFunctions')) {
            $knownFunctions = array_merge($knownFunctions, $this->getPhpBuiltinFunctions());
        }

        $ignorePatterns = [
            'if', 'else', 'elseif', 'while', 'for', 'foreach', 'switch', 'case',
            'return', 'new', 'throw', 'catch', 'try', 'finally',
            'array', 'list', 'unset', 'isset', 'empty', 'echo', 'print', 'die', 'exit',
            'self', 'static', 'parent', 'this', 'true', 'false', 'null',
        ];
        foreach ($ignorePatterns as $kw) {
            $knownFunctions[$kw] = true;
        }

        $pluginRootReal = realpath($pluginRoot);
        if ($pluginRootReal === false) {
            return $knownFunctions;
        }

        $phpFiles = glob($pluginRootReal . '/*.php') ?: [];
        $subFiles = glob($pluginRootReal . '/*/*.php') ?: [];
        $deepFiles = glob($pluginRootReal . '/*/*/*.php') ?: [];
        $allPhpFiles = array_unique(array_merge($phpFiles, $subFiles, $deepFiles));
        $allPhpFiles = array_slice($allPhpFiles, 0, 30);

        foreach ($allPhpFiles as $phpFile) {
            if (!is_file($phpFile) || filesize($phpFile) > 200 * 1024) {
                continue;
            }
            $src = @file_get_contents($phpFile);
            if ($src === false) {
                continue;
            }
            if (preg_match_all('/\bfunction\s+(\w+)\s*\(/m', $src, $fm)) {
                foreach ($fm[1] as $fn) {
                    $knownFunctions[$fn] = true;
                }
            }
            if (preg_match_all('/\bclass\s+(\w+)/m', $src, $cm)) {
                foreach ($cm[1] as $cls) {
                    $knownFunctions[$cls] = true;
                }
            }
        }

        return $knownFunctions;
    }

    /**
     * Check reference integrity: detect function calls that are neither defined in the plugin
     * nor known as WordPress core / PHP built-in functions.
     *
     * @param array<string, true>|null $prebuiltKnownFunctions Pre-scanned known functions to avoid O(N²) rescanning
     * @return array{valid: bool, undefined_calls: array, warning: string}
     */
    protected function checkReferenceIntegrity(string $filePath, string $pluginRoot, ?array $prebuiltKnownFunctions = null): array {
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') {
            return ['valid' => true, 'undefined_calls' => [], 'warning' => ''];
        }

        $calledFunctions = [];
        if (preg_match_all('/(?<!function\s)(?<!\$)\b([a-zA-Z_]\w+)\s*\(/m', $content, $m)) {
            $calledFunctions = array_unique($m[1]);
        }

        if (empty($calledFunctions)) {
            return ['valid' => true, 'undefined_calls' => [], 'warning' => ''];
        }

        if ($prebuiltKnownFunctions !== null) {
            $knownFunctions = $prebuiltKnownFunctions;
        } else {
            $knownFunctions = $this->buildKnownFunctionsForPlugin($pluginRoot);
        }

        // Find undefined calls
        $undefined = [];
        foreach ($calledFunctions as $fn) {
            if (isset($knownFunctions[$fn])) {
                continue;
            }
            // Skip likely method calls (preceded by -> or ::) — regex already filters $ but not ->
            // Skip names starting with uppercase (likely class instantiation via new)
            if (ctype_upper($fn[0])) {
                continue;
            }
            // Skip very short names (likely variables misread)
            if (strlen($fn) < 3) {
                continue;
            }
            $undefined[] = $fn;
        }

        $undefined = array_slice(array_unique($undefined), 0, 50);

        if (empty($undefined)) {
            return ['valid' => true, 'undefined_calls' => [], 'warning' => ''];
        }

        $pluginRootNorm = rtrim((string) (realpath($pluginRoot) ?: $pluginRoot), '/');
        $relPath = str_replace($pluginRootNorm . '/', '', $filePath);
        $preview = implode(', ', array_slice($undefined, 0, 8));
        $warning = "Moeglicherweise undefinierte Funktionen in {$relPath}: {$preview}";
        if (count($undefined) > 8) {
            $warning .= ' (und ' . (count($undefined) - 8) . ' weitere)';
        }

        return [
            'valid' => count($undefined) === 0,
            'undefined_calls' => $undefined,
            'warning' => $warning,
        ];
    }

    /**
     * Check for common WordPress anti-patterns and potential issues.
     *
     * @return array{warnings: array<string>}
     */
    protected function checkWordPressPatterns(string $content, string $relativePath): array {
        $warnings = [];

        // 1. echo in filter callbacks (filter should return, not echo)
        if (preg_match_all('/add_filter\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*(?:[\'"]\w+[\'"]|function\s*\(|fn\s*\()/m', $content, $filterMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($filterMatches[0] as $match) {
                $offset = $match[1];
                $surroundingBlock = mb_substr($content, $offset, 500);
                if (preg_match('/\becho\b/', $surroundingBlock) && !str_contains($surroundingBlock, 'ob_start')) {
                    $warnings[] = "Filter-Callback in {$relativePath} nutzt echo — Filter sollen return verwenden, nicht echo.";
                    break;
                }
            }
        }

        // 2. $_POST/$_GET/$_REQUEST without nonce check nearby
        if (preg_match_all('/\$_(POST|GET|REQUEST)\s*\[/', $content, $inputMatches, PREG_OFFSET_CAPTURE)) {
            $hasNonceCheck = str_contains($content, 'wp_verify_nonce')
                || str_contains($content, 'check_admin_referer')
                || str_contains($content, 'check_ajax_referer');
            if (!$hasNonceCheck && count($inputMatches[0]) > 0) {
                $warnings[] = "Formular-Input (\$_{$inputMatches[1][0][0]}) ohne Nonce-Pruefung in {$relativePath}.";
            }
        }

        // 3. Direct DB queries without prepare()
        if (preg_match('/\$wpdb\s*->\s*query\s*\(\s*["\']/', $content) ||
            preg_match('/\$wpdb\s*->\s*query\s*\(\s*\$/', $content)) {
            if (!str_contains($content, '$wpdb->prepare')) {
                $warnings[] = "Unsichere DB-Query ohne \$wpdb->prepare() in {$relativePath}.";
            }
        }

        // 4. Deprecated functions
        $deprecated = [
            'create_function' => 'anonyme Funktionen (Closures)',
            'mysql_query' => 'wpdb-Methoden',
            'mysql_connect' => 'wpdb',
            'mysql_real_escape_string' => '$wpdb->prepare()',
            'ereg' => 'preg_match',
            'eregi' => 'preg_match mit i-Flag',
            'split' => 'explode oder preg_split',
        ];
        foreach ($deprecated as $func => $replacement) {
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $content)) {
                $warnings[] = "Deprecated Funktion '{$func}' in {$relativePath} — nutze stattdessen {$replacement}.";
            }
        }

        // 5. Translation functions without text domain
        if (preg_match_all('/\b(?:__|_e|_x|_n|_nx|_ex|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*[\'"][^\'"]*[\'"]\s*\)/', $content, $i18nMatches)) {
            if (count($i18nMatches[0]) > 0) {
                $warnings[] = "Fehlende Text-Domain in Uebersetzungsfunktion(en) in {$relativePath} — zweiten Parameter angeben.";
            }
        }

        return ['warnings' => $warnings];
    }
}
