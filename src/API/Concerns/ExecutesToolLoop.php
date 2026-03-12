<?php

namespace Levi\Agent\API\Concerns;

use Levi\Agent\AI\Tools\ToolGuard;
use WP_REST_Response;

trait ExecutesToolLoop
{
    private function handleToolCallsStreaming(
        array $messageData,
        array $messages,
        string $sessionId,
        int $userId,
        string $latestUserMessage,
        callable $heartbeat,
        bool $webSearch = false
    ): void {
        $toolResults = [];
        $pendingConfirmation = null;
        $runtimeSettings = $this->settings->getSettings();
        $maxIterations = max(1, (int) ($runtimeSettings['max_tool_iterations'] ?? 25));
        $requireConfirmation = !empty($runtimeSettings['require_confirmation_destructive']);
        $taskIntent = $this->inferTaskIntent($latestUserMessage, $messages);
        $iteration = 0;
        $mutationNudgeCount = 0;
        $completionGateCount = 0;
        $hasConfirmation = $this->hasUserConfirmationSignal($latestUserMessage);

        while ($iteration < $maxIterations) {
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
                $functionArgs = $this->normalizeToolArgumentsForIntent($functionName, $functionArgs, $latestUserMessage);
                $toolCallId = $toolCall['id'] ?? '';

                $planValidation = $this->validateToolCall($functionName, $functionArgs);
                if (!($planValidation['allow'] ?? false)) {
                    $result = [
                        'success' => false,
                        'needs_replan' => true,
                        'error' => (string) ($planValidation['reason'] ?? 'Tool passt nicht zum internen Ausfuehrungsplan.'),
                        'tool' => $functionName,
                    ];
                    $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                    $toolResults[] = [
                        'tool' => $functionName,
                        'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                        'result' => $result,
                        'seq' => count($toolResults),
                        'iteration' => $iteration,
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $this->compactToolResultForModel($result),
                    ];
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Tool-Call blockiert: Er passt nicht zum internen Plan (Domain/Intent). '
                            . 'Plane die naechsten Schritte neu und verwende nur passende Tools fuer diese Aufgabe.',
                    ];
                    continue;
                }

                $guardResult = $this->toolGuard->evaluate($functionName, $functionArgs);

                error_log(sprintf(
                    'Levi ToolGuard [streaming]: tool=%s verdict=%s requireConfirm=%s hasConfirm=%s reason=%s',
                    $functionName,
                    $guardResult['verdict'] ?? 'null',
                    $requireConfirmation ? 'true' : 'false',
                    $hasConfirmation ? 'true' : 'false',
                    $guardResult['reason'] ?? '-'
                ));

                if ($guardResult['verdict'] === ToolGuard::BLOCK) {
                    $result = [
                        'success' => false,
                        'blocked' => true,
                        'error' => $guardResult['reason'] ?? 'Tool-Call blockiert.',
                        'tool' => $functionName,
                    ];
                    $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                    $toolResults[] = [
                        'tool' => $functionName,
                        'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                        'result' => $result,
                        'seq' => count($toolResults),
                        'iteration' => $iteration,
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $this->compactToolResultForModel($result),
                    ];
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Tool-Call blockiert: ' . ($guardResult['reason'] ?? '')
                            . ' Waehle einen anderen Ansatz.',
                    ];
                    continue;
                }

                $this->emitSSE('progress', [
                    'message' => $this->getToolProgressLabel($functionName, 'start'),
                    'tool' => $functionName,
                    'iteration' => $iteration,
                ]);

                if ($requireConfirmation && $guardResult['verdict'] === ToolGuard::ESCALATE && !$hasConfirmation) {
                    $actionId = wp_generate_uuid4();
                    set_transient('levi_pending_' . $actionId, [
                        'tool_name' => $functionName,
                        'tool_args' => $functionArgs,
                        'session_id' => $sessionId,
                        'user_id' => $userId,

                        'created_at' => time(),
                    ], 300);
                    $pendingConfirmation = [
                        'action_id' => $actionId,
                        'tool' => $functionName,
                        'description' => $this->describeToolAction($functionName, $functionArgs),
                    ];
                    $result = [
                        'success' => false,
                        'needs_confirmation' => true,
                        'action_id' => $actionId,
                        'error' => $guardResult['reason'] ?? 'Fuer diese Aktion brauche ich eine explizite Bestaetigung.',
                        'tool' => $functionName,
                    ];

                    $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                    $toolResults[] = [
                        'tool' => $functionName,
                        'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                        'result' => $result,
                        'seq' => count($toolResults),
                        'iteration' => $iteration,
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $this->compactToolResultForModel($result),
                    ];
                    break;
                } else {
                    $loopNudge = $this->detectToolLoop($toolResults, $functionName, $functionArgs);
                    if ($loopNudge !== null) {
                        error_log('Levi: loop detection triggered for ' . $functionName);
                        $result = [
                            'success' => false,
                            'loop_detected' => true,
                            'error' => 'Wiederholter Aufruf erkannt. Waehle einen anderen Ansatz.',
                            'tool' => $functionName,
                        ];
                        $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                        $toolResults[] = [
                            'tool' => $functionName,
                            'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                            'result' => $result,
                            'seq' => count($toolResults),
                            'iteration' => $iteration,
                        ];
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content' => $this->compactToolResultForModel($result),
                        ];
                        $messages[] = [
                            'role' => 'system',
                            'content' => $loopNudge,
                        ];
                        continue;
                    }

                    $result = $this->executeToolWithAutopaging($functionName, $functionArgs, $latestUserMessage);
                }

                if ($functionName === 'search_tools' && !empty($result['tools'])) {
                    $this->addDiscoveredTools(array_column($result['tools'], 'name'));
                }

                $this->trackOwnedPluginFromToolResult($functionName, $functionArgs, $result);
                $this->registerApprovedContext($functionName, $functionArgs, $result);
                $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                $toolResults[] = [
                    'tool' => $functionName,
                    'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                    'result' => $result,
                    'seq' => count($toolResults),
                    'iteration' => $iteration,
                ];

                $this->emitSSE('progress', [
                    'message' => $this->getToolProgressLabel($functionName, ($result['success'] ?? false) ? 'done' : 'failed'),
                    'tool' => $functionName,
                    'iteration' => $iteration,
                    'success' => $result['success'] ?? false,
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $this->compactToolResultForModel($result),
                ];
            }

            if ($pendingConfirmation !== null) {
                $confirmDesc = $pendingConfirmation['description']
                    ?? $this->describeToolAction($pendingConfirmation['tool'] ?? '', []);
                $finalMessage = "Ich moechte: **{$confirmDesc}**\n\nBitte bestaetige ueber den Button. 🔒";
                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
                $this->emitSSE('done', [
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
                    'pending_confirmation' => $pendingConfirmation,
                    'usage' => $this->usageAccumulator,
                ]);
                $this->flushUsage($sessionId, $userId);
                return;
            }

            $postWriteMessages = $this->injectPostWriteValidation($toolCalls, $toolResults);
            if (!empty($postWriteMessages)) {
                $this->emitSSE('progress', [
                    'message' => 'Prüfe Error-Logs...',
                    'tool' => 'read_error_log',
                    'iteration' => $iteration,
                ]);
                foreach ($postWriteMessages as $pwm) {
                    $messages[] = $pwm;
                }
            }

            $scaffoldNudge = $this->injectPostCreatePluginNudge($toolCalls, $toolResults);
            foreach ($scaffoldNudge as $nudge) {
                $messages[] = $nudge;
            }

            $cssNudge = $this->injectPostCSSWriteNudge($toolCalls, $toolResults);
            foreach ($cssNudge as $nudge) {
                $messages[] = $nudge;
            }

            $smokeTest = $this->injectPostPluginSmokeTest($toolCalls, $toolResults);
            if (!empty($smokeTest)) {
                $this->emitSSE('progress', [
                    'message' => 'Smoke-Test: Plugin wird aktiviert und getestet...',
                    'tool' => 'http_fetch',
                    'iteration' => $iteration,
                ]);
                foreach ($smokeTest as $st) {
                    $messages[] = $st;
                }
            }

            $integrationCheck = $this->injectPostWriteIntegrationCheck($toolCalls, $toolResults);
            if (!empty($integrationCheck)) {
                $this->emitSSE('progress', [
                    'message' => 'Prüfe Datei-Integration...',
                    'tool' => 'integration_check',
                    'iteration' => $iteration,
                ]);
                foreach ($integrationCheck as $ic) {
                    $messages[] = $ic;
                }
            }

            $codeTagWarnings = $this->injectCodeTagWarnings($toolResults);
            if (!empty($codeTagWarnings)) {
                $this->emitSSE('progress', [
                    'message' => 'Code-Tag-Sanitierung...',
                    'tool' => 'code_tag_check',
                    'iteration' => $iteration,
                ]);
                foreach ($codeTagWarnings as $ctw) {
                    $messages[] = $ctw;
                }
            }

            $toolMismatch = $this->injectToolMismatchCorrection($latestUserMessage, $toolResults);
            if (!empty($toolMismatch)) {
                $this->emitSSE('progress', [
                    'message' => 'Tool-Korrektur...',
                    'tool' => 'tool_mismatch_check',
                    'iteration' => $iteration,
                ]);
                foreach ($toolMismatch as $tm) {
                    $messages[] = $tm;
                }
            }

            if (connection_aborted()) {
                error_log('Levi: client disconnected during tool loop');
                return;
            }

            $this->emitSSE('status', ['message' => 'Levi antwortet...']);

            $loopMessages = $this->compactMessagesForToolLoop($messages, $iteration);
            $nextResponse = $this->streamContinuation($loopMessages, $this->getToolDefs(), $webSearch);
            if (is_wp_error($nextResponse)) {
                $this->emitSSE('error', [
                    'message' => $nextResponse->get_error_message(),
                    'session_id' => $sessionId,
                    'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
                ]);
                return;
            }

            $messageData = $nextResponse['choices'][0]['message'] ?? [];
            if (empty($messageData['tool_calls'])) {
                $completionIssues = $this->checkWriteCompleteness($toolResults);
                if ($completionIssues !== null && $completionGateCount < 2) {
                    $completionGateCount++;
                    $this->emitSSE('progress', [
                        'message' => 'Completion-Check: Prüfe Datei-Vollständigkeit...',
                        'tool' => 'completion_gate',
                        'iteration' => $iteration,
                    ]);
                    $messages[] = ['role' => 'assistant', 'content' => $messageData['content'] ?? ''];
                    $messages[] = [
                        'role' => 'system',
                        'content' => "[SYSTEM – COMPLETION CHECK FAILED]\n" . $completionIssues
                            . "\n\nDu darfst dem Nutzer NICHT 'fertig' melden bevor diese Probleme behoben sind. "
                            . "Fuehre die noetige(n) write_plugin_file / patch_plugin_file Aktion(en) jetzt aus.",
                    ];
                    $gateResponse = $this->chatWithTracking($messages, $this->getToolDefs(), $heartbeat, $webSearch);
                    if (!is_wp_error($gateResponse)) {
                        $messageData = $gateResponse['choices'][0]['message'] ?? [];
                        if (!empty($messageData['tool_calls'])) {
                            continue;
                        }
                    }
                }

                if ($pendingConfirmation === null && $this->shouldNudgePendingMutation($toolResults, $taskIntent, $mutationNudgeCount)) {
                    $mutationNudgeCount++;
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Der Nutzer hat eine konkrete Aenderung angefordert. Du hast bisher nur gelesen oder geprueft. '
                            . 'Fuehre jetzt den passenden mutierenden Tool-Call aus (z. B. delete/update/create/install), '
                            . 'oder erklaere konkret, warum die Ausfuehrung nicht moeglich ist. Behaupte keinen Abschluss ohne mutierenden Erfolg.',
                    ];
                    $nudgedResponse = $this->chatWithTracking($messages, $this->getToolDefs(), $heartbeat, $webSearch);
                    if (is_wp_error($nudgedResponse)) {
                        $this->emitSSE('error', [
                            'message' => $nudgedResponse->get_error_message(),
                            'session_id' => $sessionId,
                            'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
                        ]);
                        return;
                    }
                    $messageData = $nudgedResponse['choices'][0]['message'] ?? [];
                    if (!empty($messageData['tool_calls'])) {
                        continue;
                    }
                }

                $finalMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($messageData['content'] ?? '')
                );

                if ($pendingConfirmation === null && $this->looksLikeFakeConfirmation($finalMessage)) {
                    $messages[] = ['role' => 'assistant', 'content' => $finalMessage];
                    $messages[] = [
                        'role' => 'system',
                        'content' => '[SYSTEM] Du hast eine Bestaetigungsanfrage als TEXT geschrieben. Das ist FALSCH. '
                            . 'Der Nutzer sieht keinen Button und haengt fest. '
                            . 'Fuehre den destruktiven Tool-Call (delete_post, switch_theme, install_plugin, etc.) JETZT aus. '
                            . 'Das Backend zeigt dem Nutzer automatisch einen Bestaetigungs-Button.',
                    ];
                    $retryResponse = $this->chatWithTracking($messages, $this->getToolDefs(), $heartbeat, $webSearch);
                    if (!is_wp_error($retryResponse)) {
                        $retryData = $retryResponse['choices'][0]['message'] ?? [];
                        if (!empty($retryData['tool_calls'])) {
                            $messageData = $retryData;
                            continue;
                        }
                    }
                }

                if ($finalMessage === '') {
                    error_log('Levi: empty AI response after tool loop, nudging for summary');
                    $messages[] = ['role' => 'assistant', 'content' => $messageData['content'] ?? ''];
                    $messages[] = [
                        'role' => 'system',
                        'content' => '[SYSTEM] Deine letzte Antwort war leer. Fasse jetzt kurz und freundlich zusammen, '
                            . 'was du fuer den Nutzer erledigt hast. Nenne konkrete Ergebnisse (Dateinamen, IDs, etc.).',
                    ];
                    $summaryResponse = $this->streamContinuation(
                        $this->compactMessagesForToolLoop($messages, $iteration),
                        [],
                        false
                    );
                    if (!is_wp_error($summaryResponse)) {
                        $finalMessage = $this->sanitizeAssistantMessageContent(
                            (string) ($summaryResponse['choices'][0]['message']['content'] ?? '')
                        );
                    }
                    if ($finalMessage === '') {
                        $finalMessage = $this->recoverStreamedContentOrFallback($toolResults);
                    }
                }

                $finalMessage = $this->applyResponseSafetyGates($finalMessage, $toolResults, $taskIntent);

                if ($this->wasResponseTruncated($nextResponse)) {
                    $finalMessage = $this->appendTruncationHint($finalMessage);
                }

                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

                $donePayload = [
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'model' => $nextResponse['model'] ?? null,
                    'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
                    'truncated' => $this->wasResponseTruncated($nextResponse),
                ];
                if ($pendingConfirmation !== null) {
                    $donePayload['pending_confirmation'] = $pendingConfirmation;
                }
                $donePayload['usage'] = $this->usageAccumulator;
                $this->emitSSE('done', $donePayload);
                $this->flushUsage($sessionId, $userId);
                return;
            }
        }

        $finalMessage = $this->recoverStreamedContentOrFallback($toolResults);
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

        $fallbackPayload = [
            'session_id' => $sessionId,
            'message' => $finalMessage,
            'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
        ];
        if ($pendingConfirmation !== null) {
            $fallbackPayload['pending_confirmation'] = $pendingConfirmation;
        }
        $fallbackPayload['usage'] = $this->usageAccumulator;
        $this->emitSSE('done', $fallbackPayload);
        $this->flushUsage($sessionId, $userId);
    }

    private function getToolProgressLabel(string $toolName, string $phase): string {
        $humanNames = [
            'get_posts' => 'Beitraege lesen',
            'get_post' => 'Beitrag lesen',
            'get_pages' => 'Seiten lesen',
            'get_users' => 'Benutzer lesen',
            'get_media' => 'Medien lesen',
            'get_plugins' => 'Plugins pruefen',
            'get_options' => 'Einstellungen lesen',
            'create_post' => 'Beitrag erstellen',
            'create_page' => 'Seite erstellen',
            'update_post' => 'Beitrag aktualisieren',
            'delete_post' => 'Beitrag loeschen',
            'create_plugin' => 'Plugin erstellen',
            'install_plugin' => 'Plugin installieren',
            'list_plugin_files' => 'Plugin-Dateien auflisten',
            'read_plugin_file' => 'Plugin-Datei lesen',
            'write_plugin_file' => 'Plugin-Datei schreiben',
            'patch_plugin_file' => 'Plugin-Datei patchen',
            'list_theme_files' => 'Theme-Dateien auflisten',
            'read_theme_file' => 'Theme-Datei lesen',
            'write_theme_file' => 'Theme-Datei schreiben',
            'read_error_log' => 'Error-Log pruefen',
            'upload_media' => 'Medien hochladen',
            'update_option' => 'Einstellung aendern',
            'manage_post_meta' => 'Metadaten verarbeiten',
            'manage_taxonomy' => 'Taxonomie verarbeiten',
            'manage_menu' => 'Menue bearbeiten',
            'manage_cron' => 'Cron-Aufgaben verwalten',
            'get_woocommerce_data' => 'Shop-Daten lesen',
            'get_woocommerce_shop' => 'Shop-Status pruefen',
            'manage_woocommerce' => 'Shop bearbeiten',
            'get_elementor_data' => 'Elementor-Layout lesen',
            'elementor_build' => 'Elementor-Layout bearbeiten',
            'manage_elementor' => 'Elementor verwalten',
            'discover_content_types' => 'Inhaltstypen erkennen',
            'discover_rest_api' => 'REST-API erkennen',
            'execute_wp_code' => 'Code ausfuehren',
        ];

        $name = $humanNames[$toolName] ?? $toolName;

        return match ($phase) {
            'start' => 'Levi fuehrt ' . $name . ' aus...',
            'done' => 'Levi hat ' . $name . ' ausgefuehrt',
            'failed' => 'Levi: ' . $name . ' fehlgeschlagen',
            default => $name,
        };
    }

    /**
     * Handle tool calls from AI
     */
    private function handleToolCalls(array $messageData, array $messages, string $sessionId, int $userId, string $latestUserMessage, bool $webSearch = false): WP_REST_Response {
        $toolResults = [];
        $executionTrace = [];
        $pendingConfirmation = null;
        $runtimeSettings = $this->settings->getSettings();
        $maxIterations = max(1, (int) ($runtimeSettings['max_tool_iterations'] ?? 25));
        $requireConfirmation = !empty($runtimeSettings['require_confirmation_destructive']);
        $taskIntent = $this->inferTaskIntent($latestUserMessage, $messages);
        $iteration = 0;
        $mutationNudgeCount = 0;
        $completionGateCount = 0;
        $hasConfirmation = $this->hasUserConfirmationSignal($latestUserMessage);

        while ($iteration < $maxIterations) {
            $toolCalls = $messageData['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                break;
            }

            $iteration++;
            // Add AI's tool call request to messages
            $messages[] = $messageData;

            foreach ($toolCalls as $index => $toolCall) {
                $functionName = trim($toolCall['function']['name'] ?? '');
                $rawArgs = $toolCall['function']['arguments'] ?? '{}';

                $functionArgs = json_decode($rawArgs, true);
                if (!is_array($functionArgs)) {
                    $functionArgs = [];
                }
                $functionArgs = $this->normalizeToolArgumentsForIntent($functionName, $functionArgs, $latestUserMessage);
                $toolCallId = $toolCall['id'] ?? '';

                $planValidation = $this->validateToolCall($functionName, $functionArgs);
                if (!($planValidation['allow'] ?? false)) {
                    $result = [
                        'success' => false,
                        'needs_replan' => true,
                        'error' => (string) ($planValidation['reason'] ?? 'Tool passt nicht zum internen Ausfuehrungsplan.'),
                        'tool' => $functionName,
                    ];
                    $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                    $toolResults[] = [
                        'tool' => $functionName,
                        'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                        'result' => $result,
                        'seq' => count($toolResults),
                        'iteration' => $iteration,
                    ];
                    $executionTrace[] = [
                        'iteration' => $iteration,
                        'step' => count($executionTrace) + 1,
                        'tool' => $functionName,
                        'status' => 'blocked_by_plan',
                        'timestamp' => current_time('mysql'),
                        'summary' => $this->summarizeToolResult($result),
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $this->compactToolResultForModel($result),
                    ];
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Tool-Call blockiert: Er passt nicht zum internen Plan (Domain/Intent). '
                            . 'Plane die naechsten Schritte neu und verwende nur passende Tools fuer diese Aufgabe.',
                    ];
                    continue;
                }

                $guardResult = $this->toolGuard->evaluate($functionName, $functionArgs);

                error_log(sprintf(
                    'Levi ToolGuard [classic]: tool=%s verdict=%s requireConfirm=%s hasConfirm=%s reason=%s',
                    $functionName,
                    $guardResult['verdict'] ?? 'null',
                    $requireConfirmation ? 'true' : 'false',
                    $hasConfirmation ? 'true' : 'false',
                    $guardResult['reason'] ?? '-'
                ));

                if ($guardResult['verdict'] === ToolGuard::BLOCK) {
                    $result = [
                        'success' => false,
                        'blocked' => true,
                        'error' => $guardResult['reason'] ?? 'Tool-Call blockiert.',
                        'tool' => $functionName,
                    ];
                    $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                    $toolResults[] = [
                        'tool' => $functionName,
                        'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                        'result' => $result,
                        'seq' => count($toolResults),
                        'iteration' => $iteration,
                    ];
                    $executionTrace[] = [
                        'iteration' => $iteration,
                        'step' => count($executionTrace) + 1,
                        'tool' => $functionName,
                        'status' => 'blocked_by_guard',
                        'timestamp' => current_time('mysql'),
                        'summary' => $this->summarizeToolResult($result),
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $this->compactToolResultForModel($result),
                    ];
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Tool-Call blockiert: ' . ($guardResult['reason'] ?? '')
                            . ' Waehle einen anderen Ansatz.',
                    ];
                    continue;
                }

                $executionTrace[] = [
                    'iteration' => $iteration,
                    'step' => count($executionTrace) + 1,
                    'tool' => $functionName,
                    'status' => 'started',
                    'timestamp' => current_time('mysql'),
                    'details' => [
                        'tool_call_index' => $index,
                    ],
                ];

                if ($requireConfirmation && $guardResult['verdict'] === ToolGuard::ESCALATE && !$hasConfirmation) {
                    $actionId = wp_generate_uuid4();
                    set_transient('levi_pending_' . $actionId, [
                        'tool_name' => $functionName,
                        'tool_args' => $functionArgs,
                        'session_id' => $sessionId,
                        'user_id' => $userId,

                        'created_at' => time(),
                    ], 300);
                    $pendingConfirmation = [
                        'action_id' => $actionId,
                        'tool' => $functionName,
                        'description' => $this->describeToolAction($functionName, $functionArgs),
                    ];
                    $result = [
                        'success' => false,
                        'needs_confirmation' => true,
                        'action_id' => $actionId,
                        'error' => $guardResult['reason'] ?? 'Fuer diese Aktion brauche ich eine explizite Bestaetigung.',
                        'tool' => $functionName,
                    ];

                    $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                    $toolResults[] = [
                        'tool' => $functionName,
                        'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                        'result' => $result,
                        'seq' => count($toolResults),
                        'iteration' => $iteration,
                    ];
                    $executionTrace[] = [
                        'iteration' => $iteration,
                        'step' => count($executionTrace) + 1,
                        'tool' => $functionName,
                        'status' => 'awaiting_confirmation',
                        'timestamp' => current_time('mysql'),
                        'summary' => $this->summarizeToolResult($result),
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $this->compactToolResultForModel($result),
                    ];
                    break;
                } else {
                    $loopNudge = $this->detectToolLoop($toolResults, $functionName, $functionArgs);
                    if ($loopNudge !== null) {
                        error_log('Levi: loop detection triggered for ' . $functionName);
                        $result = [
                            'success' => false,
                            'loop_detected' => true,
                            'error' => 'Wiederholter Aufruf erkannt. Waehle einen anderen Ansatz.',
                            'tool' => $functionName,
                        ];
                        $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                        $toolResults[] = [
                            'tool' => $functionName,
                            'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                            'result' => $result,
                            'seq' => count($toolResults),
                            'iteration' => $iteration,
                        ];
                        $executionTrace[] = [
                            'iteration' => $iteration,
                            'step' => count($executionTrace) + 1,
                            'tool' => $functionName,
                            'status' => 'loop_detected',
                            'timestamp' => current_time('mysql'),
                            'summary' => 'Loop-Detection: wiederholter Aufruf blockiert',
                        ];
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content' => $this->compactToolResultForModel($result),
                        ];
                        $messages[] = [
                            'role' => 'system',
                            'content' => $loopNudge,
                        ];
                        continue;
                    }

                    $result = $this->executeToolWithAutopaging($functionName, $functionArgs, $latestUserMessage);
                }

                if ($functionName === 'search_tools' && !empty($result['tools'])) {
                    $this->addDiscoveredTools(array_column($result['tools'], 'name'));
                }

                $this->trackOwnedPluginFromToolResult($functionName, $functionArgs, $result);
                $this->registerApprovedContext($functionName, $functionArgs, $result);
                $this->logToolExecution($sessionId, $userId, $functionName, $functionArgs, $result);
                $toolResults[] = [
                    'tool' => $functionName,
                    'args_key' => $this->buildToolArgsKey($functionName, $functionArgs),
                    'result' => $result,
                    'seq' => count($toolResults),
                    'iteration' => $iteration,
                ];

                $executionTrace[] = [
                    'iteration' => $iteration,
                    'step' => count($executionTrace) + 1,
                    'tool' => $functionName,
                    'status' => ($result['success'] ?? false) ? 'completed' : (!empty($result['needs_confirmation']) ? 'awaiting_confirmation' : 'failed'),
                    'timestamp' => current_time('mysql'),
                    'summary' => $this->summarizeToolResult($result),
                ];

                // Add tool result to conversation
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $this->compactToolResultForModel($result),
                ];
            }

            if ($pendingConfirmation !== null) {
                $confirmDesc = $pendingConfirmation['description']
                    ?? $this->describeToolAction($pendingConfirmation['tool'] ?? '', []);
                $finalMessage = "Ich moechte: **{$confirmDesc}**\n\nBitte bestaetige ueber den Button. 🔒";
                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
                $responsePayload = [
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'execution_trace' => $executionTrace,
                    'pending_confirmation' => $pendingConfirmation,
                    'usage' => $this->usageAccumulator,
                ];
                $this->flushUsage($sessionId, $userId);
                return new WP_REST_Response($responsePayload, 200);
            }

            $postWriteMessages = $this->injectPostWriteValidation($toolCalls, $toolResults);
            if (!empty($postWriteMessages)) {
                foreach ($postWriteMessages as $pwm) {
                    $messages[] = $pwm;
                }
            }

            $scaffoldNudge = $this->injectPostCreatePluginNudge($toolCalls, $toolResults);
            foreach ($scaffoldNudge as $nudge) {
                $messages[] = $nudge;
            }

            $cssNudge = $this->injectPostCSSWriteNudge($toolCalls, $toolResults);
            foreach ($cssNudge as $nudge) {
                $messages[] = $nudge;
            }

            $smokeTest = $this->injectPostPluginSmokeTest($toolCalls, $toolResults);
            foreach ($smokeTest as $st) {
                $messages[] = $st;
            }

            $integrationCheck = $this->injectPostWriteIntegrationCheck($toolCalls, $toolResults);
            foreach ($integrationCheck as $ic) {
                $messages[] = $ic;
            }

            $codeTagWarnings = $this->injectCodeTagWarnings($toolResults);
            foreach ($codeTagWarnings as $ctw) {
                $messages[] = $ctw;
            }

            $toolMismatch = $this->injectToolMismatchCorrection($message, $toolResults);
            foreach ($toolMismatch as $tm) {
                $messages[] = $tm;
            }

            $loopMessages = $this->compactMessagesForToolLoop($messages, $iteration);
            $nextResponse = $this->chatWithTracking($loopMessages, $this->getToolDefs(), null, $webSearch);
            if (is_wp_error($nextResponse)) {
                $errMsg = $nextResponse->get_error_message();
                $errMsgLower = mb_strtolower($errMsg);
                if ($this->isNoEndpointsError($errMsgLower) || $this->isTimeoutError($errMsgLower)) {
                    $nextResponse = $this->chatWithTracking($loopMessages, [], null, $webSearch);
                }
            }
            if (is_wp_error($nextResponse)) {
                $errMsg = $nextResponse->get_error_message();
                $errMsgLower = mb_strtolower($errMsg);
                $statusCode = $this->isNoEndpointsError($errMsgLower) ? 503 : ($this->isTimeoutError($errMsgLower) ? 504 : 500);
                if ($statusCode === 503) {
                    $provider = $this->settings->getProvider();
                    $model = $this->settings->getModelForProvider($provider);
                    $errMsg = sprintf(
                        'Für das aktuell gewählte Modell sind gerade keine verfügbaren Endpoints vorhanden (%s). Bitte wechsle auf ein anderes Modell oder versuche es später erneut.',
                        $model
                    );
                } elseif ($statusCode === 504) {
                    $errMsg = 'Die Anfrage hat beim AI-Provider zu lange gedauert (Timeout). Bitte präzisieren, in kleinere Schritte aufteilen oder erneut versuchen.';
                }
                return new WP_REST_Response([
                    'error' => $errMsg,
                    'session_id' => $sessionId,
                    'execution_trace' => $executionTrace,
                ], $statusCode);
            }

            $messageData = $nextResponse['choices'][0]['message'] ?? [];
            if (empty($messageData['tool_calls'])) {
                $completionIssues = $this->checkWriteCompleteness($toolResults);
                if ($completionIssues !== null && $completionGateCount < 2) {
                    $completionGateCount++;
                    $messages[] = ['role' => 'assistant', 'content' => $messageData['content'] ?? ''];
                    $messages[] = [
                        'role' => 'system',
                        'content' => "[SYSTEM – COMPLETION CHECK FAILED]\n" . $completionIssues
                            . "\n\nDu darfst dem Nutzer NICHT 'fertig' melden bevor diese Probleme behoben sind. "
                            . "Fuehre die noetige(n) write_plugin_file / patch_plugin_file Aktion(en) jetzt aus.",
                    ];
                    $gateResponse = $this->chatWithTracking($messages, $this->getToolDefs(), null, $webSearch);
                    if (!is_wp_error($gateResponse)) {
                        $messageData = $gateResponse['choices'][0]['message'] ?? [];
                        if (!empty($messageData['tool_calls'])) {
                            continue;
                        }
                    }
                }

                if ($pendingConfirmation === null && $this->shouldNudgePendingMutation($toolResults, $taskIntent, $mutationNudgeCount)) {
                    $mutationNudgeCount++;
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Der Nutzer hat eine konkrete Aenderung angefordert. Du hast bisher nur gelesen oder geprueft. '
                            . 'Fuehre jetzt den passenden mutierenden Tool-Call aus (z. B. delete/update/create/install), '
                            . 'oder erklaere konkret, warum die Ausfuehrung nicht moeglich ist. Behaupte keinen Abschluss ohne mutierenden Erfolg.',
                    ];

                    $nextResponse = $this->chatWithTracking($messages, $this->getToolDefs(), null, $webSearch);
                    if (is_wp_error($nextResponse)) {
                        return new WP_REST_Response([
                            'error' => $nextResponse->get_error_message(),
                            'session_id' => $sessionId,
                            'execution_trace' => $executionTrace,
                        ], 500);
                    }
                    $messageData = $nextResponse['choices'][0]['message'] ?? [];
                    if (!empty($messageData['tool_calls'])) {
                        continue;
                    }
                }

                $finalMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($messageData['content'] ?? '')
                );

                if ($finalMessage === '') {
                    error_log('Levi: empty AI response after tool loop (classic), nudging for summary');
                    $messages[] = ['role' => 'assistant', 'content' => $messageData['content'] ?? ''];
                    $messages[] = [
                        'role' => 'system',
                        'content' => '[SYSTEM] Deine letzte Antwort war leer. Fasse jetzt kurz und freundlich zusammen, '
                            . 'was du fuer den Nutzer erledigt hast. Nenne konkrete Ergebnisse (Dateinamen, IDs, etc.).',
                    ];
                    $summaryResponse = $this->chatWithTracking(
                        $this->compactMessagesForToolLoop($messages, $iteration),
                        [],
                        null,
                        false
                    );
                    if (!is_wp_error($summaryResponse)) {
                        $finalMessage = $this->sanitizeAssistantMessageContent(
                            (string) ($summaryResponse['choices'][0]['message']['content'] ?? '')
                        );
                    }
                    if ($finalMessage === '') {
                        $finalMessage = $this->recoverStreamedContentOrFallback($toolResults);
                    }
                }

                $finalMessage = $this->applyResponseSafetyGates($finalMessage, $toolResults, $taskIntent);

                if ($this->wasResponseTruncated($nextResponse)) {
                    $finalMessage = $this->appendTruncationHint($finalMessage);
                }

                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

                $responsePayload = [
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'model' => $nextResponse['model'] ?? null,
                    'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
                    'execution_trace' => $executionTrace,
                    'truncated' => $this->wasResponseTruncated($nextResponse),
                    'timestamp' => current_time('mysql'),
                ];
                if ($pendingConfirmation !== null) {
                    $responsePayload['pending_confirmation'] = $pendingConfirmation;
                }
                $responsePayload['usage'] = $this->usageAccumulator;
                $this->flushUsage($sessionId, $userId);
                return new WP_REST_Response($responsePayload, 200);
            }
        }

        $finalMessage = $this->recoverStreamedContentOrFallback($toolResults);
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

        $fallbackPayload = [
            'session_id' => $sessionId,
            'message' => $finalMessage,
            'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
            'execution_trace' => $executionTrace,
            'timestamp' => current_time('mysql'),
        ];
        if ($pendingConfirmation !== null) {
            $fallbackPayload['pending_confirmation'] = $pendingConfirmation;
        }
        $fallbackPayload['usage'] = $this->usageAccumulator;
        $this->flushUsage($sessionId, $userId);
        return new WP_REST_Response($fallbackPayload, 200);
    }

    private function summarizeToolResult(array $result): string {
        if (($result['needs_confirmation'] ?? false) === true) {
            return 'Warte auf explizite Bestätigung';
        }

        if (($result['success'] ?? false) === true) {
            if (!empty($result['message']) && is_string($result['message'])) {
                return $result['message'];
            }
            if (!empty($result['post_id'])) {
                return 'OK: post_id=' . $result['post_id'];
            }
            if (!empty($result['page_id'])) {
                return 'OK: page_id=' . $result['page_id'];
            }
            if (!empty($result['theme_slug']) && empty($result['relative_path'])) {
            return 'OK: theme=' . $result['theme_slug'];
        }
            if (!empty($result['plugin_file'])) {
                return 'OK: plugin_file=' . $result['plugin_file'];
            }
            if (!empty($result['relative_path'])) {
                return 'OK: file=' . $result['relative_path'];
            }
            return 'OK';
        }

        if (!empty($result['error']) && is_string($result['error'])) {
            return 'Fehler: ' . $result['error'];
        }
        return 'Fehler bei Ausführung';
    }

    private function describeToolAction(string $toolName, array $args): string {
        return match ($toolName) {
            'create_plugin' => "Neues Plugin '" . ($args['slug'] ?? '?') . "' erstellen"
                . (!empty($args['name']) ? " ({$args['name']})" : '')
                . (!empty($args['description']) ? " — {$args['description']}" : ''),
            'delete_post' => $this->describeDeletePost($args),
            'install_plugin' => "Plugin '" . ($args['plugin_slug'] ?? '?') . "' installieren"
                . (!empty($args['action']) && $args['action'] === 'update_outdated' ? ' (alle veralteten Plugins aktualisieren)' : ''),
            'switch_theme' => "Theme zu '" . ($args['theme'] ?? $args['stylesheet'] ?? '?') . "' wechseln",
            'update_any_option' => "Option '" . ($args['option'] ?? '?') . "' aendern"
                . (isset($args['value']) ? " auf '" . mb_substr((string) $args['value'], 0, 80) . "'" : ''),
            'manage_user' => 'Benutzer-Aktion: ' . ($args['action'] ?? '?')
                . (!empty($args['user_id']) ? " (User #{$args['user_id']})" : ''),
            'patch_plugin_file' => 'Plugin-Datei patchen'
                . (!empty($args['plugin_slug']) ? " in '{$args['plugin_slug']}'" : '')
                . (!empty($args['relative_path']) ? ": {$args['relative_path']}" : '')
                . (!empty($args['replacements']) ? ' (' . count($args['replacements']) . ' Ersetzung(en))' : ''),
            'delete_plugin_file' => 'Plugin-Datei loeschen'
                . (!empty($args['plugin_slug']) ? " in '{$args['plugin_slug']}'" : '')
                . (!empty($args['relative_path']) ? ": {$args['relative_path']}" : ''),
            'delete_theme_file' => 'Theme-Datei loeschen'
                . (!empty($args['relative_path']) ? ": {$args['relative_path']}" : ''),
            'execute_wp_code' => $this->describePhpCode($args['code'] ?? ''),
            'manage_woocommerce' => $this->describeWooCommerceAction($args),
            'manage_elementor' => 'Elementor: ' . ($args['action'] ?? '?')
                . (!empty($args['page_id']) ? " (Seite #{$args['page_id']})" : ''),
            'manage_menu' => 'Menue: ' . ($args['action'] ?? '?')
                . (!empty($args['menu_name']) ? " '{$args['menu_name']}'" : ''),
            'manage_cron' => 'Cron: ' . ($args['action'] ?? '?')
                . (!empty($args['name']) ? " '{$args['name']}'" : '')
                . (!empty($args['hook']) ? " ({$args['hook']})" : ''),
            default => $toolName,
        };
    }

    private function describeDeletePost(array $args): string {
        $id = $args['id'] ?? null;
        $label = 'Beitrag';
        if ($id) {
            $post = get_post((int) $id);
            if ($post) {
                $label = match ($post->post_type) {
                    'page' => 'Seite',
                    'product' => 'Produkt',
                    'attachment' => 'Medien-Datei',
                    default => 'Beitrag',
                };
            }
        }
        return $label . ($id ? " #{$id}" : '') . ' loeschen';
    }

    private function describeWooCommerceAction(array $args): string {
        $action = (string) ($args['action'] ?? '?');
        return match ($action) {
            'create_product' => "Neues WooCommerce-Produkt erstellen: '" . ($args['name'] ?? '?') . "' (Typ: " . ($args['product_type'] ?? 'simple') . ")",
            'update_product' => 'WooCommerce-Produkt #' . ($args['product_id'] ?? '?') . ' aktualisieren',
            'delete_product' => 'WooCommerce-Produkt #' . ($args['product_id'] ?? '?') . ' loeschen',
            'set_product_attributes' => 'Attribute fuer Produkt #' . ($args['product_id'] ?? '?') . ' setzen',
            'create_variations' => 'Variationen fuer Produkt #' . ($args['product_id'] ?? '?') . ' erstellen',
            'update_variation' => 'Variation #' . ($args['variation_id'] ?? '?') . ' aktualisieren',
            'delete_variation' => 'Variation #' . ($args['variation_id'] ?? '?') . ' loeschen',
            'update_order_status' => 'Bestellstatus #' . ($args['order_id'] ?? '?') . ' auf ' . ($args['order_status'] ?? '?') . ' setzen',
            'configure_tax' => 'Steuer-Einstellungen aendern',
            'create_coupon' => "Coupon '" . ($args['coupon_code'] ?? '?') . "' erstellen",
            'update_coupon' => 'Coupon #' . ($args['coupon_id'] ?? '?') . ' aktualisieren',
            'delete_coupon' => 'Coupon #' . ($args['coupon_id'] ?? '?') . ' loeschen',
            default => 'WooCommerce: ' . $action,
        };
    }

    private function describePhpCode(string $code): string {
        if ($code === '') {
            return 'PHP-Code ausfuehren';
        }

        $preview = trim($code);
        $preview = preg_replace('/\s+/', ' ', $preview);
        if (mb_strlen($preview) > 120) {
            $preview = mb_substr($preview, 0, 120) . '...';
        }
        return "PHP-Code ausfuehren: " . $preview;
    }

    private function isDestructiveTool(string $toolName, array $args = []): bool {
        $result = $this->toolGuard->evaluate($toolName, $args);
        return $result['verdict'] === ToolGuard::ESCALATE || $result['verdict'] === ToolGuard::BLOCK;
    }

    private function isWriteTool(string $toolName): bool {
        return in_array($toolName, [
            'write_plugin_file',
            'patch_plugin_file',
            'write_theme_file',
            'create_plugin',
            'create_theme',
            'execute_wp_code',
            'elementor_build',
        ], true);
    }

    private function normalizeToolArgumentsForIntent(string $toolName, array $args, string $latestUserMessage): array {
        if (!in_array($toolName, ['get_pages', 'get_posts'], true)) {
            return $args;
        }

        if (!$this->requiresExhaustiveReadIntent($latestUserMessage)) {
            return $args;
        }

        $args['include_content'] = true;
        $args['status'] = 'any';
        $args['number'] = max((int) ($args['number'] ?? 100), 100);
        $args['page'] = max(1, (int) ($args['page'] ?? 1));

        return $args;
    }

    private function executeToolWithAutopaging(string $toolName, array $args, string $latestUserMessage): array {
        $firstResult = $this->toolRegistry->execute($toolName, $args);
        if (($firstResult['success'] ?? false) !== true) {
            return $firstResult;
        }

        if (!in_array($toolName, ['get_pages', 'get_posts'], true)) {
            return $firstResult;
        }

        if (!$this->requiresExhaustiveReadIntent($latestUserMessage) || empty($firstResult['has_more'])) {
            return $firstResult;
        }

        $allItemsKey = $toolName === 'get_pages' ? 'pages' : 'posts';
        $combined = $firstResult;
        $combined[$allItemsKey] = is_array($firstResult[$allItemsKey] ?? null) ? $firstResult[$allItemsKey] : [];

        $maxPages = max(1, (int) ($firstResult['max_pages'] ?? 1));
        $currentPage = max(1, (int) ($args['page'] ?? 1));

        while ($currentPage < $maxPages) {
            $currentPage++;
            $nextArgs = $args;
            $nextArgs['page'] = $currentPage;

            $next = $this->toolRegistry->execute($toolName, $nextArgs);
            if (($next['success'] ?? false) !== true) {
                $combined['partial_error'] = $next['error'] ?? 'Could not fetch further pages.';
                break;
            }

            $nextItems = is_array($next[$allItemsKey] ?? null) ? $next[$allItemsKey] : [];
            $combined[$allItemsKey] = array_merge($combined[$allItemsKey], $nextItems);
            $combined['count'] = count($combined[$allItemsKey]);
            $combined['page'] = $currentPage;
            $combined['has_more'] = !empty($next['has_more']);
        }

        return $combined;
    }

    private function logToolExecution(string $sessionId, int $userId, string $toolName, array $toolArgs, array $result): void {
        global $wpdb;
        $table = $wpdb->prefix . 'levi_audit_log';
        static $auditTableExists = null;

        if ($toolName === '') {
            return;
        }

        if ($auditTableExists === null) {
            $auditTableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }
        if (!$auditTableExists) {
            return;
        }

        $preparedArgs = $this->sanitizeAuditLogData($toolArgs);
        $encodedArgs = wp_json_encode($preparedArgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedArgs)) {
            $encodedArgs = '{}';
        }

        $summary = $this->summarizeToolResult($result);
        if ($summary !== '') {
            $summary = mb_substr($summary, 0, 255);
        } else {
            $summary = null;
        }

        $inserted = $wpdb->insert($table, [
            'user_id' => $userId > 0 ? $userId : null,
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'tool_args' => $encodedArgs,
            'success' => !empty($result['success']) ? 1 : 0,
            'result_summary' => $summary,
            'executed_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s']);

        if ($inserted === false) {
            error_log('Levi Audit Log insert failed: ' . $wpdb->last_error);
        }
    }

    private function sanitizeAuditLogData(mixed $value): mixed {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? strtolower($key) : (string) $key;
                if ($this->isSensitiveAuditKey($keyString)) {
                    $sanitized[$key] = '[REDACTED]';
                    continue;
                }
                $sanitized[$key] = $this->sanitizeAuditLogData($item);
            }
            return $sanitized;
        }

        if (is_string($value)) {
            return mb_strlen($value) > 1000 ? mb_substr($value, 0, 1000) . '…' : $value;
        }

        return $value;
    }

    private function registerApprovedContext(string $toolName, array $args, array $result): void {
        if (empty($result['success'])) {
            return;
        }

        if ($toolName === 'create_plugin') {
            $slug = sanitize_title((string) ($result['slug'] ?? $args['slug'] ?? $args['plugin_slug'] ?? ''));
            if ($slug !== '') {
                $this->toolGuard->approveContext('plugin:' . $slug);
            }
        }
    }

    /**
     * Detect repetitive tool calls (same tool + same primary arg 3+ times without a write in between).
     * Returns a system-message nudge string if a loop is detected, null otherwise.
     */
    private function detectToolLoop(array $toolResults, string $currentTool, array $currentArgs): ?string {
        $currentKey = $this->buildToolArgsKey($currentTool, $currentArgs);
        $consecutiveCount = 0;

        for ($i = count($toolResults) - 1; $i >= 0; $i--) {
            $prevKey = $toolResults[$i]['args_key'] ?? '';
            if ($prevKey === $currentKey) {
                $consecutiveCount++;
            } else {
                break;
            }
        }

        if ($consecutiveCount < 2) {
            return null;
        }

        return '[SYSTEM] Du rufst dasselbe Tool (' . $currentTool . ') wiederholt mit denselben Argumenten auf. '
            . 'Das deutet auf eine Schleife hin. Waehle einen anderen Ansatz: '
            . 'Wenn patch_plugin_file fehlgeschlagen ist, nutze write_plugin_file zum Neuschreiben. '
            . 'Wenn du eine Datei bereits gelesen hast, lies sie nicht nochmal – handele stattdessen.';
    }

    private function isSensitiveAuditKey(string $key): bool {
        return in_array($key, [
            'password',
            'passwort',
            'secret',
            'token',
            'api_key',
            'authorization',
            'cookie',
            'nonce',
            'levi_action_password',
            'confirm_password',
            'confirmation_password',
        ], true);
    }
}
