<?php

namespace Levi\Agent\API;

use Levi\Agent\AI\AIClientInterface;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Database\ConversationRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * HTTP-Layer, Routing und Auth für den Chat-REST-API-Endpunkt.
 *
 * Kapselt alle HTTP-spezifischen Concerns: Permission-Checks,
 * Rate-Limiting, Session-Access-Control, SSE-Stream-Setup und
 * das Parsen/Validieren von Requests.
 */
class RequestHandler
{
    private SettingsPage $settings;
    private AIClientInterface $aiClient;
    private ChatSessionManager $sessionManager;

    public function __construct(
        SettingsPage $settings,
        ConversationRepository $conversationRepo,
        AIClientInterface $aiClient,
    ) {
        $this->settings = $settings;
        $this->aiClient = $aiClient;
        $this->sessionManager = new ChatSessionManager(
            $conversationRepo,
            $settings,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Auth & Permissions                                                */
    /* ------------------------------------------------------------------ */

    public function checkPermission(): bool
    {
        return current_user_can("edit_posts");
    }

    public function checkAdminPermission(): bool
    {
        return current_user_can("manage_options");
    }

    public function checkRateLimit(int $userId): bool
    {
        $settings = $this->settings->getSettings();
        $maxRequests = $settings["rate_limit"] ?? 50;
        if ((int) $maxRequests <= 0) {
            return true;
        }

        $transientKey = "levi_rate_" . $userId;
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

    public function assertSessionAccess(
        string $sessionId,
        int $userId,
    ): bool|WP_REST_Response {
        $ownerId = $this->sessionManager->getSessionOwnerId($sessionId);
        if (
            $ownerId !== null &&
            $ownerId !== $userId &&
            !current_user_can("manage_options")
        ) {
            return new WP_REST_Response(
                [
                    "error" => "Session not found or access denied.",
                    "session_id" => $sessionId,
                ],
                403,
            );
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Utilities                                                         */
    /* ------------------------------------------------------------------ */

    public function generateSessionId(): string
    {
        return "sess_" . wp_generate_uuid4();
    }

    /**
     * Prepare the HTTP response for an SSE stream.
     * Disables output buffering, sets appropriate headers and time limit.
     */
    public function prepareStreamResponse(int $phpTimeLimit = 300): void
    {
        if ($phpTimeLimit > 0 && function_exists("set_time_limit")) {
            @set_time_limit($phpTimeLimit);
        }
        ignore_user_abort(false);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header("Content-Type: text/event-stream");
        header("Cache-Control: no-cache");
        header("Connection: keep-alive");
        header("X-Accel-Buffering: no");
    }

    /* ------------------------------------------------------------------ */
    /*  Thin REST Endpoints                                               */
    /* ------------------------------------------------------------------ */

    public function getStatus(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . "levi_conversations";
        $tableExists =
            $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) ===
            $table;
        return new WP_REST_Response(
            [
                "tables_ok" => $tableExists,
                "ai_configured" => $this->aiClient->isConfigured(),
                "user_id" => get_current_user_id(),
            ],
            200,
        );
    }

    public function getHistory(WP_REST_Request $request): WP_REST_Response
    {
        $sessionId = $request->get_param("session_id");
        $currentUserId = get_current_user_id();

        $ownerId = $this->sessionManager->getSessionOwnerId($sessionId);
        if (
            $ownerId !== null &&
            $ownerId !== $currentUserId &&
            !current_user_can("manage_options")
        ) {
            return new WP_REST_Response(
                [
                    "error" => "Session not found or access denied.",
                    "session_id" => $sessionId,
                ],
                403,
            );
        }

        $messages = $this->sessionManager->getHistory($sessionId, 500);

        return new WP_REST_Response(
            [
                "session_id" => $sessionId,
                "messages" => $messages,
            ],
            200,
        );
    }

    public function getUserSessions(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $sessions = $this->sessionManager->getUserSessions($userId);

        return new WP_REST_Response(
            [
                "sessions" => $sessions,
            ],
            200,
        );
    }

    public function testConnection(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->aiClient->testConnection();

        if (is_wp_error($result)) {
            return new WP_REST_Response(
                [
                    "success" => false,
                    "error" => $result->get_error_message(),
                ],
                200,
            );
        }

        return new WP_REST_Response($result, 200);
    }
}
