<?php

namespace Levi\Agent\API;

use Levi\Agent\AI\AIClientInterface;
use Levi\Agent\AI\Tools\AgentState;
use Levi\Agent\AI\Tools\GenericRegistry;
use Levi\Agent\AI\Tools\Registry;
use Levi\Agent\AI\Tools\ToolGuard;
use Levi\Agent\AI\Tools\ToolOrchestrator;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Database\ConversationRepository;
use WP_REST_Response;

class ToolLoopEngine
{
    private ChatController $controller;
    private MessagePipeline $messagePipeline;
    private SettingsPage $settings;
    private Registry $toolRegistry;
    private ?GenericRegistry $genericRegistry;
    private ToolGuard $toolGuard;
    private ConversationRepository $conversationRepo;
    private AIClientInterface $aiClient;

    private const V2_FALLBACK_THRESHOLD = 3;

    private static array $readOnlyTools = [
        "get_pages",
        "get_posts",
        "get_post",
        "get_plugins",
        "get_themes",
        "get_options",
        "get_users",
        "get_media",
        "read_plugin_file",
        "list_plugin_files",
        "read_theme_file",
        "list_theme_files",
        "search_posts",
        "discover_rest_api",
        "discover_content_types",
        "read_error_log",
        "http_fetch",
    ];

    private const BATCHABLE_READ_TOOLS = [
        "read_plugin_file",
        "read_theme_file",
        "list_plugin_files",
        "list_theme_files",
        "grep_plugin_files",
        "grep_theme_files",
        "get_posts",
        "get_post",
        "get_pages",
        "get_plugins",
        "get_options",
        "get_users",
        "get_media",
        "read_error_log",
        "check_plugin_health",
        "search_tools",
        "discover_content_types",
        "discover_rest_api",
    ];

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

    public function __construct(
        ChatController $controller,
        MessagePipeline $messagePipeline,
        SettingsPage $settings,
        Registry $toolRegistry,
        ?GenericRegistry $genericRegistry,
        ToolGuard $toolGuard,
        ConversationRepository $conversationRepo,
        AIClientInterface $aiClient,
    ) {
        $this->controller = $controller;
        $this->messagePipeline = $messagePipeline;
        $this->settings = $settings;
        $this->toolRegistry = $toolRegistry;
        $this->genericRegistry = $genericRegistry;
        $this->toolGuard = $toolGuard;
        $this->conversationRepo = $conversationRepo;
        $this->aiClient = $aiClient;
    }

    // =====================================================================
    // V1 Tool Loop (Streaming)
    // =====================================================================

    public function handleToolCallsStreaming(
        array $messageData,
        array $messages,
        string $sessionId,
        int $userId,
        string $latestUserMessage,
        callable $heartbeat,
        bool $webSearch = false,
    ): void {
        $toolResults = [];
        $runtimeSettings = $this->settings->getSettings();
        $maxIterations = max(
            1,
            (int) ($runtimeSettings["max_tool_iterations"] ?? 25),
        );
        $taskIntent = $this->inferTaskIntent($latestUserMessage, $messages);
        $iteration = 0;
        $mutationNudgeCount = 0;
        $completionGateCount = 0;

        while ($iteration < $maxIterations) {
            $toolCalls = $messageData["tool_calls"] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            $messages[] = $messageData;

            [$readBatch, $nonReadCalls] = $this->partitionToolCalls($toolCalls);
            $isBatchableReadOnly =
                !empty($readBatch) &&
                empty($nonReadCalls) &&
                count($readBatch) > 1;
            if ($isBatchableReadOnly) {
                $this->controller->emitSSE("activity_tool", [
                    "message" => count($readBatch) . " Dateien lesen...",
                    "tool" => "batch_read",
                    "iteration" => $iteration,
                    "phase" => "start",
                ]);
            }

            foreach ($toolCalls as $toolCall) {
                $functionName = trim($toolCall["function"]["name"] ?? "");
                $rawArgs = $toolCall["function"]["arguments"] ?? "{}";
                $functionArgs = json_decode($rawArgs, true);
                if (!is_array($functionArgs)) {
                    $functionArgs = [];
                }
                $functionArgs = $this->normalizeToolArgumentsForIntent(
                    $functionName,
                    $functionArgs,
                    $latestUserMessage,
                );
                $toolCallId = $toolCall["id"] ?? "";

                $planValidation = $this->validateToolCall(
                    $functionName,
                    $functionArgs,
                );
                if (!($planValidation["allow"] ?? false)) {
                    $result = [
                        "success" => false,
                        "needs_replan" => true,
                        "error" =>
                            (string) ($planValidation["reason"] ??
                                "Tool passt nicht zum internen Ausfuehrungsplan."),
                        "tool" => $functionName,
                    ];
                    $this->logToolExecution(
                        $sessionId,
                        $userId,
                        $functionName,
                        $functionArgs,
                        $result,
                    );
                    $toolResults[] = [
                        "tool" => $functionName,
                        "args_key" => $this->buildToolArgsKey(
                            $functionName,
                            $functionArgs,
                        ),
                        "fingerprint" => $this->buildToolCallFingerprint(
                            $functionName,
                            $functionArgs,
                        ),
                        "result" => $result,
                        "seq" => count($toolResults),
                        "iteration" => $iteration,
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => $this->controller->compactToolResultForModel(
                            $result,
                        ),
                        "_levi_iteration" => $iteration,
                        "_levi_tool" => $functionName,
                    ];
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "Tool-Call blockiert: Er passt nicht zum internen Plan (Domain/Intent). " .
                            "Plane die naechsten Schritte neu und verwende nur passende Tools fuer diese Aufgabe.",
                    ];
                    continue;
                }

                $guardResult = $this->toolGuard->evaluate(
                    $functionName,
                    $functionArgs,
                );

                error_log(
                    sprintf(
                        "Levi ToolGuard [streaming]: tool=%s verdict=%s reason=%s",
                        $functionName,
                        $guardResult["verdict"] ?? "null",
                        $guardResult["reason"] ?? "-",
                    ),
                );

                if ($guardResult["verdict"] === ToolGuard::BLOCK) {
                    $result = [
                        "success" => false,
                        "blocked" => true,
                        "error" =>
                            $guardResult["reason"] ?? "Tool-Call blockiert.",
                        "tool" => $functionName,
                    ];
                    $this->logToolExecution(
                        $sessionId,
                        $userId,
                        $functionName,
                        $functionArgs,
                        $result,
                    );
                    $toolResults[] = [
                        "tool" => $functionName,
                        "args_key" => $this->buildToolArgsKey(
                            $functionName,
                            $functionArgs,
                        ),
                        "fingerprint" => $this->buildToolCallFingerprint(
                            $functionName,
                            $functionArgs,
                        ),
                        "result" => $result,
                        "seq" => count($toolResults),
                        "iteration" => $iteration,
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => $this->controller->compactToolResultForModel(
                            $result,
                        ),
                        "_levi_iteration" => $iteration,
                        "_levi_tool" => $functionName,
                    ];
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "Tool-Call blockiert: " .
                            ($guardResult["reason"] ?? "") .
                            " Waehle einen anderen Ansatz.",
                    ];
                    continue;
                }

                $toolContext = $this->buildToolContext(
                    $functionName,
                    $functionArgs,
                );

                if (!$this->toolRegistry->get($functionName)) {
                    $result = [
                        "success" => false,
                        "error" => "Tool '{$functionName}' nicht gefunden.",
                        "tool" => $functionName,
                    ];
                    $this->logToolExecution(
                        $sessionId,
                        $userId,
                        $functionName,
                        $functionArgs,
                        $result,
                    );
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => $this->controller->compactToolResultForModel(
                            $result,
                        ),
                        "_levi_iteration" => $iteration,
                        "_levi_tool" => $functionName,
                    ];
                    continue;
                }

                if (!$isBatchableReadOnly) {
                    $this->controller->emitSSE("activity_tool", [
                        "message" => $this->getToolProgressLabel(
                            $functionName,
                            "start",
                        ),
                        "tool" => $functionName,
                        "iteration" => $iteration,
                        "phase" => "start",
                        "context" => $toolContext,
                    ]);
                }

                $loopNudge = $this->detectToolLoop(
                    $toolResults,
                    $functionName,
                    $functionArgs,
                );
                if ($loopNudge !== null) {
                    error_log(
                        "Levi: loop detection triggered for " . $functionName,
                    );
                    $result = [
                        "success" => false,
                        "loop_detected" => true,
                        "error" =>
                            "Wiederholter Aufruf erkannt. Waehle einen anderen Ansatz.",
                        "tool" => $functionName,
                    ];
                    $this->logToolExecution(
                        $sessionId,
                        $userId,
                        $functionName,
                        $functionArgs,
                        $result,
                    );
                    $toolResults[] = [
                        "tool" => $functionName,
                        "args_key" => $this->buildToolArgsKey(
                            $functionName,
                            $functionArgs,
                        ),
                        "fingerprint" => $this->buildToolCallFingerprint(
                            $functionName,
                            $functionArgs,
                        ),
                        "result" => $result,
                        "seq" => count($toolResults),
                        "iteration" => $iteration,
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => $this->controller->compactToolResultForModel(
                            $result,
                        ),
                        "_levi_iteration" => $iteration,
                        "_levi_tool" => $functionName,
                    ];
                    $messages[] = [
                        "role" => "system",
                        "content" => $loopNudge,
                    ];

                    if (!$isBatchableReadOnly) {
                        $this->controller->emitSSE("activity_tool", [
                            "message" => $this->getToolProgressLabel(
                                $functionName,
                                "failed",
                            ),
                            "tool" => $functionName,
                            "iteration" => $iteration,
                            "phase" => "failed",
                            "context" => $toolContext,
                            "result_summary" => "Loop erkannt",
                        ]);
                    }
                    continue;
                }

                $toolStartTime = hrtime(true);
                $result = $this->executeToolWithAutopaging(
                    $functionName,
                    $functionArgs,
                    $latestUserMessage,
                );
                $toolDurationMs =
                    (int) ((hrtime(true) - $toolStartTime) / 1_000_000);

                if (
                    $functionName === "search_tools" &&
                    !empty($result["tools"])
                ) {
                    $this->controller->addDiscoveredTools(
                        array_column($result["tools"], "name"),
                    );
                }

                $this->trackOwnedPluginFromToolResult(
                    $functionName,
                    $functionArgs,
                    $result,
                );
                $this->logToolExecution(
                    $sessionId,
                    $userId,
                    $functionName,
                    $functionArgs,
                    $result,
                );
                $this->controller->setWorkingSetIteration($iteration);
                $this->controller->trackFileAccessFromToolResult(
                    $functionName,
                    $functionArgs,
                    $result,
                );
                $toolResults[] = [
                    "tool" => $functionName,
                    "args_key" => $this->buildToolArgsKey(
                        $functionName,
                        $functionArgs,
                    ),
                    "fingerprint" => $this->buildToolCallFingerprint(
                        $functionName,
                        $functionArgs,
                    ),
                    "result" => $result,
                    "seq" => count($toolResults),
                    "iteration" => $iteration,
                ];

                $toolPhase = $result["success"] ?? false ? "done" : "failed";
                if (!$isBatchableReadOnly) {
                    $this->controller->emitSSE("activity_tool", [
                        "message" => $this->getToolProgressLabel(
                            $functionName,
                            $toolPhase,
                        ),
                        "tool" => $functionName,
                        "iteration" => $iteration,
                        "success" => $result["success"] ?? false,
                        "phase" => $toolPhase,
                        "context" => $toolContext,
                        "result_summary" => $this->buildToolResultSummary(
                            $functionName,
                            $result,
                        ),
                        "duration_ms" => $toolDurationMs,
                    ]);
                }

                $toolContent = $this->controller->compactToolResultForModel(
                    $result,
                );
                $toolContent .= $this->buildWriteBudgetWarning($functionName);

                $messages[] = [
                    "role" => "tool",
                    "tool_call_id" => $toolCallId,
                    "content" => $toolContent,
                    "_levi_iteration" => $iteration,
                    "_levi_tool" => $functionName,
                ];
            }

            $autoList = $this->controller->injectAutoListOnMissingFile(
                $toolCalls,
                $toolResults,
            );
            foreach ($autoList as $al) {
                $messages[] = $al;
            }

            $postWriteMessages = $this->controller->injectPostWriteValidation(
                $toolCalls,
                $toolResults,
            );
            if (!empty($postWriteMessages)) {
                $this->controller->emitSSE("activity_update", [
                    "text" => "Validierung...",
                ]);
                foreach ($postWriteMessages as $pwm) {
                    $messages[] = $pwm;
                }
            }

            $patchVerify = $this->controller->injectPostPatchVerification(
                $toolCalls,
                $toolResults,
            );
            foreach ($patchVerify as $pv) {
                $messages[] = $pv;
            }

            $scaffoldNudge = $this->controller->injectPostCreatePluginNudge(
                $toolCalls,
                $toolResults,
            );
            foreach ($scaffoldNudge as $nudge) {
                $messages[] = $nudge;
            }

            $cssNudge = $this->controller->injectPostCSSWriteNudge(
                $toolCalls,
                $toolResults,
            );
            foreach ($cssNudge as $nudge) {
                $messages[] = $nudge;
            }

            $smokeTest = $this->controller->injectPostPluginSmokeTest(
                $toolCalls,
                $toolResults,
            );
            if (!empty($smokeTest)) {
                $this->controller->emitSSE("activity_update", [
                    "text" => "Smoke-Test...",
                ]);
                foreach ($smokeTest as $st) {
                    $messages[] = $st;
                }
            }

            $envWarnings = $this->controller->injectPostWriteEnvironmentWarnings(
                $toolCalls,
                $toolResults,
            );
            foreach ($envWarnings as $ew) {
                $messages[] = $ew;
            }

            $integrationCheck = $this->controller->injectPostWriteIntegrationCheck(
                $toolCalls,
                $toolResults,
            );
            if (!empty($integrationCheck)) {
                $this->controller->emitSSE("activity_update", [
                    "text" => "Datei-Integration pruefen...",
                ]);
                foreach ($integrationCheck as $ic) {
                    $messages[] = $ic;
                }
            }

            $depWarnings = $this->controller->injectPostWriteReverseDependencyWarnings(
                $toolCalls,
                $toolResults,
            );
            foreach ($depWarnings as $dw) {
                $messages[] = $dw;
            }

            $refCheck = $this->controller->injectPostWriteReferenceCheck(
                $toolCalls,
                $toolResults,
            );
            foreach ($refCheck as $rc) {
                $messages[] = $rc;
            }

            $codeTagWarnings = $this->controller->injectCodeTagWarnings(
                $toolResults,
            );
            if (!empty($codeTagWarnings)) {
                $this->controller->emitSSE("activity_update", [
                    "text" => "Code-Tags pruefen...",
                ]);
                foreach ($codeTagWarnings as $ctw) {
                    $messages[] = $ctw;
                }
            }

            $toolMismatch = $this->controller->injectToolMismatchCorrection(
                $latestUserMessage,
                $toolResults,
            );
            if (!empty($toolMismatch)) {
                $this->controller->emitSSE("activity_update", [
                    "text" => "Tool-Korrektur...",
                ]);
                foreach ($toolMismatch as $tm) {
                    $messages[] = $tm;
                }
            }

            if ($iteration >= 2) {
                $wsSummary = $this->controller->getWorkingSetSummary();
                if ($wsSummary !== "") {
                    $messages[] = ["role" => "system", "content" => $wsSummary];
                }
            }

            if (connection_aborted()) {
                error_log("Levi: client disconnected during tool loop");
                return;
            }

            $this->controller->emitSSE("activity_update", [
                "text" => "Levi denkt nach...",
            ]);

            $loopMessages = $this->controller->compactMessagesForToolLoop(
                $messages,
                $iteration,
            );
            $nextResponse = $this->controller->streamContinuation(
                $loopMessages,
                $this->controller->getToolDefs(),
                $webSearch,
            );
            if (is_wp_error($nextResponse)) {
                $this->controller->emitSSE("error", [
                    "message" => $nextResponse->get_error_message(),
                    "session_id" => $sessionId,
                    "tools_used" => array_values(
                        array_unique(
                            array_map(fn($r) => $r["tool"], $toolResults),
                        ),
                    ),
                ]);
                return;
            }

            $messageData = $nextResponse["choices"][0]["message"] ?? [];
            if (empty($messageData["tool_calls"])) {
                $completionIssues = $this->controller->checkWriteCompleteness(
                    $toolResults,
                );
                if ($completionIssues !== null && $completionGateCount < 2) {
                    $completionGateCount++;
                    $this->controller->emitSSE("activity_tool", [
                        "message" =>
                            "Completion-Check: Prüfe Datei-Vollständigkeit...",
                        "tool" => "completion_gate",
                        "iteration" => $iteration,
                        "phase" => "start",
                    ]);
                    $assistantHistoryEntry = [
                        "role" => "assistant",
                        "content" => $messageData["content"] ?? "",
                    ];
                    if (!empty($messageData["reasoning_content"])) {
                        $assistantHistoryEntry["reasoning_content"] =
                            $messageData["reasoning_content"];
                    }
                    $messages[] = $assistantHistoryEntry;
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "[SYSTEM – COMPLETION CHECK FAILED]\n" .
                            $completionIssues .
                            "\n\nDu darfst dem Nutzer NICHT 'fertig' melden bevor diese Probleme behoben sind. " .
                            "Fuehre die noetige(n) write_plugin_file / patch_plugin_file Aktion(en) jetzt aus.",
                    ];
                    $gateResponse = $this->controller->chatWithTracking(
                        $messages,
                        $this->controller->getToolDefs(),
                        $heartbeat,
                        $webSearch,
                    );
                    if (!is_wp_error($gateResponse)) {
                        $messageData =
                            $gateResponse["choices"][0]["message"] ?? [];
                        if (!empty($messageData["tool_calls"])) {
                            continue;
                        }
                    }
                }

                if ($mutationNudgeCount < 1) {
                    $gateResult = $this->enforceMutationGate(
                        $messages,
                        $messageData,
                        $toolResults,
                        $latestUserMessage,
                        $webSearch,
                        $heartbeat,
                    );
                    if ($gateResult !== null) {
                        $mutationNudgeCount++;
                        $messageData = $gateResult;
                        continue;
                    }
                    $mutationNudgeCount++;
                }

                $finalMessage = $this->controller->sanitizeAssistantMessageContent(
                    (string) ($messageData["content"] ?? ""),
                );

                if ($finalMessage === "") {
                    error_log(
                        "Levi: empty AI response after tool loop, nudging for summary",
                    );
                    $assistantHistoryEntry = [
                        "role" => "assistant",
                        "content" => $messageData["content"] ?? "",
                    ];
                    if (!empty($messageData["reasoning_content"])) {
                        $assistantHistoryEntry["reasoning_content"] =
                            $messageData["reasoning_content"];
                    }
                    $messages[] = $assistantHistoryEntry;
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "[SYSTEM] Deine letzte Antwort war leer. Fasse jetzt kurz und freundlich zusammen, " .
                            "was du fuer den Nutzer erledigt hast. Nenne konkrete Ergebnisse (Dateinamen, IDs, etc.).",
                    ];
                    $summaryResponse = $this->controller->streamContinuation(
                        $this->controller->compactMessagesForToolLoop(
                            $messages,
                            $iteration,
                        ),
                        [],
                        false,
                    );
                    if (!is_wp_error($summaryResponse)) {
                        $finalMessage = $this->controller->sanitizeAssistantMessageContent(
                            (string) ($summaryResponse["choices"][0]["message"][
                                "content"
                            ] ?? ""),
                        );
                    }
                    if ($finalMessage === "") {
                        $finalMessage = $this->controller->recoverStreamedContentOrFallback(
                            $toolResults,
                        );
                    }
                }

                $finalMessage = $this->controller->applyResponseSafetyGates(
                    $finalMessage,
                    $toolResults,
                    $taskIntent,
                );

                if ($this->controller->wasResponseTruncated($nextResponse)) {
                    $finalMessage = $this->controller->appendTruncationHint(
                        $finalMessage,
                    );
                }

                $this->conversationRepo->saveMessage(
                    $sessionId,
                    $userId,
                    "assistant",
                    $finalMessage,
                );

                $donePayload = [
                    "session_id" => $sessionId,
                    "message" => $finalMessage,
                    "model" => $nextResponse["model"] ?? null,
                    "tools_used" => array_values(
                        array_unique(
                            array_map(fn($r) => $r["tool"], $toolResults),
                        ),
                    ),
                    "truncated" => $this->controller->wasResponseTruncated(
                        $nextResponse,
                    ),
                ];
                $this->controller->emitSSE("activity_complete", []);
                $donePayload[
                    "usage"
                ] = $this->controller->getUsageAccumulator();
                $this->controller->emitSSE("done", $donePayload);
                $this->controller->flushUsage($sessionId, $userId);
                return;
            }
        }

        $finalMessage = $this->controller->recoverStreamedContentOrFallback(
            $toolResults,
        );
        $this->conversationRepo->saveMessage(
            $sessionId,
            $userId,
            "assistant",
            $finalMessage,
        );

        $fallbackPayload = [
            "session_id" => $sessionId,
            "message" => $finalMessage,
            "tools_used" => array_values(
                array_unique(array_map(fn($r) => $r["tool"], $toolResults)),
            ),
        ];
        $this->controller->emitSSE("activity_complete", []);
        $fallbackPayload["usage"] = $this->controller->getUsageAccumulator();
        $this->controller->emitSSE("done", $fallbackPayload);
        $this->controller->flushUsage($sessionId, $userId);
    }

    public function getToolProgressLabel(
        string $toolName,
        string $phase,
    ): string {
        $humanNames = [
            "get_posts" => "Beitraege lesen",
            "get_post" => "Beitrag lesen",
            "get_pages" => "Seiten lesen",
            "get_users" => "Benutzer lesen",
            "get_media" => "Medien lesen",
            "get_plugins" => "Plugins pruefen",
            "get_options" => "Einstellungen lesen",
            "create_post" => "Beitrag erstellen",
            "create_page" => "Seite erstellen",
            "update_post" => "Beitrag aktualisieren",
            "delete_post" => "Beitrag loeschen",
            "create_plugin" => "Plugin erstellen",
            "install_plugin" => "Plugin installieren",
            "list_plugin_files" => "Plugin-Dateien auflisten",
            "read_plugin_file" => "Plugin-Datei lesen",
            "write_plugin_file" => "Plugin-Datei schreiben",
            "patch_plugin_file" => "Plugin-Datei patchen",
            "list_theme_files" => "Theme-Dateien auflisten",
            "read_theme_file" => "Theme-Datei lesen",
            "write_theme_file" => "Theme-Datei schreiben",
            "read_error_log" => "Error-Log pruefen",
            "upload_media" => "Medien hochladen",
            "update_media" => "Medien-Metadaten bearbeiten",
            "update_option" => "Einstellung aendern",
            "manage_post_meta" => "Metadaten verarbeiten",
            "manage_taxonomy" => "Taxonomie verarbeiten",
            "manage_menu" => "Menue bearbeiten",
            "manage_cron" => "Cron-Aufgaben verwalten",
            "get_woocommerce_data" => "Shop-Daten lesen",
            "get_woocommerce_shop" => "Shop-Status pruefen",
            "manage_woocommerce" => "Shop bearbeiten",
            "get_elementor_data" => "Elementor-Layout lesen",
            "elementor_build" => "Elementor-Layout bearbeiten",
            "manage_elementor" => "Elementor verwalten",
            "discover_content_types" => "Inhaltstypen erkennen",
            "discover_rest_api" => "REST-API erkennen",
            "execute_wp_code" => "Code ausfuehren",
            "grep_plugin_files" => "Plugin-Dateien durchsuchen",
            "grep_theme_files" => "Theme-Dateien durchsuchen",
            "patch_theme_file" => "Theme-Datei patchen",
            "delete_plugin_file" => "Plugin-Datei loeschen",
            "delete_theme_file" => "Theme-Datei loeschen",
            "check_plugin_health" => "Plugin-Gesundheit pruefen",
            "rename_in_plugin" => "Umbenennung in Plugin",
            "revert_file" => "Datei-Revert",
            "search_tools" => "Tools suchen",
            "http_fetch" => "HTTP-Abfrage",
            "manage_user" => "Benutzer verwalten",
            "update_any_option" => "Option aendern",
            "store_session_image" => "Bild speichern",
            "switch_theme" => "Theme wechseln",
            "create_theme" => "Theme erstellen",
        ];

        $name = $humanNames[$toolName] ?? $toolName;

        return match ($phase) {
            "start" => $name . "...",
            "done" => $name,
            "failed" => $name . " fehlgeschlagen",
            default => $name,
        };
    }

    public function handleToolCalls(
        array $messageData,
        array $messages,
        string $sessionId,
        int $userId,
        string $latestUserMessage,
        bool $webSearch = false,
    ): WP_REST_Response {
        $toolResults = [];
        $executionTrace = [];
        $runtimeSettings = $this->settings->getSettings();
        $maxIterations = max(
            1,
            (int) ($runtimeSettings["max_tool_iterations"] ?? 25),
        );
        $taskIntent = $this->inferTaskIntent($latestUserMessage, $messages);
        $iteration = 0;
        $mutationNudgeCount = 0;
        $completionGateCount = 0;

        while ($iteration < $maxIterations) {
            $toolCalls = $messageData["tool_calls"] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            $messages[] = $messageData;

            foreach ($toolCalls as $index => $toolCall) {
                $functionName = trim($toolCall["function"]["name"] ?? "");
                $rawArgs = $toolCall["function"]["arguments"] ?? "{}";

                $functionArgs = json_decode($rawArgs, true);
                if (!is_array($functionArgs)) {
                    $functionArgs = [];
                }
                $functionArgs = $this->normalizeToolArgumentsForIntent(
                    $functionName,
                    $functionArgs,
                    $latestUserMessage,
                );
                $toolCallId = $toolCall["id"] ?? "";

                $planValidation = $this->validateToolCall(
                    $functionName,
                    $functionArgs,
                );
                if (!($planValidation["allow"] ?? false)) {
                    $result = [
                        "success" => false,
                        "needs_replan" => true,
                        "error" =>
                            (string) ($planValidation["reason"] ??
                                "Tool passt nicht zum internen Ausfuehrungsplan."),
                        "tool" => $functionName,
                    ];
                    $this->logToolExecution(
                        $sessionId,
                        $userId,
                        $functionName,
                        $functionArgs,
                        $result,
                    );
                    $toolResults[] = [
                        "tool" => $functionName,
                        "args_key" => $this->buildToolArgsKey(
                            $functionName,
                            $functionArgs,
                        ),
                        "fingerprint" => $this->buildToolCallFingerprint(
                            $functionName,
                            $functionArgs,
                        ),
                        "result" => $result,
                        "seq" => count($toolResults),
                        "iteration" => $iteration,
                    ];
                    $executionTrace[] = [
                        "iteration" => $iteration,
                        "step" => count($executionTrace) + 1,
                        "tool" => $functionName,
                        "status" => "blocked_by_plan",
                        "timestamp" => current_time("mysql"),
                        "summary" => $this->summarizeToolResult($result),
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => $this->controller->compactToolResultForModel(
                            $result,
                        ),
                        "_levi_iteration" => $iteration,
                        "_levi_tool" => $functionName,
                    ];
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "Tool-Call blockiert: Er passt nicht zum internen Plan (Domain/Intent). " .
                            "Plane die naechsten Schritte neu und verwende nur passende Tools fuer diese Aufgabe.",
                    ];
                    continue;
                }

                $guardResult = $this->toolGuard->evaluate(
                    $functionName,
                    $functionArgs,
                );

                error_log(
                    sprintf(
                        "Levi ToolGuard [classic]: tool=%s verdict=%s reason=%s",
                        $functionName,
                        $guardResult["verdict"] ?? "null",
                        $guardResult["reason"] ?? "-",
                    ),
                );

                if ($guardResult["verdict"] === ToolGuard::BLOCK) {
                    $result = [
                        "success" => false,
                        "blocked" => true,
                        "error" =>
                            $guardResult["reason"] ?? "Tool-Call blockiert.",
                        "tool" => $functionName,
                    ];
                    $this->logToolExecution(
                        $sessionId,
                        $userId,
                        $functionName,
                        $functionArgs,
                        $result,
                    );
                    $toolResults[] = [
                        "tool" => $functionName,
                        "args_key" => $this->buildToolArgsKey(
                            $functionName,
                            $functionArgs,
                        ),
                        "fingerprint" => $this->buildToolCallFingerprint(
                            $functionName,
                            $functionArgs,
                        ),
                        "result" => $result,
                        "seq" => count($toolResults),
                        "iteration" => $iteration,
                    ];
                    $executionTrace[] = [
                        "iteration" => $iteration,
                        "step" => count($executionTrace) + 1,
                        "tool" => $functionName,
                        "status" => "blocked_by_guard",
                        "timestamp" => current_time("mysql"),
                        "summary" => $this->summarizeToolResult($result),
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => $this->controller->compactToolResultForModel(
                            $result,
                        ),
                        "_levi_iteration" => $iteration,
                        "_levi_tool" => $functionName,
                    ];
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "Tool-Call blockiert: " .
                            ($guardResult["reason"] ?? "") .
                            " Waehle einen anderen Ansatz.",
                    ];
                    continue;
                }

                $executionTrace[] = [
                    "iteration" => $iteration,
                    "step" => count($executionTrace) + 1,
                    "tool" => $functionName,
                    "status" => "started",
                    "timestamp" => current_time("mysql"),
                    "details" => [
                        "tool_call_index" => $index,
                    ],
                ];

                $loopNudge = $this->detectToolLoop(
                    $toolResults,
                    $functionName,
                    $functionArgs,
                );
                if ($loopNudge !== null) {
                    error_log(
                        "Levi: loop detection triggered for " . $functionName,
                    );
                    $result = [
                        "success" => false,
                        "loop_detected" => true,
                        "error" =>
                            "Wiederholter Aufruf erkannt. Waehle einen anderen Ansatz.",
                        "tool" => $functionName,
                    ];
                    $this->logToolExecution(
                        $sessionId,
                        $userId,
                        $functionName,
                        $functionArgs,
                        $result,
                    );
                    $toolResults[] = [
                        "tool" => $functionName,
                        "args_key" => $this->buildToolArgsKey(
                            $functionName,
                            $functionArgs,
                        ),
                        "fingerprint" => $this->buildToolCallFingerprint(
                            $functionName,
                            $functionArgs,
                        ),
                        "result" => $result,
                        "seq" => count($toolResults),
                        "iteration" => $iteration,
                    ];
                    $executionTrace[] = [
                        "iteration" => $iteration,
                        "step" => count($executionTrace) + 1,
                        "tool" => $functionName,
                        "status" => "loop_detected",
                        "timestamp" => current_time("mysql"),
                        "summary" =>
                            "Loop-Detection: wiederholter Aufruf blockiert",
                    ];
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCallId,
                        "content" => $this->controller->compactToolResultForModel(
                            $result,
                        ),
                        "_levi_iteration" => $iteration,
                        "_levi_tool" => $functionName,
                    ];
                    $messages[] = [
                        "role" => "system",
                        "content" => $loopNudge,
                    ];
                    continue;
                }

                $result = $this->executeToolWithAutopaging(
                    $functionName,
                    $functionArgs,
                    $latestUserMessage,
                );

                if (
                    $functionName === "search_tools" &&
                    !empty($result["tools"])
                ) {
                    $this->controller->addDiscoveredTools(
                        array_column($result["tools"], "name"),
                    );
                }

                $this->trackOwnedPluginFromToolResult(
                    $functionName,
                    $functionArgs,
                    $result,
                );
                $this->logToolExecution(
                    $sessionId,
                    $userId,
                    $functionName,
                    $functionArgs,
                    $result,
                );
                $this->controller->setWorkingSetIteration($iteration);
                $this->controller->trackFileAccessFromToolResult(
                    $functionName,
                    $functionArgs,
                    $result,
                );
                $toolResults[] = [
                    "tool" => $functionName,
                    "args_key" => $this->buildToolArgsKey(
                        $functionName,
                        $functionArgs,
                    ),
                    "fingerprint" => $this->buildToolCallFingerprint(
                        $functionName,
                        $functionArgs,
                    ),
                    "result" => $result,
                    "seq" => count($toolResults),
                    "iteration" => $iteration,
                ];
                $executionTrace[] = [
                    "iteration" => $iteration,
                    "step" => count($executionTrace) + 1,
                    "tool" => $functionName,
                    "status" =>
                        $result["success"] ?? false
                            ? "completed"
                            : (!empty($result["needs_confirmation"])
                                ? "awaiting_confirmation"
                                : "failed"),
                    "timestamp" => current_time("mysql"),
                    "summary" => $this->summarizeToolResult($result),
                ];

                $toolContent = $this->controller->compactToolResultForModel(
                    $result,
                );
                $toolContent .= $this->buildWriteBudgetWarning($functionName);

                $messages[] = [
                    "role" => "tool",
                    "tool_call_id" => $toolCallId,
                    "content" => $toolContent,
                    "_levi_iteration" => $iteration,
                    "_levi_tool" => $functionName,
                ];
            }

            $autoList = $this->controller->injectAutoListOnMissingFile(
                $toolCalls,
                $toolResults,
            );
            foreach ($autoList as $al) {
                $messages[] = $al;
            }

            $postWriteMessages = $this->controller->injectPostWriteValidation(
                $toolCalls,
                $toolResults,
            );
            if (!empty($postWriteMessages)) {
                foreach ($postWriteMessages as $pwm) {
                    $messages[] = $pwm;
                }
            }

            $patchVerify = $this->controller->injectPostPatchVerification(
                $toolCalls,
                $toolResults,
            );
            foreach ($patchVerify as $pv) {
                $messages[] = $pv;
            }

            $scaffoldNudge = $this->controller->injectPostCreatePluginNudge(
                $toolCalls,
                $toolResults,
            );
            foreach ($scaffoldNudge as $nudge) {
                $messages[] = $nudge;
            }

            $cssNudge = $this->controller->injectPostCSSWriteNudge(
                $toolCalls,
                $toolResults,
            );
            foreach ($cssNudge as $nudge) {
                $messages[] = $nudge;
            }

            $smokeTest = $this->controller->injectPostPluginSmokeTest(
                $toolCalls,
                $toolResults,
            );
            foreach ($smokeTest as $st) {
                $messages[] = $st;
            }

            $envWarnings = $this->controller->injectPostWriteEnvironmentWarnings(
                $toolCalls,
                $toolResults,
            );
            foreach ($envWarnings as $ew) {
                $messages[] = $ew;
            }

            $integrationCheck = $this->controller->injectPostWriteIntegrationCheck(
                $toolCalls,
                $toolResults,
            );
            foreach ($integrationCheck as $ic) {
                $messages[] = $ic;
            }

            $depWarnings = $this->controller->injectPostWriteReverseDependencyWarnings(
                $toolCalls,
                $toolResults,
            );
            foreach ($depWarnings as $dw) {
                $messages[] = $dw;
            }

            $refCheckClassic = $this->controller->injectPostWriteReferenceCheck(
                $toolCalls,
                $toolResults,
            );
            foreach ($refCheckClassic as $rc) {
                $messages[] = $rc;
            }

            $codeTagWarnings = $this->controller->injectCodeTagWarnings(
                $toolResults,
            );
            foreach ($codeTagWarnings as $ctw) {
                $messages[] = $ctw;
            }

            $toolMismatch = $this->controller->injectToolMismatchCorrection(
                $latestUserMessage,
                $toolResults,
            );
            if (!empty($toolMismatch)) {
                foreach ($toolMismatch as $tm) {
                    $messages[] = $tm;
                }
            }

            if ($iteration >= 2) {
                $wsSummary = $this->controller->getWorkingSetSummary();
                if ($wsSummary !== "") {
                    $messages[] = ["role" => "system", "content" => $wsSummary];
                }
            }

            $loopMessages = $this->controller->compactMessagesForToolLoop(
                $messages,
                $iteration,
            );
            $nextResponse = $this->controller->chatWithTracking(
                $loopMessages,
                $this->controller->getToolDefs(),
                null,
                $webSearch,
            );
            if (is_wp_error($nextResponse)) {
                $errMsg = $nextResponse->get_error_message();
                $errMsgLower = mb_strtolower($errMsg);
                if (
                    $this->controller->isNoEndpointsError($errMsgLower) ||
                    $this->controller->isTimeoutError($errMsgLower)
                ) {
                    $nextResponse = $this->controller->chatWithTracking(
                        $loopMessages,
                        [],
                        null,
                        $webSearch,
                    );
                }
            }
            if (is_wp_error($nextResponse)) {
                $errMsg = $nextResponse->get_error_message();
                $errMsgLower = mb_strtolower($errMsg);
                $statusCode = $this->controller->isNoEndpointsError(
                    $errMsgLower,
                )
                    ? 503
                    : ($this->controller->isTimeoutError($errMsgLower)
                        ? 504
                        : 500);
                if ($statusCode === 503) {
                    $provider = $this->settings->getProvider();
                    $model = $this->settings->getModelForProvider($provider);
                    $errMsg = sprintf(
                        "Für das aktuell gewählte Modell sind gerade keine verfügbaren Endpoints vorhanden (%s). Bitte wechsle auf ein anderes Modell oder versuche es später erneut.",
                        $model,
                    );
                } elseif ($statusCode === 504) {
                    $errMsg =
                        "Die Anfrage hat beim AI-Provider zu lange gedauert (Timeout). Bitte präzisieren, in kleinere Schritte aufteilen oder erneut versuchen.";
                }
                return new WP_REST_Response(
                    [
                        "error" => $errMsg,
                        "session_id" => $sessionId,
                        "execution_trace" => $executionTrace,
                    ],
                    $statusCode,
                );
            }

            $messageData = $nextResponse["choices"][0]["message"] ?? [];
            if (empty($messageData["tool_calls"])) {
                $completionIssues = $this->controller->checkWriteCompleteness(
                    $toolResults,
                );
                if ($completionIssues !== null && $completionGateCount < 2) {
                    $completionGateCount++;
                    $assistantHistoryEntry = [
                        "role" => "assistant",
                        "content" => $messageData["content"] ?? "",
                    ];
                    if (!empty($messageData["reasoning_content"])) {
                        $assistantHistoryEntry["reasoning_content"] =
                            $messageData["reasoning_content"];
                    }
                    $messages[] = $assistantHistoryEntry;
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "[SYSTEM – COMPLETION CHECK FAILED]\n" .
                            $completionIssues .
                            "\n\nDu darfst dem Nutzer NICHT 'fertig' melden bevor diese Probleme behoben sind. " .
                            "Fuehre die noetige(n) write_plugin_file / patch_plugin_file Aktion(en) jetzt aus.",
                    ];
                    $gateResponse = $this->controller->chatWithTracking(
                        $messages,
                        $this->controller->getToolDefs(),
                        null,
                        $webSearch,
                    );
                    if (!is_wp_error($gateResponse)) {
                        $messageData =
                            $gateResponse["choices"][0]["message"] ?? [];
                        if (!empty($messageData["tool_calls"])) {
                            continue;
                        }
                    }
                }

                if ($mutationNudgeCount < 1) {
                    $gateResult = $this->enforceMutationGate(
                        $messages,
                        $messageData,
                        $toolResults,
                        $latestUserMessage,
                        $webSearch,
                    );
                    if ($gateResult !== null) {
                        $mutationNudgeCount++;
                        $messageData = $gateResult;
                        continue;
                    }
                    $mutationNudgeCount++;
                }

                $finalMessage = $this->controller->sanitizeAssistantMessageContent(
                    (string) ($messageData["content"] ?? ""),
                );

                if ($finalMessage === "") {
                    error_log(
                        "Levi: empty AI response after tool loop (classic), nudging for summary",
                    );
                    $assistantHistoryEntry = [
                        "role" => "assistant",
                        "content" => $messageData["content"] ?? "",
                    ];
                    if (!empty($messageData["reasoning_content"])) {
                        $assistantHistoryEntry["reasoning_content"] =
                            $messageData["reasoning_content"];
                    }
                    $messages[] = $assistantHistoryEntry;
                    $messages[] = [
                        "role" => "system",
                        "content" =>
                            "[SYSTEM] Deine letzte Antwort war leer. Fasse jetzt kurz und freundlich zusammen, " .
                            "was du fuer den Nutzer erledigt hast. Nenne konkrete Ergebnisse (Dateinamen, IDs, etc.).",
                    ];
                    $summaryResponse = $this->controller->chatWithTracking(
                        $this->controller->compactMessagesForToolLoop(
                            $messages,
                            $iteration,
                        ),
                        [],
                        null,
                        false,
                    );
                    if (!is_wp_error($summaryResponse)) {
                        $finalMessage = $this->controller->sanitizeAssistantMessageContent(
                            (string) ($summaryResponse["choices"][0]["message"][
                                "content"
                            ] ?? ""),
                        );
                    }
                    if ($finalMessage === "") {
                        $finalMessage = $this->controller->recoverStreamedContentOrFallback(
                            $toolResults,
                        );
                    }
                }

                $finalMessage = $this->controller->applyResponseSafetyGates(
                    $finalMessage,
                    $toolResults,
                    $taskIntent,
                );

                if ($this->controller->wasResponseTruncated($nextResponse)) {
                    $finalMessage = $this->controller->appendTruncationHint(
                        $finalMessage,
                    );
                }

                $this->conversationRepo->saveMessage(
                    $sessionId,
                    $userId,
                    "assistant",
                    $finalMessage,
                );

                $responsePayload = [
                    "session_id" => $sessionId,
                    "message" => $finalMessage,
                    "model" => $nextResponse["model"] ?? null,
                    "tools_used" => array_values(
                        array_unique(
                            array_map(fn($r) => $r["tool"], $toolResults),
                        ),
                    ),
                    "execution_trace" => $executionTrace,
                    "truncated" => $this->controller->wasResponseTruncated(
                        $nextResponse,
                    ),
                    "timestamp" => current_time("mysql"),
                ];
                $responsePayload[
                    "usage"
                ] = $this->controller->getUsageAccumulator();
                $this->controller->flushUsage($sessionId, $userId);
                return new WP_REST_Response($responsePayload, 200);
            }
        }

        $finalMessage = $this->controller->recoverStreamedContentOrFallback(
            $toolResults,
        );
        $this->conversationRepo->saveMessage(
            $sessionId,
            $userId,
            "assistant",
            $finalMessage,
        );

        $fallbackPayload = [
            "session_id" => $sessionId,
            "message" => $finalMessage,
            "tools_used" => array_values(
                array_unique(array_map(fn($r) => $r["tool"], $toolResults)),
            ),
            "execution_trace" => $executionTrace,
            "timestamp" => current_time("mysql"),
        ];
        $fallbackPayload["usage"] = $this->controller->getUsageAccumulator();
        $this->controller->flushUsage($sessionId, $userId);
        return new WP_REST_Response($fallbackPayload, 200);
    }

    // =====================================================================
    // V1 Helpers
    // =====================================================================

    public function summarizeToolResult(array $result): string
    {
        if (($result["success"] ?? false) === true) {
            if (!empty($result["message"]) && is_string($result["message"])) {
                return $result["message"];
            }
            if (!empty($result["post_id"])) {
                return "OK: post_id=" . $result["post_id"];
            }
            if (!empty($result["page_id"])) {
                return "OK: page_id=" . $result["page_id"];
            }
            if (
                !empty($result["theme_slug"]) &&
                empty($result["relative_path"])
            ) {
                return "OK: theme=" . $result["theme_slug"];
            }
            if (!empty($result["plugin_file"])) {
                return "OK: plugin_file=" . $result["plugin_file"];
            }
            if (!empty($result["relative_path"])) {
                return "OK: file=" . $result["relative_path"];
            }
            return "OK";
        }

        if (!empty($result["error"]) && is_string($result["error"])) {
            return "Fehler: " . $result["error"];
        }
        return "Fehler bei Ausführung";
    }

    private function describeToolAction(string $toolName, array $args): string
    {
        return match ($toolName) {
            "create_plugin" => "Neues Plugin '" .
                ($args["slug"] ?? "?") .
                "' erstellen" .
                (!empty($args["name"]) ? " ({$args["name"]})" : "") .
                (!empty($args["description"])
                    ? " — {$args["description"]}"
                    : ""),
            "delete_post" => $this->describeDeletePost($args),
            "install_plugin" => "Plugin '" .
                ($args["plugin_slug"] ?? "?") .
                "' installieren" .
                (!empty($args["action"]) &&
                $args["action"] === "update_outdated"
                    ? " (alle veralteten Plugins aktualisieren)"
                    : ""),
            "switch_theme" => "Theme zu '" .
                ($args["theme"] ?? ($args["stylesheet"] ?? "?")) .
                "' wechseln",
            "update_any_option" => "Option '" .
                ($args["option"] ?? "?") .
                "' aendern" .
                (isset($args["value"])
                    ? " auf '" . mb_substr((string) $args["value"], 0, 80) . "'"
                    : ""),
            "manage_user" => "Benutzer-Aktion: " .
                ($args["action"] ?? "?") .
                (!empty($args["user_id"]) ? " (User #{$args["user_id"]})" : ""),
            "patch_plugin_file" => "Plugin-Datei patchen" .
                (!empty($args["plugin_slug"])
                    ? " in '{$args["plugin_slug"]}'"
                    : "") .
                (!empty($args["relative_path"])
                    ? ": {$args["relative_path"]}"
                    : "") .
                (!empty($args["replacements"])
                    ? " (" . count($args["replacements"]) . " Ersetzung(en))"
                    : ""),
            "delete_plugin_file" => "Plugin-Datei loeschen" .
                (!empty($args["plugin_slug"])
                    ? " in '{$args["plugin_slug"]}'"
                    : "") .
                (!empty($args["relative_path"])
                    ? ": {$args["relative_path"]}"
                    : ""),
            "delete_theme_file" => "Theme-Datei loeschen" .
                (!empty($args["relative_path"])
                    ? ": {$args["relative_path"]}"
                    : ""),
            "execute_wp_code" => $this->describePhpCode($args["code"] ?? ""),
            "manage_woocommerce" => $this->describeWooCommerceAction($args),
            "manage_elementor" => "Elementor: " .
                ($args["action"] ?? "?") .
                (!empty($args["page_id"])
                    ? " (Seite #{$args["page_id"]})"
                    : ""),
            "manage_menu" => "Menue: " .
                ($args["action"] ?? "?") .
                (!empty($args["menu_name"]) ? " '{$args["menu_name"]}'" : ""),
            "manage_cron" => "Cron: " .
                ($args["action"] ?? "?") .
                (!empty($args["name"]) ? " '{$args["name"]}'" : "") .
                (!empty($args["hook"]) ? " ({$args["hook"]})" : ""),
            default => $toolName,
        };
    }

    private function describeDeletePost(array $args): string
    {
        $id = $args["id"] ?? null;
        $label = "Beitrag";
        if ($id) {
            $post = get_post((int) $id);
            if ($post) {
                $label = match ($post->post_type) {
                    "page" => "Seite",
                    "product" => "Produkt",
                    "attachment" => "Medien-Datei",
                    default => "Beitrag",
                };
            }
        }
        return $label . ($id ? " #{$id}" : "") . " loeschen";
    }

    private function describeWooCommerceAction(array $args): string
    {
        $action = (string) ($args["action"] ?? "?");
        return match ($action) {
            "create_product" => "Neues WooCommerce-Produkt erstellen: '" .
                ($args["name"] ?? "?") .
                "' (Typ: " .
                ($args["product_type"] ?? "simple") .
                ")",
            "update_product" => "WooCommerce-Produkt #" .
                ($args["product_id"] ?? "?") .
                " aktualisieren",
            "delete_product" => "WooCommerce-Produkt #" .
                ($args["product_id"] ?? "?") .
                " loeschen",
            "set_product_attributes" => "Attribute fuer Produkt #" .
                ($args["product_id"] ?? "?") .
                " setzen",
            "create_variations" => "Variationen fuer Produkt #" .
                ($args["product_id"] ?? "?") .
                " erstellen",
            "update_variation" => "Variation #" .
                ($args["variation_id"] ?? "?") .
                " aktualisieren",
            "delete_variation" => "Variation #" .
                ($args["variation_id"] ?? "?") .
                " loeschen",
            "update_order_status" => "Bestellstatus #" .
                ($args["order_id"] ?? "?") .
                " auf " .
                ($args["order_status"] ?? "?") .
                " setzen",
            "configure_tax" => "Steuer-Einstellungen aendern",
            "create_coupon" => "Coupon '" .
                ($args["coupon_code"] ?? "?") .
                "' erstellen",
            "update_coupon" => "Coupon #" .
                ($args["coupon_id"] ?? "?") .
                " aktualisieren",
            "delete_coupon" => "Coupon #" .
                ($args["coupon_id"] ?? "?") .
                " loeschen",
            default => "WooCommerce: " . $action,
        };
    }

    private function describePhpCode(string $code): string
    {
        if ($code === "") {
            return "PHP-Code ausfuehren";
        }

        $preview = trim($code);
        $preview = preg_replace("/\s+/", " ", $preview);
        if (mb_strlen($preview) > 120) {
            $preview = mb_substr($preview, 0, 120) . "...";
        }
        return "PHP-Code ausfuehren: " . $preview;
    }

    private function isDestructiveTool(string $toolName, array $args = []): bool
    {
        $result = $this->toolGuard->evaluate($toolName, $args);
        return $result["verdict"] === ToolGuard::BLOCK;
    }

    public function isWriteTool(string $toolName): bool
    {
        return in_array(
            $toolName,
            [
                "write_plugin_file",
                "patch_plugin_file",
                "write_theme_file",
                "patch_theme_file",
                "create_plugin",
                "create_theme",
                "execute_wp_code",
                "elementor_build",
                "rename_in_plugin",
                "revert_file",
            ],
            true,
        );
    }

    private function normalizeToolArgumentsForIntent(
        string $toolName,
        array $args,
        string $latestUserMessage,
    ): array {
        if (!in_array($toolName, ["get_pages", "get_posts"], true)) {
            return $args;
        }

        if (!$this->requiresExhaustiveReadIntent($latestUserMessage)) {
            return $args;
        }

        $args["include_content"] = true;
        $args["status"] = "any";
        $args["number"] = max((int) ($args["number"] ?? 100), 100);
        $args["page"] = max(1, (int) ($args["page"] ?? 1));

        return $args;
    }

    private function requiresExhaustiveReadIntent(string $text): bool
    {
        $t = mb_strtolower($text);
        return preg_match(
            "/\b(alle|gesamt|komplett|vollständig|sämtlich|alles lesen|komplett lesen|gesamten inhalt)\b/u",
            $t,
        ) === 1;
    }

    private function executeToolWithAutopaging(
        string $toolName,
        array $args,
        string $latestUserMessage,
    ): array {
        $firstResult = $this->toolRegistry->execute($toolName, $args);
        if (($firstResult["success"] ?? false) !== true) {
            return $firstResult;
        }

        if (!in_array($toolName, ["get_pages", "get_posts"], true)) {
            return $firstResult;
        }

        if (
            !$this->requiresExhaustiveReadIntent($latestUserMessage) ||
            empty($firstResult["has_more"])
        ) {
            return $firstResult;
        }

        $allItemsKey = $toolName === "get_pages" ? "pages" : "posts";
        $combined = $firstResult;
        $combined[$allItemsKey] = is_array($firstResult[$allItemsKey] ?? null)
            ? $firstResult[$allItemsKey]
            : [];

        $maxPages = max(1, (int) ($firstResult["max_pages"] ?? 1));
        $currentPage = max(1, (int) ($args["page"] ?? 1));

        while ($currentPage < $maxPages) {
            $currentPage++;
            $nextArgs = $args;
            $nextArgs["page"] = $currentPage;

            $next = $this->toolRegistry->execute($toolName, $nextArgs);
            if (($next["success"] ?? false) !== true) {
                $combined["partial_error"] =
                    $next["error"] ?? "Could not fetch further pages.";
                break;
            }

            $nextItems = is_array($next[$allItemsKey] ?? null)
                ? $next[$allItemsKey]
                : [];
            $combined[$allItemsKey] = array_merge(
                $combined[$allItemsKey],
                $nextItems,
            );
            $combined["count"] = count($combined[$allItemsKey]);
            $combined["page"] = $currentPage;
            $combined["has_more"] = !empty($next["has_more"]);
        }

        return $combined;
    }

    private function logToolExecution(
        string $sessionId,
        int $userId,
        string $toolName,
        array $toolArgs,
        array $result,
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . "levi_audit_log";
        static $auditTableExists = null;

        if ($toolName === "") {
            return;
        }

        if ($auditTableExists === null) {
            $auditTableExists =
                $wpdb->get_var(
                    $wpdb->prepare("SHOW TABLES LIKE %s", $table),
                ) === $table;
        }
        if (!$auditTableExists) {
            return;
        }

        $preparedArgs = $this->sanitizeAuditLogData($toolArgs);
        $encodedArgs = wp_json_encode(
            $preparedArgs,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (!is_string($encodedArgs)) {
            $encodedArgs = "{}";
        }

        $summary = $this->summarizeToolResult($result);
        if ($summary !== "") {
            $summary = mb_substr($summary, 0, 255);
        } else {
            $summary = null;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                "user_id" => $userId > 0 ? $userId : null,
                "session_id" => $sessionId,
                "tool_name" => $toolName,
                "tool_args" => $encodedArgs,
                "success" => !empty($result["success"]) ? 1 : 0,
                "result_summary" => $summary,
                "executed_at" => current_time("mysql"),
            ],
            ["%d", "%s", "%s", "%s", "%d", "%s", "%s"],
        );

        if ($inserted === false) {
            error_log("Levi Audit Log insert failed: " . $wpdb->last_error);
        }
    }

    private function sanitizeAuditLogData(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? strtolower($key) : (string) $key;
                if ($this->isSensitiveAuditKey($keyString)) {
                    $sanitized[$key] = "[REDACTED]";
                    continue;
                }
                $sanitized[$key] = $this->sanitizeAuditLogData($item);
            }
            return $sanitized;
        }

        if (is_string($value)) {
            return mb_strlen($value) > 1000
                ? mb_substr($value, 0, 1000) . "…"
                : $value;
        }

        return $value;
    }

    private function isSensitiveAuditKey(string $key): bool
    {
        return in_array(
            $key,
            [
                "password",
                "passwort",
                "secret",
                "token",
                "api_key",
                "authorization",
                "cookie",
                "nonce",
                "levi_action_password",
                "confirm_password",
                "confirmation_password",
            ],
            true,
        );
    }

    private function buildToolCallFingerprint(
        string $toolName,
        array $args,
    ): string {
        ksort($args);
        return $toolName . ":" . md5(json_encode($args));
    }

    private function detectToolLoop(
        array $toolResults,
        string $currentTool,
        array $currentArgs,
    ): ?string {
        $currentFingerprint = $this->buildToolCallFingerprint(
            $currentTool,
            $currentArgs,
        );
        $consecutiveCount = 0;

        for ($i = count($toolResults) - 1; $i >= 0; $i--) {
            $prevFingerprint = $toolResults[$i]["fingerprint"] ?? "";
            if ($prevFingerprint === $currentFingerprint) {
                $consecutiveCount++;
            } else {
                break;
            }
        }

        if ($consecutiveCount >= 2) {
            return "[SYSTEM] Du rufst dasselbe Tool (" .
                $currentTool .
                ") wiederholt mit exakt denselben Argumenten auf. " .
                "Das deutet auf eine Schleife hin. Waehle einen anderen Ansatz: " .
                "Wenn patch_plugin_file fehlgeschlagen ist, nutze write_plugin_file zum Neuschreiben. " .
                "Wenn du eine Datei bereits gelesen hast, lies sie nicht nochmal – handele stattdessen.";
        }

        $errorLoopMsg = $this->detectErrorLoop($toolResults, $currentTool);
        if ($errorLoopMsg !== null) {
            return $errorLoopMsg;
        }

        return null;
    }

    private function detectErrorLoop(
        array $toolResults,
        string $currentTool,
    ): ?string {
        $recentErrors = [];
        $lookback = min(count($toolResults), 8);

        for (
            $i = count($toolResults) - 1;
            $i >= max(0, count($toolResults) - $lookback);
            $i--
        ) {
            $tr = $toolResults[$i];
            if (($tr["tool"] ?? "") !== $currentTool) {
                continue;
            }
            $res = $tr["result"] ?? [];
            if (($res["success"] ?? true) === false && !empty($res["error"])) {
                $recentErrors[] = $res["error"];
            }
        }

        if (count($recentErrors) < 2) {
            return null;
        }

        $firstError = $recentErrors[0];
        $sameErrorCount = 0;
        foreach ($recentErrors as $err) {
            if ($err === $firstError) {
                $sameErrorCount++;
            }
        }

        if ($sameErrorCount >= 2) {
            return "[SYSTEM] ACHTUNG: Das Tool '$currentTool' hat {$sameErrorCount}x denselben Fehler produziert: \"{$firstError}\". " .
                "Wiederhole NICHT denselben Ansatz. Analysiere die Ursache: " .
                "Lies die betroffene Datei mit read_plugin_file, pruefe das Error-Log mit read_error_log, " .
                "oder nutze ein anderes Tool. Falls ein Plugin nicht aktiviert werden kann, pruefe ob die Datei " .
                "existiert und einen gueltigen Plugin-Header hat.";
        }

        return null;
    }

    private function buildWriteBudgetWarning(string $toolName): string
    {
        if (!$this->isWriteTool($toolName)) {
            return "";
        }
        $remaining =
            $this->toolGuard->getMaxWriteCalls() -
            $this->toolGuard->getWriteCallCount();
        if ($remaining > 5 || $remaining < 0) {
            return "";
        }
        return "\n\n[BUDGET-WARNUNG] Noch {$remaining} von {$this->toolGuard->getMaxWriteCalls()} " .
            "Schreiboperationen uebrig. Plane die verbleibenden Writes sorgfaeltig. " .
            "Vermeide Trial-and-Error — lies Dateien und analysiere Fehler bevor du schreibst.";
    }

    private function partitionToolCalls(array $toolCalls): array
    {
        $readCalls = [];
        $nonReadCalls = [];
        foreach ($toolCalls as $tc) {
            $name = trim($tc["function"]["name"] ?? "");
            if (in_array($name, self::BATCHABLE_READ_TOOLS, true)) {
                $readCalls[] = $tc;
            } else {
                $nonReadCalls[] = $tc;
            }
        }
        return [$readCalls, $nonReadCalls];
    }

    private function buildToolContext(string $toolName, array $args): string
    {
        return match (true) {
            str_contains($toolName, "plugin_file") ||
                str_contains($toolName, "theme_file")
                => $args["relative_path"] ?? "",
            str_contains($toolName, "grep_") => $args["pattern"] ?? "",
            str_contains($toolName, "list_plugin") ||
                str_contains($toolName, "list_theme")
                => $args["plugin_slug"] ?? ($args["theme_slug"] ?? ""),
            $toolName === "check_plugin_health" => $args["plugin_slug"] ?? "",
            $toolName === "http_fetch" => mb_strimwidth(
                $args["url"] ?? "",
                0,
                60,
                "...",
            ),
            str_contains($toolName, "rename_in_plugin") => ($args["old_name"] ??
                "") .
                " → " .
                ($args["new_name"] ?? ""),
            str_contains($toolName, "create_plugin") ||
                str_contains($toolName, "create_theme")
                => $args["slug"] ??
                ($args["plugin_slug"] ?? ($args["theme_slug"] ?? "")),
            str_contains($toolName, "revert_file") => $args["relative_path"] ??
                "",
            in_array(
                $toolName,
                ["get_post", "update_post", "delete_post"],
                true,
            )
                => isset($args["post_id"]) ? "#" . $args["post_id"] : "",
            default => "",
        };
    }

    private function buildToolResultSummary(
        string $toolName,
        array $result,
    ): string {
        if (!($result["success"] ?? false)) {
            $err = $result["error"] ?? "";
            return $err !== "" ? mb_strimwidth($err, 0, 80, "...") : "Fehler";
        }

        return match (true) {
            str_contains($toolName, "read_plugin_file") ||
                str_contains($toolName, "read_theme_file")
                => ($result["meta"]["line_count"] ??
                ($result["total_lines"] ?? "?")) .
                " Zeilen",
            str_contains($toolName, "write_plugin_file") ||
                str_contains($toolName, "write_theme_file")
                => ($result["bytes_written"] ?? "?") . " Bytes",
            str_contains($toolName, "patch_") => ($result["patches_applied"] ??
                0) .
                " Ersetzungen",
            str_contains($toolName, "grep_") => ($result["total_matches"] ??
                0) .
                " Treffer in " .
                ($result["files_matched"] ?? 0) .
                " Dateien",
            str_contains($toolName, "list_plugin") ||
                str_contains($toolName, "list_theme")
                => ($result["total"] ?? count($result["entries"] ?? [])) .
                " Dateien",
            $toolName === "check_plugin_health" => $result["healthy"] ?? false
                ? "gesund"
                : count($result["issues"] ?? []) . " Issues",
            $toolName === "read_error_log" => ($result["total_lines"] ?? 0) ===
            0
                ? "keine Fehler"
                : ($result["total_lines"] ?? 0) . " Zeilen",
            str_contains($toolName, "create_plugin") ||
                str_contains($toolName, "create_theme")
                => "erstellt",
            str_contains($toolName, "rename_in_plugin") => ($result[
                "files_changed"
            ] ??
                0) .
                " Dateien geaendert",
            default => "",
        };
    }

    private function buildToolArgsKey(string $toolName, array $args): string
    {
        $discriminator =
            $args["plugin_slug"] ??
            ($args["relative_path"] ??
                ($args["post_id"] ??
                    ($args["page_id"] ??
                        ($args["option"] ?? ($args["theme_slug"] ?? "")))));
        return $toolName . ":" . $discriminator;
    }

    private function inferTaskIntent(
        string $latestUserMessage,
        array $messages,
    ): array {
        $text = mb_strtolower($latestUserMessage);
        $explicitCreate =
            preg_match(
                "/\b(neu|neues|neuen|von vorn|from scratch|erstelle|anlegen|erzeuge|schreibe( mir)? ein|baue ein)\b/u",
                $text,
            ) === 1;
        $explicitModify =
            preg_match(
                "/\b(änder|anpass|optimier|fix|korrigier|überarbeit|update|verbesser|bestehend|nochmal|weiter)\b/u",
                $text,
            ) === 1;
        $referencesExisting =
            preg_match(
                "/\b(bestehend|vorhanden|das bestehende|dieses bestehende|gleiches plugin|selbes plugin|weiter daran|nochmal daran)\b/u",
                $text,
            ) === 1;

        $priorMessages = $messages;
        if (!empty($priorMessages)) {
            $last = end($priorMessages);
            if (
                is_array($last) &&
                ($last["role"] ?? "") === "user" &&
                ((string) ($last["content"] ?? "")) === $latestUserMessage
            ) {
                array_pop($priorMessages);
            }
        }

        $recentContext = "";
        foreach (array_slice($priorMessages, -10) as $msg) {
            if (
                !is_array($msg) ||
                !in_array($msg["role"] ?? "", ["user", "assistant"], true)
            ) {
                continue;
            }
            $recentContext .=
                " " . mb_strtolower((string) ($msg["content"] ?? ""));
        }
        $hasRecentArtifacts =
            preg_match(
                "/\b(post[_ -]?id|page[_ -]?id|plugin[_ -]?file|relative[_ -]?path|slug|bytes_written|erstellt|aktiviert|angelegt)\b/u",
                $recentContext,
            ) === 1;

        $mode = "unknown";
        if ($explicitModify && !$explicitCreate) {
            $mode = "modify_existing";
        } elseif ($explicitCreate && !$explicitModify) {
            $mode = "create_new";
        } elseif ($explicitCreate && $explicitModify) {
            $mode = "ambiguous";
        } elseif ($referencesExisting && $hasRecentArtifacts) {
            $mode = "probable_modify";
        }

        return [
            "mode" => $mode,
            "explicit_create" => $explicitCreate,
            "explicit_modify" => $explicitModify,
        ];
    }

    private function trackOwnedPluginFromToolResult(
        string $toolName,
        array $toolArgs,
        array $result,
    ): void {
        if ($toolName !== "create_plugin" || empty($result["success"])) {
            return;
        }

        $slug = sanitize_title(
            (string) ($result["slug"] ??
                ($toolArgs["slug"] ?? ($toolArgs["plugin_slug"] ?? ""))),
        );
        if ($slug === "") {
            return;
        }

        $existing = get_option(ChatController::OWNED_PLUGIN_OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $normalized = [];
        foreach ($existing as $entry) {
            $candidate = sanitize_title((string) $entry);
            if ($candidate !== "") {
                $normalized[] = $candidate;
            }
        }
        if (!in_array($slug, $normalized, true)) {
            $normalized[] = $slug;
            update_option(
                ChatController::OWNED_PLUGIN_OPTION,
                array_values(array_unique($normalized)),
                false,
            );
        }
    }

    // =====================================================================
    // V2 Tool Loop
    // =====================================================================

    public function handleToolCallsStreamingV2(
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

        $orchestrator->transitionTo(AgentState::OBSERVING);

        while ($iteration < 15) {
            $toolCalls = $messageData["tool_calls"] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            $messages[] = $messageData;

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
                $this->controller->emitSSE("activity_update", [
                    "text" => AgentState::PLANNING->label(),
                ]);
                $this->controller->emitSSE("state", [
                    "state" => AgentState::PLANNING->value,
                    "label" => AgentState::PLANNING->label(),
                ]);
                $this->controller->emitSSE("plan", [
                    "message" => "Levi plant folgende Aktionen...",
                    "tools" => $plannedTools,
                    "count" => count($toolCalls),
                ]);
                usleep(300000);
            }

            $orchestrator->transitionTo(AgentState::EXECUTING);
            $this->controller->emitSSE("activity_update", [
                "text" => AgentState::EXECUTING->label(),
            ]);
            $this->controller->emitSSE("state", [
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
                    if ($guard["verdict"] === ToolGuard::BLOCK) {
                        return [
                            "blocked" => true,
                            "reason" => $guard["reason"] ?? "",
                        ];
                    }
                    return null;
                };

                $this->controller->emitSSE("activity_tool", [
                    "message" => $this->getToolProgressLabelV2($functionName),
                    "tool" => $functionName,
                    "iteration" => $iteration,
                    "phase" => "start",
                ]);

                $execResult = $orchestrator->executeToolCall(
                    $tool,
                    $functionArgs,
                    $preHook,
                );
                $result = $execResult["result"];
                $toolResults[] = $result;

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

                $this->controller->emitSSE("activity_tool", [
                    "message" => $this->getToolProgressLabelV2(
                        $functionName,
                        $execResult["success"],
                    ),
                    "tool" => $functionName,
                    "iteration" => $iteration,
                    "success" => $execResult["success"],
                    "phase" => $execResult["success"] ? "done" : "failed",
                ]);

                $messages[] = [
                    "role" => "tool",
                    "tool_call_id" => $toolCallId,
                    "content" => $this->controller->compactToolResultForModel(
                        $result,
                    ),
                    "_levi_iteration" => $iteration,
                    "_levi_tool" => $functionName,
                ];
            }

            if ($consecutiveFailureCount >= self::V2_FALLBACK_THRESHOLD) {
                error_log(
                    "Levi V2: {$consecutiveFailureCount} consecutive failures — stopping tool loop",
                );
                $orchestrator->transitionTo(AgentState::ERROR);
                $this->controller->emitSSE("state", [
                    "state" => AgentState::ERROR->value,
                    "label" => AgentState::ERROR->label(),
                ]);

                $failedTools = array_values(
                    array_unique(
                        array_map(
                            fn($r) => $r["tool"] ?? "",
                            array_slice(
                                $orchestrator->getExecutionLog(),
                                -$consecutiveFailureCount,
                            ),
                        ),
                    ),
                );

                $errorMessage =
                    "Mehrere aufeinanderfolgende Fehler mit den generischen Tools (" .
                    implode(", ", $failedTools) .
                    "). " .
                    "Du kannst in den Einstellungen die generischen Tools deaktivieren und die spezialisierten Tools verwenden.";

                $this->conversationRepo->saveMessage(
                    $sessionId,
                    $userId,
                    "assistant",
                    $errorMessage,
                );

                $this->controller->emitSSE("done", [
                    "session_id" => $sessionId,
                    "message" => $errorMessage,
                    "tools_used" => array_values(
                        array_unique(
                            array_map(
                                fn($r) => $r["tool"] ?? "",
                                $orchestrator->getExecutionLog(),
                            ),
                        ),
                    ),
                    "state_history" => $orchestrator->getStateHistory(),
                    "usage" => $this->controller->getUsageAccumulator(),
                ]);
                $this->controller->flushUsage($sessionId, $userId);
                return;
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

            if (connection_aborted()) {
                error_log("Levi V2: client disconnected during tool loop");
                return;
            }

            $orchestrator->transitionTo(AgentState::REASONING);
            $this->controller->emitSSE("activity_update", [
                "text" => AgentState::REASONING->label(),
            ]);
            $this->controller->emitSSE("state", [
                "state" => AgentState::REASONING->value,
                "label" => AgentState::REASONING->label(),
            ]);

            $loopMessages = $this->controller->compactMessagesForToolLoop(
                $messages,
                $iteration,
            );
            $nextResponse = $this->controller->streamContinuation(
                $loopMessages,
                $this->controller->getToolDefs(),
                $webSearch,
            );

            if (is_wp_error($nextResponse)) {
                $this->controller->emitSSE("error", [
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

            if (empty($messageData["tool_calls"])) {
                $orchestrator->transitionTo(AgentState::VERIFYING);
                $this->controller->emitSSE("activity_update", [
                    "text" => AgentState::VERIFYING->label(),
                ]);
                $this->controller->emitSSE("state", [
                    "state" => AgentState::VERIFYING->value,
                    "label" => AgentState::VERIFYING->label(),
                ]);

                $finalMessage = $this->controller->sanitizeAssistantMessageContent(
                    (string) ($messageData["content"] ?? ""),
                );

                if ($finalMessage === "") {
                    $finalMessage = $this->recoverStreamedContentOrFallbackV2(
                        $orchestrator->getExecutionLog(),
                    );
                }

                if ($this->controller->wasResponseTruncated($nextResponse)) {
                    $finalMessage = $this->controller->appendTruncationHint(
                        $finalMessage,
                    );
                }

                $this->conversationRepo->saveMessage(
                    $sessionId,
                    $userId,
                    "assistant",
                    $finalMessage,
                );

                $orchestrator->transitionTo(AgentState::DONE);
                $this->controller->emitSSE("activity_complete", []);
                $this->controller->emitSSE("state", [
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
                    "truncated" => $this->controller->wasResponseTruncated(
                        $nextResponse,
                    ),
                    "state_history" => $orchestrator->getStateHistory(),
                ];
                $this->controller->emitSSE("activity_complete", []);
                $donePayload[
                    "usage"
                ] = $this->controller->getUsageAccumulator();
                $this->controller->emitSSE("done", $donePayload);
                $this->controller->flushUsage($sessionId, $userId);
                return;
            }
        }

        $orchestrator->transitionTo(AgentState::ERROR);
        $this->controller->emitSSE("state", [
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

        $this->controller->emitSSE("activity_complete", []);
        $this->controller->emitSSE("done", [
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
            "usage" => $this->controller->getUsageAccumulator(),
        ]);
        $this->controller->flushUsage($sessionId, $userId);
    }

    public function handleToolCallsV2(
        array $messageData,
        array $messages,
        string $sessionId,
        int $userId,
        string $latestUserMessage,
        bool $webSearch = false,
    ): WP_REST_Response {
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
                    if ($guard["verdict"] === ToolGuard::BLOCK) {
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
                    "content" => $this->controller->compactToolResultForModel(
                        $result,
                    ),
                    "_levi_iteration" => $iteration,
                    "_levi_tool" => $functionName,
                ];
            }

            if ($consecutiveFailureCount >= self::V2_FALLBACK_THRESHOLD) {
                error_log(
                    "Levi V2 (non-streaming): {$consecutiveFailureCount} consecutive failures — stopping tool loop",
                );
                $orchestrator->transitionTo(AgentState::ERROR);

                $failedTools = array_values(
                    array_unique(
                        array_map(
                            fn($r) => $r["tool"] ?? "",
                            array_slice(
                                $orchestrator->getExecutionLog(),
                                -$consecutiveFailureCount,
                            ),
                        ),
                    ),
                );

                $errorMessage =
                    "Mehrere aufeinanderfolgende Fehler mit den generischen Tools (" .
                    implode(", ", $failedTools) .
                    "). " .
                    "Du kannst in den Einstellungen die generischen Tools deaktivieren und die spezialisierten Tools verwenden.";

                $this->conversationRepo->saveMessage(
                    $sessionId,
                    $userId,
                    "assistant",
                    $errorMessage,
                );

                return new WP_REST_Response([
                    "session_id" => $sessionId,
                    "message" => $errorMessage,
                    "tools_used" => array_values(
                        array_unique(
                            array_map(
                                fn($r) => $r["tool"] ?? "",
                                $orchestrator->getExecutionLog(),
                            ),
                        ),
                    ),
                    "state_history" => $orchestrator->getStateHistory(),
                    "usage" => $this->controller->getUsageAccumulator(),
                ]);
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

            $loopMessages = $this->controller->compactMessagesForToolLoop(
                $messages,
                $iteration,
            );
            $nextResponse = $this->controller->chatWithTracking(
                $loopMessages,
                $this->controller->getToolDefs(),
                null,
                $webSearch,
            );

            if (is_wp_error($nextResponse)) {
                return new WP_REST_Response(
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
                $finalMessage = $this->controller->sanitizeAssistantMessageContent(
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

                return new WP_REST_Response(
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
                        "truncated" => $this->controller->wasResponseTruncated(
                            $nextResponse,
                        ),
                        "state_history" => $orchestrator->getStateHistory(),
                        "usage" => $this->controller->getUsageAccumulator(),
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

        return new WP_REST_Response(
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
                "usage" => $this->controller->getUsageAccumulator(),
            ],
            200,
        );
    }

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

    // =====================================================================
    // Validation & Mutation Gate (from ChatController)
    // =====================================================================

    public function validateToolCall(string $toolName, array $args): array
    {
        if ($toolName === "") {
            return [
                "allow" => false,
                "reason" => "Leerer Tool-Name ist nicht gueltig.",
            ];
        }

        if ($this->isPluginMutationTool($toolName)) {
            $pluginSlug = $this->extractPluginSlug($args);
            if ($pluginSlug === "") {
                return [
                    "allow" => false,
                    "reason" =>
                        "Plugin-Bearbeitung blockiert: plugin_slug fehlt oder ist ungueltig.",
                ];
            }
            if (!$this->isPluginSlugOwnedOrAllowed($pluginSlug)) {
                return [
                    "allow" => false,
                    "reason" => "Plugin-Bearbeitung blockiert: '$pluginSlug' ist kein freigegebenes eigenes Plugin (Drittanbieter-Schutz aktiv).",
                ];
            }
        }

        return ["allow" => true];
    }

    private function isPluginMutationTool(string $toolName): bool
    {
        return in_array(
            $toolName,
            ["write_plugin_file", "patch_plugin_file", "delete_plugin_file"],
            true,
        );
    }

    private function extractPluginSlug(array $args): string
    {
        $slug = sanitize_title((string) ($args["plugin_slug"] ?? ""));
        return $slug;
    }

    private function isPluginSlugOwnedOrAllowed(string $pluginSlug): bool
    {
        $slug = sanitize_title($pluginSlug);
        if ($slug === "") {
            return false;
        }

        $owned = $this->getOwnedPluginSlugs();
        if (in_array($slug, $owned, true)) {
            return true;
        }

        $manualAllowed = $this->getManualAllowedPluginSlugs();
        return in_array($slug, $manualAllowed, true);
    }

    private function getOwnedPluginSlugs(): array
    {
        $this->bootstrapOwnedPluginSlugsFromAuditLog();
        $stored = get_option(ChatController::OWNED_PLUGIN_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $slugs = [];
        foreach ($stored as $entry) {
            $slug = sanitize_title((string) $entry);
            if ($slug !== "") {
                $slugs[] = $slug;
            }
        }
        return array_values(array_unique($slugs));
    }

    private function getManualAllowedPluginSlugs(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $settings = $this->settings->getSettings();
        $raw = (string) ($settings["allowed_plugin_slugs_manual"] ?? "");
        $parts = preg_split("/[\s,;]+/u", $raw) ?: [];
        $allowed = [];
        foreach ($parts as $part) {
            $slug = sanitize_title((string) $part);
            if ($slug !== "") {
                $allowed[] = $slug;
            }
        }
        $cache = array_values(array_unique($allowed));
        return $cache;
    }

    private function bootstrapOwnedPluginSlugsFromAuditLog(): void
    {
        if (
            (int) get_option(
                ChatController::OWNED_PLUGIN_BOOTSTRAP_OPTION,
                0,
            ) === 1
        ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . "levi_audit_log";
        $tableExists =
            $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) ===
            $table;
        if (!$tableExists) {
            update_option(
                ChatController::OWNED_PLUGIN_BOOTSTRAP_OPTION,
                1,
                false,
            );
            return;
        }

        $rows = $wpdb->get_col(
            "SELECT tool_args FROM {$table} WHERE tool_name = 'create_plugin' AND success = 1 ORDER BY id ASC",
        );
        if (!is_array($rows) || empty($rows)) {
            update_option(
                ChatController::OWNED_PLUGIN_BOOTSTRAP_OPTION,
                1,
                false,
            );
            return;
        }

        $collected = [];
        foreach ($rows as $rawArgs) {
            if (!is_string($rawArgs) || $rawArgs === "") {
                continue;
            }
            $decoded = json_decode($rawArgs, true);
            if (!is_array($decoded)) {
                continue;
            }
            $slug = sanitize_title(
                (string) ($decoded["slug"] ?? ($decoded["plugin_slug"] ?? "")),
            );
            if ($slug !== "") {
                $collected[] = $slug;
            }
        }

        $existing = get_option(ChatController::OWNED_PLUGIN_OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $merged = [];
        foreach (array_merge($existing, $collected) as $entry) {
            $slug = sanitize_title((string) $entry);
            if ($slug !== "") {
                $merged[] = $slug;
            }
        }
        update_option(
            ChatController::OWNED_PLUGIN_OPTION,
            array_values(array_unique($merged)),
            false,
        );
        update_option(ChatController::OWNED_PLUGIN_BOOTSTRAP_OPTION, 1, false);
    }

    public function isMutatingToolName(string $toolName): bool
    {
        return in_array(
            $toolName,
            [
                "create_post",
                "update_post",
                "create_page",
                "delete_post",
                "install_plugin",
                "switch_theme",
                "manage_user",
                "create_plugin",
                "write_plugin_file",
                "patch_plugin_file",
                "delete_plugin_file",
                "write_theme_file",
                "create_theme",
                "delete_theme_file",
                "manage_post_meta",
                "manage_taxonomy",
                "manage_woocommerce",
                "manage_menu",
                "manage_cron",
                "upload_media",
                "store_session_image",
                "update_option",
                "update_any_option",
                "execute_wp_code",
            ],
            true,
        );
    }

    public function hasSuccessfulMutation(array $toolResults): bool
    {
        foreach ($toolResults as $row) {
            $tool = (string) ($row["tool"] ?? "");
            $success = (bool) ($row["result"]["success"] ?? false);
            if ($success && $this->isMutatingToolName($tool)) {
                return true;
            }
        }
        return false;
    }

    public function hasFailedMutation(array $toolResults): bool
    {
        foreach ($toolResults as $row) {
            $tool = (string) ($row["tool"] ?? "");
            $success = (bool) ($row["result"]["success"] ?? false);
            if (!$success && $this->isMutatingToolName($tool)) {
                return true;
            }
        }
        return false;
    }

    public function enforceMutationGate(
        array &$messages,
        array $messageData,
        array $toolResults,
        string $userMessage,
        bool $webSearch,
        ?callable $heartbeat = null,
    ): ?array {
        $hasMutation = $this->hasSuccessfulMutation($toolResults);

        if (!$hasMutation) {
            if (!$this->classifyMutationIntent($userMessage, $messages)) {
                return null;
            }

            error_log(
                "Levi: enforceMutationGate Path A — user expects mutation but none ran, enforcing tool_choice:required",
            );

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $assistantEntry = [
                    "role" => "assistant",
                    "content" => $messageData["content"] ?? "",
                ];
                if (!empty($messageData["reasoning_content"])) {
                    $assistantEntry["reasoning_content"] =
                        $messageData["reasoning_content"];
                }
                $messages[] = $assistantEntry;
                $messages[] = [
                    "role" => "system",
                    "content" =>
                        "[SYSTEM – MUTATION ENFORCEMENT] Du hast nur Text generiert, aber der Nutzer erwartet " .
                        "eine konkrete Aenderung an der Website. Fuehre JETZT die passenden Tools aus. " .
                        "Falls du die Aenderung nicht durchfuehren kannst, erklaere ehrlich warum — " .
                        "aber behaupte NICHT, dass du etwas erledigt hast.",
                ];

                $enforced = $this->messagePipeline->chatWithTracking(
                    $messages,
                    $this->controller->getToolDefs(),
                    $heartbeat,
                    $webSearch,
                    "required",
                );
                if (is_wp_error($enforced)) {
                    error_log(
                        "Levi: enforceMutationGate retry failed: " .
                            $enforced->get_error_message(),
                    );
                    continue;
                }

                $enforcedData = $enforced["choices"][0]["message"] ?? [];
                if (!empty($enforcedData["tool_calls"])) {
                    return $enforcedData;
                }

                $messageData = $enforcedData;
            }

            error_log(
                "Levi: enforceMutationGate Path A exhausted — sending honest failure",
            );
            return null;
        }

        if (!$this->hasFailedMutation($toolResults)) {
            error_log(
                "Levi: enforceMutationGate Path B — all mutations succeeded, skipping completeness check",
            );
            return null;
        }

        if (
            $this->classifyTaskCompleteness(
                $userMessage,
                $toolResults,
                $messages,
            )
        ) {
            return null;
        }

        error_log(
            "Levi: enforceMutationGate Path B — task incomplete, nudging for remaining steps",
        );

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $assistantEntry = [
                "role" => "assistant",
                "content" => $messageData["content"] ?? "",
            ];
            if (!empty($messageData["reasoning_content"])) {
                $assistantEntry["reasoning_content"] =
                    $messageData["reasoning_content"];
            }
            $messages[] = $assistantEntry;
            $messages[] = [
                "role" => "system",
                "content" =>
                    "[SYSTEM – COMPLETENESS ENFORCEMENT] Du hast die Aufgabe nur teilweise erledigt. " .
                    "Pruefe, welche Schritte aus der Nutzer-Anfrage noch fehlen und fuehre sie JETZT aus. " .
                    'Melde dem Nutzer erst "fertig", wenn ALLE angeforderten Aenderungen durchgefuehrt sind.',
            ];

            $enforced = $this->messagePipeline->chatWithTracking(
                $messages,
                $this->controller->getToolDefs(),
                $heartbeat,
                $webSearch,
                "required",
            );
            if (is_wp_error($enforced)) {
                error_log(
                    "Levi: enforceMutationGate completeness retry failed: " .
                        $enforced->get_error_message(),
                );
                continue;
            }

            $enforcedData = $enforced["choices"][0]["message"] ?? [];
            if (!empty($enforcedData["tool_calls"])) {
                return $enforcedData;
            }

            $messageData = $enforcedData;
        }

        error_log(
            "Levi: enforceMutationGate Path B exhausted — letting partial response through",
        );
        return null;
    }

    private function classifyMutationIntent(
        string $userMessage,
        array $messages,
    ): bool {
        $contextSnippets = [];
        $recentMessages = array_slice($messages, -6);
        foreach ($recentMessages as $msg) {
            $role = $msg["role"] ?? "";
            $content = $msg["content"] ?? "";
            if (
                !in_array($role, ["user", "assistant"], true) ||
                !is_string($content) ||
                $content === ""
            ) {
                continue;
            }
            $truncated = mb_substr($content, 0, 300);
            $contextSnippets[] =
                ($role === "user" ? "Nutzer" : "Assistent") . ": " . $truncated;
        }

        $contextBlock = !empty($contextSnippets)
            ? "Konversationskontext:\n" .
                implode("\n", $contextSnippets) .
                "\n\n"
            : "";

        $classifyMessages = [
            [
                "role" => "system",
                "content" =>
                    "Du bist ein Klassifikator. Deine einzige Aufgabe: Bestimme, ob der Nutzer erwartet, " .
                    "dass an seiner WordPress-Website etwas geaendert, erstellt, geloescht, aktualisiert, " .
                    "veroeffentlicht, installiert oder konfiguriert wird. " .
                    "Antworte NUR mit einem einzigen Wort: ja oder nein",
            ],
            [
                "role" => "user",
                "content" =>
                    $contextBlock .
                    "Aktuelle Nutzer-Nachricht: " .
                    mb_substr($userMessage, 0, 500) .
                    "\n\nErwartet der Nutzer eine Aenderung an der Website? (ja/nein)",
            ],
        ];

        try {
            $response = $this->aiClient->chat($classifyMessages, []);
            if (is_wp_error($response)) {
                error_log(
                    "Levi: classifyMutationIntent failed: " .
                        $response->get_error_message(),
                );
                return true;
            }
            $answer = mb_strtolower(
                trim(
                    (string) ($response["choices"][0]["message"]["content"] ??
                        ""),
                ),
            );
            $this->messagePipeline->accumulateUsage(
                $response,
                $this->controller->getUsageAccumulator(),
            );

            return str_starts_with($answer, "ja");
        } catch (\Throwable $e) {
            error_log(
                "Levi: classifyMutationIntent exception: " . $e->getMessage(),
            );
            return true;
        }
    }

    private function classifyTaskCompleteness(
        string $userMessage,
        array $toolResults,
        array $messages,
    ): bool {
        $toolSummaries = [];
        foreach ($toolResults as $r) {
            $tool = (string) ($r["tool"] ?? "");
            $success = $r["result"]["success"] ?? false ? "OK" : "FEHLER";
            $summary = $this->summarizeToolResult(
                is_array($r["result"] ?? null) ? $r["result"] : [],
            );
            $toolSummaries[] =
                "- {$tool}: {$success}" .
                ($summary !== "" ? " ({$summary})" : "");
        }

        $contextSnippets = [];
        $recentMessages = array_slice($messages, -4);
        foreach ($recentMessages as $msg) {
            $role = $msg["role"] ?? "";
            $content = $msg["content"] ?? "";
            if (
                !in_array($role, ["user", "assistant"], true) ||
                !is_string($content) ||
                $content === ""
            ) {
                continue;
            }
            $truncated = mb_substr($content, 0, 200);
            $contextSnippets[] =
                ($role === "user" ? "Nutzer" : "Assistent") . ": " . $truncated;
        }

        $contextBlock = !empty($contextSnippets)
            ? "Konversationskontext:\n" .
                implode("\n", $contextSnippets) .
                "\n\n"
            : "";

        $classifyMessages = [
            [
                "role" => "system",
                "content" =>
                    "Du bist ein Klassifikator. Deine einzige Aufgabe: Bestimme, ob ALLE vom Nutzer " .
                    "angeforderten Aenderungen durch die ausgefuehrten Tools erledigt wurden. " .
                    'Wenn der Nutzer z.B. "erstelle 3 Seiten und veroeffentliche sie" sagt, ' .
                    "muessen sowohl Erstellungs- als auch Veroeffentlichungs-Calls vorhanden sein. " .
                    "Antworte NUR mit einem einzigen Wort: ja oder nein",
            ],
            [
                "role" => "user",
                "content" =>
                    $contextBlock .
                    "Nutzer-Anfrage: " .
                    mb_substr($userMessage, 0, 500) .
                    "\n\nAusgefuehrte Tools:\n" .
                    implode("\n", $toolSummaries) .
                    "\n\nWurde alles erledigt was der Nutzer wollte? (ja/nein)",
            ],
        ];

        try {
            $response = $this->aiClient->chat($classifyMessages, []);
            if (is_wp_error($response)) {
                error_log(
                    "Levi: classifyTaskCompleteness failed: " .
                        $response->get_error_message(),
                );
                return true;
            }
            $answer = mb_strtolower(
                trim(
                    (string) ($response["choices"][0]["message"]["content"] ??
                        ""),
                ),
            );
            $this->messagePipeline->accumulateUsage(
                $response,
                $this->controller->getUsageAccumulator(),
            );

            return str_starts_with($answer, "ja");
        } catch (\Throwable $e) {
            error_log(
                "Levi: classifyTaskCompleteness exception: " . $e->getMessage(),
            );
            return true;
        }
    }
}
