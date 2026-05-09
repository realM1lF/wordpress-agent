<?php

namespace Levi\Agent\API;

use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Database\ConversationRepository;
use WP_REST_Request;

/**
 * Session CRUD, History und Zusammenfassungen.
 *
 * Kapselt alle Datenbank-Operationen rund um Chat-Sessions
 * und entkoppelt den ChatController vom ConversationRepository.
 */
class ChatSessionManager
{
    private ConversationRepository $conversationRepo;
    private SettingsPage $settings;

    public function __construct(
        ConversationRepository $conversationRepo,
        SettingsPage $settings,
    ) {
        $this->conversationRepo = $conversationRepo;
        $this->settings = $settings;
    }

    /**
     * Prepare session context from an incoming request.
     *
     * Validates ownership, rate-limit, saves the user message and returns
     * all session data needed by the controller.
     *
     * @return array{
     *     success: bool,
     *     session_id: string,
     *     user_id: int,
     *     message: string,
     *     web_search: bool,
     *     has_uploads: bool,
     *     error?: string,
     *     status?: int,
     * }
     */
    public function prepareSessionContext(WP_REST_Request $request): array
    {
        $message = $request->get_param("message");
        $sessionId =
            $request->get_param("session_id") ?? "sess_" . wp_generate_uuid4();
        $userId = get_current_user_id();

        // Session ownership check
        if ($sessionId !== null) {
            $ownerId = $this->conversationRepo->getSessionOwnerId($sessionId);
            if (
                $ownerId !== null &&
                $ownerId !== $userId &&
                !current_user_can("manage_options")
            ) {
                return [
                    "success" => false,
                    "error" => "Session not found or access denied.",
                    "session_id" => $sessionId,
                    "status" => 403,
                ];
            }
        }

        // Rate limit check
        $settings = $this->settings->getSettings();
        $maxRequests = $settings["rate_limit"] ?? 50;
        if ((int) $maxRequests > 0) {
            $transientKey = "levi_rate_" . $userId;
            $requests = get_transient($transientKey);
            if ($requests !== false && $requests >= $maxRequests) {
                return [
                    "success" => false,
                    "error" =>
                        "Rate limit exceeded. Please try again later.",
                    "session_id" => $sessionId,
                    "status" => 429,
                ];
            }
            set_transient(
                $transientKey,
                $requests === false ? 1 : $requests + 1,
                HOUR_IN_SECONDS,
            );
        }

        $webSearch =
            (bool) $request->get_param("web_search") &&
            $this->settings->isWebSearchEnabled();

        $replaceLast = (bool) $request->get_param("replace_last");
        if ($replaceLast) {
            try {
                $this->conversationRepo->deleteLastUserAssistantPair(
                    $sessionId,
                );
            } catch (\Exception $e) {
                error_log(
                    "Levi DB Error (replace_last): " . $e->getMessage(),
                );
            }
        }

        try {
            $this->conversationRepo->saveMessage(
                $sessionId,
                $userId,
                "user",
                $message,
            );
        } catch (\Exception $e) {
            error_log("Levi DB Error: " . $e->getMessage());
        }

        return [
            "success" => true,
            "session_id" => $sessionId,
            "user_id" => $userId,
            "message" => $message,
            "web_search" => $webSearch,
        ];
    }

    public function saveUserMessage(
        string $sessionId,
        int $userId,
        string $message,
    ): void {
        try {
            $this->conversationRepo->saveMessage(
                $sessionId,
                $userId,
                "user",
                $message,
            );
        } catch (\Exception $e) {
            error_log("Levi DB Error: " . $e->getMessage());
        }
    }

    public function saveAssistantMessage(
        string $sessionId,
        int $userId,
        string $message,
    ): void {
        try {
            $this->conversationRepo->saveMessage(
                $sessionId,
                $userId,
                "assistant",
                $message,
            );
        } catch (\Exception $e) {
            error_log("Levi DB Error: " . $e->getMessage());
        }
    }

    public function deleteLastPair(string $sessionId): void
    {
        try {
            $this->conversationRepo->deleteLastUserAssistantPair($sessionId);
        } catch (\Exception $e) {
            error_log("Levi DB Error (replace_last): " . $e->getMessage());
        }
    }

    public function getSessionOwnerId(string $sessionId): ?int
    {
        return $this->conversationRepo->getSessionOwnerId($sessionId);
    }

    public function getHistory(string $sessionId, int $limit = 500): array
    {
        return $this->conversationRepo->getHistory($sessionId, $limit);
    }

    public function getUserSessions(int $userId): array
    {
        return $this->conversationRepo->getUserSessions($userId);
    }

    public function deleteSession(string $sessionId): void
    {
        $this->conversationRepo->deleteSession($sessionId);
    }
}
