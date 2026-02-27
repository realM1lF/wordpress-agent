<?php

namespace Levi\Agent\AI\Tools;

class ExecuteWPCodeTool implements ToolInterface {

    private const MAX_OUTPUT_BYTES = 51200;
    private const MAX_EXECUTION_SECONDS = 30;

    private static array $blockedFunctions = [
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open',
        'pcntl_exec', 'dl', 'putenv', 'ini_set', 'ini_alter',
        'apache_setenv', 'header', 'setcookie',
    ];

    public function getName(): string {
        return 'execute_wp_code';
    }

    public function getDescription(): string {
        return 'Execute arbitrary PHP code in the WordPress context. Extremely powerful: can call any WP/WC function, query the database, test plugin output, etc. Use for diagnostics, testing, and operations not covered by other tools. Requires admin permission and explicit user confirmation. The code runs inside a try/catch with output buffering.';
    }

    public function getParameters(): array {
        return [
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to execute (without <?php tags). Example: "return wc_get_product(123)->get_name();"',
                'required' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $settings = get_option('levi_agent_settings', []);
        return !empty($settings['enable_code_execution']);
    }

    public function execute(array $params): array {
        $code = (string) ($params['code'] ?? '');

        if (trim($code) === '') {
            return ['success' => false, 'error' => 'No code provided.'];
        }

        $safetyCheck = $this->checkCodeSafety($code);
        if ($safetyCheck !== null) {
            return ['success' => false, 'error' => $safetyCheck];
        }

        error_log('Levi execute_wp_code: ' . mb_substr($code, 0, 1000));

        $previousTimeLimit = ini_get('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_EXECUTION_SECONDS);
        }

        ob_start();
        $returnValue = null;
        $error = null;

        try {
            $returnValue = eval($code);
        } catch (\Throwable $e) {
            $error = $e->getMessage() . ' in line ' . $e->getLine();
        }

        $output = ob_get_clean();
        if ($output === false) {
            $output = '';
        }

        if (function_exists('set_time_limit') && $previousTimeLimit !== false) {
            @set_time_limit((int) $previousTimeLimit);
        }

        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            $output = mb_substr($output, 0, self::MAX_OUTPUT_BYTES) . "\n... [truncated at " . self::MAX_OUTPUT_BYTES . " bytes]";
        }

        $result = [
            'success' => $error === null,
            'output' => $output ?: null,
        ];

        if ($error !== null) {
            $result['error'] = $error;
        }

        if ($returnValue !== null && $returnValue !== 1) {
            $encoded = $this->encodeReturnValue($returnValue);
            if (strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                $encoded = mb_substr($encoded, 0, self::MAX_OUTPUT_BYTES) . "\n... [truncated]";
            }
            $result['return_value'] = $encoded;
        }

        return $result;
    }

    private function checkCodeSafety(string $code): ?string {
        $codeLower = strtolower($code);

        foreach (self::$blockedFunctions as $func) {
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $code)) {
                return "Blocked function detected: $func(). This function is not allowed for security reasons.";
            }
        }

        if (preg_match('/\beval\s*\(/i', $code)) {
            return 'Nested eval() is not allowed.';
        }

        if (str_contains($codeLower, 'file_put_contents') || str_contains($codeLower, 'fwrite')) {
            if (!str_contains($codeLower, 'wp_content') && !str_contains($codeLower, 'wp_upload_dir')) {
                return 'Direct file writing detected. Use write_plugin_file or write_theme_file tools instead.';
            }
        }

        if (str_contains($codeLower, 'unlink(') || str_contains($codeLower, 'rmdir(')) {
            return 'File/directory deletion via code is not allowed. Use appropriate WordPress tools.';
        }

        return null;
    }

    private function encodeReturnValue($value): string {
        if (is_string($value)) {
            return $value;
        }
        if (is_numeric($value) || is_bool($value) || is_null($value)) {
            return json_encode($value);
        }
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return print_r($value, true);
            }
            return $json;
        }
        return (string) $value;
    }
}
