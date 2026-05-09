<?php

namespace Levi\Agent\API;

use Levi\Agent\AI\AIClientInterface;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Database\ConversationRepository;
use WP_Error;

/**
 * MessagePipeline — Build → Send → Parse → Stream
 *
 * Kapselt die komplette Nachrichten-Pipeline:
 * - Kontextaufbau (buildMessages, buildMessagesLight)
 * - Blocking Chat + Usage-Tracking (chatWithTracking)
 * - Streaming Chat + Usage-Tracking (streamChatWithTracking)
 * - Stream-Fortsetzung nach Tool-Ausfuehrung (streamContinuation)
 * - Usage-Akkumulation und Flush
 */
class MessagePipeline
{
    use Concerns\ManagesContext;
    use Concerns\ManagesUploads;

    private AIClientInterface $aiClient;
    private SettingsPage $settings;
    private ConversationRepository $conversationRepo;

    /** @var callable */
    private $getSystemPromptParts;

    public function __construct(
        AIClientInterface $aiClient,
        SettingsPage $settings,
        ConversationRepository $conversationRepo,
        callable $getSystemPromptParts,
    ) {
        $this->aiClient = $aiClient;
        $this->settings = $settings;
        $this->conversationRepo = $conversationRepo;
        $this->getSystemPromptParts = $getSystemPromptParts;
    }

    /**
     * Build full message array with system prompts, history, memories and uploads.
     */
    public function buildMessages(
        string $sessionId,
        string $newMessage,
        bool $includeUploadedContext = true,
        ?array $preClassification = null,
    ): array {
        $messages = [];

        [$stablePrompt, $dynamicPrompt] = ($this->getSystemPromptParts)(
            $newMessage,
            $sessionId,
            $includeUploadedContext,
            $preClassification,
        );
        $messages[] = [
            "role" => "system",
            "content" => $stablePrompt,
        ];
        if ($dynamicPrompt !== "") {
            $messages[] = [
                "role" => "system",
                "content" => $dynamicPrompt,
            ];
        }

        $runtimeSettings = $this->settings->getSettings();
        $historyLimit = max(
            10,
            (int) ($runtimeSettings["history_context_limit"] ?? 20),
        );
        $history = $this->conversationRepo->getHistory(
            $sessionId,
            $historyLimit,
        );

        $summary = $this->conversationRepo->getLatestSummary($sessionId);
        if ($summary !== null) {
            $messages[] = [
                "role" => "system",
                "content" =>
                    "[SESSION-ZUSAMMENFASSUNG – aeltere Nachrichten komprimiert]\n\n" .
                    $summary["content"],
            ];
        }

        $lastChatRole = null;
        foreach ($history as $msg) {
            if (!in_array($msg["role"], ["user", "assistant"], true)) {
                continue;
            }
            if ($msg["role"] === $lastChatRole) {
                if ($lastChatRole === "user") {
                    $messages[] = [
                        "role" => "assistant",
                        "content" => "[Vorherige Antwort nicht verfuegbar]",
                    ];
                } else {
                    $messages[] = [
                        "role" => "user",
                        "content" => "(Fortsetzen)",
                    ];
                }
            }
            $messages[] = [
                "role" => $msg["role"],
                "content" => $msg["content"],
            ];
            $lastChatRole = $msg["role"];
        }

        if ($lastChatRole === "user") {
            $messages[] = [
                "role" => "assistant",
                "content" => "[Vorherige Antwort nicht verfuegbar]",
            ];
        }

        $userId = get_current_user_id();
        $sessionImages = $includeUploadedContext
            ? $this->getSessionImages($sessionId, $userId)
            : [];

        if (!empty($sessionImages)) {
            $contentParts = [["type" => "text", "text" => $newMessage]];
            foreach ($sessionImages as $img) {
                $contentParts[] = [
                    "type" => "image_url",
                    "image_url" => ["url" => $img["base64"]],
                ];
            }
            $messages[] = ["role" => "user", "content" => $contentParts];
        } else {
            $messages[] = ["role" => "user", "content" => $newMessage];
        }

        return $this->trimMessagesToBudget($messages, $sessionId);
    }

    /**
     * Lightweight message builder for conversational messages (no tools needed).
     * Uses minimal system prompt + limited history. Saves ~90% tokens.
     */
    public function buildMessagesLight(
        string $sessionId,
        string $newMessage,
    ): array {
        $messages = [];

        $messages[] = [
            "role" => "system",
            "content" => $this->getMinimalSystemPrompt(),
        ];

        $history = $this->conversationRepo->getHistory($sessionId, 6);
        $lastChatRole = null;
        foreach ($history as $msg) {
            if (!in_array($msg["role"], ["user", "assistant"], true)) {
                continue;
            }
            if ($msg["role"] === $lastChatRole && $lastChatRole === "user") {
                $messages[] = [
                    "role" => "assistant",
                    "content" => "[Vorherige Antwort nicht verfuegbar]",
                ];
            }
            $messages[] = [
                "role" => $msg["role"],
                "content" => $msg["content"],
            ];
            $lastChatRole = $msg["role"];
        }

        if ($lastChatRole === "user") {
            $messages[] = [
                "role" => "assistant",
                "content" => "[Vorherige Antwort nicht verfuegbar]",
            ];
        }

        $messages[] = ["role" => "user", "content" => $newMessage];

        return $messages;
    }

    /**
     * Get minimal system prompt for simple queries.
     */
    private function getMinimalSystemPrompt(): string
    {
        return <<<'PROMPT'
        Du bist Levi, ein KI-Assistent direkt in WordPress. Freundlich, per Du, mindestens 1 Emoji pro Antwort.

        ## Regeln
        - Tool-Ergebnisse = einzige Wahrheit. NUR zeigen was das Tool zurückgibt, nie ergänzen/halluzinieren.
        - Alle Einträge mit exakten IDs/Titeln zeigen, nie Platzhalter.
        - Neue Inhalte als Draft. Destruktive Aktionen: Direkt ausführen, Backend zeigt Button.
        - Globale WP-Einstellungen nie eigenmächtig ändern.
        PROMPT;
    }

    /**
     * Blocking chat with automatic usage accumulation.
     */
    public function chatWithTracking(
        array $messages,
        array $tools = [],
        ?callable $heartbeat = null,
        bool $webSearch = false,
        ?string $toolChoice = null,
        array &$usageAccumulator,
    ): array|WP_Error {
        if ($toolChoice !== null) {
            $this->aiClient->setToolChoice($toolChoice);
        }
        if ($this->aiClient instanceof \Levi\Agent\AI\OpenRouterClient) {
            $response = $this->aiClient->chat(
                $messages,
                $tools,
                $heartbeat,
                $webSearch,
            );
        } else {
            $response = $this->aiClient->chat($messages, $tools);
        }
        if (!is_wp_error($response)) {
            $this->accumulateUsage($response, $usageAccumulator);
        }
        return $response;
    }

    /**
     * Stream a continuation response after tool execution, with graduated fallback.
     */
    public function streamContinuation(
        array $messages,
        array $tools = [],
        bool $webSearch = false,
        callable $emitSse,
        callable $getToolProgressLabel,
        array &$usageAccumulator,
        ?string &$lastStreamedContent = null,
    ): array|WP_Error {
        if (empty($tools)) {
            $emitSse("stream_start", []);
        }

        $streamedContent = "";
        $onChunk = function (string $chunk, string $type = "content") use (
            &$streamedContent,
            $emitSse,
            $getToolProgressLabel,
        ) {
            if ($type === "reasoning_start") {
                $emitSse("activity_update", [
                    "text" => "Levi denkt nach...",
                ]);
                return;
            }
            if ($type === "tool_call_start") {
                $info = json_decode($chunk, true);
                if (is_array($info) && !empty($info["tool"])) {
                    $toolName = $info["tool"];
                    $emitSse("activity_tool", [
                        "message" => $getToolProgressLabel($toolName, "start"),
                        "tool" => $toolName,
                        "phase" => "preview",
                    ]);
                }
                return;
            }
            $streamedContent .= $chunk;
            $emitSse("delta", ["content" => $chunk]);
        };

        $result = $this->aiClient->streamChat($messages, $onChunk, $tools);

        // Track substantial streamed content for fallback recovery
        if (mb_strlen($streamedContent) > 50) {
            $lastStreamedContent = $streamedContent;
        }

        if (!is_wp_error($result)) {
            $hasToolCalls = !empty($result["tool_calls"]);
            $emitSse(
                "stream_end",
                $hasToolCalls && trim($streamedContent) !== ""
                    ? ["preserve" => true]
                    : [],
            );
            $this->accumulateStreamUsage($result, $usageAccumulator);
            return $this->streamResultToResponse($result);
        }

        // --- Streaming failed ---

        if ($streamedContent !== "") {
            $emitSse("stream_end", []);
            error_log(
                "Levi: stream partially completed (" .
                    strlen($streamedContent) .
                    " chars shown before error)",
            );
            $usageAccumulator["api_calls"]++;
            return $this->streamResultToResponse([
                "content" => $streamedContent,
                "finish_reason" => "stop",
                "tool_calls" => [],
            ]);
        }

        $errMsg = mb_strtolower($result->get_error_message());
        $isTransient =
            str_contains($errMsg, "timeout") ||
            str_contains($errMsg, "curl") ||
            str_contains($errMsg, "502") ||
            str_contains($errMsg, "503");

        if (!$isTransient) {
            $emitSse("stream_end", []);
            return $result;
        }

        // --- Graduated retry: strip tools for speed, but guard against hallucination ---

        error_log(
            "Levi: stream continuation failed (" .
                $result->get_error_message() .
                "), retrying without tools + honesty guard",
        );
        $emitSse("activity_update", [
            "text" => "Levi versucht es erneut...",
        ]);

        $guardedMessages = $messages;
        $guardedMessages[] = [
            "role" => "system",
            "content" =>
                "[SYSTEM] Tools sind voruebergehend nicht verfuegbar. " .
                "Fasse NUR zusammen, was tatsaechlich erledigt wurde – also nur Aktionen, " .
                "fuer die ein erfolgreiches Tool-Ergebnis in dieser Konversation vorliegt. " .
                "Falls noch Schritte offen sind, sage dem Nutzer ehrlich, welche Aktionen " .
                "du nicht ausfuehren konntest, und bitte ihn, es erneut zu versuchen. " .
                "Erfinde KEINE Ergebnisse, IDs oder Links.",
        ];

        $retryResult = $this->aiClient->streamChat(
            $guardedMessages,
            $onChunk,
            [],
        );

        if (!is_wp_error($retryResult)) {
            $emitSse(
                "stream_end",
                trim($streamedContent) !== "" ? ["preserve" => true] : [],
            );
            $this->accumulateStreamUsage($retryResult, $usageAccumulator);
            return $this->streamResultToResponse($retryResult);
        }

        if ($streamedContent !== "") {
            $emitSse(
                "stream_end",
                trim($streamedContent) !== "" ? ["preserve" => true] : [],
            );
            error_log(
                "Levi: stream retry partially completed (" .
                    strlen($streamedContent) .
                    " chars shown)",
            );
            $usageAccumulator["api_calls"]++;
            return $this->streamResultToResponse([
                "content" => $streamedContent,
                "finish_reason" => "stop",
                "tool_calls" => [],
            ]);
        }

        // --- Last resort: blocking call without tools + same honesty guard ---

        error_log(
            "Levi: stream retry also failed, blocking fallback without tools",
        );
        $heartbeat = fn() => $emitSse("heartbeat", []);
        $fallback = $this->chatWithTracking(
            $guardedMessages,
            [],
            $heartbeat,
            $webSearch,
            null,
            $usageAccumulator,
        );
        $emitSse("stream_end", []);

        return $fallback;
    }

    public function accumulateStreamUsage(
        array $streamResult,
        array &$usageAccumulator,
    ): void {
        if (!empty($streamResult["usage"])) {
            $usage = $streamResult["usage"];
            $usageAccumulator["prompt_tokens"] +=
                (int) ($usage["prompt_tokens"] ?? 0);
            $usageAccumulator["completion_tokens"] +=
                (int) ($usage["completion_tokens"] ?? 0);
            $usageAccumulator["cached_tokens"] +=
                (int) ($usage["prompt_tokens_details"]["cached_tokens"] ??
                    ($usage["cache_read_input_tokens"] ?? 0));
            if ($usageAccumulator["model"] === null) {
                $usageAccumulator["model"] = $streamResult["model"] ?? null;
            }
        }
        $usageAccumulator["api_calls"]++;
    }

    public function streamResultToResponse(array $streamResult): array
    {
        $message = [
            "role" => "assistant",
            "content" => $streamResult["content"] ?? "",
            "tool_calls" => $streamResult["tool_calls"] ?? [],
        ];

        if (!empty($streamResult["reasoning_content"])) {
            $message["reasoning_content"] = $streamResult["reasoning_content"];
        }

        return [
            "choices" => [
                [
                    "message" => $message,
                    "finish_reason" => $streamResult["finish_reason"] ?? "stop",
                ],
            ],
            "model" => $streamResult["model"] ?? null,
            "usage" => $streamResult["usage"] ?? [],
        ];
    }

    /**
     * Stream a chat response, emitting SSE delta events for each text chunk.
     */
    public function streamChatWithTracking(
        array $messages,
        array $tools = [],
        callable $emitSse,
        callable $getToolProgressLabel,
        array &$usageAccumulator,
    ): array|WP_Error {
        $result = $this->aiClient->streamChat(
            $messages,
            function (string $chunk, string $type = "content") use (
                $emitSse,
                $getToolProgressLabel,
            ) {
                if ($type === "reasoning_start") {
                    $emitSse("activity_update", [
                        "text" => "Levi denkt nach...",
                    ]);
                    return;
                }
                if ($type === "tool_call_start") {
                    $info = json_decode($chunk, true);
                    if (is_array($info) && !empty($info["tool"])) {
                        $toolName = $info["tool"];
                        $emitSse("activity_tool", [
                            "message" => $getToolProgressLabel(
                                $toolName,
                                "start",
                            ),
                            "tool" => $toolName,
                            "phase" => "preview",
                        ]);
                    }
                    return;
                }
                $emitSse("delta", ["content" => $chunk]);
            },
            $tools,
        );

        if (is_wp_error($result)) {
            return $result;
        }

        if (!empty($result["usage"])) {
            $usage = $result["usage"];
            $usageAccumulator["prompt_tokens"] +=
                (int) ($usage["prompt_tokens"] ?? 0);
            $usageAccumulator["completion_tokens"] +=
                (int) ($usage["completion_tokens"] ?? 0);
            $usageAccumulator["cached_tokens"] +=
                (int) ($usage["prompt_tokens_details"]["cached_tokens"] ??
                    ($usage["cache_read_input_tokens"] ?? 0));
            $usageAccumulator["api_calls"]++;
            if ($usageAccumulator["model"] === null) {
                $usageAccumulator["model"] = $result["model"] ?? null;
            }
        } else {
            $usageAccumulator["api_calls"]++;
        }

        return $result;
    }

    public function accumulateUsage(
        array $response,
        array &$usageAccumulator,
    ): void {
        $usage = $response["usage"] ?? [];
        $usageAccumulator["prompt_tokens"] +=
            (int) ($usage["prompt_tokens"] ?? 0);
        $usageAccumulator["completion_tokens"] +=
            (int) ($usage["completion_tokens"] ?? 0);
        $usageAccumulator["cached_tokens"] +=
            (int) ($usage["prompt_tokens_details"]["cached_tokens"] ??
                ($usage["cache_read_input_tokens"] ?? 0));
        $usageAccumulator["api_calls"]++;
        if ($usageAccumulator["model"] === null) {
            $usageAccumulator["model"] = $response["model"] ?? null;
        }
    }

    public function flushUsage(
        string $sessionId,
        int $userId,
        array &$usageAccumulator,
    ): void {
        if ($usageAccumulator["api_calls"] === 0) {
            return;
        }
        global $wpdb;
        $wpdb->insert($wpdb->prefix . "levi_usage_log", [
            "session_id" => $sessionId,
            "user_id" => $userId > 0 ? $userId : null,
            "prompt_tokens" => $usageAccumulator["prompt_tokens"],
            "completion_tokens" => $usageAccumulator["completion_tokens"],
            "cached_tokens" => $usageAccumulator["cached_tokens"],
            "api_calls" => $usageAccumulator["api_calls"],
            "model" => $usageAccumulator["model"],
        ]);
        $usageAccumulator = [
            "prompt_tokens" => 0,
            "completion_tokens" => 0,
            "cached_tokens" => 0,
            "api_calls" => 0,
            "model" => null,
        ];
    }
}
