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
    private ?string $pendingToolChoice = null;

    public function setToolChoice(?string $toolChoice): void {
        $this->pendingToolChoice = $toolChoice;
    }

    public function __construct(?string $modelOverride = null) {
        $settings = new SettingsPage();
        $this->apiKey = $settings->getApiKeyForProvider('anthropic');
        $this->model = $modelOverride ?? $settings->getModelForProvider('anthropic');
        $allSettings = $settings->getSettings();
        $this->timeout = max(1, (int) ($allSettings['ai_timeout'] ?? 120));
        $userMax = max(1, (int) ($allSettings['max_tokens'] ?? 131072));
        $limits = $settings->getModelLimits('anthropic', $this->model);
        $modelMaxOutput = $limits['max_output_tokens'] ?? 16384;
        $this->maxTokens = min($userMax, $modelMaxOutput);
    }

    public function isConfigured(): bool {
        return $this->apiKey !== null;
    }

    public function overrideApiKey(string $key): void {
        $this->apiKey = $key;
    }

    public function chat(array $messages, array $tools = []): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'Anthropic API key not configured');
        }

        $toolChoice = $this->pendingToolChoice;
        $this->pendingToolChoice = null;
        $anthropicPayload = $this->toAnthropicPayload($messages, $tools, $toolChoice);

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
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'Anthropic API key not configured');
        }

        $toolChoice = $this->pendingToolChoice;
        $this->pendingToolChoice = null;

        if (!function_exists('curl_init')) {
            $this->pendingToolChoice = $toolChoice;
            return $this->streamChatFallback($messages, $onChunk, $tools);
        }

        $anthropicPayload = $this->toAnthropicPayload($messages, $tools, $toolChoice);
        $anthropicPayload['stream'] = true;

        $fullContent = '';
        $finishReason = null;
        $usage = [];
        $model = null;
        $hasToolCalls = false;
        $toolCallBlocks = [];
        $currentBlockIndex = -1;
        $currentBlockType = null;
        $sseBuffer = '';
        $rawResponseBody = '';

        $ch = curl_init(self::API_BASE . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($anthropicPayload),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: prompt-caching-2024-07-31',
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                $onChunk, &$fullContent, &$finishReason, &$usage, &$model,
                &$hasToolCalls, &$toolCallBlocks, &$currentBlockIndex, &$currentBlockType, &$sseBuffer, &$rawResponseBody
            ) {
                $rawResponseBody .= $data;
                $sseBuffer .= $data;

                while (($pos = strpos($sseBuffer, "\n")) !== false) {
                    $line = substr($sseBuffer, 0, $pos);
                    $sseBuffer = substr($sseBuffer, $pos + 1);
                    $line = rtrim($line, "\r");

                    if (str_starts_with($line, 'event: ')) {
                        continue;
                    }

                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = substr($line, 6);
                    $event = json_decode($json, true);
                    if (!is_array($event)) {
                        continue;
                    }

                    $type = $event['type'] ?? '';

                    if ($type === 'message_start') {
                        $msg = $event['message'] ?? [];
                        $model = $msg['model'] ?? null;
                        $u = $msg['usage'] ?? [];
                        if (!empty($u)) {
                            $usage = array_merge($usage, $u);
                        }
                        continue;
                    }

                    if ($type === 'content_block_start') {
                        $currentBlockIndex = $event['index'] ?? 0;
                        $block = $event['content_block'] ?? [];
                        $currentBlockType = $block['type'] ?? 'text';

                        if ($currentBlockType === 'tool_use') {
                            $hasToolCalls = true;
                            $toolCallBlocks[$currentBlockIndex] = [
                                'id' => $block['id'] ?? ('tool_' . uniqid()),
                                'type' => 'function',
                                'function' => [
                                    'name' => $block['name'] ?? '',
                                    'arguments' => '',
                                ],
                            ];
                            $onChunk(json_encode(['tool' => $block['name'] ?? '', 'index' => $currentBlockIndex]), 'tool_call_start');
                        }
                        continue;
                    }

                    if ($type === 'content_block_delta') {
                        $delta = $event['delta'] ?? [];
                        $deltaType = $delta['type'] ?? '';

                        if ($deltaType === 'text_delta') {
                            $text = $delta['text'] ?? '';
                            if ($text !== '') {
                                $fullContent .= $text;
                                $onChunk($text);
                            }
                        } elseif ($deltaType === 'input_json_delta') {
                            $partial = $delta['partial_json'] ?? '';
                            if ($partial !== '' && isset($toolCallBlocks[$currentBlockIndex])) {
                                $toolCallBlocks[$currentBlockIndex]['function']['arguments'] .= $partial;
                            }
                        }
                        continue;
                    }

                    if ($type === 'content_block_stop') {
                        $currentBlockType = null;
                        continue;
                    }

                    if ($type === 'message_delta') {
                        $delta = $event['delta'] ?? [];
                        $stopReason = $delta['stop_reason'] ?? null;
                        if ($stopReason !== null) {
                            $finishReason = match ($stopReason) {
                                'tool_use' => 'tool_calls',
                                'max_tokens' => 'length',
                                default => 'stop',
                            };
                        }
                        $u = $event['usage'] ?? [];
                        if (!empty($u)) {
                            $usage = array_merge($usage, $u);
                        }
                        continue;
                    }
                }

                return strlen($data);
            },
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            $isTimeout = $errno === CURLE_OPERATION_TIMEDOUT || $errno === 28;
            return new WP_Error($isTimeout ? 'timeout' : 'curl_error', $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $errorMessage = "Anthropic streaming returned HTTP $httpCode";
            $parsed = json_decode($rawResponseBody, true);
            if (is_array($parsed) && !empty($parsed['error']['message'])) {
                $errorMessage = (string) $parsed['error']['message'];
            }
            return new WP_Error('api_error', $errorMessage, ['status' => $httpCode]);
        }

        $oaiToolCalls = !empty($toolCallBlocks) ? array_values($toolCallBlocks) : [];

        return [
            'content' => $fullContent,
            'finish_reason' => $finishReason ?? 'stop',
            'usage' => $usage,
            'model' => $model ?? $this->model,
            'has_tool_calls' => $hasToolCalls,
            'tool_calls' => $oaiToolCalls,
        ];
    }

    private function streamChatFallback(array $messages, callable $onChunk, array $tools = []): array|WP_Error {
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
            'model' => $response['model'] ?? $this->model,
            'has_tool_calls' => $hasToolCalls,
            'tool_calls' => $toolCalls,
        ];
    }

    public function testConnection(): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'Anthropic API key not configured');
        }

        $payload = [
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 16,
            'messages' => [
                ['role' => 'user', 'content' => 'Say "OK" and nothing else.'],
            ],
        ];

        $response = wp_remote_post(self::API_BASE . '/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => 'prompt-caching-2024-07-31',
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

    private function toAnthropicPayload(array $messages, array $tools, ?string $toolChoice = null): array {
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

            if ($toolChoice !== null && $toolChoice !== 'auto') {
                $payload['tool_choice'] = match ($toolChoice) {
                    'required' => ['type' => 'any'],
                    'none' => ['type' => 'auto'],
                    default => ['type' => 'auto'],
                };
            }
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
