<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\AI\Concerns\RetriableApiCall;
use WP_Error;

class AnthropicClient implements AIClientInterface {
    use RetriableApiCall;
    private const API_BASE = 'https://api.anthropic.com/v1';
    private ?string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxTokens;

    public function __construct(?string $modelOverride = null) {
        $settings = new SettingsPage();
        $this->apiKey = $settings->getApiKeyForProvider('anthropic');
        $this->model = $modelOverride ?? $settings->getModelForProvider('anthropic');
        $allSettings = $settings->getSettings();
        $this->timeout = max(1, (int) ($allSettings['ai_timeout'] ?? 120));
        $this->maxTokens = max(1, (int) ($allSettings['max_tokens'] ?? 131072));
    }

    public function isConfigured(): bool {
        return $this->apiKey !== null;
    }

    public function chat(array $messages, array $tools = []): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'Anthropic API key not configured');
        }

        $anthropicPayload = $this->toAnthropicPayload($messages, $tools);

        return $this->executeWithRetry(
            fn() => $this->executeApiCall($anthropicPayload),
            'Anthropic'
        );
    }

    private function executeApiCall(array $payload): array|WP_Error {
        $response = wp_remote_post(self::API_BASE . '/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => 'prompt-caching-2024-07-31',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            return new WP_Error(
                'api_error',
                'Anthropic returned an invalid response format.',
                [
                    'status' => $statusCode,
                    'raw_body_excerpt' => mb_substr(trim((string) $rawBody), 0, 400),
                ]
            );
        }
        if ($statusCode !== 200) {
            $errorMessage = $body['error']['message'] ?? $body['error']['type'] ?? 'Unknown error';
            error_log(sprintf('Levi Anthropic Error [%d]: %s', $statusCode, $errorMessage));
            return new WP_Error('api_error', $errorMessage, ['status' => $statusCode]);
        }

        return $this->toOpenAICompatibleResponse($body);
    }

    public function streamChat(array $messages, callable $onChunk, array $tools = []): array|WP_Error {
        $response = $this->chat($messages, $tools);
        if (is_wp_error($response)) {
            return $response;
        }
        $msgData = $response['choices'][0]['message'] ?? [];
        $text = (string) ($msgData['content'] ?? '');
        $toolCalls = $msgData['tool_calls'] ?? [];
        $hasToolCalls = !empty($toolCalls);

        if ($text !== '' && !$hasToolCalls) {
            $onChunk($text);
        }

        return [
            'content' => $text,
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? ($hasToolCalls ? 'tool_calls' : 'stop'),
            'usage' => $response['usage'] ?? [],
            'model' => $response['model'] ?? 'anthropic',
            'has_tool_calls' => $hasToolCalls,
            'tool_calls' => $toolCalls,
        ];
    }

    public function testConnection(): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'Anthropic API key not configured');
        }

        $payload = [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 16,
            'messages' => [
                ['role' => 'user', 'content' => 'Say "OK" and nothing else.'],
            ],
        ];

        $response = wp_remote_post(self::API_BASE . '/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $errorMessage = $body['error']['message'] ?? 'Connection failed';
            return new WP_Error('test_failed', $errorMessage);
        }

        return ['success' => true, 'message' => 'Connection successful'];
    }

    private function toAnthropicPayload(array $messages, array $tools): array {
        $systemParts = [];
        $anthropicMessages = [];

        foreach ($messages as $msg) {
            $role = (string) ($msg['role'] ?? '');
            if ($role === 'system') {
                $systemParts[] = (string) ($msg['content'] ?? '');
                continue;
            }

            if ($role === 'tool') {
                $anthropicMessages[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => (string) ($msg['tool_call_id'] ?? ''),
                        'content' => (string) ($msg['content'] ?? ''),
                    ]],
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $contentBlocks = [];
                foreach ($msg['tool_calls'] as $call) {
                    $name = (string) ($call['function']['name'] ?? '');
                    $callId = (string) ($call['id'] ?? '');
                    $argsJson = (string) ($call['function']['arguments'] ?? '{}');
                    $input = json_decode($argsJson, true);
                    if (!is_array($input)) {
                        $input = [];
                    }
                    $contentBlocks[] = [
                        'type' => 'tool_use',
                        'id' => $callId !== '' ? $callId : ('tool_' . wp_generate_uuid4()),
                        'name' => $name,
                        'input' => $input,
                    ];
                }
                $anthropicMessages[] = [
                    'role' => 'assistant',
                    'content' => $contentBlocks,
                ];
                continue;
            }

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $content = $msg['content'] ?? '';
            if (!is_string($content)) {
                $content = wp_json_encode($content);
            }
            $anthropicMessages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $anthropicMessages,
        ];

        if (!empty($systemParts)) {
            $payload['system'] = $this->buildSystemBlocks($systemParts);
        }

        $convertedTools = $this->convertTools($tools);
        if (!empty($convertedTools)) {
            $lastIdx = count($convertedTools) - 1;
            $convertedTools[$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
            $payload['tools'] = $convertedTools;
        }

        return $payload;
    }

    /**
     * Build system content blocks with cache_control on the stable identity
     * prefix so Anthropic can cache it across turns in tool loops.
     *
     * @param string[] $parts System message texts (first = stable identity, rest = dynamic)
     * @return array<int, array{type: string, text: string, cache_control?: array}>
     */
    private function buildSystemBlocks(array $parts): array {
        $blocks = [];
        foreach ($parts as $i => $text) {
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $block = ['type' => 'text', 'text' => $text];
            if ($i === 0 && mb_strlen($text) > 1024) {
                $block['cache_control'] = ['type' => 'ephemeral'];
            }
            $blocks[] = $block;
        }
        return $blocks;
    }

    private function convertTools(array $tools): array {
        $converted = [];
        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? null;
            if (!is_array($fn)) {
                continue;
            }
            $converted[] = [
                'name' => (string) ($fn['name'] ?? ''),
                'description' => (string) ($fn['description'] ?? ''),
                'input_schema' => $fn['parameters'] ?? ['type' => 'object', 'properties' => []],
            ];
        }
        return array_values(array_filter($converted, fn($t) => $t['name'] !== ''));
    }

    private function toOpenAICompatibleResponse(array $anthropic): array {
        $contentBlocks = $anthropic['content'] ?? [];
        $textParts = [];
        $toolCalls = [];

        if (is_array($contentBlocks)) {
            foreach ($contentBlocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? '') === 'text') {
                    $textParts[] = (string) ($block['text'] ?? '');
                    continue;
                }
                if (($block['type'] ?? '') === 'tool_use') {
                    $toolCalls[] = [
                        'id' => (string) ($block['id'] ?? ('tool_' . wp_generate_uuid4())),
                        'type' => 'function',
                        'function' => [
                            'name' => (string) ($block['name'] ?? ''),
                            'arguments' => wp_json_encode($block['input'] ?? new \stdClass()),
                        ],
                    ];
                }
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => trim(implode("\n", $textParts)),
        ];
        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => $anthropic['id'] ?? null,
            'model' => $anthropic['model'] ?? $this->model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => $message,
                    'finish_reason' => !empty($toolCalls) ? 'tool_calls' : (($anthropic['stop_reason'] ?? '') === 'max_tokens' ? 'length' : 'stop'),
                ],
            ],
        ];
    }
}
