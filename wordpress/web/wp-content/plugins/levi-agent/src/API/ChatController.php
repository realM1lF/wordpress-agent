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
        try {
            return $this->processMessage($request);
        } catch (\Throwable $e) {
            error_log('Levi Agent Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new WP_REST_Response([
                'error' => 'Internal error: ' . $e->getMessage(),
                'session_id' => $request->get_param('session_id') ?? $this->generateSessionId(),
            ], 500);
        }
    }
    
    private function processMessage(WP_REST_Request $request): WP_REST_Response {
        $message = $request->get_param('message');
        $sessionId = $request->get_param('session_id') ?? $this->generateSessionId();
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
            $isProviderFailure = str_contains($errMsg, 'Provider') || str_contains($errMsg, 'provider') || str_contains($errMsg, '503');
            if (!empty($tools) && $isProviderFailure && !$this->isActionIntent($message)) {
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
            return $this->handleToolCalls($messageData, $messages, $sessionId, $userId, (string) $message);
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
    private function handleToolCalls(array $messageData, array $messages, string $sessionId, int $userId, string $latestUserMessage): WP_REST_Response {
        $toolResults = [];
        $executionTrace = [];
        $runtimeSettings = $this->settings->getSettings();
        $maxIterations = max(1, (int) ($runtimeSettings['max_tool_iterations'] ?? 12));
        $requireConfirmation = !empty($runtimeSettings['require_confirmation_destructive']);
        $taskIntent = $this->inferTaskIntent($latestUserMessage, $messages);
        $iteration = 0;
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
                $functionName = $toolCall['function']['name'] ?? '';
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
                    $result = [
                        'success' => false,
                        'needs_confirmation' => true,
                        'error' => 'Für diese Aktion brauche ich eine explizite Bestätigung von dir.',
                        'tool' => $functionName,
                    ];
                } else {
                    $result = $this->executeToolWithAutopaging($functionName, $functionArgs, $latestUserMessage);
                }
                $toolResults[] = [
                    'tool' => $functionName,
                    'result' => $result,
                ];

                $executionTrace[] = [
                    'iteration' => $iteration,
                    'step' => count($executionTrace) + 1,
                    'tool' => $functionName,
                    'status' => ($result['success'] ?? false) ? 'completed' : 'failed',
                    'timestamp' => current_time('mysql'),
                    'summary' => $this->summarizeToolResult($result),
                ];

                // Add tool result to conversation
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => json_encode($result),
                ];
            }

            // Ask model again with tool outputs (include tool definitions for further tool rounds)
            $nextResponse = $this->aiClient->chat($messages, $this->toolRegistry->getDefinitions());
            if (is_wp_error($nextResponse)) {
                return new WP_REST_Response([
                    'error' => $nextResponse->get_error_message(),
                    'session_id' => $sessionId,
                    'execution_trace' => $executionTrace,
                ], 500);
            }

            $messageData = $nextResponse['choices'][0]['message'] ?? [];
            if (empty($messageData['tool_calls'])) {
                $finalMessage = $messageData['content'] ?? 'Sorry, I could not process the results.';
                $finalMessage = $this->applyResponseSafetyGates($finalMessage, $toolResults, $taskIntent);
                $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

                return new WP_REST_Response([
                    'session_id' => $sessionId,
                    'message' => $finalMessage,
                    'model' => $nextResponse['model'] ?? null,
                    'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
                    'execution_trace' => $executionTrace,
                    'timestamp' => current_time('mysql'),
                ], 200);
            }
        }

        $finalMessage = 'Ich habe mehrere Teilaufgaben ausgeführt, aber brauche eine kurze Bestätigung zum nächsten Schritt.';
        $this->conversationRepo->saveMessage($sessionId, $userId, 'assistant', $finalMessage);

        return new WP_REST_Response([
            'session_id' => $sessionId,
            'message' => $finalMessage,
            'tools_used' => array_values(array_unique(array_map(fn($r) => $r['tool'], $toolResults))),
            'execution_trace' => $executionTrace,
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

    private function isDestructiveTool(string $toolName): bool {
        return in_array($toolName, [
            'delete_post',
            'switch_theme',
            'update_any_option',
            'manage_user',
            'install_plugin',
            'delete_plugin_file',
        ], true);
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
            $runtimeSettings = $this->settings->getSettings();
            $identityK = max(1, (int) ($runtimeSettings['memory_identity_k'] ?? 5));
            $referenceK = max(1, (int) ($runtimeSettings['memory_reference_k'] ?? 5));
            $episodicK = max(1, (int) ($runtimeSettings['memory_episodic_k'] ?? 4));
            $similarity = (float) ($runtimeSettings['memory_min_similarity'] ?? 0.6);

            $identityResults = $vectorStore->searchSimilar($queryEmbedding, 'identity', $identityK, $similarity);
            $referenceResults = $vectorStore->searchSimilar($queryEmbedding, 'reference', $referenceK, $similarity);
            $userId = get_current_user_id();
            $episodicResults = $vectorStore->searchEpisodicMemories($queryEmbedding, $userId, $episodicK, $similarity);
            
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

    private function requiresExhaustiveReadIntent(string $text): bool {
        $t = mb_strtolower($text);
        return preg_match('/\b(alle|gesamt|komplett|vollständig|sämtlich|rechtschreibung|review|prüf|analysier)\b/u', $t) === 1;
    }

    private function normalizeToolArgumentsForIntent(string $toolName, array $args, string $latestUserMessage): array {
        if (!in_array($toolName, ['get_pages', 'get_posts'], true)) {
            return $args;
        }

        $settings = $this->settings->getSettings();
        $forceExhaustive = !empty($settings['force_exhaustive_reads']) || $this->requiresExhaustiveReadIntent($latestUserMessage);
        if (!$forceExhaustive) {
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

        $settings = $this->settings->getSettings();
        $forceExhaustive = !empty($settings['force_exhaustive_reads']) || $this->requiresExhaustiveReadIntent($latestUserMessage);
        if (!$forceExhaustive || empty($firstResult['has_more'])) {
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
        if (in_array($toolName, ['create_post', 'create_page', 'create_plugin', 'install_plugin'], true)) {
            return true;
        }

        if ($toolName === 'manage_user') {
            return ($args['action'] ?? '') === 'create';
        }

        return false;
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

    private function applyResponseSafetyGates(string $finalMessage, array $toolResults, array $taskIntent): string {
        $successful = array_values(array_filter($toolResults, fn($r) => ($r['result']['success'] ?? false) === true));
        $failed = array_values(array_filter($toolResults, fn($r) => ($r['result']['success'] ?? false) !== true));
        $claimsDone = preg_match('/\b(fertig|abgeschlossen|komplett|erledigt|installiert und aktiviert|ist jetzt)\b/ui', $finalMessage) === 1;

        if ($claimsDone && empty($successful)) {
            return 'Ich kann den Abschluss noch nicht sicher bestätigen, weil keine erfolgreiche Ausführung vorliegt. Soll ich es erneut versuchen oder zuerst den aktuellen Stand prüfen?';
        }

        if ($claimsDone && !empty($failed)) {
            return $finalMessage . "\n\nHinweis: Mindestens ein Teilschritt ist fehlgeschlagen. Bitte prüfe den Zwischenstand, bevor wir final abschließen.";
        }

        if (in_array($taskIntent['mode'] ?? 'unknown', ['modify_existing', 'probable_modify'], true)) {
            $createdNew = array_filter($successful, fn($r) => $this->isCreationTool((string) ($r['tool'] ?? ''), is_array($r['result'] ?? null) ? $r['result'] : []));
            if (!empty($createdNew) && empty($taskIntent['explicit_create'])) {
                return $finalMessage . "\n\nHinweis: Ich habe dabei etwas neu erstellt. Wenn du stattdessen nur das Bestehende ändern willst, sage kurz Bescheid, dann passe ich nur das vorhandene Artefakt an.";
            }
        }

        return $finalMessage;
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

    public function deleteSession(WP_REST_Request $request): WP_REST_Response {
        $sessionId = (string) $request->get_param('session_id');
        if ($sessionId === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'Missing session_id'], 400);
        }

        $history = $this->conversationRepo->getHistory($sessionId, 1);
        $currentUserId = get_current_user_id();
        $isAdmin = current_user_can('manage_options');

        if (!$isAdmin) {
            if (empty($history)) {
                return new WP_REST_Response(['success' => true, 'session_id' => $sessionId], 200);
            }
            $ownerId = (int) ($history[0]['user_id'] ?? 0);
            if ($ownerId !== $currentUserId) {
                return new WP_REST_Response(['success' => false, 'error' => 'Not allowed to delete this session'], 403);
            }
        }

        $this->conversationRepo->deleteSession($sessionId);

        return new WP_REST_Response([
            'success' => true,
            'session_id' => $sessionId,
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
}
