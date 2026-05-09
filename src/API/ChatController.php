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
use Levi\Agent\AI\Tools\GenericRegistry;
use Levi\Agent\AI\Tools\ToolGuard;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ChatController extends WP_REST_Controller
{
    use Concerns\BuildsContext;
    use Concerns\ManagesContext;
    use Concerns\PostProcessesToolResults;
    use Concerns\ManagesUploads;
    use Concerns\TracksWorkingSet;
    use \Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
    use \Levi\Agent\AI\Tools\Concerns\WordPressCoreWhitelist;

    protected $namespace = "levi-agent/v1";
    protected $rest_base = "chat";
    protected const OWNED_PLUGIN_OPTION = "levi_owned_plugin_slugs";
    protected const OWNED_PLUGIN_BOOTSTRAP_OPTION = "levi_owned_plugin_slugs_bootstrapped";
    private AIClientInterface $aiClient;
    private ConversationRepository $conversationRepo;
    private SettingsPage $settings;
    private Registry $toolRegistry;
    private ?GenericRegistry $genericRegistry = null;
    private ToolGuard $toolGuard;
    private bool $useGenericTools = false;
    private static bool $initialized = false;
    private static ?self $instance = null;
    private RequestHandler $requestHandler;
    private ChatSessionManager $sessionManager;
    private MessagePipeline $messagePipeline;
    private ToolLoopEngine $toolLoopEngine;

    // Thin delegations for methods extracted into ToolLoopEngine
    public function getToolProgressLabel(
        string $toolName,
        string $phase,
    ): string {
        return $this->toolLoopEngine->getToolProgressLabel($toolName, $phase);
    }

    public function summarizeToolResult(array $result): string
    {
        return $this->toolLoopEngine->summarizeToolResult($result);
    }

    public function isWriteTool(string $toolName): bool
    {
        return $this->toolLoopEngine->isWriteTool($toolName);
    }

    /** @var array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, api_calls: int, model: ?string} */
    private array $usageAccumulator = [
        "prompt_tokens" => 0,
        "completion_tokens" => 0,
        "cached_tokens" => 0,
        "api_calls" => 0,
        "model" => null,
    ];

    /** Last substantial content successfully streamed to the client (fallback recovery). */
    private ?string $lastStreamedContent = null;

    /** Tool names discovered via search_tools during the current request */
    private array $discoveredToolNames = [];

    public function __construct()
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $this->settings = new SettingsPage();
        $this->aiClient = AIClientFactory::create(
            $this->settings->getProvider(),
        );
        $this->conversationRepo = new ConversationRepository();
        $toolProfile =
            $this->settings->getSettings()["tool_profile"] ?? "standard";
        $this->toolRegistry = new Registry($toolProfile);
        $this->toolRegistry->register(
            new \Levi\Agent\AI\Tools\SearchToolsTool($this->toolRegistry),
        );

        // 0.9.0: Initialize generic registry (parallel, for feature-flag toggle)
        $this->useGenericTools = !empty(
            $this->settings->getSettings()["use_generic_tools"]
        );
        if ($this->useGenericTools) {
            $this->genericRegistry = new GenericRegistry($toolProfile);
        }

        $allowDestructive = !empty(
            $this->settings->getSettings()["allow_destructive"]
        );
        $this->toolGuard = new ToolGuard(15, $allowDestructive);

        PIIRedactor::init($this->settings->getSettings());

        $this->requestHandler = new RequestHandler(
            $this->settings,
            $this->conversationRepo,
            $this->aiClient,
        );
        $this->sessionManager = new ChatSessionManager(
            $this->conversationRepo,
            $this->settings,
        );
        $getSystemPromptParts = function (
            string $query = "",
            ?string $sessionId = null,
            bool $includeUploadedContext = true,
            ?array $preClassification = null,
        ): array {
            return $this->getSystemPromptParts(
                $query,
                $sessionId,
                $includeUploadedContext,
                $preClassification,
            );
        };
        $this->messagePipeline = new MessagePipeline(
            $this->aiClient,
            $this->settings,
            $this->conversationRepo,
            $getSystemPromptParts,
        );
        $this->toolLoopEngine = new ToolLoopEngine(
            $this,
            $this->messagePipeline,
            $this->settings,
            $this->toolRegistry,
            $this->genericRegistry,
            $this->toolGuard,
            $this->conversationRepo,
            $this->aiClient,
        );

        add_action("rest_api_init", [$this, "register_routes"]);
        self::$instance = $this;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getToolRegistry(): Registry
    {
        return $this->toolRegistry;
    }

    /**
     * Get tool definitions using deferred loading.
     * Returns core tools + any tools discovered via search_tools.
     * When total tools <= 20, returns all (no benefit from deferring).
     *
     * 0.9.0: When use_generic_tools is enabled, returns the 12 generic tools
     * from GenericRegistry instead of the 44 specialized ones.
     */
    public function getToolDefs(): array
    {
        if ($this->useGenericTools && $this->genericRegistry !== null) {
            return $this->genericRegistry->getDefinitions();
        }

        return $this->toolRegistry->getCoreAndDiscoveredDefinitions(
            $this->discoveredToolNames,
        );
    }

    /**
     * Track tools discovered via search_tools (kept for search_tools compatibility).
     */
    public function addDiscoveredTools(array $toolNames): void
    {
        foreach ($toolNames as $name) {
            $this->discoveredToolNames[] = (string) $name;
        }
        $this->discoveredToolNames = array_values(
            array_unique($this->discoveredToolNames),
        );
    }

    /**
     * Get AI client for queries.
     * Uses the configured model for all queries.
     *
     * @return AIClientInterface
     */
    private function getAIClient(): AIClientInterface
    {
        return $this->aiClient;
    }

    public function register_routes(): void
    {
        $sharedArgs = [
            "message" => [
                "required" => true,
                "type" => "string",
                "sanitize_callback" => "sanitize_textarea_field",
            ],
            "session_id" => [
                "type" => ["string", "null"],
                "default" => null,
                "sanitize_callback" => function ($value) {
                    return $value ? sanitize_text_field($value) : null;
                },
            ],
            "replace_last" => [
                "type" => "boolean",
                "default" => false,
            ],
            "web_search" => [
                "type" => "boolean",
                "default" => false,
            ],
        ];

        register_rest_route($this->namespace, "/" . $this->rest_base, [
            [
                "methods" => WP_REST_Server::CREATABLE,
                "callback" => [$this, "sendMessage"],
                "permission_callback" => [
                    $this->requestHandler,
                    "checkPermission",
                ],
                "args" => $sharedArgs,
            ],
        ]);

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/stream",
            [
                [
                    "methods" => WP_REST_Server::CREATABLE,
                    "callback" => [$this, "sendMessageStream"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                    "args" => $sharedArgs,
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/upload",
            [
                [
                    "methods" => WP_REST_Server::CREATABLE,
                    "callback" => [$this, "uploadFiles"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/(?P<session_id>[a-zA-Z0-9_.-]+)/uploads",
            [
                [
                    "methods" => WP_REST_Server::READABLE,
                    "callback" => [$this, "getSessionUploadsMeta"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
                [
                    "methods" => WP_REST_Server::DELETABLE,
                    "callback" => [$this, "clearSessionUploadsEndpoint"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" .
                $this->rest_base .
                "/(?P<session_id>[a-zA-Z0-9_.-]+)/uploads/(?P<file_id>[a-zA-Z0-9_.-]+)",
            [
                [
                    "methods" => WP_REST_Server::DELETABLE,
                    "callback" => [$this, "deleteSessionUploadById"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/(?P<session_id>[a-zA-Z0-9_.-]+)/history",
            [
                [
                    "methods" => WP_REST_Server::READABLE,
                    "callback" => [$this->requestHandler, "getHistory"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/(?P<session_id>[a-zA-Z0-9_.-]+)",
            [
                [
                    "methods" => WP_REST_Server::DELETABLE,
                    "callback" => [$this, "deleteSession"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/test",
            [
                [
                    "methods" => WP_REST_Server::READABLE,
                    "callback" => [$this->requestHandler, "testConnection"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkAdminPermission",
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/sessions",
            [
                [
                    "methods" => WP_REST_Server::READABLE,
                    "callback" => [$this->requestHandler, "getUserSessions"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            "/" . $this->rest_base . "/status",
            [
                [
                    "methods" => WP_REST_Server::READABLE,
                    "callback" => [$this->requestHandler, "getStatus"],
                    "permission_callback" => [
                        $this->requestHandler,
                        "checkPermission",
                    ],
                ],
            ],
        );
    }

    public function sendMessage(WP_REST_Request $request): WP_REST_Response
    {
        $phpTimeLimit =
            (int) ($this->settings->getSettings()["php_time_limit"] ?? 300);
        if ($phpTimeLimit > 0 && function_exists("set_time_limit")) {
            @set_time_limit($phpTimeLimit);
        }
        ob_start();
        try {
            $response = $this->processMessage($request);
        } catch (\Throwable $e) {
            error_log(
                "Levi Agent Error: " .
                    $e->getMessage() .
                    "\n" .
                    $e->getTraceAsString(),
            );
            $response = new WP_REST_Response(
                [
                    "error" => "Internal error: " . $e->getMessage(),
                    "session_id" =>
                        $request->get_param("session_id") ??
                        $this->requestHandler->generateSessionId(),
                ],
                500,
            );
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        return $response;
    }

    public function sendMessageStream(WP_REST_Request $request): void
    {
        $phpTimeLimit =
            (int) ($this->settings->getSettings()["php_time_limit"] ?? 300);
        $this->requestHandler->prepareStreamResponse($phpTimeLimit);

        try {
            $this->processMessageStreaming($request);
        } catch (\Throwable $e) {
            error_log(
                "Levi Stream Error: " .
                    $e->getMessage() .
                    "\n" .
                    $e->getTraceAsString(),
            );
            $this->emitSSE("error", [
                "message" => "Internal error: " . $e->getMessage(),
                "session_id" => $request->get_param("session_id") ?? "",
            ]);
        }

        die();
    }

    public function emitSSE(string $type, array $data): void
    {
        // Safety: only emit SSE when the response is actually in event-stream mode.
        // handleToolCallsV2 (non-streaming) may call this, but we must not output
        // raw SSE data into a regular JSON REST response.
        $headers = headers_list();
        $isSSE = false;
        foreach ($headers as $header) {
            if (stripos($header, "Content-Type: text/event-stream") !== false) {
                $isSSE = true;
                break;
            }
        }
        if (!$isSSE) {
            return;
        }

        $data["type"] = $type;
        echo "data: " . wp_json_encode($data) . "\n\n";
        if (function_exists("ob_flush")) {
            @ob_flush();
        }
        flush();
    }

    private function processMessageStreaming(WP_REST_Request $request): void
    {
        $this->messagePipeline->setLastStreamedContent(null);

        $ctx = $this->sessionManager->prepareSessionContext($request);
        if (!$ctx["success"]) {
            $this->emitSSE("error", [
                "message" => $ctx["error"],
                "session_id" => $ctx["session_id"],
            ]);
            return;
        }

        if (!$this->aiClient->isConfigured()) {
            $this->emitSSE("error", [
                "message" =>
                    "AI not configured. Please set up provider credentials in Settings.",
                "session_id" => $ctx["session_id"],
            ]);
            return;
        }

        $this->emitSSE("activity_start", [
            "text" => "Nachricht empfangen...",
            "session_id" => $ctx["session_id"],
        ]);

        $sessionId = $ctx["session_id"];
        $userId = $ctx["user_id"];
        $message = $ctx["message"];
        $webSearch = $ctx["web_search"];

        $hasUploadedContext = !empty(
            $this->getSessionUploads($sessionId, $userId)
        );

        // ── Upfront classification ──────────────────────────────────────
        $this->emitSSE("activity_update", ["text" => "Kontext laden..."]);
        $classification = $this->classifyQuery((string) $message, $sessionId);
        $queryCategory = $classification["category"] ?? "COMPLEX";

        // ── SIMPLE fast-path: no tools, no memories, no gate ────────────
        if ($queryCategory === "SIMPLE" && !$hasUploadedContext) {
            error_log(
                "Levi: SIMPLE fast-path for: " .
                    mb_substr((string) $message, 0, 80),
            );
            $this->emitSSE("activity_update", ["text" => "Levi denkt nach..."]);
            $lightMessages = $this->messagePipeline->buildMessagesLight(
                $sessionId,
                (string) $message,
            );
            $streamResult = $this->messagePipeline->streamChatWithTracking(
                $lightMessages,
                [],
                function (string $type, array $data) {
                    $this->emitSSE($type, $data);
                },
                function (string $toolName, string $phase) {
                    return $this->getToolProgressLabel($toolName, $phase);
                },
                $this->usageAccumulator,
            );

            if (!is_wp_error($streamResult)) {
                $assistantMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($streamResult["content"] ?? ""),
                );
                if ($assistantMessage === "") {
                    $assistantMessage = $this->getEmptyResponseFallback();
                }
            } else {
                $assistantMessage = $this->getEmptyResponseFallback();
            }

            $this->sessionManager->saveAssistantMessage(
                $sessionId,
                $userId,
                $assistantMessage,
            );
            $this->emitSSE(
                "stream_end",
                trim($assistantMessage) !== "" ? ["preserve" => true] : [],
            );
            $this->emitSSE("activity_complete", []);
            $this->emitSSE("done", [
                "session_id" => $sessionId,
                "message" => $assistantMessage,
            ]);
            return;
        }

        // ── Normal path: CRUD / COMPLEX ─────────────────────────────────
        $this->discoveredToolNames = [];
        $messages = $this->messagePipeline->buildMessages(
            $sessionId,
            $message,
            true,
            $classification,
        );
        $tools = $this->getToolDefs();

        // Heartbeat callback for SSE keepalive during non-streaming API calls
        $heartbeat = function () {
            if (connection_aborted()) {
                return;
            }
            $this->emitSSE("heartbeat", []);
        };

        $this->emitSSE("activity_update", ["text" => "Levi denkt nach..."]);
        $streamResult = $this->messagePipeline->streamChatWithTracking(
            $messages,
            $tools,
            function (string $type, array $data) {
                $this->emitSSE($type, $data);
            },
            function (string $toolName, string $phase) {
                return $this->getToolProgressLabel($toolName, $phase);
            },
            $this->usageAccumulator,
        );

        if (!is_wp_error($streamResult)) {
            if (
                !empty($streamResult["has_tool_calls"]) &&
                !empty($streamResult["tool_calls"])
            ) {
                $hasVisibleText =
                    trim((string) ($streamResult["content"] ?? "")) !== "";
                $this->emitSSE(
                    "stream_end",
                    $hasVisibleText ? ["preserve" => true] : [],
                );
                $toolCallData = [
                    "role" => "assistant",
                    "content" => $streamResult["content"] ?? null,
                    "tool_calls" => $streamResult["tool_calls"],
                ];
                if (!empty($streamResult["reasoning_content"])) {
                    $toolCallData["reasoning_content"] =
                        $streamResult["reasoning_content"];
                }
                if ($this->useGenericTools) {
                    $this->toolLoopEngine->handleToolCallsStreamingV2(
                        $toolCallData,
                        $messages,
                        $sessionId,
                        $userId,
                        (string) $message,
                        $heartbeat,
                        $webSearch,
                    );
                } else {
                    $this->toolLoopEngine->handleToolCallsStreaming(
                        $toolCallData,
                        $messages,
                        $sessionId,
                        $userId,
                        (string) $message,
                        $heartbeat,
                        $webSearch,
                    );
                }
                if ($hasUploadedContext) {
                    $this->clearSessionUploads($sessionId, $userId);
                }
                return;
            }

            // Gate removed from initial response: if the model chose to respond with
            // text only (plan, question, greeting), that is a legitimate decision.
            // The mutation gate remains active inside the tool loop where it catches
            // actual "claimed but not executed" scenarios.

            $assistantMessage = $this->sanitizeAssistantMessageContent(
                (string) ($streamResult["content"] ?? ""),
            );

            if ($assistantMessage === "") {
                $assistantMessage = $this->getEmptyResponseFallback();
            }

            $truncated =
                ($streamResult["finish_reason"] ?? "stop") === "length";
            if ($truncated) {
                $assistantMessage = $this->appendTruncationHint(
                    $assistantMessage,
                );
            }

            $this->sessionManager->saveAssistantMessage(
                $sessionId,
                $userId,
                $assistantMessage,
            );

            if ($hasUploadedContext) {
                $this->clearSessionUploads($sessionId, $userId);
            }

            $this->emitSSE("activity_complete", []);
            $this->emitSSE("done", [
                "session_id" => $sessionId,
                "message" => $assistantMessage,
                "model" => $streamResult["model"] ?? null,
                "truncated" => $truncated,
                "usage" => $this->usageAccumulator,
            ]);
            $this->messagePipeline->flushUsage($sessionId, $userId);
            return;
        }

        // --- Fallback: streaming failed → non-streaming with full retry logic ---
        $this->emitSSE("stream_end", []);
        error_log(
            "Levi: streaming failed (" .
                $streamResult->get_error_code() .
                ": " .
                $streamResult->get_error_message() .
                "), falling back to non-streaming",
        );

        $response = $this->messagePipeline->chatWithTracking(
            $messages,
            $tools,
            $heartbeat,
            $webSearch,
        );

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $errMsgLower = mb_strtolower($errMsg);
            $isNoEndpointFailure = $this->isNoEndpointsError($errMsgLower);
            $isProviderFailure =
                str_contains($errMsgLower, "provider") ||
                str_contains($errMsgLower, "503");
            $isTimeoutFailure = $this->isTimeoutError($errMsgLower);

            if (
                !empty($tools) &&
                ($isNoEndpointFailure ||
                    ($isProviderFailure && !$this->isActionIntent($message)))
            ) {
                $this->emitSSE("activity_update", [
                    "text" => "Neuer Versuch ohne Tools...",
                ]);
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    [],
                    $heartbeat,
                    $webSearch,
                );
            } elseif ($isTimeoutFailure && $hasUploadedContext) {
                $this->emitSSE("activity_update", [
                    "text" => "Timeout, versuche mit weniger Kontext...",
                ]);
                $messages = $this->messagePipeline->buildMessages(
                    $sessionId,
                    $message,
                    false,
                );
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    $tools,
                    $heartbeat,
                    $webSearch,
                );
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->messagePipeline->chatWithTracking(
                        $messages,
                        [],
                        $heartbeat,
                        $webSearch,
                    );
                }
            } elseif ($isTimeoutFailure && !empty($tools)) {
                $this->emitSSE("activity_update", [
                    "text" => "Timeout, neuer Versuch...",
                ]);
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    [],
                    $heartbeat,
                    $webSearch,
                );
            }
        }

        if (is_wp_error($response)) {
            $overflowMsg = mb_strtolower($response->get_error_message());
            if (
                str_contains($overflowMsg, "context length") ||
                str_contains($overflowMsg, "too many tokens") ||
                str_contains($overflowMsg, "maximum context")
            ) {
                $this->emitSSE("activity_update", [
                    "text" => "Kontext wird gekuerzt...",
                ]);
                $halvedMessages = $this->halveHistory($messages);
                $response = $this->messagePipeline->chatWithTracking(
                    $halvedMessages,
                    $tools,
                    $heartbeat,
                    $webSearch,
                );
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->messagePipeline->chatWithTracking(
                        $halvedMessages,
                        [],
                        $heartbeat,
                        $webSearch,
                    );
                }
                if (!is_wp_error($response)) {
                    $messages = $halvedMessages;
                }
            }
        }

        if (is_wp_error($response)) {
            $this->emitSSE("error", [
                "message" => $response->get_error_message(),
                "session_id" => $sessionId,
            ]);
            return;
        }

        if ($this->isEmptyAiResponse($response)) {
            $originalContent =
                (string) ($response["choices"][0]["message"]["content"] ?? "");
            error_log(
                "Levi: empty AI response (attempt 1), original content: " .
                    mb_substr($originalContent, 0, 500),
            );
            for ($retryAttempt = 1; $retryAttempt <= 2; $retryAttempt++) {
                $this->emitSSE("activity_update", [
                    "text" =>
                        "Levi versucht es erneut... (Versuch " .
                        ($retryAttempt + 1) .
                        ")",
                ]);
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    $tools,
                    $heartbeat,
                    $webSearch,
                );
                if (is_wp_error($response)) {
                    $this->emitSSE("error", [
                        "message" => $response->get_error_message(),
                        "session_id" => $sessionId,
                    ]);
                    return;
                }
                if (!$this->isEmptyAiResponse($response)) {
                    break;
                }
                error_log(
                    "Levi: empty AI response (attempt " .
                        ($retryAttempt + 1) .
                        ")",
                );
            }
        }

        $messageData = $response["choices"][0]["message"] ?? [];

        if (!empty($messageData["tool_calls"])) {
            $this->emitSSE("activity_start", [
                "text" => "Aufgabe wird ausgeführt...",
            ]);
            if ($this->useGenericTools) {
                $this->toolLoopEngine->handleToolCallsStreamingV2(
                    $messageData,
                    $messages,
                    $sessionId,
                    $userId,
                    (string) $message,
                    $heartbeat,
                    $webSearch,
                );
            } else {
                $this->toolLoopEngine->handleToolCallsStreaming(
                    $messageData,
                    $messages,
                    $sessionId,
                    $userId,
                    (string) $message,
                    $heartbeat,
                    $webSearch,
                );
            }
            if ($hasUploadedContext) {
                $this->clearSessionUploads($sessionId, $userId);
            }
            return;
        }

        $assistantMessage = $this->sanitizeAssistantMessageContent(
            (string) ($messageData["content"] ?? ""),
        );
        if ($assistantMessage === "") {
            $assistantMessage = $this->getEmptyResponseFallback();
        }
        if ($this->wasResponseTruncated($response)) {
            $assistantMessage = $this->appendTruncationHint($assistantMessage);
        }

        $this->sessionManager->saveAssistantMessage(
            $sessionId,
            $userId,
            $assistantMessage,
        );

        if ($hasUploadedContext) {
            $this->clearSessionUploads($sessionId, $userId);
        }

        $this->emitSSE("activity_complete", []);
        $this->emitSSE("done", [
            "session_id" => $sessionId,
            "message" => $assistantMessage,
            "model" => $response["model"] ?? null,
            "truncated" => $this->wasResponseTruncated($response),
            "usage" => $this->usageAccumulator,
        ]);
        $this->messagePipeline->flushUsage($sessionId, $userId);
    }

    private function processMessage(WP_REST_Request $request): WP_REST_Response
    {
        $ctx = $this->sessionManager->prepareSessionContext($request);
        if (!$ctx["success"]) {
            return new WP_REST_Response(
                [
                    "error" => $ctx["error"],
                    "session_id" => $ctx["session_id"],
                ],
                $ctx["status"] ?? 500,
            );
        }

        if (!$this->aiClient->isConfigured()) {
            return new WP_REST_Response(
                [
                    "error" =>
                        "AI not configured. Please set up provider credentials in Settings.",
                    "session_id" => $ctx["session_id"],
                ],
                503,
            );
        }

        $sessionId = $ctx["session_id"];
        $userId = $ctx["user_id"];
        $message = $ctx["message"];
        $webSearch = $ctx["web_search"];

        $hasUploadedContext = !empty(
            $this->getSessionUploads($sessionId, $userId)
        );

        // ── Upfront classification ──────────────────────────────────────
        $classification = $this->classifyQuery((string) $message, $sessionId);
        $queryCategory = $classification["category"] ?? "COMPLEX";

        // ── SIMPLE fast-path (non-streaming) ────────────────────────────
        if ($queryCategory === "SIMPLE" && !$hasUploadedContext) {
            error_log(
                "Levi: SIMPLE fast-path (non-streaming) for: " .
                    mb_substr((string) $message, 0, 80),
            );
            $lightMessages = $this->messagePipeline->buildMessagesLight(
                $sessionId,
                (string) $message,
            );
            $response = $this->messagePipeline->chatWithTracking(
                $lightMessages,
                [],
            );

            $assistantMessage = "";
            if (!is_wp_error($response)) {
                $assistantMessage = $this->sanitizeAssistantMessageContent(
                    (string) ($response["choices"][0]["message"]["content"] ??
                        ""),
                );
            }
            if ($assistantMessage === "") {
                $assistantMessage = $this->getEmptyResponseFallback();
            }

            $this->sessionManager->saveAssistantMessage(
                $sessionId,
                $userId,
                $assistantMessage,
            );

            return new WP_REST_Response([
                "message" => $assistantMessage,
                "session_id" => $sessionId,
            ]);
        }

        // ── Normal path: CRUD / COMPLEX ─────────────────────────────────
        $this->discoveredToolNames = [];
        $messages = $this->messagePipeline->buildMessages(
            $sessionId,
            $message,
            true,
            $classification,
        );
        $tools = $this->getToolDefs();

        // Call AI – try with tools first, fallback to no tools on provider error
        $response = $this->messagePipeline->chatWithTracking(
            $messages,
            $tools,
            null,
            $webSearch,
        );

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            $errMsgLower = mb_strtolower($errMsg);
            $isNoEndpointFailure = $this->isNoEndpointsError($errMsgLower);
            $isProviderFailure =
                str_contains($errMsgLower, "provider") ||
                str_contains($errMsgLower, "503");
            $isTimeoutFailure = $this->isTimeoutError($errMsgLower);

            // For endpoint availability issues, always retry once without tools
            // (also for action intents), because some free endpoints reject tool mode.
            if (
                !empty($tools) &&
                ($isNoEndpointFailure ||
                    ($isProviderFailure && !$this->isActionIntent($message)))
            ) {
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    [],
                    null,
                    $webSearch,
                );
            } elseif ($isTimeoutFailure && $hasUploadedContext) {
                // Retry once with same history but without uploaded file context.
                $messages = $this->messagePipeline->buildMessages(
                    $sessionId,
                    $message,
                    false,
                );
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    $tools,
                    null,
                    $webSearch,
                );
                if (is_wp_error($response) && !empty($tools)) {
                    // Last retry for timeout path: disable tools to reduce payload/latency.
                    $response = $this->messagePipeline->chatWithTracking(
                        $messages,
                        [],
                        null,
                        $webSearch,
                    );
                }
            } elseif ($isTimeoutFailure && !empty($tools)) {
                // Retry once without tools for slow/loaded endpoints.
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    [],
                    null,
                    $webSearch,
                );
            }
        }

        // Context overflow auto-recovery: halve the history and retry once
        if (is_wp_error($response)) {
            $overflowMsg = mb_strtolower($response->get_error_message());
            if (
                str_contains($overflowMsg, "context length") ||
                str_contains($overflowMsg, "too many tokens") ||
                str_contains($overflowMsg, "maximum context")
            ) {
                error_log(
                    "Levi: context overflow detected, retrying with halved history",
                );
                $halvedMessages = $this->halveHistory($messages);
                $response = $this->messagePipeline->chatWithTracking(
                    $halvedMessages,
                    $tools,
                    null,
                    $webSearch,
                );
                if (is_wp_error($response) && !empty($tools)) {
                    $response = $this->messagePipeline->chatWithTracking(
                        $halvedMessages,
                        [],
                        null,
                        $webSearch,
                    );
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
            $upstreamStatus = is_array($errData)
                ? (int) ($errData["status"] ?? 0)
                : 0;

            if (
                $upstreamStatus === 429 ||
                str_contains($errMsgLower, "rate-limit") ||
                str_contains($errMsgLower, "rate limit")
            ) {
                $statusCode = 429;
            } elseif ($this->isNoEndpointsError($errMsgLower)) {
                $statusCode = 503;
            } elseif ($this->isTimeoutError($errMsgLower)) {
                $statusCode = 504;
            } else {
                $statusCode = $upstreamStatus >= 400 ? $upstreamStatus : 500;
            }

            if ($statusCode === 429) {
                $errMsg =
                    "Das KI-Modell ist gerade überlastet (Rate Limit). Bitte warte einen Moment und versuche es erneut.";
            } elseif ($statusCode === 503) {
                $provider = $this->settings->getProvider();
                $model = $this->settings->getModelForProvider($provider);
                $errMsg = sprintf(
                    "Für das aktuell gewählte Modell sind gerade keine verfügbaren Endpoints vorhanden (%s). Bitte wechsle auf ein anderes Modell oder versuche es später erneut.",
                    $model,
                );
            } elseif ($statusCode === 504) {
                $errMsg =
                    "Die Anfrage hat beim AI-Provider zu lange gedauert (Timeout). Bitte Anfrage kürzen oder Upload-Inhalt reduzieren und erneut versuchen.";
            }
            return new WP_REST_Response(
                [
                    "error" => $errMsg,
                    "session_id" => $sessionId,
                ],
                $statusCode,
            );
        }

        // Auto-retry on empty AI response (up to 2 attempts)
        if ($this->isEmptyAiResponse($response)) {
            $originalContent =
                (string) ($response["choices"][0]["message"]["content"] ?? "");
            error_log(
                "Levi: empty AI response (classic, attempt 1), original content: " .
                    mb_substr($originalContent, 0, 500),
            );

            for ($retryAttempt = 1; $retryAttempt <= 2; $retryAttempt++) {
                $response = $this->messagePipeline->chatWithTracking(
                    $messages,
                    $tools,
                    null,
                    $webSearch,
                );
                if (is_wp_error($response)) {
                    break;
                }
                if (!$this->isEmptyAiResponse($response)) {
                    break;
                }
                error_log(
                    "Levi: empty AI response (classic, attempt " .
                        ($retryAttempt + 1) .
                        ")",
                );
            }
        }

        if (is_wp_error($response)) {
            return new WP_REST_Response(
                [
                    "error" => $response->get_error_message(),
                    "session_id" => $sessionId,
                ],
                500,
            );
        }

        // Check if AI wants to use a tool
        $messageData = $response["choices"][0]["message"] ?? [];

        if (
            isset($messageData["tool_calls"]) &&
            !empty($messageData["tool_calls"])
        ) {
            if ($this->useGenericTools) {
                $toolResponse = $this->toolLoopEngine->handleToolCallsV2(
                    $messageData,
                    $messages,
                    $sessionId,
                    $userId,
                    (string) $message,
                    $webSearch,
                );
            } else {
                $toolResponse = $this->toolLoopEngine->handleToolCalls(
                    $messageData,
                    $messages,
                    $sessionId,
                    $userId,
                    (string) $message,
                    $webSearch,
                );
            }
            if ($hasUploadedContext) {
                $this->clearSessionUploads($sessionId, $userId);
            }
            return $toolResponse;
        }

        // Normal response (no tools)
        $assistantMessage = $this->sanitizeAssistantMessageContent(
            (string) ($messageData["content"] ?? ""),
        );

        if ($assistantMessage === "") {
            $assistantMessage = $this->getEmptyResponseFallback();
        }

        if ($this->wasResponseTruncated($response)) {
            $assistantMessage = $this->appendTruncationHint($assistantMessage);
        }

        $this->sessionManager->saveAssistantMessage(
            $sessionId,
            $userId,
            $assistantMessage,
        );

        if ($hasUploadedContext) {
            $this->clearSessionUploads($sessionId, $userId);
        }

        $usage = $this->usageAccumulator;
        $this->messagePipeline->flushUsage($sessionId, $userId);
        return new WP_REST_Response(
            [
                "session_id" => $sessionId,
                "message" => $assistantMessage,
                "model" => $response["model"] ?? null,
                "truncated" => $this->wasResponseTruncated($response),
                "usage" => $usage,
                "timestamp" => current_time("mysql"),
            ],
            200,
        );
    }

    public function chatWithTracking(
        array $messages,
        array $tools = [],
        ?callable $heartbeat = null,
        bool $webSearch = false,
        ?string $toolChoice = null,
    ): array|WP_Error {
        return $this->messagePipeline->chatWithTracking(
            $messages,
            $tools,
            $heartbeat,
            $webSearch,
            $toolChoice,
            $this->usageAccumulator,
        );
    }

    public function streamContinuation(
        array $messages,
        array $tools = [],
        bool $webSearch = false,
    ): array|WP_Error {
        $emitSse = function (string $type, array $data) {
            $this->emitSSE($type, $data);
        };
        $getToolProgressLabel = function (string $toolName, string $phase) {
            return $this->getToolProgressLabel($toolName, $phase);
        };
        return $this->messagePipeline->streamContinuation(
            $messages,
            $tools,
            $webSearch,
            $emitSse,
            $getToolProgressLabel,
            $this->usageAccumulator,
            $this->lastStreamedContent,
        );
    }

    public function flushUsage(string $sessionId, int $userId): void
    {
        $this->messagePipeline->flushUsage(
            $sessionId,
            $userId,
            $this->usageAccumulator,
        );
    }

    public function getUsageAccumulator(): array
    {
        return $this->usageAccumulator;
    }

    private function isActionIntent(string $text): bool
    {
        $t = mb_strtolower($text);
        $patterns = [
            "/\b(erstell|anleg|schreib|änder|bearbeit|update|install|aktivier|deaktivier|lösch|entfern|switch|veröffentl|publish)\b/u",
            "/\b(plugin|seite|post|beitrag|datei|theme|benutzer|user|option|einstellung)\b/u",
        ];
        $score = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $t) === 1) {
                $score++;
            }
        }
        return $score >= 1;
    }

    public function isNoEndpointsError(string $errMsgLower): bool
    {
        return str_contains($errMsgLower, "no endpoints found") ||
            str_contains($errMsgLower, "no endpoint found");
    }

    public function isTimeoutError(string $errMsgLower): bool
    {
        return str_contains($errMsgLower, "curl error 28") ||
            str_contains($errMsgLower, "operation timed out") ||
            str_contains($errMsgLower, "timed out");
    }

    private function requiresExhaustiveReadIntent(string $text): bool
    {
        $t = mb_strtolower($text);
        return preg_match(
            "/\b(alle|gesamt|komplett|vollständig|sämtlich|alles lesen|komplett lesen|gesamten inhalt)\b/u",
            $t,
        ) === 1;
    }

    public function sanitizeAssistantMessageContent(string $text): string
    {
        $clean = $text;
        // Strip leaked tool protocol tokens from some provider responses.
        $clean =
            preg_replace(
                '/<\|tool_calls_section_begin\|>[\s\S]*$/u',
                "",
                $clean,
            ) ?? $clean;
        $clean = preg_replace("/<\|[^|>]+?\|>/u", "", $clean) ?? $clean;
        $clean =
            preg_replace(
                '/(?:^|\R)\s*functions\.[a-z0-9_]+\s*:\s*\d+[\s\S]*$/iu',
                "",
                $clean,
            ) ?? $clean;
        $clean = trim((string) preg_replace("/\R{3,}/u", "\n\n", $clean));
        return $clean;
    }

    private function isEmptyAiResponse(array $response): bool
    {
        $content =
            (string) ($response["choices"][0]["message"]["content"] ?? "");
        $sanitized = $this->sanitizeAssistantMessageContent($content);
        $hasToolCalls = !empty(
            $response["choices"][0]["message"]["tool_calls"] ?? []
        );
        return $sanitized === "" && !$hasToolCalls;
    }

    public function getEmptyResponseFallback(): string
    {
        return 'Ich bin leider nicht ganz fertig geworden. Schreib einfach „mach weiter" und ich mach mich wieder an die Aufgabe.';
    }

    public function recoverStreamedContentOrFallback(array $toolResults): string
    {
        $lastStreamed = $this->messagePipeline->getLastStreamedContent();
        if ($lastStreamed !== null && $lastStreamed !== "") {
            error_log(
                "Levi: recovering previously streamed content (" .
                    strlen($lastStreamed) .
                    " chars) instead of fallback",
            );
            return $this->sanitizeAssistantMessageContent($lastStreamed);
        }
        return $this->buildToolLoopFallbackMessage($toolResults);
    }

    public function buildToolLoopFallbackMessage(array $toolResults): string
    {
        $successful = array_values(
            array_filter(
                $toolResults,
                fn($r) => ($r["result"]["success"] ?? false) === true,
            ),
        );
        if (empty($successful)) {
            return $this->getEmptyResponseFallback();
        }

        $recentSuccessful = array_slice($successful, -4);
        $lines = [];
        foreach ($recentSuccessful as $row) {
            $tool = (string) ($row["tool"] ?? "tool");
            $result = is_array($row["result"] ?? null) ? $row["result"] : [];
            $lines[] =
                "- " . $tool . ": " . $this->summarizeToolResult($result);
        }

        return "Erledigt! ✅\n\nIch habe die Schritte ausgefuehrt, aber konnte keinen sauberen KI-Abschlusstext erzeugen. " .
            "Hier ist der technische Stand:\n" .
            implode("\n", $lines);
    }

    private function isCreationTool(string $toolName, array $args): bool
    {
        if (
            in_array(
                $toolName,
                ["create_post", "create_page", "create_plugin", "create_theme"],
                true,
            )
        ) {
            return true;
        }

        if ($toolName === "install_plugin") {
            $action = $args["action"] ?? "";
            return $action !== "update_outdated" && $action !== "update";
        }

        if ($toolName === "manage_user") {
            return ($args["action"] ?? "") === "create";
        }

        return false;
    }

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

    public function applyResponseSafetyGates(
        string $finalMessage,
        array $toolResults,
        array $taskIntent,
    ): string {
        if (empty($toolResults)) {
            return $finalMessage;
        }

        // Deduplicate: keep LAST result per args_key (tool + primary argument).
        $lastByKey = [];
        foreach ($toolResults as $r) {
            $key = $r["args_key"] ?? ($r["tool"] ?? "");
            if ($key !== "") {
                $lastByKey[$key] = $r;
            }
        }
        $deduplicated = array_values($lastByKey);

        $successful = array_filter(
            $deduplicated,
            fn($r) => ($r["result"]["success"] ?? false) === true,
        );
        $failed = array_filter(
            $deduplicated,
            fn($r) => ($r["result"]["success"] ?? false) !== true &&
                empty($r["result"]["needs_confirmation"]),
        );

        // Self-healing: if a tool failed once but later succeeded (same tool name,
        // different args_key), the failure is considered resolved.
        $successfulToolNames = array_unique(
            array_map(fn($r) => (string) ($r["tool"] ?? ""), $successful),
        );
        $unresolvedFailed = array_filter($failed, function ($r) use (
            $successfulToolNames,
        ) {
            return !in_array(
                (string) ($r["tool"] ?? ""),
                $successfulToolNames,
                true,
            );
        });

        // Read-only tool failures are harmless when the AI recovered and ran
        // more tools successfully afterwards -- drop them from unresolved.
        if (!empty($successful) && !empty($unresolvedFailed)) {
            $lastSuccessSeq = max(
                array_map(fn($r) => (int) ($r["seq"] ?? 0), $successful),
            );
            $unresolvedFailed = array_filter($unresolvedFailed, function (
                $r,
            ) use ($lastSuccessSeq) {
                $toolName = (string) ($r["tool"] ?? "");
                $seq = (int) ($r["seq"] ?? 0);
                if (
                    in_array($toolName, self::$readOnlyTools, true) &&
                    $seq < $lastSuccessSeq
                ) {
                    return false;
                }
                return true;
            });
        }

        // All failures resolved -- AI response is fine as-is.
        if (empty($unresolvedFailed)) {
            return $this->appendCreationHintIfNeeded(
                $finalMessage,
                $successful,
                $taskIntent,
            );
        }

        // No successful tools at all -- append warning to AI response.
        if (empty($successful)) {
            return $finalMessage .
                "\n\nHinweis: Ich hatte Probleme bei der Ausfuehrung. Soll ich es nochmal versuchen?";
        }

        // Mixed: some succeeded, some unresolved failures -- append short notice.
        return $finalMessage .
            "\n\nIch hatte kurz Probleme bei einem Teilschritt, aber es sollte soweit alles passen :)";
    }

    private function appendCreationHintIfNeeded(
        string $finalMessage,
        array $successful,
        array $taskIntent,
    ): string {
        if (
            !in_array(
                $taskIntent["mode"] ?? "unknown",
                ["modify_existing", "probable_modify"],
                true,
            )
        ) {
            return $finalMessage;
        }
        $createdNew = array_filter(
            $successful,
            fn($r) => $this->isCreationTool(
                (string) ($r["tool"] ?? ""),
                is_array($r["result"] ?? null) ? $r["result"] : [],
            ),
        );
        if (!empty($createdNew) && empty($taskIntent["explicit_create"])) {
            return $finalMessage .
                "\n\nHinweis: Ich habe dabei etwas neu erstellt. Wenn du stattdessen nur das Bestehende ändern willst, sage kurz Bescheid, dann passe ich nur das vorhandene Artefakt an.";
        }
        return $finalMessage;
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

    public function deleteSession(WP_REST_Request $request): WP_REST_Response
    {
        $sessionId = (string) $request->get_param("session_id");
        if ($sessionId === "") {
            return new WP_REST_Response(
                ["success" => false, "error" => "Missing session_id"],
                400,
            );
        }

        $fullHistory = $this->sessionManager->getHistory($sessionId, 500);
        $currentUserId = get_current_user_id();
        $isAdmin = current_user_can("manage_options");
        $ownerId = !empty($fullHistory)
            ? (int) ($fullHistory[0]["user_id"] ?? 0)
            : 0;

        if (!$isAdmin) {
            if (empty($fullHistory)) {
                $this->clearSessionUploads($sessionId, $currentUserId);
                return new WP_REST_Response(
                    ["success" => true, "session_id" => $sessionId],
                    200,
                );
            }
            if ($ownerId !== $currentUserId) {
                return new WP_REST_Response(
                    [
                        "success" => false,
                        "error" => "Not allowed to delete this session",
                    ],
                    403,
                );
            }
        }

        if (count($fullHistory) >= 6) {
            $learningOwner =
                $isAdmin && $ownerId > 0 ? $ownerId : $currentUserId;
            set_transient(
                "levi_learnings_pending_" . $sessionId,
                ["history" => $fullHistory, "user_id" => $learningOwner],
                HOUR_IN_SECONDS,
            );
            wp_schedule_single_event(time(), "levi_extract_session_learnings", [
                $sessionId,
            ]);
            spawn_cron();
        }

        $this->sessionManager->deleteSession($sessionId);
        if ($isAdmin && $ownerId > 0 && $ownerId !== $currentUserId) {
            $this->clearSessionUploads($sessionId, $ownerId);
        } else {
            $this->clearSessionUploads($sessionId, $currentUserId);
        }

        return new WP_REST_Response(
            [
                "success" => true,
                "session_id" => $sessionId,
            ],
            200,
        );
    }

    public function uploadFiles(WP_REST_Request $request): WP_REST_Response
    {
        $sessionId = (string) ($request->get_param("session_id") ?? "");
        if ($sessionId === "") {
            $sessionId = $this->requestHandler->generateSessionId();
        }

        $userId = get_current_user_id();
        $access = $this->requestHandler->assertSessionAccess(
            $sessionId,
            $userId,
        );
        if ($access !== true) {
            return $access;
        }

        $files = $request->get_file_params();
        if (empty($files)) {
            return new WP_REST_Response(
                [
                    "error" => "No files uploaded.",
                    "session_id" => $sessionId,
                ],
                400,
            );
        }

        $normalizedFiles = $this->normalizeUploadedFiles($files);
        if (empty($normalizedFiles)) {
            return new WP_REST_Response(
                [
                    "error" => "No valid file payload found.",
                    "session_id" => $sessionId,
                ],
                400,
            );
        }

        $stored = $this->getSessionUploads($sessionId, $userId);
        $uploaded = [];
        $errors = [];

        foreach ($normalizedFiles as $file) {
            $single = $this->processUploadedFile($file);
            if (($single["success"] ?? false) !== true) {
                $errors[] = $single["error"] ?? "Upload failed.";
                continue;
            }

            $entry = $single["file"];
            $stored[] = $entry;
            if (count($stored) > 5) {
                $stored = array_slice($stored, -5);
            }
            $uploaded[] = [
                "id" => $entry["id"],
                "name" => $entry["name"],
                "size" => $entry["size"],
                "type" => $entry["type"],
                "preview" => $entry["preview"],
            ];
        }

        $this->setSessionUploads($sessionId, $userId, $stored);

        return new WP_REST_Response(
            [
                "success" => !empty($uploaded),
                "session_id" => $sessionId,
                "files" => $uploaded,
                "session_files" => $this->filesToMeta($stored),
                "errors" => $errors,
            ],
            !empty($uploaded) ? 200 : 400,
        );
    }

    public function getSessionUploadsMeta(
        WP_REST_Request $request,
    ): WP_REST_Response {
        $sessionId = (string) ($request->get_param("session_id") ?? "");
        if ($sessionId === "") {
            return new WP_REST_Response(["error" => "Missing session_id"], 400);
        }

        $userId = get_current_user_id();
        $access = $this->requestHandler->assertSessionAccess(
            $sessionId,
            $userId,
        );
        if ($access !== true) {
            return $access;
        }

        $files = $this->getSessionUploads($sessionId, $userId);
        return new WP_REST_Response(
            [
                "success" => true,
                "session_id" => $sessionId,
                "files" => $this->filesToMeta($files),
            ],
            200,
        );
    }

    public function clearSessionUploadsEndpoint(
        WP_REST_Request $request,
    ): WP_REST_Response {
        $sessionId = (string) ($request->get_param("session_id") ?? "");
        if ($sessionId === "") {
            return new WP_REST_Response(["error" => "Missing session_id"], 400);
        }

        $userId = get_current_user_id();
        $access = $this->requestHandler->assertSessionAccess(
            $sessionId,
            $userId,
        );
        if ($access !== true) {
            return $access;
        }

        $this->clearSessionUploads($sessionId, $userId);
        return new WP_REST_Response(
            [
                "success" => true,
                "session_id" => $sessionId,
                "files" => [],
            ],
            200,
        );
    }

    public function deleteSessionUploadById(
        WP_REST_Request $request,
    ): WP_REST_Response {
        $sessionId = (string) ($request->get_param("session_id") ?? "");
        $fileId = (string) ($request->get_param("file_id") ?? "");
        if ($sessionId === "" || $fileId === "") {
            return new WP_REST_Response(
                ["error" => "Missing session_id or file_id"],
                400,
            );
        }

        $userId = get_current_user_id();
        $access = $this->requestHandler->assertSessionAccess(
            $sessionId,
            $userId,
        );
        if ($access !== true) {
            return $access;
        }

        $files = $this->getSessionUploads($sessionId, $userId);
        $before = count($files);
        $files = array_values(
            array_filter($files, function ($f) use ($fileId) {
                return (string) ($f["id"] ?? "") !== $fileId;
            }),
        );
        if (count($files) === $before) {
            return new WP_REST_Response(
                [
                    "error" => "File not found in session.",
                    "session_id" => $sessionId,
                ],
                404,
            );
        }

        $this->setSessionUploads($sessionId, $userId, $files);
        return new WP_REST_Response(
            [
                "success" => true,
                "session_id" => $sessionId,
                "files" => $this->filesToMeta($files),
            ],
            200,
        );
    }

    public function wasResponseTruncated(array $apiResponse): bool
    {
        return ($apiResponse["choices"][0]["finish_reason"] ?? "") === "length";
    }

    public function appendTruncationHint(string $message): string
    {
        return $message .
            "\n\n---\n*Meine Antwort wurde aufgrund des Token-Limits abgeschnitten. Schreibe \"mach weiter\", damit ich fortfahre.*";
    }
}
