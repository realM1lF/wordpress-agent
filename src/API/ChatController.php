<?php

namespace Levi\Agent\API;

use Levi\Agent\AI\AIClientFactory;
use Levi\Agent\AI\AIClientInterface;
use Levi\Agent\AI\PIIRedactor;
use Levi\Agent\AI\QueryClassifier;
use Levi\Agent\Memory\EmbeddingCache;
use Levi\Agent\Database\ConversationRepository;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Agent\Identity;
use Levi\Agent\Memory\VectorStore;
use Levi\Agent\Memory\StateSnapshotService;
use Levi\Agent\AI\Tools\Registry;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ChatController extends WP_REST_Controller {
    protected $namespace = 'levi-agent/v1';
    protected $rest_base = 'chat';
    private AIClientInterface $aiClient;
    private ConversationRepository $conversationRepo;
    private SettingsPage $settings;
    private Registry $toolRegistry;
    private static bool $initialized = false;

    public function __construct() {
        // Prevent multiple initializations
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        
        $this->settings = new SettingsPage();
        $this->aiClient = AIClientFactory::create($this->settings->getProvider());
        $this->conversationRepo = new ConversationRepository();
        $toolProfile = $this->settings->getSettings()['tool_profile'] ?? 'standard';
        $this->toolRegistry = new Registry($toolProfile);

        PIIRedactor::init($this->settings->getSettings());
        
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Get AI client for queries.
     * Uses the configured model for all queries.
     * 
     * @return AIClientInterface
     */
    private function getAIClient(): AIClientInterface {
        return $this->aiClient;
    }

    public function register_routes(): void {
        $sharedArgs = [
            'message' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'session_id' => [
                'type' => ['string', 'null'],
                'default' => null,
                'sanitize_callback' => function($value) {
                    return $value ? sanitize_text_field($value) : null;
                },
            ],
            'replace_last' => [
                'type' => 'boolean',
                'default' => false,
            ],
        ];

        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'sendMessage'],
                'permission_callback' => [$this, 'checkPermission'],
                'args' => $sharedArgs,
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/stream', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'sendMessageStream'],
                'permission_callback' => [$this, 'checkPermission'],
                'args' => $sharedArgs,
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/upload', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'uploadFiles'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<session_id>[a-zA-Z0-9_.-]+)/uploads', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getSessionUploadsMeta'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'clearSessionUploadsEndpoint'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<session_id>[a-zA-Z0-9_.-]+)/uploads/(?P<file_id>[a-zA-Z0-9_.-]+)', [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'deleteSessionUploadById'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<session_id>[a-zA-Z0-9_.-]+)/history', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getHistory'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<session_id>[a-zA-Z0-9_.-]+)', [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'deleteSession'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/test', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'testConnection'],
                'permission_callback' => [$this, 'checkAdminPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/sessions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getUserSessions'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/status', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getStatus'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/confirm-action', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'confirmAction'],
                'permission_callback' => [$this, 'checkPermission'],
                'args' => [
                    'action_id' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
    }

    public function getStatus(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'levi_conversations';
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        return new WP_REST_Response([
            'tables_ok' => $tableExists,
            'ai_configured' => $this->aiClient->isConfigured(),
            'user_id' => get_current_user_id(),
        ], 200);
    }

    public function confirmAction(WP_REST_Request $request): WP_REST_Response {
        $actionId = (string) $request->get_param('action_id');
        $userId = get_current_user_id();

        $transientKey = 'levi_pending_' . $actionId;
        $pending = get_transient($transientKey);

        if (!is_array($pending) || empty($pending['tool_name'])) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Aktion nicht gefunden oder abgelaufen. Bitte erneut anfordern.',
            ], 404);
        }

        if ((int) ($pending['user_id'] ?? 0) !== $userId) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Keine Berechtigung fuer diese Aktion.',
            ], 403);
        }

        $toolName = (string) $pending['tool_name'];
        $toolArgs = is_array($pending['tool_args']) ? $pending['tool_args'] : [];
        $sessionId = (string) ($pending['session_id'] ?? '');

        $result = $this->toolRegistry->execute($toolName, $toolArgs);
        $this->logToolExecution($sessionId, $userId, $toolName, $toolArgs, $result);

        delete_transient($transientKey);

        $summary = $this->describeToolAction($toolName, $toolArgs);
        $ok = !empty($result['success']);
        $assistantMessage = $ok
            ? '✅ ' . $summary . ' – erfolgreich ausgefuehrt.'
            : '❌ ' . $summary . ' – fehlgeschlagen: ' . ($result['error'] ?? 'Unbekannter Fehler');

        if ($sessionId !== '') {
            $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $assistantMessage);
        }

        return new WP_REST_Response([
            'success' => $ok,
            'message' => $assistantMessage,
            'tool' => $toolName,
            'result' => $result,
        ], 200);
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function checkAdminPermission(): bool {
        return current_user_can('manage_options');
    }

    public function checkRateLimit(int $userId): bool {
        $settings = $this->settings->getSettings();
        $maxRequests = $settings['rate_limit'] ?? 50;
        if ((int) $maxRequests <= 0) {
            return true;
        }
        
        $transientKey = 'levi_rate_' . $userId;
        $requests = get_transient($transientKey);

        if ($requests === false) {
            set_transient($transientKey, 1, HOUR_IN_SECONDS);
            return true;
        }

        if ($requests >= $maxRequests) {
            return false;
        }

        set_transient($transientKey, $requests + 1, HOUR_IN_SECONDS);
        return true;
    }

    public function sendMessage(WP_REST_Request $request): WP_REST_Response {
        $phpTimeLimit = (int) ($this->settings->getSettings()['php_time_limit'] ?? 180);
        if ($phpTimeLimit > 0 && function_exists('set_time_limit')) {
            @set_time_limit($phpTimeLimit);
        }
        ob_start();
        try {
            $response = $this->processMessage($request);
        } catch (\Throwable $e) {
            error_log('Levi Agent Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $response = new WP_REST_Response([
                'error' => 'Internal error: ' . $e->getMessage(),
                'session_id' => $request->get_param('session_id') ?? $this->generateSessionId(),
            ], 500);
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        return $response;
    }
    
    public function sendMessageStream(WP_REST_Request $request): void {
        $phpTimeLimit = (int) ($this->settings->getSettings()['php_time_limit'] ?? 180);
        if ($phpTimeLimit > 0 && function_exists('set_time_limit')) {
            @set_time_limit($phpTimeLimit);
        }
        ignore_user_abort(false);

        // Disable ALL output buffering layers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        try {
            $this->processMessageStreaming($request);
        } catch (\Throwable $e) {
            error_log('Levi Stream Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->emitSSE('error', [
                'message' => 'Internal error: ' . $e->getMessage(),
                'session_id' => $request->get_param('session_id') ?? '',
            ]);
        }

        die();
    }

    private function emitSSE(string $type, array $data): void {
        $data['type'] = $type;
        echo 'data: ' . wp_json_encode($data) . "\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    private function processMessageStreaming(WP_REST_Request $request): void {
        $message = $request->get_param('message');
        $sessionId = $request->get_param('session_id') ?? $this->generateSessionId();
        $userId = get_current_user_id();

        // Session ownership check
        if ($sessionId !== null) {
            $ownerId = $this->conversationRepo->getSessionOwnerId($sessionId);
            if ($ownerId !== null && $ownerId !== $userId && !current_user_can('manage_options')) {
                $this->emitSSE('error', ['message' => 'Session not found or access denied.', 'session_id' => $sessionId]);
                return;
            }
        }

        if (!$this->checkRateLimit($userId)) {
            $this->emitSSE('error', ['message' => 'Rate limit exceeded. Please try again later.', 'session_id' => $sessionId]);
            return;
        }

        if (!$this->aiClient->isConfigured()) {
            $this->emitSSE('error', ['message' => 'AI not configured. Please set up provider credentials in Settings.', 'session_id' => $sessionId]);
            return;
        }

        // Emit initial status immediately (keeps Nginx alive)
        $this->emitSSE('status', ['message' => 'Levi denkt nach...', 'session_id' => $sessionId]);

        $replaceLast = (bool) $request->get_param('replace_last');
        if ($replaceLast) {
            try {
                $this->conversationRepo->deleteLastUserAssistantPair($sessionId);
            } catch (\Exception $e) {
                error_log('Levi DB Error (replace_last): ' . $e->getMessage());
            }
        }

        try {
            $this->conversationRepo->saveMessage($sessionId, $userId, 'user', $message);
        } catch (\Exception $e) {
            error_log('Levi DB Error: ' . $e->getMessage());
        }

        $hasUploadedContext = !empty($this->getSessionUploads($sessionId, $userId));
        $messages = $this->buildMessages($sessionId, $message, true);
        $tools = $this->toolRegistry->getDefinitions();

        // Heartbeat callback for SSE keepalive during AI calls
        $heartbeat = function () {
            if (connection_aborted()) {
                return;
            }
            $this->emitSSE('heartbeat', []);
        };

        // Get AI client (uses alternative model for simple queries)
        $aiClient = $this->getAIClient();
        
        // Call AI with heartbeat
        $response = $aiClient->chat($messages, $tools, $heartbeat);

        // Error handling with fallbacks (same logic as non-streaming)
        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $errMsgLower = mb_strtolower($errMsg);
            $isNoEndpointFailure = $this->isNoEndpointsError($errMsgLower);
            $isProviderFailure = str_contains($errMsgLower, 'provider') || str_contains($errMsgLower, '503');
            $isTimeoutFailure = $this->isTimeoutError($errMsgLower);

            if (!empty($tools) && ($isNoEndpointFailure || ($isProviderFailure && !$this->isActionIntent($message)))) {
                $this->emitSSE('status', ['message' => 'Neuer Versuch ohne Tools...']);
                $response = $this->aiClient->chat($messages, [], $heartbeat);
            } elseif ($isTimeoutFailure && $hasUploadedContext) {
                $this->emitSSE('status', ['message' => 'Timeout, versuche mit weniger Kontext...']);
                $messages = $this->buildMessages($sessionId, $message, false);
                $response = $this->aiClient->chat($messages, $tools, $heartbeat);
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->aiClient->chat($messages, [], $heartbeat);
                }
            } elseif ($isTimeoutFailure && !empty($tools)) {
                $this->emitSSE('status', ['message' => 'Timeout, neuer Versuch...']);
                $response = $this->aiClient->chat($messages, [], $heartbeat);
            }
        }

        // Context overflow auto-recovery
        if (is_wp_error($response)) {
            $overflowMsg = mb_strtolower($response->get_error_message());
            if (str_contains($overflowMsg, 'context length') || str_contains($overflowMsg, 'too many tokens') || str_contains($overflowMsg, 'maximum context')) {
                $this->emitSSE('status', ['message' => 'Kontext wird gekuerzt...']);
                $halvedMessages = $this->halveHistory($messages);
                $response = $this->aiClient->chat($halvedMessages, $tools, $heartbeat);
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->aiClient->chat($halvedMessages, [], $heartbeat);
                }
                if (!is_wp_error($response)) {
                    $messages = $halvedMessages;
                }
            }
        }

        if (is_wp_error($response)) {
            $this->emitSSE('error', ['message' => $response->get_error_message(), 'session_id' => $sessionId]);
            return;
        }

        // Auto-retry on empty AI response (up to 2 attempts)
        if ($this->isEmptyAiResponse($response)) {
            $originalContent = (string) ($response['choices'][0]['message']['content'] ?? '');
            error_log('Levi: empty AI response (attempt 1), original content: ' . mb_substr($originalContent, 0, 500));

            for ($retryAttempt = 1; $retryAttempt <= 2; $retryAttempt++) {
                $this->emitSSE('status', ['message' => 'Levi versucht es erneut... (Versuch ' . ($retryAttempt + 1) . ')']);
                $response = $this->aiClient->chat($messages, $tools, $heartbeat);

                if (is_wp_error($response)) {
                    $this->emitSSE('error', ['message' => $response->get_error_message(), 'session_id' => $sessionId]);
                    return;
                }
                if (!$this->isEmptyAiResponse($response)) {
                    break;
                }
                error_log('Levi: empty AI response (attempt ' . ($retryAttempt + 1) . ')');
            }
        }

        $messageData = $response['choices'][0]['message'] ?? [];

        if (isset($messageData['tool_calls']) && !empty($messageData['tool_calls'])) {
            $this->handleToolCallsStreaming($messageData, $messages, $sessionId, $userId, (string) $message, $heartbeat);
            if ($hasUploadedContext) {
                $this->clearSessionUploads($sessionId, $userId);
            }
            return;
        }

        // Normal response (no tools)
        $assistantMessage = $this->sanitizeAssistantMessageContent(
            (string) ($messageData['content'] ?? '')
        );

        if ($assistantMessage === '') {
            $assistantMessage = $this->getEmptyResponseFallback();
        }

        if ($this->wasResponseTruncated($response)) {
            $assistantMessage = $this->appendTruncationHint($assistantMessage);
        }

        try {
            $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $assistantMessage);
        } catch (\Exception $e) {
            error_log('Levi DB Error: ' . $e->getMessage());
        }

        if ($hasUploadedContext) {
            $this->clearSessionUploads($sessionId, $userId);
        }

        $this->emitSSE('done', [
            'session_id' => $sessionId,
            'message' => $assistantMessage,
            'model' => $response['model'] ?? null,
            'truncated' => $this->wasResponseTruncated($response),
        ]);
    }

    private function handleToolCallsStreaming(
        array $messageData,
        array $messages,
        string $sessionId,
        int $userId,
        string $latestUserMessage,
        callable $heartbeat
    ): void {
        $toolResults = [];
        $pendingConfirmation = null;
        $runtimeSettings = $this->settings->getSettings();
        $maxIterations = max(1, (int) ($runtimeSettings['max_tool_iterations'] ?? 12));
        $requireConfirmation = !empty($runtimeSettings['require_confirmation_destructive']);
        $taskIntent = $this->inferTaskIntent($latestUserMessage, $messages);
        $iteration = 0;
        $mutationNudgeCount = 0;
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

                $this->emitSSE('progress', [
                    'message' => $this->getToolProgressLabel($functionName, 'start'),
                    'tool' => $functionName,
                    'iteration' => $iteration,
                ]);

                if ($this->shouldDeferCreationTool($functionName, $functionArgs, $taskIntent)) {
                    $result = [
                        'success' => false,
                        'needs_clarification' => true,
                        'error' => 'Der Chat-Kontext deutet auf das Bearbeiten von bestehendem Inhalt hin.',
                        'tool' => $functionName,
                    ];
                } elseif ($requireConfirmation && $this->isDestructiveTool($functionName) && !$hasConfirmation) {
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
                        'error' => 'Fuer diese Aktion brauche ich eine explizite Bestaetigung.',
                        'tool' => $functionName,
                    ];
                } else {
                    $result = $this->executeToolWithAutopaging($functionName, $functionArgs, $latestUserMessage);
                }

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
                $confirmMsg = $pendingConfirmation['description']
                    ?? $this->describeToolAction($pendingConfirmation['tool'] ?? '', []);
                $finalMessage = 'Fuer diese Aktion brauche ich deine Bestaetigung. Bitte klicke auf den Button unter dieser Nachricht. 🔒';
                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
                $this->emitSSE('done', [
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
                    'pending_confirmation' => $pendingConfirmation,
                ]);
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

            if (connection_aborted()) {
                error_log('Levi: client disconnected during tool loop');
                return;
            }

            $this->emitSSE('status', ['message' => 'Levi verarbeitet Ergebnisse...']);

            $nextResponse = $this->aiClient->chat($messages, $this->toolRegistry->getDefinitions(), $heartbeat);
            if (is_wp_error($nextResponse)) {
                $errMsgLower = mb_strtolower($nextResponse->get_error_message());
                if ($this->isNoEndpointsError($errMsgLower) || $this->isTimeoutError($errMsgLower)) {
                    $nextResponse = $this->aiClient->chat($messages, [], $heartbeat);
                }
            }
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
                if ($pendingConfirmation === null && $this->shouldNudgePendingMutation($toolResults, $taskIntent, $mutationNudgeCount)) {
                    $mutationNudgeCount++;
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Der Nutzer hat eine konkrete Aenderung angefordert. Du hast bisher nur gelesen oder geprueft. '
                            . 'Fuehre jetzt den passenden mutierenden Tool-Call aus (z. B. delete/update/create/install), '
                            . 'oder erklaere konkret, warum die Ausfuehrung nicht moeglich ist. Behaupte keinen Abschluss ohne mutierenden Erfolg.',
                    ];
                    $nudgedResponse = $this->aiClient->chat($messages, $this->toolRegistry->getDefinitions(), $heartbeat);
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

                if ($finalMessage === '') {
                    error_log('Levi: empty AI response after tool loop, original: ' . mb_substr((string) ($messageData['content'] ?? ''), 0, 500));
                    $finalMessage = $this->getEmptyResponseFallback();
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
                $this->emitSSE('done', $donePayload);
                return;
            }
        }

        $finalMessage = 'Ich bin leider nicht ganz fertig geworden. Schreib einfach „mach weiter" und ich mach mich wieder an die Aufgabe.';
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

        $fallbackPayload = [
            'session_id' => $sessionId,
            'message' => $finalMessage,
            'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
        ];
        if ($pendingConfirmation !== null) {
            $fallbackPayload['pending_confirmation'] = $pendingConfirmation;
        }
        $this->emitSSE('done', $fallbackPayload);
    }

    private function getToolProgressLabel(string $toolName, string $phase): string {
        $labels = [
            'get_posts' => ['start' => 'Beitraege werden gelesen...', 'done' => 'Beitraege gelesen', 'failed' => 'Beitraege lesen fehlgeschlagen'],
            'get_pages' => ['start' => 'Seiten werden gelesen...', 'done' => 'Seiten gelesen', 'failed' => 'Seiten lesen fehlgeschlagen'],
            'create_post' => ['start' => 'Beitrag wird erstellt...', 'done' => 'Beitrag erstellt', 'failed' => 'Beitrag erstellen fehlgeschlagen'],
            'create_page' => ['start' => 'Seite wird erstellt...', 'done' => 'Seite erstellt', 'failed' => 'Seite erstellen fehlgeschlagen'],
            'update_post' => ['start' => 'Beitrag wird aktualisiert...', 'done' => 'Beitrag aktualisiert', 'failed' => 'Beitrag aktualisieren fehlgeschlagen'],
            'get_woocommerce_data' => ['start' => 'Shop-Daten werden gelesen...', 'done' => 'Shop-Daten gelesen', 'failed' => 'Shop-Daten lesen fehlgeschlagen'],
            'manage_woocommerce' => ['start' => 'Shop wird bearbeitet...', 'done' => 'Shop-Aktion abgeschlossen', 'failed' => 'Shop-Aktion fehlgeschlagen'],
            'create_plugin' => ['start' => 'Plugin wird erstellt...', 'done' => 'Plugin erstellt', 'failed' => 'Plugin erstellen fehlgeschlagen'],
            'install_plugin' => ['start' => 'Plugin wird installiert...', 'done' => 'Plugin installiert', 'failed' => 'Plugin installieren fehlgeschlagen'],
            'discover_content_types' => ['start' => 'Inhaltstypen werden erkannt...', 'done' => 'Inhaltstypen erkannt', 'failed' => 'Erkennung fehlgeschlagen'],
            'manage_post_meta' => ['start' => 'Metadaten werden verarbeitet...', 'done' => 'Metadaten verarbeitet', 'failed' => 'Metadaten-Zugriff fehlgeschlagen'],
            'manage_taxonomy' => ['start' => 'Taxonomie wird verarbeitet...', 'done' => 'Taxonomie verarbeitet', 'failed' => 'Taxonomie-Zugriff fehlgeschlagen'],
        ];
        $phaseLabels = $labels[$toolName] ?? null;
        if ($phaseLabels) {
            return $phaseLabels[$phase] ?? $phaseLabels['start'];
        }
        return match ($phase) {
            'start' => 'Tool wird ausgefuehrt: ' . $toolName,
            'done' => 'Tool abgeschlossen: ' . $toolName,
            'failed' => 'Tool fehlgeschlagen: ' . $toolName,
            default => $toolName,
        };
    }

    private function processMessage(WP_REST_Request $request): WP_REST_Response {
        $message = $request->get_param('message');
        $sessionId = $request->get_param('session_id') ?? $this->generateSessionId();
        $userId = get_current_user_id();

        // Session ownership: if reusing existing session, verify it belongs to current user
        if ($sessionId !== null) {
            $ownerId = $this->conversationRepo->getSessionOwnerId($sessionId);
            if ($ownerId !== null && $ownerId !== $userId && !current_user_can('manage_options')) {
                return new WP_REST_Response([
                    'error' => 'Session not found or access denied.',
                    'session_id' => $sessionId,
                ], 403);
            }
        }

        // Check rate limit
        if (!$this->checkRateLimit($userId)) {
            return new WP_REST_Response([
                'error' => 'Rate limit exceeded. Please try again later.',
                'session_id' => $sessionId,
            ], 429);
        }

        // Check if AI is configured
        if (!$this->aiClient->isConfigured()) {
            return new WP_REST_Response([
                'error' => 'AI not configured. Please set up provider credentials in Settings.',
                'session_id' => $sessionId,
            ], 503);
        }

        $replaceLast = (bool) $request->get_param('replace_last');
        if ($replaceLast) {
            try {
                $this->conversationRepo->deleteLastUserAssistantPair($sessionId);
            } catch (\Exception $e) {
                error_log('Levi DB Error (replace_last): ' . $e->getMessage());
            }
        }

        try {
            $this->conversationRepo->saveMessage($sessionId, $userId, 'user', $message);
        } catch (\Exception $e) {
            error_log('Levi DB Error: ' . $e->getMessage());
        }

        $hasUploadedContext = !empty($this->getSessionUploads($sessionId, $userId));

        // Build conversation history (with uploaded context by default)
        $messages = $this->buildMessages($sessionId, $message, true);

        // Get available tools
        $tools = $this->toolRegistry->getDefinitions();

        // Get AI client (uses alternative model for simple queries)
        $aiClient = $this->getAIClient();

        // Call AI – try with tools first, fallback to no tools on provider error
        $response = $aiClient->chat($messages, $tools);

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $errMsgLower = mb_strtolower($errMsg);
            $isNoEndpointFailure = $this->isNoEndpointsError($errMsgLower);
            $isProviderFailure = str_contains($errMsgLower, 'provider') || str_contains($errMsgLower, '503');
            $isTimeoutFailure = $this->isTimeoutError($errMsgLower);

            // For endpoint availability issues, always retry once without tools
            // (also for action intents), because some free endpoints reject tool mode.
            if (!empty($tools) && ($isNoEndpointFailure || ($isProviderFailure && !$this->isActionIntent($message)))) {
                $response = $this->aiClient->chat($messages, []);
            } elseif ($isTimeoutFailure && $hasUploadedContext) {
                // Retry once with same history but without uploaded file context.
                $messages = $this->buildMessages($sessionId, $message, false);
                $response = $this->aiClient->chat($messages, $tools);
                if (is_wp_error($response) && !empty($tools)) {
                    // Last retry for timeout path: disable tools to reduce payload/latency.
                    $response = $this->aiClient->chat($messages, []);
                }
            } elseif ($isTimeoutFailure && !empty($tools)) {
                // Retry once without tools for slow/loaded endpoints.
                $response = $this->aiClient->chat($messages, []);
            }
        }

        // Context overflow auto-recovery: halve the history and retry once
        if (is_wp_error($response)) {
            $overflowMsg = mb_strtolower($response->get_error_message());
            if (str_contains($overflowMsg, 'context length') || str_contains($overflowMsg, 'too many tokens') || str_contains($overflowMsg, 'maximum context')) {
                error_log('Levi: context overflow detected, retrying with halved history');
                $halvedMessages = $this->halveHistory($messages);
                $response = $this->aiClient->chat($halvedMessages, $tools);
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->aiClient->chat($halvedMessages, []);
                }
                if (!is_wp_error($response)) {
                    $messages = $halvedMessages;
                }
            }
        }

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $errMsgLower = mb_strtolower($errMsg);
            $errData = $response->get_error_data();
            $upstreamStatus = is_array($errData) ? (int) ($errData['status'] ?? 0) : 0;

            if ($upstreamStatus === 429 || str_contains($errMsgLower, 'rate-limit') || str_contains($errMsgLower, 'rate limit')) {
                $statusCode = 429;
            } elseif ($this->isNoEndpointsError($errMsgLower)) {
                $statusCode = 503;
            } elseif ($this->isTimeoutError($errMsgLower)) {
                $statusCode = 504;
            } else {
                $statusCode = $upstreamStatus >= 400 ? $upstreamStatus : 500;
            }

            if ($statusCode === 429) {
                $errMsg = 'Das KI-Modell ist gerade überlastet (Rate Limit). Bitte warte einen Moment und versuche es erneut.';
            } elseif ($statusCode === 503) {
                $provider = $this->settings->getProvider();
                $model = $this->settings->getModelForProvider($provider);
                $errMsg = sprintf(
                    'Für das aktuell gewählte Modell sind gerade keine verfügbaren Endpoints vorhanden (%s). Bitte wechsle auf ein anderes Modell oder versuche es später erneut.',
                    $model
                );
            } elseif ($statusCode === 504) {
                $errMsg = 'Die Anfrage hat beim AI-Provider zu lange gedauert (Timeout). Bitte Anfrage kürzen oder Upload-Inhalt reduzieren und erneut versuchen.';
            }
            return new WP_REST_Response([
                'error' => $errMsg,
                'session_id' => $sessionId,
            ], $statusCode);
        }

        // Auto-retry on empty AI response (up to 2 attempts)
        if ($this->isEmptyAiResponse($response)) {
            $originalContent = (string) ($response['choices'][0]['message']['content'] ?? '');
            error_log('Levi: empty AI response (classic, attempt 1), original content: ' . mb_substr($originalContent, 0, 500));

            for ($retryAttempt = 1; $retryAttempt <= 2; $retryAttempt++) {
                $response = $this->aiClient->chat($messages, $tools);
                if (is_wp_error($response)) {
                    break;
                }
                if (!$this->isEmptyAiResponse($response)) {
                    break;
                }
                error_log('Levi: empty AI response (classic, attempt ' . ($retryAttempt + 1) . ')');
            }
        }

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'error' => $response->get_error_message(),
                'session_id' => $sessionId,
            ], 500);
        }

        // Check if AI wants to use a tool
        $messageData = $response['choices'][0]['message'] ?? [];
        
        if (isset($messageData['tool_calls']) && !empty($messageData['tool_calls'])) {
            $toolResponse = $this->handleToolCalls($messageData, $messages, $sessionId, $userId, (string) $message);
            if ($hasUploadedContext) {
                $this->clearSessionUploads($sessionId, $userId);
            }
            return $toolResponse;
        }

        // Normal response (no tools)
        $assistantMessage = $this->sanitizeAssistantMessageContent(
            (string) ($messageData['content'] ?? '')
        );

        if ($assistantMessage === '') {
            $assistantMessage = $this->getEmptyResponseFallback();
        }

        if ($this->wasResponseTruncated($response)) {
            $assistantMessage = $this->appendTruncationHint($assistantMessage);
        }

        try {
            $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $assistantMessage);
        } catch (\Exception $e) {
            error_log('Levi DB Error: ' . $e->getMessage());
        }

        if ($hasUploadedContext) {
            $this->clearSessionUploads($sessionId, $userId);
        }

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $assistantMessage,
            'model' => $response['model'] ?? null,
            'truncated' => $this->wasResponseTruncated($response),
            'timestamp' => current_time('mysql'),
        ], 200);
    }

    /**
     * Handle tool calls from AI
     */
    private function handleToolCalls(array $messageData, array $messages, string $sessionId, int $userId, string $latestUserMessage): WP_REST_Response {
        $toolResults = [];
        $executionTrace = [];
        $pendingConfirmation = null;
        $runtimeSettings = $this->settings->getSettings();
        $maxIterations = max(1, (int) ($runtimeSettings['max_tool_iterations'] ?? 12));
        $requireConfirmation = !empty($runtimeSettings['require_confirmation_destructive']);
        $taskIntent = $this->inferTaskIntent($latestUserMessage, $messages);
        $iteration = 0;
        $mutationNudgeCount = 0;
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

                if ($this->shouldDeferCreationTool($functionName, $functionArgs, $taskIntent)) {
                    $result = [
                        'success' => false,
                        'needs_clarification' => true,
                        'error' => 'Der Chat-Kontext deutet auf das Bearbeiten von bestehendem Inhalt hin. Kläre kurz, ob ich bestehendes ändern oder wirklich neu erstellen soll.',
                        'tool' => $functionName,
                    ];
                } elseif ($requireConfirmation && $this->isDestructiveTool($functionName) && !$hasConfirmation) {
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
                        'error' => 'Für diese Aktion brauche ich eine explizite Bestätigung von dir.',
                        'tool' => $functionName,
                    ];
                } else {
                    $result = $this->executeToolWithAutopaging($functionName, $functionArgs, $latestUserMessage);
                }
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
                $finalMessage = 'Fuer diese Aktion brauche ich deine Bestaetigung. Bitte klicke auf den Button unter dieser Nachricht.';
                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
                $responsePayload = [
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'execution_trace' => $executionTrace,
                    'pending_confirmation' => $pendingConfirmation,
                ];
                return new WP_REST_Response($responsePayload, 200);
            }

            $postWriteMessages = $this->injectPostWriteValidation($toolCalls, $toolResults);
            if (!empty($postWriteMessages)) {
                foreach ($postWriteMessages as $pwm) {
                    $messages[] = $pwm;
                }
            }

            $nextResponse = $this->aiClient->chat($messages, $this->toolRegistry->getDefinitions());
            if (is_wp_error($nextResponse)) {
                $errMsg = $nextResponse->get_error_message();
                $errMsgLower = mb_strtolower($errMsg);
                if ($this->isNoEndpointsError($errMsgLower) || $this->isTimeoutError($errMsgLower)) {
                    // Retry once without tool definitions to avoid tool-mode endpoint limitations.
                    $nextResponse = $this->aiClient->chat($messages, []);
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
                if ($pendingConfirmation === null && $this->shouldNudgePendingMutation($toolResults, $taskIntent, $mutationNudgeCount)) {
                    $mutationNudgeCount++;
                    $messages[] = [
                        'role' => 'system',
                        'content' => 'Der Nutzer hat eine konkrete Aenderung angefordert. Du hast bisher nur gelesen oder geprueft. '
                            . 'Fuehre jetzt den passenden mutierenden Tool-Call aus (z. B. delete/update/create/install), '
                            . 'oder erklaere konkret, warum die Ausfuehrung nicht moeglich ist. Behaupte keinen Abschluss ohne mutierenden Erfolg.',
                    ];

                    $nextResponse = $this->aiClient->chat($messages, $this->toolRegistry->getDefinitions());
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
                    error_log('Levi: empty AI response after tool loop (classic), original: ' . mb_substr((string) ($messageData['content'] ?? ''), 0, 500));
                    $finalMessage = $this->getEmptyResponseFallback();
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
                return new WP_REST_Response($responsePayload, 200);
            }
        }

        $finalMessage = 'Ich bin leider nicht ganz fertig geworden. Schreib einfach „mach weiter" und ich mach mich wieder an die Aufgabe.';
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
        return new WP_REST_Response($fallbackPayload, 200);
    }

    private function buildMessages(string $sessionId, string $newMessage, bool $includeUploadedContext = true): array {
        $messages = [];

        // System message with relevant memories
        $messages[] = [
            'role' => 'system',
            'content' => $this->getSystemPrompt($newMessage, $sessionId, $includeUploadedContext),
        ];

        // History (configurable context window)
        $runtimeSettings = $this->settings->getSettings();
        $historyLimit = max(10, (int) ($runtimeSettings['history_context_limit'] ?? 50));
        $history = $this->conversationRepo->getHistory($sessionId, $historyLimit);
        foreach ($history as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }

        // Current message – attach session images as Vision content if present
        $userId = get_current_user_id();
        $sessionImages = $includeUploadedContext ? $this->getSessionImages($sessionId, $userId) : [];

        if (!empty($sessionImages)) {
            $contentParts = [['type' => 'text', 'text' => $newMessage]];
            foreach ($sessionImages as $img) {
                $contentParts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $img['base64']],
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $contentParts];
        } else {
            $messages[] = ['role' => 'user', 'content' => $newMessage];
        }

        return $this->trimMessagesToBudget($messages);
    }

    private function estimateTokenCount($content): int {
        if (is_string($content)) {
            return (int) ceil(mb_strlen($content) / 3.5);
        }
        if (is_array($content)) {
            $tokens = 0;
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $tokens += (int) ceil(mb_strlen((string) ($part['text'] ?? '')) / 3.5);
                } elseif (is_array($part) && ($part['type'] ?? '') === 'image_url') {
                    $tokens += 1000;
                }
            }
            return $tokens;
        }
        return 0;
    }

    private function trimMessagesToBudget(array $messages): array {
        $runtimeSettings = $this->settings->getSettings();
        $maxContextTokens = max(1000, (int) ($runtimeSettings['max_context_tokens'] ?? 100000));

        $totalTokens = 0;
        foreach ($messages as $msg) {
            $totalTokens += $this->estimateTokenCount($msg['content'] ?? '');
        }

        if ($totalTokens <= $maxContextTokens) {
            return $messages;
        }

        // Keep system prompt (index 0) and current user message (last) untouched.
        // Trim oldest history messages first.
        $systemMsg = $messages[0] ?? null;
        $userMsg = array_pop($messages);
        array_shift($messages); // remove system prompt from history
        $historyMessages = $messages;

        $reservedTokens = $this->estimateTokenCount($systemMsg['content'] ?? '')
            + $this->estimateTokenCount($userMsg['content'] ?? '');

        $availableBudget = $maxContextTokens - $reservedTokens;
        if ($availableBudget < 500) {
            $availableBudget = 500;
        }

        // Work backwards through history (newest first), accumulating tokens
        $keptHistory = [];
        $usedTokens = 0;
        for ($i = count($historyMessages) - 1; $i >= 0; $i--) {
            $msgTokens = $this->estimateTokenCount($historyMessages[$i]['content'] ?? '');
            if ($usedTokens + $msgTokens > $availableBudget) {
                break;
            }
            $usedTokens += $msgTokens;
            array_unshift($keptHistory, $historyMessages[$i]);
        }

        $trimmedCount = count($historyMessages) - count($keptHistory);
        if ($trimmedCount > 0) {
            error_log(sprintf(
                'Levi Token Budget: trimmed %d older messages (estimated %d -> %d tokens)',
                $trimmedCount,
                $totalTokens,
                $reservedTokens + $usedTokens
            ));
        }

        $result = [];
        if ($systemMsg) {
            $result[] = $systemMsg;
        }
        foreach ($keptHistory as $msg) {
            $result[] = $msg;
        }
        $result[] = $userMsg;

        return $result;
    }

    private function halveHistory(array $messages): array {
        if (count($messages) <= 3) {
            return $messages;
        }
        $system = $messages[0];
        $userMsg = array_pop($messages);
        array_shift($messages);
        $history = $messages;
        $kept = array_slice($history, (int) ceil(count($history) / 2));
        return array_merge([$system], $kept, [$userMsg]);
    }

    private function getSystemPrompt(string $query = '', ?string $sessionId = null, bool $includeUploadedContext = true): string {
        // Identity: cached static full text (ALWAYS, every message)
        try {
            $basePrompt = $this->getCachedIdentity();
        } catch (\Throwable $e) {
            error_log('Levi Identity Error: ' . $e->getMessage());
            $basePrompt = "You are Levi, a helpful AI assistant for WordPress.";
        }

        // Dynamic context: user name, role, time (ALWAYS, fresh per request, no disk I/O)
        try {
            $basePrompt .= "\n\n---\n\n" . Identity::getDynamicContext();
        } catch (\Throwable $e) {
            // non-critical
        }

        // QueryClassifier: evaluate once, pass strategy through
        $strategy = ['identity' => true, 'reference' => false, 'snapshot' => false, 'full_tools' => false];
        if (!empty($query)) {
            try {
                $classifier = new QueryClassifier();
                $strategy = $classifier->getRetrievalStrategy($query);
            } catch (\Throwable $e) {
                $strategy = ['identity' => true, 'reference' => true, 'snapshot' => true, 'full_tools' => true];
            }
        }

        // Context Layer: only when strategy demands it (knowledge/complex queries)
        if ($strategy['reference'] || $strategy['snapshot']) {
            try {
                $contextMemories = $this->getContextMemories($query, $strategy);
                if (!empty($contextMemories)) {
                    $basePrompt .= "\n\n# Relevant Context\n\n" . $contextMemories;
                }
            } catch (\Throwable $e) {
                error_log('Levi Memory Error: ' . $e->getMessage());
            }
        }

        // State Baseline metadata (ALWAYS, cheap -- from wp_options)
        $stateBaseline = StateSnapshotService::getPromptContext();
        if ($stateBaseline !== '') {
            $basePrompt .= "\n\n# Daily WordPress State Baseline\n\n" . $stateBaseline;
        }

        // Uploaded file context only for non-simple queries
        $isSimpleQuery = !$strategy['reference'] && !$strategy['snapshot'] && !$strategy['full_tools'];
        if (!$isSimpleQuery && $includeUploadedContext && !empty($sessionId)) {
            $uploadedContext = $this->buildUploadedFilesContext($sessionId, get_current_user_id());
            if ($uploadedContext !== '') {
                $basePrompt .= "\n\n# Session File Context\n\n" . $uploadedContext;
            }
        }

        return $basePrompt;
    }
    
    /**
     * Get minimal system prompt for simple queries
     * Reduces 21KB -> ~500 bytes for 10x speedup
     */
    private function getMinimalSystemPrompt(): string {
        return <<<'PROMPT'
You are Levi, a helpful AI assistant for WordPress.

## Your Personality
- Friendly, professional, and concise
- You address users with "Du" (informal German)
- Use appropriate emojis in responses

## What You Can Do
- Answer WordPress questions
- Help with WooCommerce, Elementor, and other plugins
- Write code when needed

## CRITICAL RULES (Always follow!)

### Tool Results Are The Only Truth
When you use a tool (get_pages, get_posts, etc.):
1. Show EXACTLY what the tool returns - never add or remove entries
2. Use EXACT IDs and titles from the tool result
3. NEVER use placeholders like "(weitere Seite)" or "..."
4. NEVER say "list truncated" or similar - show complete data
5. Your previous chat messages may be WRONG - trust the tool!

### Example
WRONG:
```
ID 3: Datenschutzerklärung
ID 2: (weitere Seite)
ID 1: (weitere Seite)
```

RIGHT:
```
ID 17: Elementor #17
ID 5: Dein WordPress KI-Assistent
ID 3: Datenschutzerklärung
```

## Safety
- Never delete content without confirmation
- Prefer drafts over published changes
PROMPT;
    }

    /**
     * Get cached identity text from WordPress transient.
     * Caches only the static content (soul+rules+knowledge). getDynamicContext() is appended separately per request.
     */
    private function getCachedIdentity(): string {
        $this->ensureMemoryFreshness();

        $cached = get_transient('levi_identity_cache');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $identity = new Identity();
        $content = $identity->getFullContent();
        $hash = $identity->getContentHash();

        if ($content !== '') {
            set_transient('levi_identity_cache', $content, HOUR_IN_SECONDS);
            set_transient('levi_identity_hash', $hash, HOUR_IN_SECONDS);
        }

        return $content !== '' ? $content : "You are Levi, a helpful AI assistant for WordPress.";
    }

    /**
     * Check if identity files changed (throttled to once per 60 seconds).
     * If changed: invalidate cache and re-sync identity to Vector DB.
     */
    private function ensureMemoryFreshness(): void {
        if (get_transient('levi_identity_freshness_checked')) {
            return;
        }
        set_transient('levi_identity_freshness_checked', '1', 60);

        try {
            $identity = new Identity();
            $currentHash = $identity->getContentHash();
            $storedHash = get_transient('levi_identity_hash');

            if ($storedHash !== false && $storedHash === $currentHash) {
                return;
            }

            delete_transient('levi_identity_cache');

            $loader = new \Levi\Agent\Memory\MemoryLoader();
            $loader->loadIdentityFiles();

            set_transient('levi_identity_hash', $currentHash, HOUR_IN_SECONDS);
        } catch (\Throwable $e) {
            error_log('Levi ensureMemoryFreshness: ' . $e->getMessage());
        }
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
            'delete_post' => 'Beitrag' . (!empty($args['id']) ? ' #' . $args['id'] : '') . ' loeschen',
            'install_plugin' => "Plugin '" . ($args['plugin_slug'] ?? '?') . "' installieren",
            'switch_theme' => "Theme zu '" . ($args['theme'] ?? $args['stylesheet'] ?? '?') . "' wechseln",
            'update_any_option' => "Option '" . ($args['option'] ?? '?') . "' aendern",
            'manage_user' => 'Benutzer-Aktion: ' . ($args['action'] ?? '?'),
            'delete_plugin_file' => 'Plugin-Datei loeschen',
            'delete_theme_file' => 'Theme-Datei loeschen',
            'execute_wp_code' => 'PHP-Code ausfuehren',
            'manage_woocommerce' => 'WooCommerce-Aktion: ' . ($args['action'] ?? '?'),
            'manage_menu' => 'Menue-Aktion: ' . ($args['action'] ?? '?'),
            'manage_cron' => 'Cron-Aktion: ' . ($args['action'] ?? '?'),
            default => $toolName,
        };
    }

    private function isDestructiveTool(string $toolName): bool {
        return in_array($toolName, [
            'delete_post',
            'switch_theme',
            'update_any_option',
            'manage_user',
            'install_plugin',
            'delete_plugin_file',
            'delete_theme_file',
            'execute_wp_code',
            'manage_woocommerce',
            'manage_menu',
            'manage_cron',
        ], true);
    }

    private function isWriteTool(string $toolName): bool {
        return in_array($toolName, [
            'write_plugin_file',
            'write_theme_file',
            'create_plugin',
            'create_theme',
            'execute_wp_code',
        ], true);
    }

    /**
     * After write tools, auto-inject a read_error_log check so the AI sees any PHP errors
     * it may have caused. Returns additional messages to append to the conversation.
     */
    private function injectPostWriteValidation(array $toolCalls, array $toolResults): array {
        $hadWrite = false;
        foreach ($toolCalls as $tc) {
            $name = $tc['function']['name'] ?? '';
            if ($this->isWriteTool($name)) {
                $matchingResult = null;
                foreach ($toolResults as $tr) {
                    if ($tr['tool'] === $name && ($tr['result']['success'] ?? false)) {
                        $matchingResult = $tr;
                        break;
                    }
                }
                if ($matchingResult) {
                    $hadWrite = true;
                    break;
                }
            }
        }

        if (!$hadWrite) {
            return [];
        }

        $errorLogResult = $this->toolRegistry->execute('read_error_log', [
            'lines' => 30,
            'filter' => 'Fatal',
        ]);

        $validationMessages = [];

        $fakeToolCallId = 'auto_validation_' . bin2hex(random_bytes(8));

        $validationMessages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => $fakeToolCallId,
                'type' => 'function',
                'function' => [
                    'name' => 'read_error_log',
                    'arguments' => json_encode(['lines' => 30, 'filter' => 'Fatal']),
                ],
            ]],
        ];

        $hasErrors = !empty($errorLogResult['lines']);
        $content = $this->compactToolResultForModel($errorLogResult);

        if ($hasErrors) {
            $content .= "\n\n[SYSTEM] ACHTUNG: Nach deiner letzten Code-Aenderung wurden PHP-Fehler im Error-Log gefunden. "
                . "Analysiere die Fehler und behebe sie sofort, bevor du dem Kunden antwortest.";
        }

        $validationMessages[] = [
            'role' => 'tool',
            'tool_call_id' => $fakeToolCallId,
            'content' => $content,
        ];

        return $validationMessages;
    }

    private function hasUserConfirmationSignal(string $text): bool {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        $patterns = [
            '/\bja\b/u',
            '/\byes\b/u',
            '/\bok(ay)?\b/u',
            '/\bconfirm(ed|ation)?\b/u',
            '/\bbestätig(e|t|ung)\b/u',
            '/\bmach( es)?\b/u',
            '/\bgo ahead\b/u',
            '/\bausführen\b/u',
            '/\bdo it\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    
    /**
     * Fetch context memories from Vector DB based on the retrieval strategy.
     * Only searches reference and state_snapshot -- identity comes from transient cache.
     */
    private function getContextMemories(string $query, array $strategy): string {
        try {
            $vectorStore = new VectorStore();
        } catch (\Throwable $e) {
            error_log('Levi VectorStore init: ' . $e->getMessage());
            return '';
        }

        $cache = new EmbeddingCache();
        $queryEmbedding = $cache->get($query);

        if ($queryEmbedding === null) {
            $queryEmbedding = $vectorStore->generateEmbedding($query);
            if (!is_wp_error($queryEmbedding) && !empty($queryEmbedding)) {
                $cache->set($query, $queryEmbedding);
            }
        }
        if (is_wp_error($queryEmbedding) || empty($queryEmbedding)) {
            return '';
        }

        $memories = [];
        try {
            $runtimeSettings = $this->settings->getSettings();
            $referenceK = max(1, (int) ($runtimeSettings['memory_reference_k'] ?? 5));
            $similarity = (float) ($runtimeSettings['memory_min_similarity'] ?? 0.6);

            if (!empty($strategy['reference'])) {
                $referenceResults = $vectorStore->searchSimilar($queryEmbedding, 'reference', $referenceK, $similarity);
                if (!empty($referenceResults)) {
                    $memories[] = "## Reference Knowledge\n" . implode("\n", array_map(fn($r) => $r['content'], $referenceResults));
                }
            }

            if (!empty($strategy['snapshot'])) {
                $stateSnapshotResults = $vectorStore->searchSimilar(
                    $queryEmbedding,
                    'state_snapshot',
                    2,
                    max(0.5, $similarity - 0.1)
                );
                if (!empty($stateSnapshotResults)) {
                    $snapshotTexts = array_map(function ($r) {
                        $content = (string) ($r['content'] ?? '');
                        if (mb_strlen($content) > 1500) {
                            $content = mb_substr($content, 0, 1500) . "\n...[truncated]";
                        }
                        return $content;
                    }, $stateSnapshotResults);
                    $memories[] = "## Historical System Snapshots\n" . implode("\n\n", $snapshotTexts);
                }
            }
        } catch (\Throwable $e) {
            error_log('Levi Memory search: ' . $e->getMessage());
        }

        return implode("\n\n", $memories);
    }

    private function isActionIntent(string $text): bool {
        $t = mb_strtolower($text);
        $patterns = [
            '/\b(erstell|anleg|schreib|änder|bearbeit|update|install|aktivier|deaktivier|lösch|entfern|switch|veröffentl|publish)\b/u',
            '/\b(plugin|seite|post|beitrag|datei|theme|benutzer|user|option|einstellung)\b/u',
        ];
        $score = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $t) === 1) {
                $score++;
            }
        }
        return $score >= 1;
    }

    private function isNoEndpointsError(string $errMsgLower): bool {
        return str_contains($errMsgLower, 'no endpoints found')
            || str_contains($errMsgLower, 'no endpoint found');
    }

    private function isTimeoutError(string $errMsgLower): bool {
        return str_contains($errMsgLower, 'curl error 28')
            || str_contains($errMsgLower, 'operation timed out')
            || str_contains($errMsgLower, 'timed out');
    }

    private function requiresExhaustiveReadIntent(string $text): bool {
        $t = mb_strtolower($text);
        return preg_match('/\b(alle|gesamt|komplett|vollständig|sämtlich|alles lesen|komplett lesen|gesamten inhalt)\b/u', $t) === 1;
    }

    private function compactToolResultForModel(array $result): string {
        $compact = $result;
        foreach (['posts', 'pages', 'media', 'users', 'plugins'] as $listKey) {
            if (!isset($compact[$listKey]) || !is_array($compact[$listKey])) {
                continue;
            }
            $originalCount = count($compact[$listKey]);
            if ($originalCount > 20) {
                $compact[$listKey] = array_slice($compact[$listKey], 0, 20);
                $compact[$listKey . '_truncated_count'] = $originalCount - 20;
            }
        }

        if (isset($compact['content']) && is_string($compact['content']) && mb_strlen($compact['content']) > 4000) {
            $compact['content'] = mb_substr($compact['content'], 0, 4000) . "\n...[truncated]";
        }

        $json = wp_json_encode($compact);
        if (!is_string($json)) {
            return '{"success":false,"error":"Could not serialize tool result."}';
        }
        if (mb_strlen($json) > 12000) {
            $json = mb_substr($json, 0, 12000) . '...[truncated]';
        }

        $redactor = PIIRedactor::getInstance();
        if ($redactor->isEnabled()) {
            $json = $redactor->redact($json);
        }

        return $json;
    }

    private function sanitizeAssistantMessageContent(string $text): string {
        $clean = $text;
        // Strip leaked tool protocol tokens from some provider responses.
        $clean = preg_replace('/<\|tool_calls_section_begin\|>[\s\S]*$/u', '', $clean) ?? $clean;
        $clean = preg_replace('/<\|[^|>]+?\|>/u', '', $clean) ?? $clean;
        $clean = preg_replace('/(?:^|\R)\s*functions\.[a-z0-9_]+\s*:\s*\d+[\s\S]*$/iu', '', $clean) ?? $clean;
        $clean = trim((string) preg_replace('/\R{3,}/u', "\n\n", $clean));
        return $clean;
    }

    private function isEmptyAiResponse(array $response): bool {
        $content = (string) ($response['choices'][0]['message']['content'] ?? '');
        $sanitized = $this->sanitizeAssistantMessageContent($content);
        $hasToolCalls = !empty($response['choices'][0]['message']['tool_calls'] ?? []);
        return $sanitized === '' && !$hasToolCalls;
    }

    private function getEmptyResponseFallback(): string {
        return 'Ich bin leider nicht ganz fertig geworden. Schreib einfach „mach weiter" und ich mach mich wieder an die Aufgabe.';
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

    private function inferTaskIntent(string $latestUserMessage, array $messages): array {
        $text = mb_strtolower($latestUserMessage);
        $explicitCreate = preg_match('/\b(neu|neues|neuen|von vorn|from scratch|erstelle|anlegen|erzeuge|schreibe( mir)? ein|baue ein)\b/u', $text) === 1;
        $explicitModify = preg_match('/\b(änder|anpass|optimier|fix|korrigier|überarbeit|update|verbesser|bestehend|nochmal|weiter)\b/u', $text) === 1;
        $referencesExisting = preg_match('/\b(bestehend|vorhanden|das bestehende|dieses bestehende|gleiches plugin|selbes plugin|weiter daran|nochmal daran)\b/u', $text) === 1;

        // Only infer "modify existing" from PRIOR context, not from the current request text.
        $priorMessages = $messages;
        if (!empty($priorMessages)) {
            $last = end($priorMessages);
            if (is_array($last) && ($last['role'] ?? '') === 'user' && ((string) ($last['content'] ?? '')) === $latestUserMessage) {
                array_pop($priorMessages);
            }
        }

        $recentContext = '';
        foreach (array_slice($priorMessages, -10) as $msg) {
            if (!is_array($msg) || !in_array($msg['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            $recentContext .= ' ' . mb_strtolower((string) ($msg['content'] ?? ''));
        }
        $hasRecentArtifacts = preg_match('/\b(post[_ -]?id|page[_ -]?id|plugin[_ -]?file|relative[_ -]?path|slug|bytes_written|erstellt|aktiviert|angelegt)\b/u', $recentContext) === 1;

        $mode = 'unknown';
        if ($explicitModify && !$explicitCreate) {
            $mode = 'modify_existing';
        } elseif ($explicitCreate && !$explicitModify) {
            $mode = 'create_new';
        } elseif ($explicitCreate && $explicitModify) {
            $mode = 'ambiguous';
        } elseif ($referencesExisting && $hasRecentArtifacts) {
            $mode = 'probable_modify';
        }

        return [
            'mode' => $mode,
            'explicit_create' => $explicitCreate,
            'explicit_modify' => $explicitModify,
        ];
    }

    private function isCreationTool(string $toolName, array $args): bool {
        if (in_array($toolName, ['create_post', 'create_page', 'create_plugin', 'create_theme', 'install_plugin'], true)) {
            return true;
        }

        if ($toolName === 'manage_user') {
            return ($args['action'] ?? '') === 'create';
        }

        return false;
    }

    private function isMutatingToolName(string $toolName): bool {
        return in_array($toolName, [
            'create_post',
            'update_post',
            'create_page',
            'delete_post',
            'install_plugin',
            'switch_theme',
            'manage_user',
            'create_plugin',
            'write_plugin_file',
            'delete_plugin_file',
            'write_theme_file',
            'create_theme',
            'delete_theme_file',
            'manage_post_meta',
            'manage_taxonomy',
            'manage_woocommerce',
            'manage_menu',
            'manage_cron',
            'upload_media',
            'store_session_image',
            'update_option',
            'update_any_option',
            'execute_wp_code',
        ], true);
    }

    private function requestedMutationIntent(array $taskIntent): bool {
        if (!empty($taskIntent['explicit_modify']) || !empty($taskIntent['explicit_create'])) {
            return true;
        }
        return in_array($taskIntent['mode'] ?? 'unknown', ['modify_existing', 'create_new', 'probable_modify'], true);
    }

    private function hasSuccessfulMutation(array $toolResults): bool {
        foreach ($toolResults as $row) {
            $tool = (string) ($row['tool'] ?? '');
            $success = (bool) ($row['result']['success'] ?? false);
            if ($success && $this->isMutatingToolName($tool)) {
                return true;
            }
        }
        return false;
    }

    private function shouldNudgePendingMutation(array $toolResults, array $taskIntent, int $nudgeCount): bool {
        if ($nudgeCount >= 1) {
            return false;
        }
        if (!$this->requestedMutationIntent($taskIntent)) {
            return false;
        }
        if ($this->hasSuccessfulMutation($toolResults)) {
            return false;
        }
        return !empty($toolResults);
    }

    private function shouldDeferCreationTool(string $toolName, array $args, array $taskIntent): bool {
        if (!$this->isCreationTool($toolName, $args)) {
            return false;
        }

        if (!empty($taskIntent['explicit_create'])) {
            return false;
        }

        // Only hard-defer when intent is clearly "modify existing".
        return ($taskIntent['mode'] ?? 'unknown') === 'modify_existing';
    }

    private static array $readOnlyTools = [
        'get_pages', 'get_posts', 'get_post', 'get_plugins', 'get_themes',
        'get_options', 'get_users', 'get_media',
        'read_plugin_file', 'list_plugin_files',
        'read_theme_file', 'list_theme_files',
        'search_posts', 'discover_rest_api', 'discover_content_types',
        'read_error_log', 'http_fetch',
    ];

    private function applyResponseSafetyGates(string $finalMessage, array $toolResults, array $taskIntent): string {
        if (empty($toolResults)) {
            return $finalMessage;
        }

        // Deduplicate: keep LAST result per args_key (tool + primary argument).
        $lastByKey = [];
        foreach ($toolResults as $r) {
            $key = $r['args_key'] ?? ($r['tool'] ?? '');
            if ($key !== '') {
                $lastByKey[$key] = $r;
            }
        }
        $deduplicated = array_values($lastByKey);

        $successful = array_filter($deduplicated, fn($r) => ($r['result']['success'] ?? false) === true);
        $failed = array_filter($deduplicated, fn($r) =>
            ($r['result']['success'] ?? false) !== true
            && empty($r['result']['needs_confirmation'])
        );

        // Self-healing: if a tool failed once but later succeeded (same tool name,
        // different args_key), the failure is considered resolved.
        $successfulToolNames = array_unique(array_map(fn($r) => (string) ($r['tool'] ?? ''), $successful));
        $unresolvedFailed = array_filter($failed, function ($r) use ($successfulToolNames) {
            return !in_array((string) ($r['tool'] ?? ''), $successfulToolNames, true);
        });

        // Read-only tool failures are harmless when the AI recovered and ran
        // more tools successfully afterwards -- drop them from unresolved.
        if (!empty($successful) && !empty($unresolvedFailed)) {
            $lastSuccessSeq = max(array_map(fn($r) => (int) ($r['seq'] ?? 0), $successful));
            $unresolvedFailed = array_filter($unresolvedFailed, function ($r) use ($lastSuccessSeq) {
                $toolName = (string) ($r['tool'] ?? '');
                $seq = (int) ($r['seq'] ?? 0);
                if (in_array($toolName, self::$readOnlyTools, true) && $seq < $lastSuccessSeq) {
                    return false;
                }
                return true;
            });
        }

        // All failures resolved -- AI response is fine as-is.
        if (empty($unresolvedFailed)) {
            return $this->appendCreationHintIfNeeded($finalMessage, $successful, $taskIntent);
        }

        $failedList = implode(', ', array_unique(array_map(fn($r) => (string) ($r['tool'] ?? ''), $unresolvedFailed)));
        if ($failedList === '') {
            $failedList = 'mindestens ein Tool';
        }

        // No successful tools at all -- append warning to AI response.
        if (empty($successful)) {
            return $finalMessage . "\n\nHinweis: Mindestens ein Teilschritt ist fehlgeschlagen (" . $failedList . '). '
                . 'Soll ich es erneut versuchen oder zuerst den aktuellen Stand prüfen?';
        }

        // Mixed: some succeeded, some unresolved failures -- append short notice.
        return $finalMessage . "\n\nHinweis: Mindestens ein Teilschritt ist fehlgeschlagen (" . $failedList . '). '
            . 'Bitte prüfe den Zwischenstand, bevor wir final abschließen.';
    }

    private function appendCreationHintIfNeeded(string $finalMessage, array $successful, array $taskIntent): string {
        if (!in_array($taskIntent['mode'] ?? 'unknown', ['modify_existing', 'probable_modify'], true)) {
            return $finalMessage;
        }
        $createdNew = array_filter($successful, fn($r) => $this->isCreationTool(
            (string) ($r['tool'] ?? ''),
            is_array($r['result'] ?? null) ? $r['result'] : []
        ));
        if (!empty($createdNew) && empty($taskIntent['explicit_create'])) {
            return $finalMessage . "\n\nHinweis: Ich habe dabei etwas neu erstellt. Wenn du stattdessen nur das Bestehende ändern willst, sage kurz Bescheid, dann passe ich nur das vorhandene Artefakt an.";
        }
        return $finalMessage;
    }

    private function buildToolArgsKey(string $toolName, array $args): string {
        $discriminator = $args['plugin_slug']
            ?? $args['relative_path']
            ?? $args['post_id']
            ?? $args['page_id']
            ?? $args['option']
            ?? $args['theme_slug']
            ?? '';
        return $toolName . ':' . $discriminator;
    }

    public function getHistory(WP_REST_Request $request): WP_REST_Response {
        $sessionId = $request->get_param('session_id');
        $currentUserId = get_current_user_id();

        // Session ownership: only owner or admin may read history
        $ownerId = $this->conversationRepo->getSessionOwnerId($sessionId);
        if ($ownerId !== null && $ownerId !== $currentUserId && !current_user_can('manage_options')) {
            return new WP_REST_Response([
                'error' => 'Session not found or access denied.',
                'session_id' => $sessionId,
            ], 403);
        }

        $messages = $this->conversationRepo->getHistory($sessionId);

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'messages' => $messages,
        ], 200);
    }

    public function getUserSessions(WP_REST_Request $request): WP_REST_Response {
        $userId = get_current_user_id();
        $sessions = $this->conversationRepo->getUserSessions($userId);

        return new WP_REST_Response([
            'sessions' => $sessions,
        ], 200);
    }

    public function deleteSession(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (string) $request->get_param('session_id');
        if ($sessionId === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'Missing session_id'], 400);
        }

        $history = $this->conversationRepo->getHistory($sessionId, 1);
        $currentUserId = get_current_user_id();
        $isAdmin = current_user_can('manage_options');
        $ownerId = !empty($history) ? (int) ($history[0]['user_id'] ?? 0) : 0;

        if (!$isAdmin) {
            if (empty($history)) {
                $this->clearSessionUploads($sessionId, $currentUserId);
                return new WP_REST_Response(['success' => true, 'session_id' => $sessionId], 200);
            }
            if ($ownerId !== $currentUserId) {
                return new WP_REST_Response(['success' => false, 'error' => 'Not allowed to delete this session'], 403);
            }
        }

        $this->conversationRepo->deleteSession($sessionId);
        if ($isAdmin && $ownerId > 0 && $ownerId !== $currentUserId) {
            $this->clearSessionUploads($sessionId, $ownerId);
        } else {
            $this->clearSessionUploads($sessionId, $currentUserId);
        }

        return new WP_REST_Response([
            'success' => true,
            'session_id' => $sessionId,
        ], 200);
    }

    public function uploadFiles(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (string) ($request->get_param('session_id') ?? '');
        if ($sessionId === '') {
            $sessionId = $this->generateSessionId();
        }

        $userId = get_current_user_id();
        $access = $this->assertSessionAccess($sessionId, $userId);
        if ($access !== true) {
            return $access;
        }

        $files = $request->get_file_params();
        if (empty($files)) {
            return new WP_REST_Response([
                'error' => 'No files uploaded.',
                'session_id' => $sessionId,
            ], 400);
        }

        $normalizedFiles = $this->normalizeUploadedFiles($files);
        if (empty($normalizedFiles)) {
            return new WP_REST_Response([
                'error' => 'No valid file payload found.',
                'session_id' => $sessionId,
            ], 400);
        }

        $stored = $this->getSessionUploads($sessionId, $userId);
        $uploaded = [];
        $errors = [];

        foreach ($normalizedFiles as $file) {
            $single = $this->processUploadedFile($file);
            if (($single['success'] ?? false) !== true) {
                $errors[] = $single['error'] ?? 'Upload failed.';
                continue;
            }

            $entry = $single['file'];
            $stored[] = $entry;
            if (count($stored) > 5) {
                $stored = array_slice($stored, -5);
            }
            $uploaded[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'size' => $entry['size'],
                'type' => $entry['type'],
                'preview' => $entry['preview'],
            ];
        }

        $this->setSessionUploads($sessionId, $userId, $stored);

        return new WP_REST_Response([
            'success' => !empty($uploaded),
            'session_id' => $sessionId,
            'files' => $uploaded,
            'session_files' => $this->filesToMeta($stored),
            'errors' => $errors,
        ], !empty($uploaded) ? 200 : 400);
    }

    public function getSessionUploadsMeta(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (string) ($request->get_param('session_id') ?? '');
        if ($sessionId === '') {
            return new WP_REST_Response(['error' => 'Missing session_id'], 400);
        }

        $userId = get_current_user_id();
        $access = $this->assertSessionAccess($sessionId, $userId);
        if ($access !== true) {
            return $access;
        }

        $files = $this->getSessionUploads($sessionId, $userId);
        return new WP_REST_Response([
            'success' => true,
            'session_id' => $sessionId,
            'files' => $this->filesToMeta($files),
        ], 200);
    }

    public function clearSessionUploadsEndpoint(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (string) ($request->get_param('session_id') ?? '');
        if ($sessionId === '') {
            return new WP_REST_Response(['error' => 'Missing session_id'], 400);
        }

        $userId = get_current_user_id();
        $access = $this->assertSessionAccess($sessionId, $userId);
        if ($access !== true) {
            return $access;
        }

        $this->clearSessionUploads($sessionId, $userId);
        return new WP_REST_Response([
            'success' => true,
            'session_id' => $sessionId,
            'files' => [],
        ], 200);
    }

    public function deleteSessionUploadById(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (string) ($request->get_param('session_id') ?? '');
        $fileId = (string) ($request->get_param('file_id') ?? '');
        if ($sessionId === '' || $fileId === '') {
            return new WP_REST_Response(['error' => 'Missing session_id or file_id'], 400);
        }

        $userId = get_current_user_id();
        $access = $this->assertSessionAccess($sessionId, $userId);
        if ($access !== true) {
            return $access;
        }

        $files = $this->getSessionUploads($sessionId, $userId);
        $before = count($files);
        $files = array_values(array_filter($files, function ($f) use ($fileId) {
            return (string) ($f['id'] ?? '') !== $fileId;
        }));
        if (count($files) === $before) {
            return new WP_REST_Response([
                'error' => 'File not found in session.',
                'session_id' => $sessionId,
            ], 404);
        }

        $this->setSessionUploads($sessionId, $userId, $files);
        return new WP_REST_Response([
            'success' => true,
            'session_id' => $sessionId,
            'files' => $this->filesToMeta($files),
        ], 200);
    }

    public function testConnection(WP_REST_Request $request): WP_REST_Response {
        $result = $this->aiClient->testConnection();

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $result->get_error_message(),
            ], 200);
        }

        return new WP_REST_Response($result, 200);
    }

    private function generateSessionId(): string {
        return 'sess_' . wp_generate_uuid4();
    }

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

    private function assertSessionAccess(string $sessionId, int $userId): bool|WP_REST_Response {
        $ownerId = $this->conversationRepo->getSessionOwnerId($sessionId);
        if ($ownerId !== null && $ownerId !== $userId && !current_user_can('manage_options')) {
            return new WP_REST_Response([
                'error' => 'Session not found or access denied.',
                'session_id' => $sessionId,
            ], 403);
        }
        return true;
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

        // Keep context bounded for prompt stability and provider latency.
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
     * Get image data URLs from session uploads for Vision API.
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

    private function wasResponseTruncated(array $apiResponse): bool {
        return ($apiResponse['choices'][0]['finish_reason'] ?? '') === 'length';
    }

    private function appendTruncationHint(string $message): string {
        return $message . "\n\n---\n*Meine Antwort wurde aufgrund des Token-Limits abgeschnitten. Schreibe \"mach weiter\", damit ich fortfahre.*";
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
