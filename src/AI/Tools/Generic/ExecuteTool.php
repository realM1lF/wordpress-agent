<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Generic execute tool — run PHP/WP code or WP-CLI commands.
 */
class ExecuteTool extends AbstractTool {

    public function getName(): string { return 'execute'; }

    public function getDescription(): string {
        return "Führt PHP-Code, WordPress-Code oder WP-CLI-Befehle aus. "
            . "`type=php`: Beliebiger PHP-Code. Rückgabe via `return`. "
            . "`type=wp`: WordPress-Code mit vollständig geladenem WordPress-Kontext. "
            . "`type=cli`: WP-CLI-Befehl (nur wenn WP-CLI verfügbar). "
            . "VORSICHT: Code-Ausführung ist mächtig. Keine `eval()` von Benutzer-Input. "
            . "Maximale Ausführungszeit: 30 Sekunden.";
    }

    public function getParameters(): array {
        return [
            'type' => [
                'type' => 'string',
                'enum' => ['php', 'wp', 'cli'],
                'description' => 'Ausführungstyp',
            ],
            'code' => [
                'type' => 'string',
                'description' => 'PHP/WP Code (für type=php oder type=wp)',
            ],
            'command' => [
                'type' => 'string',
                'description' => 'WP-CLI Befehl (für type=cli, z.B. "plugin list --status=active")',
            ],
            'timeout' => [
                'type' => 'integer',
                'default' => 30,
                'description' => 'Timeout in Sekunden',
            ],
        ];
    }

    public function execute(array $params): array {
        $type = (string) ($params['type'] ?? 'wp');

        return match ($type) {
            'php' => $this->executePhp($params),
            'wp' => $this->executeWp($params),
            'cli' => $this->executeCli($params),
            default => ['success' => false, 'error' => "Unbekannter type: {$type}"],
        };
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    private function executePhp(array $params): array {
        $code = (string) ($params['code'] ?? '');
        if ($code === '') {
            return ['success' => false, 'error' => 'Erforderlich: code'];
        }

        // Verbotene Funktionen
        $forbidden = ['exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open', 'eval', 'assert'];
        foreach ($forbidden as $func) {
            if (preg_match("/\\b{$func}\\s*\\(/", $code)) {
                return ['success' => false, 'error' => "Verbotene Funktion: {$func}"];
            }
        }

        // Safe wrapper
        $wrapped = "<?php\n" . $code;
        $tempFile = tempnam(sys_get_temp_dir(), 'levi_exec_');
        if ($tempFile === false) {
            return ['success' => false, 'error' => 'Konnte temporäre Datei nicht erstellen'];
        }

        file_put_contents($tempFile, $wrapped);

        ob_start();
        try {
            $result = include $tempFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            unlink($tempFile);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        }
        $output = ob_get_clean();
        unlink($tempFile);

        return [
            'success' => true,
            'return_value' => $result,
            'output' => $output,
        ];
    }

    private function executeWp(array $params): array {
        $code = (string) ($params['code'] ?? '');
        if ($code === '') {
            return ['success' => false, 'error' => 'Erforderlich: code'];
        }

        ob_start();
        try {
            $result = eval($code);
        } catch (\Throwable $e) {
            ob_end_clean();
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        }
        $output = ob_get_clean();

        return [
            'success' => true,
            'return_value' => $result,
            'output' => $output,
        ];
    }

    private function executeCli(array $params): array {
        $command = (string) ($params['command'] ?? '');
        if ($command === '') {
            return ['success' => false, 'error' => 'Erforderlich: command'];
        }

        $wpCli = $this->findWpCli();
        if ($wpCli === null) {
            return ['success' => false, 'error' => 'WP-CLI nicht gefunden'];
        }

        $timeout = (int) ($params['timeout'] ?? 30);
        $cmd = escapeshellcmd($wpCli) . ' ' . escapeshellarg($command) . ' --format=json --allow-root 2>&1';

        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);

        $outputStr = implode("\n", $output);
        $decoded = json_decode($outputStr, true);

        return [
            'success' => $returnVar === 0,
            'exit_code' => $returnVar,
            'output' => $decoded !== null ? $decoded : $outputStr,
            'raw_output' => $outputStr,
        ];
    }

    private function findWpCli(): ?string {
        $candidates = ['wp', '/usr/local/bin/wp', '/usr/bin/wp'];
        foreach ($candidates as $candidate) {
            $output = [];
            $returnVar = 0;
            exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null', $output, $returnVar);
            if ($returnVar === 0 && !empty($output[0])) {
                return $output[0];
            }
        }
        return null;
    }
}
