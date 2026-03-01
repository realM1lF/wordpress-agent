<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;
use WP_Error;

class OpenAIClient implements AIClientInterface {
    private const API_BASE = 'https://api.openai.com/v1';
    private ?string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxTokens;

    public function __construct() {
        $settings = new SettingsPage();
        $this->apiKey = $settings->getApiKeyForProvider('openai');
        $this->model = $settings->getModelForProvider('openai');
        $allSettings = $settings->getSettings();
        $this->timeout = max(1, (int) ($allSettings['ai_timeout'] ?? 120));
        $this->maxTokens = max(1, (int) ($allSettings['max_tokens'] ?? 131072));
    }

    public function isConfigured(): bool {
        return $this->apiKey !== null;
    }

    public function chat(array $messages, array $tools = [], ?callable $heartbeat = null, string $toolChoice = 'auto'): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenAI API key not configured');
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

        $response = wp_remote_post(self::API_BASE . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
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
                'OpenAI returned an invalid response format.',
                [
                    'status' => $statusCode,
                    'raw_body_excerpt' => mb_substr(trim((string) $rawBody), 0, 400),
                ]
            );
        }
        if ($statusCode !== 200) {
            $errorMessage = $body['error']['message'] ?? $body['error']['code'] ?? 'Unknown error';
            error_log(sprintf('Levi OpenAI Error [%d]: %s', $statusCode, $errorMessage));
            return new WP_Error('api_error', $errorMessage, ['status' => $statusCode]);
        }

        if (!isset($body['choices']) || !is_array($body['choices'])) {
            return new WP_Error(
                'api_error',
                'OpenAI response does not contain expected choices payload.',
                ['status' => $statusCode]
            );
        }

        return $body;
    }

    public function streamChat(array $messages, callable $onChunk): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenAI API key not configured');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => $this->maxTokens,
            'stream' => true,
        ];

        $ch = curl_init(self::API_BASE . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, 'data: ')) {
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

    public function testConnection(): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenAI API key not configured');
        }

        $testPayload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Say "OK" and nothing else.'],
            ],
            'max_tokens' => 10,
        ];

        $response = wp_remote_post(self::API_BASE . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($testPayload),
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
}
