<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;
use WP_Error;

class OpenRouterClient implements AIClientInterface {
    private const API_BASE = 'https://openrouter.ai/api/v1';
    private ?string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxTokens;

    public function __construct() {
        $settings = new SettingsPage();
        $this->apiKey = $settings->getApiKeyForProvider('openrouter');
        $this->model = $settings->getModelForProvider('openrouter');
        $allSettings = $settings->getSettings();
        $this->timeout = max(1, (int) ($allSettings['ai_timeout'] ?? 120));
        $this->maxTokens = max(1, (int) ($allSettings['max_tokens'] ?? 131072));
    }

    public function isConfigured(): bool {
        return $this->apiKey !== null;
    }

    public function chat(array $messages, array $tools = [], ?callable $heartbeat = null, string $toolChoice = 'auto'): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenRouter API key not configured');
        }

        $temperature = $this->resolveTemperature($messages);
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $this->maxTokens,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = in_array($toolChoice, ['required', 'none'], true) ? $toolChoice : 'auto';
        }

        return $this->executeWithRetry($payload, $heartbeat);
    }

    private function executeWithRetry(array $payload, ?callable $heartbeat = null, int $maxRetries = 3): array|WP_Error {
        $lastError = null;
        $backoffSeconds = [1, 2, 4];

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $delay = $backoffSeconds[$attempt - 1] ?? 4;
                error_log(sprintf('Levi OpenRouter: retry %d/%d after %ds', $attempt, $maxRetries, $delay));
                sleep($delay);
            }

            $result = $this->executeApiCall($payload, $heartbeat);

            if (!is_wp_error($result)) {
                return $result;
            }

            $lastError = $result;
            $errData = $result->get_error_data();
            $httpStatus = is_array($errData) ? (int) ($errData['status'] ?? 0) : 0;
            $errMsg = mb_strtolower($result->get_error_message());

            $isRetriable = in_array($httpStatus, [429, 502, 503], true)
                || str_contains($errMsg, 'timed out')
                || str_contains($errMsg, 'curl error 28')
                || str_contains($errMsg, 'rate limit')
                || str_contains($errMsg, 'overloaded')
                || str_contains($errMsg, 'server error');

            if (!$isRetriable) {
                return $lastError;
            }
        }

        return $lastError;
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
                return 0;
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

    public function streamChat(array $messages, callable $onChunk): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenRouter API key not configured');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => $this->maxTokens,
            'stream' => true,
        ];

        // For streaming, we need to use raw cURL
        $ch = curl_init(self::API_BASE . '/chat/completions');
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: ' . get_site_url(),
                'X-Title: Levi WordPress Agent',
                'Accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') === 0) {
                        $json = substr($line, 6);
                        if ($json === '[DONE]') {
                            return strlen($data);
                        }
                        $chunk = json_decode($json, true);
                        if ($chunk && isset($chunk['choices'][0]['delta']['content'])) {
                            $onChunk($chunk['choices'][0]['delta']['content']);
                        }
                    }
                }
                return strlen($data);
            },
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return new WP_Error('curl_error', $error);
        }

        curl_close($ch);
        return ['success' => true];
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
        // Simple test with a cheap model
        $testPayload = [
            'model' => 'moonshotai/kimi-k2.5',
            'messages' => [
                ['role' => 'user', 'content' => 'Say "OK" and nothing else.']
            ],
            'max_tokens' => 10,
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
