<?php

namespace Mohami\Agent\API;

use Mohami\Agent\AI\OpenRouterClient;
use Mohami\Agent\Database\ConversationRepository;
use Mohami\Agent\Admin\SettingsPage;
use Mohami\Agent\Agent\Identity;
use Mohami\Agent\Memory\VectorStore;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ChatController extends WP_REST_Controller {
    protected $namespace = 'mohami-agent/v1';
    protected $rest_base = 'chat';
    private OpenRouterClient $aiClient;
    private ConversationRepository $conversationRepo;
    private SettingsPage $settings;

    public function __construct() {
        $this->aiClient = new OpenRouterClient();
        $this->conversationRepo = new ConversationRepository();
        $this->settings = new SettingsPage();
        
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void {
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
                        'type' => 'string',
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
        
        $transientKey = 'mohami_rate_' . $userId;
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

        // Save user message
        $this->conversationRepo->saveMessage($sessionId, $userId, 'user', $message);

        // Build conversation history
        $messages = $this->buildMessages($sessionId, $message);

        // Get response from AI
        $response = $this->aiClient->chat($messages);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'error' => $response->get_error_message(),
                'session_id' => $sessionId,
            ], 500);
        }

        // Extract assistant message
        $assistantMessage = $response['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';

        // Save assistant message
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $assistantMessage);

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $assistantMessage,
            'model' => $response['model'] ?? null,
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
        $identity = new Identity();
        $basePrompt = $identity->getSystemPrompt();
        
        // Add relevant memories if query provided
        if (!empty($query)) {
            $relevantMemories = $this->getRelevantMemories($query);
            if (!empty($relevantMemories)) {
                $basePrompt .= "\n\n# Relevant Context\n\n" . $relevantMemories;
            }
        }
        
        return $basePrompt;
    }
    
    private function getRelevantMemories(string $query): string {
        $vectorStore = new VectorStore();
        
        // Generate embedding for query
        $queryEmbedding = $vectorStore->generateEmbedding($query);
        if (is_wp_error($queryEmbedding) || empty($queryEmbedding)) {
            return '';
        }
        
        // Search identity memories
        $identityResults = $vectorStore->searchSimilar($queryEmbedding, 'identity', 3, 0.7);
        
        // Search reference memories
        $referenceResults = $vectorStore->searchSimilar($queryEmbedding, 'reference', 3, 0.7);
        
        // Search episodic memories for this user
        $userId = get_current_user_id();
        $episodicResults = $vectorStore->searchEpisodicMemories($queryEmbedding, $userId, 2, 0.75);
        
        $memories = [];
        
        if (!empty($identityResults)) {
            $memories[] = "## Identity Knowledge\n" . implode("\n", array_map(fn($r) => $r['content'], $identityResults));
        }
        
        if (!empty($referenceResults)) {
            $memories[] = "## Reference Knowledge\n" . implode("\n", array_map(fn($r) => $r['content'], $referenceResults));
        }
        
        if (!empty($episodicResults)) {
            $memories[] = "## Learned Preferences\n" . implode("\n", array_map(fn($r) => $r['fact'], $episodicResults));
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
