<?php

namespace Levi\Agent\API;

use Levi\Agent\AI\AIClientFactory;
use Levi\Agent\AI\AIClientInterface;
use Levi\Agent\AI\PIIRedactor;
use Levi\Agent\Memory\EmbeddingCache;
use Levi\Agent\Database\ConversationRepository;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Agent\Identity;
use Levi\Agent\Memory\VectorStore;
use Levi\Agent\Memory\StateSnapshotService;
use Levi\Agent\AI\Tools\Registry;
use Levi\Agent\AI\Tools\ToolGuard;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ChatController extends WP_REST_Controller {
    use Concerns\BuildsContext;
    use Concerns\ManagesContext;
    use Concerns\ExecutesToolLoop;
    use Concerns\PostProcessesToolResults;
    use Concerns\ManagesUploads;

    protected $namespace = 'levi-agent/v1';
    protected $rest_base = 'chat';
    private const OWNED_PLUGIN_OPTION = 'levi_owned_plugin_slugs';
    private const OWNED_PLUGIN_BOOTSTRAP_OPTION = 'levi_owned_plugin_slugs_bootstrapped';
    private AIClientInterface $aiClient;
    private ConversationRepository $conversationRepo;
    private SettingsPage $settings;
    private Registry $toolRegistry;
    private ToolGuard $toolGuard;
    private static bool $initialized = false;
    private static ?self $instance = null;

    /** @var array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, api_calls: int, model: ?string} */
    private array $usageAccumulator = [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'cached_tokens' => 0,
        'api_calls' => 0,
        'model' => null,
    ];

    /** Tool names discovered via search_tools during the current request */
    private array $discoveredToolNames = [];

    public function __construct() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $this->settings = new SettingsPage();
        $this->aiClient = AIClientFactory::create($this->settings->getProvider());
        $this->conversationRepo = new ConversationRepository();
        $toolProfile = $this->settings->getSettings()['tool_profile'] ?? 'standard';
        $this->toolRegistry = new Registry($toolProfile);
        $this->toolRegistry->register(new \Levi\Agent\AI\Tools\SearchToolsTool($this->toolRegistry));
        $this->toolGuard = new ToolGuard();

        PIIRedactor::init($this->settings->getSettings());

        add_action('rest_api_init', [$this, 'register_routes']);
        self::$instance = $this;
    }

    public static function getInstance(): ?self {
        return self::$instance;
    }

    public function getToolRegistry(): Registry {
        return $this->toolRegistry;
    }

    /**
     * Get all tool definitions. Always sends complete set — provider-side
     * automatic caching ensures the stable tool block is cached between calls.
     */
    private function getToolDefs(): array {
        return $this->toolRegistry->getDefinitions();
    }

    /**
     * Track tools discovered via search_tools (kept for search_tools compatibility).
     */
    public function addDiscoveredTools(array $toolNames): void {
        foreach ($toolNames as $name) {
            $this->discoveredToolNames[] = (string) $name;
        }
        $this->discoveredToolNames = array_values(array_unique($this->discoveredToolNames));
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
            'web_search' => [
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
                'callback' => [$this, 'confirmActionStream'],
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/confirm-action-sync', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'confirmActionNonStreaming'],
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

    public function confirmActionStream(WP_REST_Request $request): void {
        $phpTimeLimit = (int) ($this->settings->getSettings()['php_time_limit'] ?? 300);
        if ($phpTimeLimit > 0 && function_exists('set_time_limit')) {
            @set_time_limit($phpTimeLimit);
        }
        ignore_user_abort(false);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        try {
            $this->processConfirmationStreaming($request);
        } catch (\Throwable $e) {
            error_log('Levi Confirm Stream Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->emitSSE('error', [
                'message' => 'Internal error: ' . $e->getMessage(),
            ]);
        }

        die();
    }

    public function confirmActionNonStreaming(WP_REST_Request $request): WP_REST_Response {
        $phpTimeLimit = (int) ($this->settings->getSettings()['php_time_limit'] ?? 300);
        if ($phpTimeLimit > 0 && function_exists('set_time_limit')) {
            @set_time_limit($phpTimeLimit);
        }

        $actionId = (string) $request->get_param('action_id');
        $userId = get_current_user_id();

        $transientKey = 'levi_pending_' . $actionId;
        $pending = get_transient($transientKey);

        if (!is_array($pending) || empty($pending['tool_name'])) {
            return new WP_REST_Response(['error' => 'Aktion nicht gefunden oder abgelaufen.'], 404);
        }

        if ((int) ($pending['user_id'] ?? 0) !== $userId) {
            return new WP_REST_Response(['error' => 'Keine Berechtigung fuer diese Aktion.'], 403);
        }

        $toolName = (string) $pending['tool_name'];
        $toolArgs = is_array($pending['tool_args']) ? $pending['tool_args'] : [];
        $sessionId = (string) ($pending['session_id'] ?? '');
        $planContext = $pending['plan_context'] ?? null;

        $result = $this->toolRegistry->execute($toolName, $toolArgs);
        $this->trackOwnedPluginFromToolResult($toolName, $toolArgs, $result);
        $this->logToolExecution($sessionId, $userId, $toolName, $toolArgs, $result);
        delete_transient($transientKey);

        $ok = !empty($result['success']);
        $compactResult = $this->compactToolResultForModel($result);

        if (!$ok || !$this->aiClient->isConfigured() || $sessionId === '') {
            $summary = $this->describeToolAction($toolName, $toolArgs);
            $statusLine = $ok
                ? "✅ {$summary} – erfolgreich ausgefuehrt."
                : "❌ {$summary} – fehlgeschlagen.";
            $finalMessage = $statusLine . "\n\n" . mb_substr($compactResult, 0, 500);
            if ($sessionId !== '') {
                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
            }
            return new WP_REST_Response([
                'session_id' => $sessionId,
                'message' => $finalMessage,
                'tool_executed' => $toolName,
                'success' => $ok,
            ], 200);
        }

        $messages = $this->buildMessagesForConfirmation($sessionId, $toolName, $toolArgs, $compactResult);
        $response = $this->chatWithTracking($messages, $this->getToolDefs());
        if (is_wp_error($response)) {
            $errMsgLower = mb_strtolower($response->get_error_message());
            if ($this->isNoEndpointsError($errMsgLower) || $this->isTimeoutError($errMsgLower)) {
                $response = $this->chatWithTracking($messages, []);
            }
        }
        if (is_wp_error($response)) {
            $summary = $this->describeToolAction($toolName, $toolArgs);
            $finalMessage = "✅ {$summary} – erfolgreich ausgefuehrt.";
            $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
            return new WP_REST_Response([
                'session_id' => $sessionId,
                'message' => $finalMessage,
                'tool_executed' => $toolName,
                'success' => true,
            ], 200);
        }

        $messageData = $response['choices'][0]['message'] ?? [];
        if (!empty($messageData['tool_calls'])) {
            $latestUserMessage = $this->getLatestUserMessage($sessionId);
            return $this->handleToolCalls(
                $messageData, $messages, $sessionId, $userId,
                $latestUserMessage, false, $planContext
            );
        }

        $assistantMessage = $this->sanitizeAssistantMessageContent(
            (string) ($messageData['content'] ?? '')
        );
        if ($assistantMessage === '') {
            $summary = $this->describeToolAction($toolName, $toolArgs);
            $assistantMessage = "✅ {$summary} – erfolgreich ausgefuehrt.";
        }

        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $assistantMessage);
        $usage = $this->usageAccumulator;
        $this->flushUsage($sessionId, $userId);
        return new WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $assistantMessage,
            'model' => $response['model'] ?? null,
            'tool_executed' => $toolName,
            'success' => true,
            'usage' => $usage,
        ], 200);
    }

    private function processConfirmationStreaming(WP_REST_Request $request): void {
        $actionId = (string) $request->get_param('action_id');
        $userId = get_current_user_id();

        $transientKey = 'levi_pending_' . $actionId;
        $pending = get_transient($transientKey);

        if (!is_array($pending) || empty($pending['tool_name'])) {
            $this->emitSSE('error', ['message' => 'Aktion nicht gefunden oder abgelaufen. Bitte erneut anfordern.']);
            return;
        }

        if ((int) ($pending['user_id'] ?? 0) !== $userId) {
            $this->emitSSE('error', ['message' => 'Keine Berechtigung fuer diese Aktion.']);
            return;
        }

        $toolName = (string) $pending['tool_name'];
        $toolArgs = is_array($pending['tool_args']) ? $pending['tool_args'] : [];
        $sessionId = (string) ($pending['session_id'] ?? '');

        $this->emitSSE('progress', [
            'message' => $this->getToolProgressLabel($toolName, 'start'),
            'tool' => $toolName,
        ]);

        $result = $this->toolRegistry->execute($toolName, $toolArgs);
        $this->trackOwnedPluginFromToolResult($toolName, $toolArgs, $result);
        $this->logToolExecution($sessionId, $userId, $toolName, $toolArgs, $result);
        delete_transient($transientKey);

        $ok = !empty($result['success']);
        $compactResult = $this->compactToolResultForModel($result);

        $this->emitSSE('progress', [
            'message' => $this->getToolProgressLabel($toolName, $ok ? 'done' : 'failed'),
            'tool' => $toolName,
            'success' => $ok,
        ]);

        if (!$ok || !$this->aiClient->isConfigured() || $sessionId === '') {
            $summary = $this->describeToolAction($toolName, $toolArgs);
            $statusLine = $ok
                ? "✅ {$summary} – erfolgreich ausgefuehrt."
                : "❌ {$summary} – fehlgeschlagen.";
            $finalMessage = $statusLine . "\n\n" . mb_substr($compactResult, 0, 500);
            $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
            $this->emitSSE('done', [
                'session_id' => $sessionId,
                'message' => $finalMessage,
                'usage' => $this->usageAccumulator,
            ]);
            $this->flushUsage($sessionId, $userId);
            return;
        }

        $messages = $this->buildMessagesForConfirmation($sessionId, $toolName, $toolArgs, $compactResult);
        $heartbeat = function () {
            if (connection_aborted()) {
                return;
            }
            $this->emitSSE('heartbeat', []);
        };

        $this->emitSSE('status', ['message' => 'Levi arbeitet...']);

        $response = $this->chatWithTracking($messages, $this->getToolDefs(), $heartbeat);
        if (is_wp_error($response)) {
            $errMsgLower = mb_strtolower($response->get_error_message());
            if ($this->isNoEndpointsError($errMsgLower) || $this->isTimeoutError($errMsgLower)) {
                $response = $this->chatWithTracking($messages, [], $heartbeat);
            }
        }
        if (is_wp_error($response)) {
            $summary = $this->describeToolAction($toolName, $toolArgs);
            $finalMessage = "✅ {$summary} – erfolgreich ausgefuehrt.";
            $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);
            $this->emitSSE('done', ['session_id' => $sessionId, 'message' => $finalMessage, 'usage' => $this->usageAccumulator]);
            $this->flushUsage($sessionId, $userId);
            return;
        }

        $messageData = $response['choices'][0]['message'] ?? [];

        if (!empty($messageData['tool_calls'])) {
            $latestUserMessage = $this->getLatestUserMessage($sessionId);
            $this->handleToolCallsStreaming(
                $messageData, $messages, $sessionId, $userId,
                $latestUserMessage, $heartbeat, false, $pending['plan_context'] ?? null
            );
            return;
        }

        $assistantMessage = $this->sanitizeAssistantMessageContent(
            (string) ($messageData['content'] ?? '')
        );
        if ($assistantMessage === '') {
            $summary = $this->describeToolAction($toolName, $toolArgs);
            $assistantMessage = "✅ {$summary} – erfolgreich ausgefuehrt.";
        }

        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $assistantMessage);
        $this->emitSSE('done', [
            'session_id' => $sessionId,
            'message' => $assistantMessage,
            'usage' => $this->usageAccumulator,
        ]);
        $this->flushUsage($sessionId, $userId);
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
        $phpTimeLimit = (int) ($this->settings->getSettings()['php_time_limit'] ?? 300);
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
        $phpTimeLimit = (int) ($this->settings->getSettings()['php_time_limit'] ?? 300);
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
        $this->emitSSE('status', ['message' => 'Levi verarbeitet die Anfrage...', 'session_id' => $sessionId]);

        $webSearch = (bool) $request->get_param('web_search') && $this->settings->isWebSearchEnabled();

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

        $this->discoveredToolNames = [];
        $messages = $this->buildMessages($sessionId, $message, true);
        $tools = $this->toolRegistry->getDefinitions();

        // Heartbeat callback for SSE keepalive during non-streaming API calls
        $heartbeat = function () {
            if (connection_aborted()) {
                return;
            }
            $this->emitSSE('heartbeat', []);
        };

        // Get AI client (uses alternative model for simple queries)
        $aiClient = $this->getAIClient();

        // --- Primary path: streaming with real-time delta output ---
        $streamResult = $this->streamChatWithTracking($messages, $tools);

        if (!is_wp_error($streamResult)) {
            if (!empty($streamResult['has_tool_calls']) && !empty($streamResult['tool_calls'])) {
                $this->emitSSE('stream_end', []);
                $toolCallData = [
                    'role' => 'assistant',
                    'content' => $streamResult['content'] ?? null,
                    'tool_calls' => $streamResult['tool_calls'],
                ];
                $this->handleToolCallsStreaming($toolCallData, $messages, $sessionId, $userId, (string) $message, $heartbeat, $webSearch, null);
                if ($hasUploadedContext) {
                    $this->clearSessionUploads($sessionId, $userId);
                }
                return;
            }

            $assistantMessage = $this->sanitizeAssistantMessageContent(
                (string) ($streamResult['content'] ?? '')
            );

            if ($assistantMessage !== '' && $this->looksLikeFakeConfirmation($assistantMessage)) {
                $this->emitSSE('stream_end', []);
                $messages[] = ['role' => 'assistant', 'content' => $assistantMessage];
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
                        $this->handleToolCallsStreaming($retryData, $messages, $sessionId, $userId, (string) $message, $heartbeat, $webSearch, null);
                        if ($hasUploadedContext) {
                            $this->clearSessionUploads($sessionId, $userId);
                        }
                        return;
                    }
                }
            }

            if ($assistantMessage === '') {
                $assistantMessage = $this->getEmptyResponseFallback();
            }

            $truncated = ($streamResult['finish_reason'] ?? 'stop') === 'length';
            if ($truncated) {
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
                'model' => $streamResult['model'] ?? null,
                'truncated' => $truncated,
                'usage' => $this->usageAccumulator,
            ]);
            $this->flushUsage($sessionId, $userId);
            return;
        }

        // --- Fallback: streaming failed → non-streaming with full retry logic ---
        $this->emitSSE('stream_end', []);
        error_log('Levi: streaming failed (' . $streamResult->get_error_code() . ': ' . $streamResult->get_error_message() . '), falling back to non-streaming');

        $response = $this->chatWithTracking($messages, $tools, $heartbeat, $webSearch);

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $errMsgLower = mb_strtolower($errMsg);
            $isNoEndpointFailure = $this->isNoEndpointsError($errMsgLower);
            $isProviderFailure = str_contains($errMsgLower, 'provider') || str_contains($errMsgLower, '503');
            $isTimeoutFailure = $this->isTimeoutError($errMsgLower);

            if (!empty($tools) && ($isNoEndpointFailure || ($isProviderFailure && !$this->isActionIntent($message)))) {
                $this->emitSSE('status', ['message' => 'Neuer Versuch ohne Tools...']);
                $response = $this->chatWithTracking($messages, [], $heartbeat, $webSearch);
            } elseif ($isTimeoutFailure && $hasUploadedContext) {
                $this->emitSSE('status', ['message' => 'Timeout, versuche mit weniger Kontext...']);
                $messages = $this->buildMessages($sessionId, $message, false);
                $response = $this->chatWithTracking($messages, $tools, $heartbeat, $webSearch);
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->chatWithTracking($messages, [], $heartbeat, $webSearch);
                }
            } elseif ($isTimeoutFailure && !empty($tools)) {
                $this->emitSSE('status', ['message' => 'Timeout, neuer Versuch...']);
                $response = $this->chatWithTracking($messages, [], $heartbeat, $webSearch);
            }
        }

        if (is_wp_error($response)) {
            $overflowMsg = mb_strtolower($response->get_error_message());
            if (str_contains($overflowMsg, 'context length') || str_contains($overflowMsg, 'too many tokens') || str_contains($overflowMsg, 'maximum context')) {
                $this->emitSSE('status', ['message' => 'Kontext wird gekuerzt...']);
                $halvedMessages = $this->halveHistory($messages);
                $response = $this->chatWithTracking($halvedMessages, $tools, $heartbeat, $webSearch);
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->chatWithTracking($halvedMessages, [], $heartbeat, $webSearch);
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

        if ($this->isEmptyAiResponse($response)) {
            $originalContent = (string) ($response['choices'][0]['message']['content'] ?? '');
            error_log('Levi: empty AI response (attempt 1), original content: ' . mb_substr($originalContent, 0, 500));
            for ($retryAttempt = 1; $retryAttempt <= 2; $retryAttempt++) {
                $this->emitSSE('status', ['message' => 'Levi versucht es erneut... (Versuch ' . ($retryAttempt + 1) . ')']);
                $response = $this->chatWithTracking($messages, $tools, $heartbeat, $webSearch);
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

        if (!empty($messageData['tool_calls'])) {
            $this->handleToolCallsStreaming($messageData, $messages, $sessionId, $userId, (string) $message, $heartbeat, $webSearch, null);
            if ($hasUploadedContext) {
                $this->clearSessionUploads($sessionId, $userId);
            }
            return;
        }

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
            'usage' => $this->usageAccumulator,
        ]);
        $this->flushUsage($sessionId, $userId);
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

        $webSearch = (bool) $request->get_param('web_search') && $this->settings->isWebSearchEnabled();

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

        $this->discoveredToolNames = [];
        $messages = $this->buildMessages($sessionId, $message, true);
        $tools = $this->toolRegistry->getDefinitions();

        // Get AI client (uses alternative model for simple queries)
        $aiClient = $this->getAIClient();

        // Call AI – try with tools first, fallback to no tools on provider error
        $response = $this->chatWithTracking($messages, $tools, null, $webSearch);

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $errMsgLower = mb_strtolower($errMsg);
            $isNoEndpointFailure = $this->isNoEndpointsError($errMsgLower);
            $isProviderFailure = str_contains($errMsgLower, 'provider') || str_contains($errMsgLower, '503');
            $isTimeoutFailure = $this->isTimeoutError($errMsgLower);

            // For endpoint availability issues, always retry once without tools
            // (also for action intents), because some free endpoints reject tool mode.
            if (!empty($tools) && ($isNoEndpointFailure || ($isProviderFailure && !$this->isActionIntent($message)))) {
                $response = $this->chatWithTracking($messages, [], null, $webSearch);
            } elseif ($isTimeoutFailure && $hasUploadedContext) {
                // Retry once with same history but without uploaded file context.
                $messages = $this->buildMessages($sessionId, $message, false);
                $response = $this->chatWithTracking($messages, $tools, null, $webSearch);
                if (is_wp_error($response) && !empty($tools)) {
                    // Last retry for timeout path: disable tools to reduce payload/latency.
                    $response = $this->chatWithTracking($messages, [], null, $webSearch);
                }
            } elseif ($isTimeoutFailure && !empty($tools)) {
                // Retry once without tools for slow/loaded endpoints.
                $response = $this->chatWithTracking($messages, [], null, $webSearch);
            }
        }

        // Context overflow auto-recovery: halve the history and retry once
        if (is_wp_error($response)) {
            $overflowMsg = mb_strtolower($response->get_error_message());
            if (str_contains($overflowMsg, 'context length') || str_contains($overflowMsg, 'too many tokens') || str_contains($overflowMsg, 'maximum context')) {
                error_log('Levi: context overflow detected, retrying with halved history');
                $halvedMessages = $this->halveHistory($messages);
                $response = $this->chatWithTracking($halvedMessages, $tools, null, $webSearch);
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->chatWithTracking($halvedMessages, [], null, $webSearch);
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
                $response = $this->chatWithTracking($messages, $tools, null, $webSearch);
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
            $toolResponse = $this->handleToolCalls($messageData, $messages, $sessionId, $userId, (string) $message, $webSearch, null);
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

        $usage = $this->usageAccumulator;
        $this->flushUsage($sessionId, $userId);
        return new WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $assistantMessage,
            'model' => $response['model'] ?? null,
            'truncated' => $this->wasResponseTruncated($response),
            'usage' => $usage,
            'timestamp' => current_time('mysql'),
        ], 200);
    }

    private function chatWithTracking(array $messages, array $tools = [], ?callable $heartbeat = null, bool $webSearch = false): array|WP_Error {
        $response = $this->aiClient->chat($messages, $tools, $heartbeat, $webSearch);
        if (!is_wp_error($response)) {
            $this->accumulateUsage($response);
        }
        return $response;
    }

    /**
     * Stream a chat response, emitting SSE delta events for each text chunk.
     * Returns the full stream result for post-processing (tool_calls detection, usage).
     */
    private function streamChatWithTracking(array $messages, array $tools = []): array|WP_Error {
        $result = $this->aiClient->streamChat($messages, function (string $chunk) {
            $this->emitSSE('delta', ['content' => $chunk]);
        }, $tools);

        if (is_wp_error($result)) {
            return $result;
        }

        if (!empty($result['usage'])) {
            $usage = $result['usage'];
            $this->usageAccumulator['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
            $this->usageAccumulator['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
            $this->usageAccumulator['cached_tokens'] += (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['cache_read_input_tokens'] ?? 0);
            $this->usageAccumulator['api_calls']++;
            if ($this->usageAccumulator['model'] === null) {
                $this->usageAccumulator['model'] = $result['model'] ?? null;
            }
        } else {
            $this->usageAccumulator['api_calls']++;
        }

        return $result;
    }

    private function accumulateUsage(array $response): void {
        $usage = $response['usage'] ?? [];
        $this->usageAccumulator['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $this->usageAccumulator['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
        $this->usageAccumulator['cached_tokens'] += (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['cache_read_input_tokens'] ?? 0);
        $this->usageAccumulator['api_calls']++;
        if ($this->usageAccumulator['model'] === null) {
            $this->usageAccumulator['model'] = $response['model'] ?? null;
        }
    }

    private function flushUsage(string $sessionId, int $userId): void {
        if ($this->usageAccumulator['api_calls'] === 0) {
            return;
        }
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'levi_usage_log', [
            'session_id' => $sessionId,
            'user_id' => $userId > 0 ? $userId : null,
            'prompt_tokens' => $this->usageAccumulator['prompt_tokens'],
            'completion_tokens' => $this->usageAccumulator['completion_tokens'],
            'cached_tokens' => $this->usageAccumulator['cached_tokens'],
            'api_calls' => $this->usageAccumulator['api_calls'],
            'model' => $this->usageAccumulator['model'],
        ]);
        $this->usageAccumulator = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cached_tokens' => 0,
            'api_calls' => 0,
            'model' => null,
        ];
    }

    private function hasUserConfirmationSignal(string $text): bool {
        // Confirmation only via the dedicated confirm_action REST endpoint (button click).
        // Text-based pattern matching was removed because natural language messages
        // like "Ok, lösche das Plugin" falsely bypassed the confirmation mechanism.
        return false;
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

    private function buildToolLoopFallbackMessage(array $toolResults): string {
        $successful = array_values(array_filter($toolResults, fn($r) => ($r['result']['success'] ?? false) === true));
        if (empty($successful)) {
            return $this->getEmptyResponseFallback();
        }

        $recentSuccessful = array_slice($successful, -4);
        $lines = [];
        foreach ($recentSuccessful as $row) {
            $tool = (string) ($row['tool'] ?? 'tool');
            $result = is_array($row['result'] ?? null) ? $row['result'] : [];
            $lines[] = '- ' . $tool . ': ' . $this->summarizeToolResult($result);
        }

        return "Erledigt! ✅\n\nIch habe die Schritte ausgefuehrt, aber konnte keinen sauberen KI-Abschlusstext erzeugen. "
            . "Hier ist der technische Stand:\n" . implode("\n", $lines);
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
        if (in_array($toolName, ['create_post', 'create_page', 'create_plugin', 'create_theme'], true)) {
            return true;
        }

        if ($toolName === 'install_plugin') {
            $action = $args['action'] ?? '';
            return $action !== 'update_outdated' && $action !== 'update';
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
            'patch_plugin_file',
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

    /**
     * @deprecated Removed — ToolGuard handles safety; intent-mismatch is handled via post-execution hint only.
     */
    private function shouldDeferCreationTool(string $toolName, array $args, array $taskIntent): bool {
        return false;
    }

    /**
     * Build an internal dry-plan before first mutating execution.
     * Plan is kept internal and reused across confirmation round-trips.
     */
    private function ensureDryPlan(?array $planContext, array $toolCalls, string $latestUserMessage, array $taskIntent): array {
        if (is_array($planContext) && !empty($planContext['steps']) && !empty($planContext['domain'])) {
            return $planContext;
        }

        $hasMutation = false;
        foreach ($toolCalls as $toolCall) {
            $name = trim((string) ($toolCall['function']['name'] ?? ''));
            if ($this->isMutatingToolName($name)) {
                $hasMutation = true;
                break;
            }
        }

        if (!$hasMutation) {
            return is_array($planContext) ? $planContext : [
                'plan_id' => wp_generate_uuid4(),
                'domain' => 'unknown',
                'intent_mode' => (string) ($taskIntent['mode'] ?? 'unknown'),
                'steps' => [],
                'created_at' => time(),
            ];
        }

        $steps = $this->buildDryPlanSteps($toolCalls);
        $domain = $this->inferPlanDomainFromIntentAndSteps($latestUserMessage, $steps);

        return [
            'plan_id' => wp_generate_uuid4(),
            'domain' => $domain,
            'intent_mode' => (string) ($taskIntent['mode'] ?? 'unknown'),
            'steps' => $steps,
            'created_at' => time(),
        ];
    }

    private function buildDryPlanSteps(array $toolCalls): array {
        $steps = [];
        foreach ($toolCalls as $toolCall) {
            $tool = trim((string) ($toolCall['function']['name'] ?? ''));
            $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
            if (!is_array($args)) {
                $args = [];
            }
            $action = $this->extractToolAction($tool, $args);
            $domain = $this->classifyToolDomain($tool, $args);
            $steps[] = [
                'tool' => $tool,
                'action' => $action,
                'reason' => 'Vom Modell vorgeschlagener Schritt fuer die aktuelle Anfrage.',
                'expected_object' => $domain !== 'unknown' ? $domain : 'wordpress_object',
            ];
        }
        return $steps;
    }

    private function extractToolAction(string $toolName, array $args): string {
        if (isset($args['action']) && is_string($args['action']) && $args['action'] !== '') {
            return $args['action'];
        }
        return $toolName;
    }

    private function inferPlanDomainFromIntentAndSteps(string $latestUserMessage, array $steps): string {
        $requested = $this->inferRequestedDomain($latestUserMessage);
        if ($requested !== 'unknown') {
            return $requested;
        }

        $counts = [];
        foreach ($steps as $step) {
            $domain = (string) ($step['expected_object'] ?? 'unknown');
            if ($domain === 'unknown') {
                continue;
            }
            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }
        if (empty($counts)) {
            return 'unknown';
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    private function inferRequestedDomain(string $latestUserMessage): string {
        $text = mb_strtolower($latestUserMessage);
        $domains = [
            'woocommerce' => '/\b(woocommerce|produkt|produkte|variation|variationen|bestellung|bestellungen|coupon|gutschein|warenkorb|shop|product|products|order|orders|coupon|coupons)\b/u',
            'elementor' => '/\b(elementor|landingpage|layout|widget|section|container)\b/u',
            'theme' => '/\b(theme|design|stylesheet|style\\.css|template)\b/u',
            'plugin' => '/\b(plugin|plugin-datei|plugin ordner|plugin-ordner|wp-content\/plugins)\b/u',
            'content' => '/\b(post|beitrag|seite|page|artikel|kategorie|tag|taxonomy)\b/u',
        ];

        foreach ($domains as $domain => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $domain;
            }
        }
        return 'unknown';
    }

    private function classifyToolDomain(string $toolName, array $args): string {
        if (in_array($toolName, ['manage_woocommerce', 'get_woocommerce_data', 'get_woocommerce_shop'], true)) {
            return 'woocommerce';
        }

        if ($toolName === 'manage_taxonomy') {
            $taxonomy = (string) ($args['taxonomy'] ?? '');
            if (str_starts_with($taxonomy, 'product_')) {
                return 'woocommerce';
            }
            return 'content';
        }

        if (in_array($toolName, ['create_plugin', 'write_plugin_file', 'patch_plugin_file', 'delete_plugin_file', 'read_plugin_file', 'list_plugin_files'], true)) {
            return 'plugin';
        }
        if (in_array($toolName, ['create_theme', 'write_theme_file', 'delete_theme_file', 'read_theme_file', 'list_theme_files'], true)) {
            return 'theme';
        }
        if (in_array($toolName, ['elementor_build', 'manage_elementor', 'get_elementor_data'], true)) {
            return 'elementor';
        }
        if (in_array($toolName, ['create_post', 'update_post', 'create_page', 'delete_post', 'manage_post_meta'], true)) {
            return 'content';
        }
        return 'unknown';
    }

    private function validateToolCallAgainstPlan(string $toolName, array $args, array $taskIntent, array $planContext): array {
        if ($toolName === '') {
            return ['allow' => false, 'reason' => 'Leerer Tool-Name ist nicht gueltig.'];
        }

        $isMutation = $this->isMutatingToolName($toolName);
        if (!$isMutation) {
            return ['allow' => true];
        }

        if ($this->isPluginMutationTool($toolName)) {
            $pluginSlug = $this->extractPluginSlug($args);
            if ($pluginSlug === '') {
                return [
                    'allow' => false,
                    'reason' => 'Plugin-Bearbeitung blockiert: plugin_slug fehlt oder ist ungueltig.',
                ];
            }
            if (!$this->isPluginSlugOwnedOrAllowed($pluginSlug)) {
                return [
                    'allow' => false,
                    'reason' => "Plugin-Bearbeitung blockiert: '$pluginSlug' ist kein freigegebenes eigenes Plugin (Drittanbieter-Schutz aktiv).",
                ];
            }
        }

        $planDomain = (string) ($planContext['domain'] ?? 'unknown');
        if ($planDomain === 'unknown') {
            return ['allow' => true];
        }

        $toolDomain = $this->classifyToolDomain($toolName, $args);
        if ($toolDomain === 'unknown') {
            return ['allow' => true];
        }

        if ($toolDomain !== $planDomain) {
            if (
                $toolDomain === 'plugin'
                && ($this->isPluginMutationTool($toolName) || $toolName === 'create_plugin')
            ) {
                return ['allow' => true];
            }
            return [
                'allow' => false,
                'reason' => "Tool-Domain-Mismatch: erwartet '{$planDomain}', erhalten '{$toolDomain}'.",
            ];
        }

        return ['allow' => true];
    }

    private static array $readOnlyTools = [
        'get_pages', 'get_posts', 'get_post', 'get_plugins', 'get_themes',
        'get_options', 'get_users', 'get_media',
        'read_plugin_file', 'list_plugin_files',
        'read_theme_file', 'list_theme_files',
        'search_posts', 'discover_rest_api', 'discover_content_types',
        'read_error_log', 'http_fetch',
    ];

    private function isPluginMutationTool(string $toolName): bool {
        return in_array($toolName, ['write_plugin_file', 'patch_plugin_file', 'delete_plugin_file'], true);
    }

    private function looksLikeFakeConfirmation(string $message): bool {
        if ($message === '') {
            return false;
        }
        $lower = mb_strtolower($message);
        $hasConfirmPhrase = str_contains($lower, 'bestaetige')
            || str_contains($lower, 'bestätige')
            || str_contains($lower, 'bitte bestätigen')
            || str_contains($lower, 'bitte bestätigen');
        $hasActionPhrase = str_contains($lower, 'ich moechte')
            || str_contains($lower, 'ich möchte')
            || str_contains($lower, 'soll ich');
        return $hasConfirmPhrase && $hasActionPhrase;
    }

    private function extractPluginSlug(array $args): string {
        $slug = sanitize_title((string) ($args['plugin_slug'] ?? ''));
        return $slug;
    }

    private function isPluginSlugOwnedOrAllowed(string $pluginSlug): bool {
        $slug = sanitize_title($pluginSlug);
        if ($slug === '') {
            return false;
        }

        $owned = $this->getOwnedPluginSlugs();
        if (in_array($slug, $owned, true)) {
            return true;
        }

        $manualAllowed = $this->getManualAllowedPluginSlugs();
        return in_array($slug, $manualAllowed, true);
    }

    private function getOwnedPluginSlugs(): array {
        $this->bootstrapOwnedPluginSlugsFromAuditLog();
        $stored = get_option(self::OWNED_PLUGIN_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $slugs = [];
        foreach ($stored as $entry) {
            $slug = sanitize_title((string) $entry);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        return array_values(array_unique($slugs));
    }

    private function getManualAllowedPluginSlugs(): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $settings = $this->settings->getSettings();
        $raw = (string) ($settings['allowed_plugin_slugs_manual'] ?? '');
        $parts = preg_split('/[\s,;]+/u', $raw) ?: [];
        $allowed = [];
        foreach ($parts as $part) {
            $slug = sanitize_title((string) $part);
            if ($slug !== '') {
                $allowed[] = $slug;
            }
        }
        $cache = array_values(array_unique($allowed));
        return $cache;
    }

    private function bootstrapOwnedPluginSlugsFromAuditLog(): void {
        if ((int) get_option(self::OWNED_PLUGIN_BOOTSTRAP_OPTION, 0) === 1) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'levi_audit_log';
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$tableExists) {
            update_option(self::OWNED_PLUGIN_BOOTSTRAP_OPTION, 1, false);
            return;
        }

        $rows = $wpdb->get_col(
            "SELECT tool_args FROM {$table} WHERE tool_name = 'create_plugin' AND success = 1 ORDER BY id ASC"
        );
        if (!is_array($rows) || empty($rows)) {
            update_option(self::OWNED_PLUGIN_BOOTSTRAP_OPTION, 1, false);
            return;
        }

        $collected = [];
        foreach ($rows as $rawArgs) {
            if (!is_string($rawArgs) || $rawArgs === '') {
                continue;
            }
            $decoded = json_decode($rawArgs, true);
            if (!is_array($decoded)) {
                continue;
            }
            $slug = sanitize_title((string) ($decoded['slug'] ?? $decoded['plugin_slug'] ?? ''));
            if ($slug !== '') {
                $collected[] = $slug;
            }
        }

        $existing = get_option(self::OWNED_PLUGIN_OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $merged = [];
        foreach (array_merge($existing, $collected) as $entry) {
            $slug = sanitize_title((string) $entry);
            if ($slug !== '') {
                $merged[] = $slug;
            }
        }
        update_option(self::OWNED_PLUGIN_OPTION, array_values(array_unique($merged)), false);
        update_option(self::OWNED_PLUGIN_BOOTSTRAP_OPTION, 1, false);
    }

    private function trackOwnedPluginFromToolResult(string $toolName, array $toolArgs, array $result): void {
        if ($toolName !== 'create_plugin' || empty($result['success'])) {
            return;
        }

        $slug = sanitize_title((string) ($result['slug'] ?? $toolArgs['slug'] ?? $toolArgs['plugin_slug'] ?? ''));
        if ($slug === '') {
            return;
        }

        $existing = get_option(self::OWNED_PLUGIN_OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $normalized = [];
        foreach ($existing as $entry) {
            $candidate = sanitize_title((string) $entry);
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }
        if (!in_array($slug, $normalized, true)) {
            $normalized[] = $slug;
            update_option(self::OWNED_PLUGIN_OPTION, array_values(array_unique($normalized)), false);
        }
    }

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

        // No successful tools at all -- append warning to AI response.
        if (empty($successful)) {
            return $finalMessage . "\n\nHinweis: Ich hatte Probleme bei der Ausfuehrung. Soll ich es nochmal versuchen?";
        }

        // Mixed: some succeeded, some unresolved failures -- append short notice.
        return $finalMessage . "\n\nIch hatte kurz Probleme bei einem Teilschritt, aber es sollte soweit alles passen :)";
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

    private function wasResponseTruncated(array $apiResponse): bool {
        return ($apiResponse['choices'][0]['finish_reason'] ?? '') === 'length';
    }

    private function appendTruncationHint(string $message): string {
        return $message . "\n\n---\n*Meine Antwort wurde aufgrund des Token-Limits abgeschnitten. Schreibe \"mach weiter\", damit ich fortfahre.*";
    }

}
