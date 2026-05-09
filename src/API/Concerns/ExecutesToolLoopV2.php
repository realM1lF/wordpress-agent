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
 * - Fallback to specialized tools on generic tool failure
 * - Loop-level fallback to V1 after repeated failures
 */
trait ExecutesToolLoopV2
{
    /**
     * Maximum consecutive failed iterations before falling back to V1.
     */
    private const V2_FALLBACK_THRESHOLD = 3;

    /**
     * Mapping from generic tool names to suggested specialized alternatives.
     * Used to add hints when a generic tool fails.
     */
    private const GENERIC_TO_SPECIALIZED_MAP = [
        "read" => [
            "post" => ["get_post", "get_posts"],
            "page" => ["get_page", "get_pages"],
            "option" => ["get_options"],
            "user" => ["get_users"],
            "media" => ["get_media"],
            "file" => ["read_plugin_file", "read_theme_file", "read_error_log"],
        ],
        "write" => [
            "post" => ["create_post", "update_post"],
            "page" => ["create_page"],
            "file" => ["write_plugin_file", "write_theme_file"],
            "media" => ["upload_media"],
        ],
        "edit" => [
            "file" => ["patch_plugin_file", "patch_theme_file"],
            "post" => ["update_post"],
            "page" => ["update_post"],
            "media" => ["update_media"],
        ],
        "list" => [
            "post" => ["get_posts"],
            "page" => ["get_pages"],
            "plugin_file" => ["list_plugin_files"],
            "theme_file" => ["list_theme_files"],
            "media" => ["get_media"],
        ],
        "grep" => [
            "plugin_file" => ["grep_plugin_files"],
            "theme_file" => ["grep_theme_files"],
        ],
        "execute" => [
            "_default" => ["execute_wp_code"],
        ],
        "install" => [
            "_default" => ["install_plugin"],
        ],
        "manage" => [
            "post_meta" => ["manage_post_meta"],
            "taxonomy" => ["manage_taxonomy"],
            "menu" => ["manage_menu"],
            "user" => ["manage_user"],
            "option" => ["update_option"],
        ],
        "fetch" => [
            "_default" => ["http_fetch"],
        ],
        "health_check" => [
            "_default" => ["check_plugin_health"],
        ],
        "manage_woo" => [
            "_default" => [
                "manage_woocommerce",
                "create_product",
                "update_product",
            ],
        ],
        "manage_elementor" => [
            "_default" => [
                "elementor_manage",
                "elementor_build",
                "elementor_read",
            ],
        ],
    ];

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
        bool $webSearch = false,
    ): void {
        $orchestrator = new ToolOrchestrator(
            maxIterations: 15,
            maxWriteCalls: 10,
        );

        $toolResults = [];
        $iteration = 0;
        $consecutiveFailureCount = 0;

        // Initial state: OBSERVING (we've already received the first response)
        $orchestrator->transitionTo(AgentState::OBSERVING);

        while ($iteration < 15) {
            $toolCalls = $messageData["tool_calls"] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            $messages[] = $messageData;

            // First iteration: show PLANNING state with plan summary
            if ($iteration === 1) {
                $orchestrator->transitionTo(AgentState::PLANNING);
                $plannedTools = array_values(
                    array_unique(
                        array_map(
                            fn($tc) => $tc["function"]["name"] ?? "",
                            $toolCalls,
                        ),
                    ),
                );
                $this->emitSSE("state", [
                    "state" => AgentState::PLANNING->value,
                    "label" => AgentState::PLANNING->label(),
                ]);
                $this->emitSSE("plan", [
                    "message" => "Levi plant folgende Aktionen...",
                    "tools" => $plannedTools,
                    "count" => count($toolCalls),
                ]);
                // Brief pause so frontend can render the planning UI
                usleep(300000);
            }

            // Transition to EXECUTING
            $orchestrator->transitionTo(AgentState::EXECUTING);
            $this->emitSSE("state", [
                "state" => AgentState::EXECUTING->value,
                "label" => AgentState::EXECUTING->label(),
            ]);

            foreach ($toolCalls as $toolCall) {
                $functionName = trim($toolCall["function"]["name"] ?? "");
                $rawArgs = $toolCall["function"]["arguments"] ?? "{}";
                $functionArgs = json_decode($rawArgs, true);
                if (!is_array($functionArgs)) {
                    $functionArgs = [];
                }
                $toolCallId = $toolCall["id"] ?? "";

                // Resolve tool
                $tool = $this->toolRegistry->get($functionName);
                if ($tool === null && $this->genericRegistry !== null) {
                    $tool = $this->genericRegistry->get($functionName);
                }

                if ($tool === null) {
                    $result = [
                        "success" => false,
                        "error" => "Tool '{$functionName}' nicht gefunden.",
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => json_encode(
                            $result,
                            JSON_UNESCAPED_UNICODE,
                        ),
                    ];
                    continue;
                }

                // Pre-execute guard (ToolGuard integration)
                $preHook = function (string $name, array $args) {
                    if (!isset($this->toolGuard)) {
                        return null;
                    }
                    $guard = $this->toolGuard->evaluate($name, $args);
                    if (
                        $guard["verdict"] ===
                        \Levi\Agent\AI\Tools\ToolGuard::BLOCK
                    ) {
                        return [
                            "blocked" => true,
                            "reason" => $guard["reason"] ?? "",
                        ];
                    }
                    return null;
                };

                // Emit progress
                $this->emitSSE("progress", [
                    "message" => $this->getToolProgressLabelV2($functionName),
                    "tool" => $functionName,
                    "iteration" => $iteration,
                    "phase" => "start",
                ]);

                // Execute via orchestrator
                $execResult = $orchestrator->executeToolCall(
                    $tool,
                    $functionArgs,
                    $preHook,
                );
                $result = $execResult["result"];
                $toolResults[] = $result;

                // Tool-level fallback: add specialized tool hint on generic tool failure
                if (
                    !$execResult["success"] &&
                    $this->genericRegistry !== null
                ) {
                    $fallbackHint = $this->buildSpecializedFallbackHint(
                        $functionName,
                        $functionArgs,
                    );
                    if ($fallbackHint !== null) {
                        $result["fallback_hint"] = $fallbackHint;
                        $toolResults[count($toolResults) - 1] = $result;
                    }
                    $consecutiveFailureCount++;
                } else {
                    $consecutiveFailureCount = 0;
                }

                // Emit completion
                $this->emitSSE("progress", [
                    "message" => $this->getToolProgressLabelV2(
                        $functionName,
                        $execResult["success"],
                    ),
                    "tool" => $functionName,
                    "iteration" => $iteration,
                    "success" => $execResult["success"],
                    "phase" => $execResult["success"] ? "done" : "failed",
                ]);

                // Add tool result to messages
                $messages[] = [
                    "role" => "tool",
                    "tool_call_id" => $toolCallId,
                    "content" => $this->compactToolResultForModel($result),
                    "_levi_iteration" => $iteration,
                    "_levi_tool" => $functionName,
                ];
            }

            // Loop-level fallback: if too many consecutive failures, switch to V1
            if ($consecutiveFailureCount >= self::V2_FALLBACK_THRESHOLD) {
                error_log(
                    "Levi V2: {$consecutiveFailureCount} consecutive failures — falling back to V1 specialized tools",
                );
                $this->emitSSE("fallback_v1", [
                    "reason" =>
                        "Zu viele aufeinanderfolgende Fehler mit generischen Tools",
                    "failed_tools" => array_values(
                        array_unique(
                            array_map(
                                fn($r) => $r["tool"] ?? "",
                                array_slice(
                                    $orchestrator->getExecutionLog(),
                                    -$consecutiveFailureCount,
                                ),
                            ),
                        ),
                    ),
                ]);
                $this->useGenericTools = false;
                $this->handleToolCallsStreaming(
                    $messageData,
                    $messages,
                    $sessionId,
                    $userId,
                    $latestUserMessage,
                    $heartbeat,
                    $webSearch,
                );
                return;
            }

            // Consolidated post-execution instruction (replaces 15+ injections)
            $recentResults = array_slice($toolResults, -count($toolCalls));
            $postInstruction = $orchestrator->buildPostExecutionInstruction(
                $recentResults,
            );
            if ($postInstruction !== "") {
                $messages[] = [
                    "role" => "system",
                    "content" => $postInstruction,
                ];
            }

            if (connection_aborted()) {
                error_log("Levi V2: client disconnected during tool loop");
                return;
            }

            // Transition to REASONING before next AI call
            $orchestrator->transitionTo(AgentState::REASONING);
            $this->emitSSE("state", [
                "state" => AgentState::REASONING->value,
                "label" => AgentState::REASONING->label(),
            ]);

            // Get next response
            $loopMessages = $this->compactMessagesForToolLoop(
                $messages,
                $iteration,
            );
            $nextResponse = $this->streamContinuation(
                $loopMessages,
                $this->getToolDefs(),
                $webSearch,
            );

            if (is_wp_error($nextResponse)) {
                $this->emitSSE("error", [
                    "message" => $nextResponse->get_error_message(),
                    "session_id" => $sessionId,
                    "tools_used" => array_values(
                        array_unique(
                            array_map(
                                fn($r) => $r["tool"] ?? "",
                                $orchestrator->getExecutionLog(),
                            ),
                        ),
                    ),
                ]);
                return;
            }

            $messageData = $nextResponse["choices"][0]["message"] ?? [];

            // No more tool calls → done
            if (empty($messageData["tool_calls"])) {
                // Transition to VERIFYING briefly
                $orchestrator->transitionTo(AgentState::VERIFYING);
                $this->emitSSE("state", [
                    "state" => AgentState::VERIFYING->value,
                    "label" => AgentState::VERIFYING->label(),
                ]);

                $finalMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($messageData["content"] ?? ""),
                );

                if ($finalMessage === "") {
                    $finalMessage = $this->recoverStreamedContentOrFallbackV2(
                        $orchestrator->getExecutionLog(),
                    );
                }

                if ($this->wasResponseTruncated($nextResponse)) {
                    $finalMessage = $this->appendTruncationHint($finalMessage);
                }

                $this->conversationRepo->saveMessage(
                    $sessionId,
                    $userId,
                    "assistant",
                    $finalMessage,
                );

                $orchestrator->transitionTo(AgentState::DONE);
                $this->emitSSE("state", [
                    "state" => AgentState::DONE->value,
                    "label" => AgentState::DONE->label(),
                ]);

                $donePayload = [
                    "session_id" => $sessionId,
                    "message" => $finalMessage,
                    "model" => $nextResponse["model"] ?? null,
                    "tools_used" => array_values(
                        array_unique(
                            array_map(
                                fn($r) => $r["tool"] ?? "",
                                $orchestrator->getExecutionLog(),
                            ),
                        ),
                    ),
                    "truncated" => $this->wasResponseTruncated($nextResponse),
                    "state_history" => $orchestrator->getStateHistory(),
                ];
                $donePayload["usage"] = $this->usageAccumulator ?? [];
                $this->emitSSE("done", $donePayload);
                $this->flushUsage($sessionId, $userId);
                return;
            }
        }

        // Max iterations reached
        $orchestrator->transitionTo(AgentState::ERROR);
        $this->emitSSE("state", [
            "state" => AgentState::ERROR->value,
            "label" => AgentState::ERROR->label(),
        ]);

        $finalMessage = $this->recoverStreamedContentOrFallbackV2(
            $orchestrator->getExecutionLog(),
        );
        $this->conversationRepo->saveMessage(
            $sessionId,
            $userId,
            "assistant",
            $finalMessage,
        );

        $this->emitSSE("done", [
            "session_id" => $sessionId,
            "message" => $finalMessage,
            "tools_used" => array_values(
                array_unique(
                    array_map(
                        fn($r) => $r["tool"] ?? "",
                        $orchestrator->getExecutionLog(),
                    ),
                ),
            ),
            "state_history" => $orchestrator->getStateHistory(),
            "usage" => $this->usageAccumulator ?? [],
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
        bool $webSearch = false,
    ): \WP_REST_Response {
        $orchestrator = new ToolOrchestrator(
            maxIterations: 15,
            maxWriteCalls: 10,
        );

        $toolResults = [];
        $iteration = 0;
        $consecutiveFailureCount = 0;

        while ($iteration < 15) {
            $toolCalls = $messageData["tool_calls"] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            $messages[] = $messageData;

            foreach ($toolCalls as $toolCall) {
                $functionName = trim($toolCall["function"]["name"] ?? "");
                $rawArgs = $toolCall["function"]["arguments"] ?? "{}";
                $functionArgs = json_decode($rawArgs, true);
                if (!is_array($functionArgs)) {
                    $functionArgs = [];
                }
                $toolCallId = $toolCall["id"] ?? "";

                $tool = $this->toolRegistry->get($functionName);
                if ($tool === null && $this->genericRegistry !== null) {
                    $tool = $this->genericRegistry->get($functionName);
                }

                if ($tool === null) {
                    $result = [
                        "success" => false,
                        "error" => "Tool '{$functionName}' nicht gefunden.",
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => json_encode(
                            $result,
                            JSON_UNESCAPED_UNICODE,
                        ),
                    ];
                    continue;
                }

                $preHook = function (string $name, array $args) {
                    if (!isset($this->toolGuard)) {
                        return null;
                    }
                    $guard = $this->toolGuard->evaluate($name, $args);
                    if (
                        $guard["verdict"] ===
                        \Levi\Agent\AI\Tools\ToolGuard::BLOCK
                    ) {
                        return [
                            "blocked" => true,
                            "reason" => $guard["reason"] ?? "",
                        ];
                    }
                    return null;
                };

                $execResult = $orchestrator->executeToolCall(
                    $tool,
                    $functionArgs,
                    $preHook,
                );
                $result = $execResult["result"];
                $toolResults[] = $result;

                // Tool-level fallback: add specialized tool hint on generic tool failure
                if (
                    !$execResult["success"] &&
                    $this->genericRegistry !== null
                ) {
                    $fallbackHint = $this->buildSpecializedFallbackHint(
                        $functionName,
                        $functionArgs,
                    );
                    if ($fallbackHint !== null) {
                        $result["fallback_hint"] = $fallbackHint;
                        $toolResults[count($toolResults) - 1] = $result;
                    }
                    $consecutiveFailureCount++;
                } else {
                    $consecutiveFailureCount = 0;
                }

                $messages[] = [
                    "role" => "tool",
                    "tool_call_id" => $toolCallId,
                    "content" => $this->compactToolResultForModel($result),
                    "_levi_iteration" => $iteration,
                    "_levi_tool" => $functionName,
                ];
            }

            // Loop-level fallback: if too many consecutive failures, switch to V1
            if ($consecutiveFailureCount >= self::V2_FALLBACK_THRESHOLD) {
                error_log(
                    "Levi V2 (non-streaming): {$consecutiveFailureCount} consecutive failures — falling back to V1",
                );
                $this->useGenericTools = false;
                return $this->handleToolCalls(
                    $messageData,
                    $messages,
                    $sessionId,
                    $userId,
                    $latestUserMessage,
                    $webSearch,
                );
            }

            $recentResults = array_slice($toolResults, -count($toolCalls));
            $postInstruction = $orchestrator->buildPostExecutionInstruction(
                $recentResults,
            );
            if ($postInstruction !== "") {
                $messages[] = [
                    "role" => "system",
                    "content" => $postInstruction,
                ];
            }

            $loopMessages = $this->compactMessagesForToolLoop(
                $messages,
                $iteration,
            );
            $nextResponse = $this->chatWithTracking(
                $loopMessages,
                $this->getToolDefs(),
                null,
                $webSearch,
            );

            if (is_wp_error($nextResponse)) {
                return new \WP_REST_Response(
                    [
                        "error" => $nextResponse->get_error_message(),
                        "session_id" => $sessionId,
                        "tools_used" => array_values(
                            array_unique(
                                array_map(
                                    fn($r) => $r["tool"] ?? "",
                                    $orchestrator->getExecutionLog(),
                                ),
                            ),
                        ),
                    ],
                    500,
                );
            }

            $messageData = $nextResponse["choices"][0]["message"] ?? [];

            if (empty($messageData["tool_calls"])) {
                $finalMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($messageData["content"] ?? ""),
                );

                if ($finalMessage === "") {
                    $finalMessage = $this->recoverStreamedContentOrFallbackV2(
                        $orchestrator->getExecutionLog(),
                    );
                }

                $this->conversationRepo->saveMessage(
                    $sessionId,
                    $userId,
                    "assistant",
                    $finalMessage,
                );

                return new \WP_REST_Response(
                    [
                        "session_id" => $sessionId,
                        "message" => $finalMessage,
                        "model" => $nextResponse["model"] ?? null,
                        "tools_used" => array_values(
                            array_unique(
                                array_map(
                                    fn($r) => $r["tool"] ?? "",
                                    $orchestrator->getExecutionLog(),
                                ),
                            ),
                        ),
                        "truncated" => $this->wasResponseTruncated(
                            $nextResponse,
                        ),
                        "state_history" => $orchestrator->getStateHistory(),
                        "usage" => $this->usageAccumulator ?? [],
                    ],
                    200,
                );
            }
        }

        $finalMessage = $this->recoverStreamedContentOrFallbackV2(
            $orchestrator->getExecutionLog(),
        );
        $this->conversationRepo->saveMessage(
            $sessionId,
            $userId,
            "assistant",
            $finalMessage,
        );

        return new \WP_REST_Response(
            [
                "session_id" => $sessionId,
                "message" => $finalMessage,
                "tools_used" => array_values(
                    array_unique(
                        array_map(
                            fn($r) => $r["tool"] ?? "",
                            $orchestrator->getExecutionLog(),
                        ),
                    ),
                ),
                "state_history" => $orchestrator->getStateHistory(),
                "usage" => $this->usageAccumulator ?? [],
            ],
            200,
        );
    }

    // -----------------------------------------------------------------------
    // V2 helpers
    // -----------------------------------------------------------------------

    private function getToolProgressLabelV2(
        string $toolName,
        ?bool $success = null,
    ): string {
        $labels = [
            "read" => "Lesen",
            "write" => "Schreiben",
            "edit" => "Editieren",
            "list" => "Auflisten",
            "grep" => "Suchen",
            "execute" => "Ausführen",
            "install" => "Installieren",
            "manage" => "Verwalten",
            "manage_woo" => "WooCommerce",
            "manage_elementor" => "Elementor",
            "fetch" => "HTTP-Abruf",
            "health_check" => "Health-Check",
        ];

        $label = $labels[$toolName] ?? $toolName;

        if ($success === null) {
            return $label . "...";
        }
        return $success ? $label : $label . " fehlgeschlagen";
    }

    private function recoverStreamedContentOrFallbackV2(
        array $executionLog,
    ): string {
        if (empty($executionLog)) {
            return "Levi hat die Aufgabe bearbeitet, aber es gab keine Tool-Aufrufe. " .
                "Bitte wiederhole die Anfrage oder sei spezifischer.";
        }

        $successCount = count(
            array_filter(
                $executionLog,
                fn($e) => $e["result_success"] ?? false,
            ),
        );
        $totalCount = count($executionLog);

        return "✅ Ich habe {$successCount}/{$totalCount} Operationen ausgeführt. " .
            "Die Ergebnisse sind in der Tool-Ausgabe sichtbar.";
    }

    /**
     * Build a hint suggesting specialized tool alternatives when a generic tool fails.
     * Returns null if no mapping exists or the tool is not from the generic registry.
     */
    private function buildSpecializedFallbackHint(
        string $genericTool,
        array $params,
    ): ?string {
        if (!isset(self::GENERIC_TO_SPECIALIZED_MAP[$genericTool])) {
            return null;
        }

        $mapping = self::GENERIC_TO_SPECIALIZED_MAP[$genericTool];
        $type = $params["type"] ?? "_default";

        $candidates = $mapping[$type] ?? ($mapping["_default"] ?? null);
        if ($candidates === null) {
            return null;
        }

        // Verify that at least one candidate exists in the old registry
        $available = [];
        foreach ($candidates as $candidate) {
            if ($this->toolRegistry->get($candidate) !== null) {
                $available[] = $candidate;
            }
        }

        if (empty($available)) {
            return null;
        }

        $hint = "Hinweis: Das generische Tool '{$genericTool}' ist fehlgeschlagen. ";
        if (count($available) === 1) {
            $hint .= "Versuche stattdessen das spezialisierte Tool '{$available[0]}'.";
        } else {
            $hint .=
                "Versuche stattdessen eines dieser spezialisierten Tools: " .
                implode(", ", $available) .
                ".";
        }

        return $hint;
    }
}
