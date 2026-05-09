<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\AI\Concerns\RetriableApiCall;
use WP_Error;

class OpenRouterClient implements AIClientInterface {
    use RetriableApiCall;
    private const API_BASE = 'https://openrouter.ai/api/v1';
    private const PREFERRED_PROVIDER = 'Baseten';
    private ?string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxTokens;

    public function __construct(?string $modelOverride = null) {
        $settings = new SettingsPage();
        $this->apiKey = $settings->getApiKeyForProvider('openrouter');
        // Use override model if provided, otherwise use default
        $this->model = $modelOverride ?? $settings->getModelForProvider('openrouter');
        $allSettings = $settings->getSettings();
        $this->timeout = max(1, (int) ($allSettings['ai_timeout'] ?? 120));
        $this->maxTokens = max(1, (int) ($allSettings['max_tokens'] ?? 131072));
    }

    public function isConfigured(): bool {
        return $this->apiKey !== null;
    }

    public function chat(array $messages, array $tools = [], ?callable $heartbeat = null, bool $webSearch = false): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenRouter API key not configured');
        }

        $model = $this->model;
        if ($webSearch) {
            $model .= ':online';
        }

        $temperature = $this->resolveTemperature($messages);
        $payload = [
            'model' => $model,
            'messages' => $this->applyCacheControl($messages),
            'temperature' => $temperature,
            'max_tokens' => $this->maxTokens,
            'provider' => $this->getProviderPreferences(),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $this->executeWithRetry(
            fn() => $this->executeApiCall($payload, $heartbeat),
            'OpenRouter'
        );
    }

    private function executeApiCall(array $payload, ?callable $heartbeat = null): array|WP_Error {
        if ($heartbeat !== null) {
            return $this->executeApiCallWithHeartbeat($payload, $heartbeat);
        }

        $response = wp_remote_post(self::API_BASE . '/chat/completions', [
            'headers' => $this->getApiHeaders(),
            'body' => json_encode($payload),
            'timeout' => $this->timeout,
        ]);

        return $this->parseApiResponse($response);
    }

    private function executeApiCallWithHeartbeat(array $payload, callable $heartbeat): array|WP_Error {
        $lastHeartbeat = time();
        $ch = curl_init(self::API_BASE . '/chat/completions');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array_map(
                fn($k, $v) => "$k: $v",
                array_keys($this->getApiHeaders()),
                array_values($this->getApiHeaders())
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function () use ($heartbeat, &$lastHeartbeat) {
                $now = time();
                if ($now - $lastHeartbeat >= 3) {
                    $lastHeartbeat = $now;
                    $heartbeat();
                }
                return connection_aborted() ? 1 : 0;
            },
        ]);

        $rawBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return new WP_Error('api_error', $error, ['status' => 0]);
        }

        curl_close($ch);

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            error_log(sprintf('Levi OpenRouter Error [%d]: invalid JSON response body', $httpCode));
            return new WP_Error('api_error', 'OpenRouter returned an invalid response format.', [
                'status' => $httpCode,
                'raw_body_excerpt' => mb_substr(trim((string) $rawBody), 0, 400),
            ]);
        }

        if ($httpCode !== 200) {
            $errorMessage = $body['error']['message'] ?? $body['error']['code'] ?? 'Unknown error';
            $metadata = $body['error']['metadata'] ?? [];
            error_log(sprintf('Levi OpenRouter Error [%d]: %s', $httpCode, $errorMessage));
            return new WP_Error('api_error', $errorMessage, ['status' => $httpCode, 'metadata' => $metadata]);
        }

        if (!isset($body['choices']) || !is_array($body['choices'])) {
            return new WP_Error('api_error', 'OpenRouter response does not contain expected choices payload.', ['status' => $httpCode]);
        }

        return $body;
    }

    private function getApiHeaders(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => get_site_url(),
            'X-Title' => 'Levi WordPress Agent',
        ];
    }

    private function parseApiResponse($response): array|WP_Error {
        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            error_log(sprintf('Levi OpenRouter Error [%d]: invalid JSON response body', $statusCode));
            return new WP_Error('api_error', 'OpenRouter returned an invalid response format.', [
                'status' => $statusCode,
                'raw_body_excerpt' => mb_substr(trim((string) $rawBody), 0, 400),
            ]);
        }

        if ($statusCode !== 200) {
            $errorMessage = $body['error']['message'] ?? $body['error']['code'] ?? 'Unknown error';
            $metadata = $body['error']['metadata'] ?? [];
            error_log(sprintf('Levi OpenRouter Error [%d]: %s', $statusCode, $errorMessage));
            return new WP_Error('api_error', $errorMessage, ['status' => $statusCode, 'metadata' => $metadata]);
        }

        if (!isset($body['choices']) || !is_array($body['choices'])) {
            return new WP_Error('api_error', 'OpenRouter response does not contain expected choices payload.', ['status' => $statusCode]);
        }

        return $body;
    }

    /**
     * Add cache_control breakpoints to system messages.
     *
     * Only applied for Anthropic models which require explicit cache_control.
     * All other providers (Moonshot/Kimi, OpenAI, DeepSeek, Gemini) use
     * automatic implicit caching — converting content to array format
     * would break their caching and waste tokens.
     */
    private function applyCacheControl(array $messages): array {
        if (!str_starts_with($this->model, 'anthropic/')) {
            return $messages;
        }

        $systemIndex = 0;
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') !== 'system') {
                continue;
            }
            $content = $msg['content'] ?? '';
            if (!is_string($content)) {
                continue;
            }
            $minLength = $systemIndex === 0 ? 1024 : 256;
            if (mb_strlen($content) >= $minLength) {
                $messages[$i]['content'] = [[
                    'type' => 'text',
                    'text' => $content,
                    'cache_control' => ['type' => 'ephemeral'],
                ]];
            }
            $systemIndex++;
        }
        return $messages;
    }

    private function resolveTemperature(array $messages): float {
        $lastUserMessage = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUserMessage = (string) ($messages[$i]['content'] ?? '');
                break;
            }
        }

        if ($lastUserMessage === '') {
            return 0.7;
        }

        $text = mb_strtolower($lastUserMessage);
        $isOperationalTask = preg_match('/\b(erstell|anleg|schreib|änder|bearbeit|install|update|fix|prüf|analysier|lösch|deaktivier|aktivier)\b/u', $text) === 1;
        return $isOperationalTask ? 0.2 : 0.7;
    }

    public function streamChat(array $messages, callable $onChunk, array $tools = []): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenRouter API key not configured');
        }

        if (!function_exists('curl_init')) {
            return new WP_Error('curl_missing', 'cURL extension required for streaming');
        }

        $temperature = $this->resolveTemperature($messages);
        $payload = [
            'model' => $this->model,
            'messages' => $this->applyCacheControl($messages),
            'temperature' => $temperature,
            'max_tokens' => $this->maxTokens,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'provider' => $this->getProviderPreferences(),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $fullContent = '';
        $fullReasoningContent = '';
        $reasoningSignalled = false;
        $finishReason = null;
        $usage = [];
        $model = null;
        $hasToolCalls = false;
        $toolCallChunks = [];
        $sseBuffer = '';

        $ch = curl_init(self::API_BASE . '/chat/completions');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array_map(
                fn($k, $v) => "$k: $v",
                array_keys($this->getApiHeaders()),
                array_values($this->getApiHeaders())
            ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk, &$fullContent, &$finishReason, &$usage, &$model, &$hasToolCalls, &$toolCallChunks, &$sseBuffer) {
                $sseBuffer .= $data;
                while (($pos = strpos($sseBuffer, "\n")) !== false) {
                    $line = substr($sseBuffer, 0, $pos);
                    $sseBuffer = substr($sseBuffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '' || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        continue;
                    }

                    $chunk = json_decode($json, true);
                    if (!is_array($chunk)) {
                        continue;
                    }

                    if ($model === null && !empty($chunk['model'])) {
                        $model = $chunk['model'];
                    }

                    if (!empty($chunk['usage'])) {
                        $usage = $chunk['usage'];
                    }

                    $choice = $chunk['choices'][0] ?? null;
                    if ($choice === null) {
                        continue;
                    }

                    if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                        $finishReason = $choice['finish_reason'];
                    }

                    $delta = $choice['delta'] ?? [];

                    if (!empty($delta['tool_calls'])) {
                        $hasToolCalls = true;
                        foreach ($delta['tool_calls'] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            $isNew = !isset($toolCallChunks[$idx]);
                            if ($isNew) {
                                $toolCallChunks[$idx] = [
                                    'id' => $tc['id'] ?? '',
                                    'type' => 'function',
                                    'function' => ['name' => '', 'arguments' => ''],
                                ];
                            }
                            if (!empty($tc['id'])) {
                                $toolCallChunks[$idx]['id'] = $tc['id'];
                            }
                            if (!empty($tc['function']['name'])) {
                                $toolCallChunks[$idx]['function']['name'] .= $tc['function']['name'];
                                if ($isNew) {
                                    $onChunk(json_encode(['tool' => $tc['function']['name'], 'index' => $idx]), 'tool_call_start');
                                }
                            }
                            if (isset($tc['function']['arguments'])) {
                                $toolCallChunks[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                            }
                        }
                        continue;
                    }

                    if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                        $fullReasoningContent .= $delta['reasoning_content'];
                        if (!$reasoningSignalled) {
                            $reasoningSignalled = true;
                            $onChunk('', 'reasoning_start');
                        }
                    }

                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $fullContent .= $delta['content'];
                        $onChunk($delta['content']);
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
            return new WP_Error('api_error', "OpenRouter streaming returned HTTP $httpCode", ['status' => $httpCode]);
        }

        $result = [
            'content' => $fullContent,
            'finish_reason' => $finishReason ?? 'stop',
            'usage' => $usage,
            'model' => $model ?? $this->model,
            'has_tool_calls' => $hasToolCalls,
            'tool_calls' => $hasToolCalls ? array_values($toolCallChunks) : [],
        ];

        if ($fullReasoningContent !== '') {
            $result['reasoning_content'] = $fullReasoningContent;
        }

        return $result;
    }

    private function getProviderPreferences(): array {
        return [
            'order' => [self::PREFERRED_PROVIDER],
            'allow_fallbacks' => true,
        ];
    }

    public function getAvailableModels(): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenRouter API key not configured');
        }

        $response = wp_remote_get(self::API_BASE . '/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            return new WP_Error('api_error', 'Failed to fetch models');
        }

        return $body['data'] ?? [];
    }

    public function testConnection(): array|WP_Error {
        $testPayload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => 'Say "OK" and nothing else.']
            ],
            'max_tokens' => 10,
            'provider' => $this->getProviderPreferences(),
        ];

        $response = wp_remote_post(self::API_BASE . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => get_site_url(),
            ],
            'body' => json_encode($testPayload),
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
}
