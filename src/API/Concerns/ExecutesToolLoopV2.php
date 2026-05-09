<?php

namespace Levi\Agent\API\Concerns;

use Levi\Agent\AI\Tools\AgentState;
use Levi\Agent\AI\Tools\ToolOrchestrator;

/**
 * V2 Tool Execution Loop — replaces ExecutesToolLoop with ORPA state machine.
 *
 * Key differences from V1:
 * - Uses ToolOrchestrator instead of inline loop logic
 * - No 15+ post-execution guard injections (consolidated to 1)
 * - ORPA state streaming via SSE
 * - ~60% fewer tokens per iteration
 */
trait ExecutesToolLoopV2 {

    /**
     * Handle tool calls in streaming mode with ORPA state machine.
     */
    private function handleToolCallsStreamingV2(
        array $messageData,
        array $messages,
        string $sessionId,
        int $userId,
        string $latestUserMessage,
        callable $heartbeat,
        bool $webSearch = false
    ): void {
        $orchestrator = new ToolOrchestrator(
            maxIterations: 15,
            maxWriteCalls: 10
        );

        $toolResults = [];
        $iteration = 0;

        // Initial state: OBSERVING (we've already received the first response)
        $orchestrator->transitionTo(AgentState::OBSERVING);

        while ($iteration < 15) {
            $toolCalls = $messageData['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            $messages[] = $messageData;

            // Transition to EXECUTING
            $orchestrator->transitionTo(AgentState::EXECUTING);
            $this->emitSSE('state', [
                'state' => AgentState::EXECUTING->value,
                'label' => AgentState::EXECUTING->label(),
            ]);

            foreach ($toolCalls as $toolCall) {
                $functionName = trim($toolCall['function']['name'] ?? '');
                $rawArgs = $toolCall['function']['arguments'] ?? '{}';
                $functionArgs = json_decode($rawArgs, true);
                if (!is_array($functionArgs)) {
                    $functionArgs = [];
                }
                $toolCallId = $toolCall['id'] ?? '';

                // Resolve tool
                $tool = $this->toolRegistry->get($functionName);
                if ($tool === null && $this->genericRegistry !== null) {
                    $tool = $this->genericRegistry->get($functionName);
                }

                if ($tool === null) {
                    $result = [
                        'success' => false,
                        'error' => "Tool '{$functionName}' nicht gefunden.",
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                    continue;
                }

                // Pre-execute guard (ToolGuard integration)
                $preHook = function (string $name, array $args) {
                    if (!isset($this->toolGuard)) {
                        return null;
                    }
                    $guard = $this->toolGuard->evaluate($name, $args);
                    if ($guard['verdict'] === \Levi\Agent\AI\Tools\ToolGuard::BLOCK) {
                        return ['blocked' => true, 'reason' => $guard['reason'] ?? ''];
                    }
                    return null;
                };

                // Emit progress
                $this->emitSSE('progress', [
                    'message' => $this->getToolProgressLabelV2($functionName),
                    'tool' => $functionName,
                    'iteration' => $iteration,
                    'phase' => 'start',
                ]);

                // Execute via orchestrator
                $execResult = $orchestrator->executeToolCall($tool, $functionArgs, $preHook);
                $result = $execResult['result'];
                $toolResults[] = $result;

                // Emit completion
                $this->emitSSE('progress', [
                    'message' => $this->getToolProgressLabelV2($functionName, $execResult['success']),
                    'tool' => $functionName,
                    'iteration' => $iteration,
                    'success' => $execResult['success'],
                    'phase' => $execResult['success'] ? 'done' : 'failed',
                ]);

                // Add tool result to messages
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $this->compactToolResultForModel($result),
                    '_levi_iteration' => $iteration,
                    '_levi_tool' => $functionName,
                ];
            }

            // Consolidated post-execution instruction (replaces 15+ injections)
            $recentResults = array_slice($toolResults, -count($toolCalls));
            $postInstruction = $orchestrator->buildPostExecutionInstruction($recentResults);
            if ($postInstruction !== '') {
                $messages[] = [
                    'role' => 'system',
                    'content' => $postInstruction,
                ];
            }

            if (connection_aborted()) {
                error_log('Levi V2: client disconnected during tool loop');
                return;
            }

            // Transition to REASONING before next AI call
            $orchestrator->transitionTo(AgentState::REASONING);
            $this->emitSSE('state', [
                'state' => AgentState::REASONING->value,
                'label' => AgentState::REASONING->label(),
            ]);

            // Get next response
            $loopMessages = $this->compactMessagesForToolLoop($messages, $iteration);
            $nextResponse = $this->streamContinuation($loopMessages, $this->getToolDefs(), $webSearch);

            if (is_wp_error($nextResponse)) {
                $this->emitSSE('error', [
                    'message' => $nextResponse->get_error_message(),
                    'session_id' => $sessionId,
                    'tools_used' => array_values(array_unique(array_map(
                        fn($r) => $r['tool'] ?? '',
                        $orchestrator->getExecutionLog()
                    ))),
                ]);
                return;
            }

            $messageData = $nextResponse['choices'][0]['message'] ?? [];

            // No more tool calls → done
            if (empty($messageData['tool_calls'])) {
                // Transition to VERIFYING briefly
                $orchestrator->transitionTo(AgentState::VERIFYING);
                $this->emitSSE('state', [
                    'state' => AgentState::VERIFYING->value,
                    'label' => AgentState::VERIFYING->label(),
                ]);

                $finalMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($messageData['content'] ?? '')
                );

                if ($finalMessage === '') {
                    $finalMessage = $this->recoverStreamedContentOrFallbackV2($orchestrator->getExecutionLog());
                }

                if ($this->wasResponseTruncated($nextResponse)) {
                    $finalMessage = $this->appendTruncationHint($finalMessage);
                }

                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

                $orchestrator->transitionTo(AgentState::DONE);
                $this->emitSSE('state', [
                    'state' => AgentState::DONE->value,
                    'label' => AgentState::DONE->label(),
                ]);

                $donePayload = [
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'model' => $nextResponse['model'] ?? null,
                    'tools_used' => array_values(array_unique(array_map(
                        fn($r) => $r['tool'] ?? '',
                        $orchestrator->getExecutionLog()
                    ))),
                    'truncated' => $this->wasResponseTruncated($nextResponse),
                    'state_history' => $orchestrator->getStateHistory(),
                ];
                $donePayload['usage'] = $this->usageAccumulator ?? [];
                $this->emitSSE('done', $donePayload);
                $this->flushUsage($sessionId, $userId);
                return;
            }
        }

        // Max iterations reached
        $orchestrator->transitionTo(AgentState::ERROR);
        $this->emitSSE('state', [
            'state' => AgentState::ERROR->value,
            'label' => AgentState::ERROR->label(),
        ]);

        $finalMessage = $this->recoverStreamedContentOrFallbackV2($orchestrator->getExecutionLog());
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

        $this->emitSSE('done', [
            'session_id' => $sessionId,
            'message' => $finalMessage,
            'tools_used' => array_values(array_unique(array_map(
                fn($r) => $r['tool'] ?? '',
                $orchestrator->getExecutionLog()
            ))),
            'state_history' => $orchestrator->getStateHistory(),
            'usage' => $this->usageAccumulator ?? [],
        ]);
        $this->flushUsage($sessionId, $userId);
    }

    /**
     * V2 non-streaming tool loop.
     */
    private function handleToolCallsV2(
        array $messageData,
        array $messages,
        string $sessionId,
        int $userId,
        string $latestUserMessage,
        bool $webSearch = false
    ): \WP_REST_Response {
        $orchestrator = new ToolOrchestrator(
            maxIterations: 15,
            maxWriteCalls: 10
        );

        $toolResults = [];
        $iteration = 0;

        while ($iteration < 15) {
            $toolCalls = $messageData['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            $messages[] = $messageData;

            foreach ($toolCalls as $toolCall) {
                $functionName = trim($toolCall['function']['name'] ?? '');
                $rawArgs = $toolCall['function']['arguments'] ?? '{}';
                $functionArgs = json_decode($rawArgs, true);
                if (!is_array($functionArgs)) {
                    $functionArgs = [];
                }
                $toolCallId = $toolCall['id'] ?? '';

                $tool = $this->toolRegistry->get($functionName);
                if ($tool === null && $this->genericRegistry !== null) {
                    $tool = $this->genericRegistry->get($functionName);
                }

                if ($tool === null) {
                    $result = ['success' => false, 'error' => "Tool '{$functionName}' nicht gefunden."];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                    continue;
                }

                $preHook = function (string $name, array $args) {
                    if (!isset($this->toolGuard)) {
                        return null;
                    }
                    $guard = $this->toolGuard->evaluate($name, $args);
                    if ($guard['verdict'] === \Levi\Agent\AI\Tools\ToolGuard::BLOCK) {
                        return ['blocked' => true, 'reason' => $guard['reason'] ?? ''];
                    }
                    return null;
                };

                $execResult = $orchestrator->executeToolCall($tool, $functionArgs, $preHook);
                $result = $execResult['result'];
                $toolResults[] = $result;

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $this->compactToolResultForModel($result),
                    '_levi_iteration' => $iteration,
                    '_levi_tool' => $functionName,
                ];
            }

            $recentResults = array_slice($toolResults, -count($toolCalls));
            $postInstruction = $orchestrator->buildPostExecutionInstruction($recentResults);
            if ($postInstruction !== '') {
                $messages[] = ['role' => 'system', 'content' => $postInstruction];
            }

            $loopMessages = $this->compactMessagesForToolLoop($messages, $iteration);
            $nextResponse = $this->chatWithTracking($loopMessages, $this->getToolDefs(), null, $webSearch);

            if (is_wp_error($nextResponse)) {
                return new \WP_REST_Response([
                    'error' => $nextResponse->get_error_message(),
                    'session_id' => $sessionId,
                    'tools_used' => array_values(array_unique(array_map(
                        fn($r) => $r['tool'] ?? '',
                        $orchestrator->getExecutionLog()
                    ))),
                ], 500);
            }

            $messageData = $nextResponse['choices'][0]['message'] ?? [];

            if (empty($messageData['tool_calls'])) {
                $finalMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($messageData['content'] ?? '')
                );

                if ($finalMessage === '') {
                    $finalMessage = $this->recoverStreamedContentOrFallbackV2($orchestrator->getExecutionLog());
                }

                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

                return new \WP_REST_Response([
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'model' => $nextResponse['model'] ?? null,
                    'tools_used' => array_values(array_unique(array_map(
                        fn($r) => $r['tool'] ?? '',
                        $orchestrator->getExecutionLog()
                    ))),
                    'truncated' => $this->wasResponseTruncated($nextResponse),
                    'state_history' => $orchestrator->getStateHistory(),
                    'usage' => $this->usageAccumulator ?? [],
                ], 200);
            }
        }

        $finalMessage = $this->recoverStreamedContentOrFallbackV2($orchestrator->getExecutionLog());
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

        return new \WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $finalMessage,
            'tools_used' => array_values(array_unique(array_map(
                fn($r) => $r['tool'] ?? '',
                $orchestrator->getExecutionLog()
            ))),
            'state_history' => $orchestrator->getStateHistory(),
            'usage' => $this->usageAccumulator ?? [],
        ], 200);
    }

    // -----------------------------------------------------------------------
    // V2 helpers
    // -----------------------------------------------------------------------

    private function getToolProgressLabelV2(string $toolName, ?bool $success = null): string {
        $labels = [
            'read' => 'Lesen',
            'write' => 'Schreiben',
            'edit' => 'Editieren',
            'list' => 'Auflisten',
            'grep' => 'Suchen',
            'execute' => 'Ausführen',
            'install' => 'Installieren',
            'manage' => 'Verwalten',
            'manage_woo' => 'WooCommerce',
            'manage_elementor' => 'Elementor',
            'fetch' => 'HTTP-Abruf',
            'health_check' => 'Health-Check',
        ];

        $label = $labels[$toolName] ?? $toolName;

        if ($success === null) {
            return $label . '...';
        }
        return $success ? $label : $label . ' fehlgeschlagen';
    }

    private function recoverStreamedContentOrFallbackV2(array $executionLog): string {
        if (empty($executionLog)) {
            return "Levi hat die Aufgabe bearbeitet, aber es gab keine Tool-Aufrufe. "
                . "Bitte wiederhole die Anfrage oder sei spezifischer.";
        }

        $successCount = count(array_filter($executionLog, fn($e) => $e['result_success'] ?? false));
        $totalCount = count($executionLog);

        return "✅ Ich habe {$successCount}/{$totalCount} Operationen ausgeführt. "
            . "Die Ergebnisse sind in der Tool-Ausgabe sichtbar.";
    }
}
