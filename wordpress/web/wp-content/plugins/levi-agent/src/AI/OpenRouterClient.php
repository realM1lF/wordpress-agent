<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;
use WP_Error;

class OpenRouterClient {
    private const API_BASE = 'https://openrouter.ai/api/v1';
    private ?string $apiKey;
    private string $model;
    private int $timeout = 60;

    public function __construct() {
        $settings = new SettingsPage();
        $this->apiKey = $settings->getApiKey();
        $this->model = 'anthropic/claude-3.5-sonnet';
    }

    public function isConfigured(): bool {
        return $this->apiKey !== null;
    }

    public function chat(array $messages, array $tools = []): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenRouter API key not configured');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 4096,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = wp_remote_post(self::API_BASE . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => get_site_url(),
                'X-Title' => 'Mohami WordPress Agent',
            ],
            'body' => json_encode($payload),
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);

        if ($statusCode !== 200) {
            $errorMessage = $body['error']['message'] ?? $body['error']['code'] ?? 'Unknown error';
            $metadata = $body['error']['metadata'] ?? [];
            error_log(sprintf(
                'Levi OpenRouter Error [%d]: %s | Body: %s',
                $statusCode,
                $errorMessage,
                substr($rawBody, 0, 500)
            ));
            return new WP_Error('api_error', $errorMessage, ['status' => $statusCode, 'metadata' => $metadata]);
        }

        return $body;
    }

    public function streamChat(array $messages, callable $onChunk): array|WP_Error {
        if (!$this->apiKey) {
            return new WP_Error('not_configured', 'OpenRouter API key not configured');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 4096,
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
                'X-Title: Mohami WordPress Agent',
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
            'model' => 'meta-llama/llama-3.1-70b-instruct:free',
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
