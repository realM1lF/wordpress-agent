<?php

namespace Levi\Agent\API;

use Levi\Agent\AI\OpenRouterClient;
use Levi\Agent\Database\ConversationRepository;
use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Agent\Identity;
use Levi\Agent\Memory\VectorStore;
use Levi\Agent\AI\Tools\Registry;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ChatController extends WP_REST_Controller {
    protected $namespace = 'levi-agent/v1';
    protected $rest_base = 'chat';
    private OpenRouterClient $aiClient;
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
        
        $this->aiClient = new OpenRouterClient();
        $this->conversationRepo = new ConversationRepository();
        $this->settings = new SettingsPage();
        $this->toolRegistry = new Registry();
        
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'sendMessage'],
                'permission_callback' => [$this, 'checkPermission'],
                'args' => [
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
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<session_id>[a-zA-Z0-9_-]+)/history', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getHistory'],
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

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function checkAdminPermission(): bool {
        return current_user_can('manage_options');
    }

    public function checkRateLimit(int $userId): bool {
        $settings = $this->settings->getSettings();
        $maxRequests = $settings['rate_limit'] ?? 50;
        
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
        try {
            return $this->processMessage($request);
        } catch (\Throwable $e) {
            error_log('Levi Agent Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new WP_REST_Response([
                'error' => 'Internal error: ' . $e->getMessage(),
                'session_id' => $request->get_param('session_id') ?? uniqid('sess_', true),
            ], 500);
        }
    }
    
    private function processMessage(WP_REST_Request $request): WP_REST_Response {
        $message = $request->get_param('message');
        $sessionId = $request->get_param('session_id') ?? uniqid('sess_', true);
        $userId = get_current_user_id();

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
                'error' => 'AI not configured. Please set up your OpenRouter API key in Settings.',
                'session_id' => $sessionId,
            ], 503);
        }

        // Save user message (wrapped in try-catch for DB errors)
        try {
            $this->conversationRepo->saveMessage($sessionId, $userId, 'user', $message);
        } catch (\Exception $e) {
            error_log('Levi DB Error: ' . $e->getMessage());
            // Continue without saving - don't break the chat
        }

        // Build conversation history
        $messages = $this->buildMessages($sessionId, $message);

        // Get available tools
        $tools = $this->toolRegistry->getDefinitions();

        // Call AI – try with tools first, fallback to no tools on provider error
        $response = $this->aiClient->chat($messages, $tools);

        if (is_wp_error($response)) {
            $errMsg = $response->get_error_message();
            // Retry without tools on provider/routing errors (often tool-related)
            if (!empty($tools) && (str_contains($errMsg, 'Provider') || str_contains($errMsg, 'provider') || str_contains($errMsg, '503'))) {
                $response = $this->aiClient->chat($messages, []);
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
            // AI wants to use tool(s)
            return $this->handleToolCalls($messageData, $messages, $sessionId, $userId);
        }

        // Normal response (no tools)
        $assistantMessage = $messageData['content'] ?? 'Sorry, I could not generate a response.';

        // Save assistant message (wrapped in try-catch)
        try {
            $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $assistantMessage);
        } catch (\Exception $e) {
            error_log('Levi DB Error: ' . $e->getMessage());
            // Continue without saving
        }

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $assistantMessage,
            'model' => $response['model'] ?? null,
            'timestamp' => current_time('mysql'),
        ], 200);
    }

    /**
     * Handle tool calls from AI
     */
    private function handleToolCalls(array $messageData, array $messages, string $sessionId, int $userId): WP_REST_Response {
        $toolCalls = $messageData['tool_calls'];
        
        // Add AI's tool call request to messages
        $messages[] = $messageData;

        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'] ?? '';
            $functionArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true);
            $toolCallId = $toolCall['id'] ?? '';

            // Execute tool
            $result = $this->toolRegistry->execute($functionName, $functionArgs);
            $toolResults[] = [
                'tool' => $functionName,
                'result' => $result,
            ];

            // Add tool result to conversation
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => json_encode($result),
            ];
        }

        // Get final response from AI with tool results
        $finalResponse = $this->aiClient->chat($messages);

        if (is_wp_error($finalResponse)) {
            return new WP_REST_Response([
                'error' => $finalResponse->get_error_message(),
                'session_id' => $sessionId,
            ], 500);
        }

        $finalMessage = $finalResponse['choices'][0]['message']['content'] ?? 'Sorry, I could not process the results.';

        // Save assistant message
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $finalMessage,
            'model' => $finalResponse['model'] ?? null,
            'tools_used' => array_map(fn($r) => $r['tool'], $toolResults),
            'timestamp' => current_time('mysql'),
        ], 200);
    }

    private function buildMessages(string $sessionId, string $newMessage): array {
        $messages = [];

        // System message with relevant memories
        $messages[] = [
            'role' => 'system',
            'content' => $this->getSystemPrompt($newMessage),
        ];

        // History (last 20 messages for context)
        $history = $this->conversationRepo->getHistory($sessionId, 20);
        foreach ($history as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }

        // Current message
        $messages[] = [
            'role' => 'user',
            'content' => $newMessage,
        ];

        return $messages;
    }

    private function getSystemPrompt(string $query = ''): string {
        try {
            $identity = new Identity();
            $basePrompt = $identity->getSystemPrompt();
        } catch (\Throwable $e) {
            error_log('Levi Identity Error: ' . $e->getMessage());
            $basePrompt = "You are Levi, a helpful AI assistant for WordPress.";
        }
        
        // Add relevant memories if query provided (optional - don't fail chat if memory fails)
        if (!empty($query)) {
            try {
                $relevantMemories = $this->getRelevantMemories($query);
                if (!empty($relevantMemories)) {
                    $basePrompt .= "\n\n# Relevant Context\n\n" . $relevantMemories;
                }
            } catch (\Throwable $e) {
                error_log('Levi Memory Error: ' . $e->getMessage());
            }
        }
        
        return $basePrompt;
    }
    
    private function getRelevantMemories(string $query): string {
        try {
            $vectorStore = new VectorStore();
        } catch (\Throwable $e) {
            error_log('Levi VectorStore init: ' . $e->getMessage());
            return '';
        }
        
        $queryEmbedding = $vectorStore->generateEmbedding($query);
        if (is_wp_error($queryEmbedding) || empty($queryEmbedding)) {
            return '';
        }
        
        $memories = [];
        try {
            $identityResults = $vectorStore->searchSimilar($queryEmbedding, 'identity', 3, 0.7);
            $referenceResults = $vectorStore->searchSimilar($queryEmbedding, 'reference', 3, 0.7);
            $userId = get_current_user_id();
            $episodicResults = $vectorStore->searchEpisodicMemories($queryEmbedding, $userId, 2, 0.75);
            
            if (!empty($identityResults)) {
                $memories[] = "## Identity Knowledge\n" . implode("\n", array_map(fn($r) => $r['content'], $identityResults));
            }
            if (!empty($referenceResults)) {
                $memories[] = "## Reference Knowledge\n" . implode("\n", array_map(fn($r) => $r['content'], $referenceResults));
            }
            if (!empty($episodicResults)) {
                $memories[] = "## Learned Preferences\n" . implode("\n", array_map(fn($r) => $r['fact'], $episodicResults));
            }
        } catch (\Throwable $e) {
            error_log('Levi Memory search: ' . $e->getMessage());
        }
        
        return implode("\n\n", $memories);
    }

    public function getHistory(WP_REST_Request $request): WP_REST_Response {
        $sessionId = $request->get_param('session_id');
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
}
