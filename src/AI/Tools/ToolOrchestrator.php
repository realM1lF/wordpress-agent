<?php

namespace Levi\Agent\AI\Tools;

/**
 * ORPA-style Tool Orchestrator — replaces the complex ExecutesToolLoop trait.
 *
 * Manages agent state transitions and tool execution with minimal overhead:
 * - No 15+ post-execution guard injections
 * - Consolidated validation (1-2 system messages max)
 * - Explicit planning step (PLANNING state)
 * - Loop detection via state tracking
 */
class ToolOrchestrator {

    private AgentState $state;
    private array $executionLog = [];
    private int $iterationCount = 0;
    private int $maxIterations;
    private int $writeCount = 0;
    private int $maxWriteCalls;
    private ?string $lastError = null;
    private array $stateHistory = [];

    public function __construct(int $maxIterations = 15, int $maxWriteCalls = 10) {
        $this->state = AgentState::IDLE;
        $this->maxIterations = $maxIterations;
        $this->maxWriteCalls = $maxWriteCalls;
    }

    // -----------------------------------------------------------------------
    // State management
    // -----------------------------------------------------------------------

    public function getState(): AgentState {
        return $this->state;
    }

    public function transitionTo(AgentState $next): bool {
        if (!$this->state->canTransitionTo($next)) {
            $this->lastError = "Ungültiger Zustandsübergang: {$this->state->value} → {$next->value}";
            return false;
        }
        $this->stateHistory[] = [
            'from' => $this->state->value,
            'to' => $next->value,
            'at' => time(),
        ];
        $this->state = $next;
        return true;
    }

    public function getStateHistory(): array {
        return $this->stateHistory;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    // -----------------------------------------------------------------------
    // Main execution loop
    // -----------------------------------------------------------------------

    /**
     * Execute a single tool call with orchestration.
     * Returns [success, result, state_changed, issues].
     */
    public function executeToolCall(
        ToolInterface $tool,
        array $params,
        ?callable $preExecuteHook = null,
        ?callable $postExecuteHook = null
    ): array {
        $this->iterationCount++;

        if ($this->iterationCount > $this->maxIterations) {
            $this->transitionTo(AgentState::ERROR);
            return [
                'success' => false,
                'result' => ['success' => false, 'error' => "Maximale Iterationen ({$this->maxIterations}) erreicht"],
                'state_changed' => true,
                'issues' => ['iteration_limit_exceeded'],
            ];
        }

        // Loop detection: same tool with same params within last 3 calls
        if ($this->isLoop($tool->getName(), $params)) {
            $this->transitionTo(AgentState::ERROR);
            return [
                'success' => false,
                'result' => ['success' => false, 'error' => 'Tool-Loop erkannt: Gleiches Tool mit gleichen Parametern wiederholt aufgerufen'],
                'state_changed' => true,
                'issues' => ['loop_detected'],
            ];
        }

        // Permission check
        if (!$tool->checkPermission()) {
            return [
                'success' => false,
                'result' => ['success' => false, 'error' => 'Keine Berechtigung für Tool: ' . $tool->getName()],
                'state_changed' => false,
                'issues' => ['permission_denied'],
            ];
        }

        // Pre-execute hook (for ToolGuard integration)
        if ($preExecuteHook !== null) {
            $hookResult = $preExecuteHook($tool->getName(), $params);
            if ($hookResult !== null && ($hookResult['blocked'] ?? false)) {
                return [
                    'success' => false,
                    'result' => ['success' => false, 'error' => $hookResult['reason'] ?? 'Durch Pre-Execute-Hook blockiert'],
                    'state_changed' => false,
                    'issues' => ['blocked_by_guard'],
                ];
            }
        }

        // Execute
        try {
            $result = $tool->execute($params);
        } catch (\Throwable $e) {
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }

        // Track writes
        if ($this->isWriteTool($tool->getName())) {
            $this->writeCount++;
        }

        // Log
        $this->executionLog[] = [
            'iteration' => $this->iterationCount,
            'tool' => $tool->getName(),
            'params' => $this->sanitizeParams($params),
            'result_success' => $result['success'] ?? false,
            'timestamp' => time(),
        ];

        // Post-execute hook
        if ($postExecuteHook !== null) {
            $postExecuteHook($tool->getName(), $params, $result);
        }

        // Consolidated validation (single check, not 15+)
        $issues = $this->validateResult($tool->getName(), $result);

        return [
            'success' => $result['success'] ?? false,
            'result' => $result,
            'state_changed' => false,
            'issues' => $issues,
            'iteration' => $this->iterationCount,
            'write_count' => $this->writeCount,
        ];
    }

    // -----------------------------------------------------------------------
    // Batch execution (for parallel read-only calls)
    // -----------------------------------------------------------------------

    /**
     * Execute multiple tool calls in parallel (only safe for read-only tools).
     */
    public function executeBatch(array $calls, callable $toolResolver): array {
        $results = [];
        foreach ($calls as $index => $call) {
            $toolName = $call['tool'] ?? '';
            $params = $call['params'] ?? [];
            $tool = $toolResolver($toolName);

            if ($tool === null) {
                $results[$index] = [
                    'success' => false,
                    'error' => "Tool nicht gefunden: {$toolName}",
                ];
                continue;
            }

            if ($this->isWriteTool($toolName)) {
                $results[$index] = [
                    'success' => false,
                    'error' => "Batch-Ausführung nicht erlaubt für Schreib-Tool: {$toolName}",
                ];
                continue;
            }

            $result = $this->executeToolCall($tool, $params);
            $results[$index] = $result['result'];
        }
        return $results;
    }

    // -----------------------------------------------------------------------
    // Validation (consolidated, replaces 15+ guard injections)
    // -----------------------------------------------------------------------

    /**
     * Single consolidated validation check.
     * Returns array of issue codes. Empty = no issues.
     */
    private function validateResult(string $toolName, array $result): array {
        $issues = [];

        // 1. Write budget
        if ($this->writeCount > $this->maxWriteCalls) {
            $issues[] = 'write_budget_exceeded';
        }

        // 2. Tool reported failure but no error message
        if (($result['success'] ?? false) === false && empty($result['error'])) {
            $issues[] = 'failed_without_error';
        }

        // 3. Syntax error after file write
        if ($toolName === 'write' && !empty($result['syntax_error'])) {
            $issues[] = 'syntax_error_in_written_file';
        }

        return $issues;
    }

    /**
     * Generate a single post-execution instruction for the LLM.
     * Replaces the 15+ separate injection methods.
     */
    public function buildPostExecutionInstruction(array $recentResults): string {
        $parts = [];

        // Write count warning
        if ($this->writeCount >= $this->maxWriteCalls - 2) {
            $parts[] = "⚠️ Schreib-Budget fast erschöpft ({$this->writeCount}/{$this->maxWriteCalls}). "
                . "Fasse verbleibende Änderungen zusammen oder beende den Task.";
        }

        // Syntax errors
        foreach ($recentResults as $result) {
            if (!empty($result['syntax_error'])) {
                $path = $result['path'] ?? 'unbekannte Datei';
                $parts[] = "⚠️ Syntax-Fehler in {$path}: " . $result['syntax_error'];
            }
        }

        // Iteration warning
        if ($this->iterationCount >= $this->maxIterations - 3) {
            $parts[] = "⚠️ Iterationslimit nahe ({$this->iterationCount}/{$this->maxIterations}). "
                . "Beende den Task oder fasse zusammen.";
        }

        // Self-check reminder (only if writes occurred)
        if ($this->writeCount > 0) {
            $parts[] = "✅ Überprüfe: Alle geplanten Änderungen ausgeführt? Falls ja, antworte dem Nutzer. "
                . "Falls nein, führe verbleibende aus.";
        }

        if (empty($parts)) {
            return '';
        }

        return "---\n" . implode("\n", $parts) . "\n---";
    }

    // -----------------------------------------------------------------------
    // Completion check (simplified)
    // -----------------------------------------------------------------------

    /**
     * Check if the task appears complete based on execution history.
     */
    public function isTaskComplete(array $claimedMutations = []): array {
        $executedMutations = [];
        foreach ($this->executionLog as $log) {
            if ($this->isWriteTool($log['tool']) && ($log['result_success'] ?? false)) {
                $executedMutations[] = $log['tool'];
            }
        }

        $pending = array_diff($claimedMutations, $executedMutations);
        $hasPending = !empty($pending);

        return [
            'complete' => !$hasPending && $this->writeCount > 0,
            'pending_mutations' => $pending,
            'executed_mutations' => $executedMutations,
            'can_finish' => !$hasPending,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function isLoop(string $toolName, array $params): bool {
        $recent = array_slice($this->executionLog, -3);
        foreach ($recent as $log) {
            if ($log['tool'] === $toolName && $log['params'] === $this->sanitizeParams($params)) {
                return true;
            }
        }
        return false;
    }

    private function isWriteTool(string $toolName): bool {
        static $writeTools = ['write', 'edit', 'install', 'manage', 'manage_woo', 'manage_elementor'];
        return in_array($toolName, $writeTools, true);
    }

    private function sanitizeParams(array $params): array {
        // Remove large content fields for comparison
        $sanitized = $params;
        if (isset($sanitized['content']) && strlen((string) $sanitized['content']) > 200) {
            $sanitized['content'] = substr((string) $sanitized['content'], 0, 200) . '...';
        }
        if (isset($sanitized['file_data'])) {
            $sanitized['file_data'] = '[base64-data]';
        }
        return $sanitized;
    }

    public function getExecutionLog(): array {
        return $this->executionLog;
    }

    public function getIterationCount(): int {
        return $this->iterationCount;
    }

    public function getWriteCount(): int {
        return $this->writeCount;
    }

    public function reset(): void {
        $this->state = AgentState::IDLE;
        $this->executionLog = [];
        $this->iterationCount = 0;
        $this->writeCount = 0;
        $this->lastError = null;
        $this->stateHistory = [];
    }
}
