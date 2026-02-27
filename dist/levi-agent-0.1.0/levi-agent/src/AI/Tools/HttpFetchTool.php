<?php

namespace Levi\Agent\AI\Tools;

class HttpFetchTool implements ToolInterface {

    private const MAX_RESPONSE_BYTES = 51200;
    private const TIMEOUT_SECONDS = 15;

    public function getName(): string {
        return 'http_fetch';
    }

    public function getDescription(): string {
        return 'Fetch a URL via HTTP GET from the same WordPress site. Useful to test frontend output, check if a page renders correctly, inspect REST API responses, or verify shortcode output. Only same-site requests are allowed.';
    }

    public function getParameters(): array {
        return [
            'url' => [
                'type' => 'string',
                'description' => 'Full URL or path on this site (e.g. "/shop/" or "/wp-json/wc/v3/products")',
                'required' => true,
            ],
            'method' => [
                'type' => 'string',
                'description' => 'HTTP method (default GET)',
                'enum' => ['GET', 'HEAD'],
            ],
            'extract' => [
                'type' => 'string',
                'description' => 'What to extract from HTML response',
                'enum' => ['full', 'body', 'title', 'headers_only'],
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $url = trim((string) ($params['url'] ?? ''));
        $method = strtoupper(trim((string) ($params['method'] ?? 'GET')));
        $extract = (string) ($params['extract'] ?? 'body');

        if ($url === '') {
            return ['success' => false, 'error' => 'URL is required.'];
        }

        if (!str_starts_with($url, 'http')) {
            $url = rtrim(home_url(), '/') . '/' . ltrim($url, '/');
        }

        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
        $requestHost = wp_parse_url($url, PHP_URL_HOST);

        if ($siteHost !== $requestHost) {
            return [
                'success' => false,
                'error' => "Only same-site requests allowed. Site: $siteHost, requested: $requestHost",
            ];
        }

        $args = [
            'timeout' => self::TIMEOUT_SECONDS,
            'sslverify' => false,
            'user-agent' => 'Levi-Agent/1.0',
        ];

        if ($method === 'HEAD') {
            $response = wp_remote_head($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $contentType = $headers['content-type'] ?? 'unknown';

        $result = [
            'success' => true,
            'status_code' => $statusCode,
            'content_type' => $contentType,
            'url' => $url,
        ];

        if ($method === 'HEAD' || $extract === 'headers_only') {
            $result['headers'] = $this->formatHeaders($headers);
            return $result;
        }

        $body = wp_remote_retrieve_body($response);

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if ($decoded !== null) {
                $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if (strlen($json) > self::MAX_RESPONSE_BYTES) {
                    $json = mb_substr($json, 0, self::MAX_RESPONSE_BYTES) . "\n... [truncated]";
                }
                $result['json'] = $decoded;
                return $result;
            }
        }

        if ($extract === 'title') {
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m);
            $result['title'] = $m[1] ?? null;
            $result['body_length'] = strlen($body);
            return $result;
        }

        if ($extract === 'body') {
            $body = $this->extractBody($body);
        }

        $body = $this->cleanHtml($body);

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $body = mb_substr($body, 0, self::MAX_RESPONSE_BYTES) . "\n... [truncated at " . self::MAX_RESPONSE_BYTES . " bytes]";
        }

        $result['body'] = $body;
        $result['body_length'] = strlen($body);

        return $result;
    }

    private function extractBody(string $html): string {
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
            return $m[1];
        }
        return $html;
    }

    private function cleanHtml(string $html): string {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = strip_tags($html, '<h1><h2><h3><h4><p><a><ul><ol><li><table><tr><td><th><div><span><form><input><select><option><img>');
        $html = preg_replace('/\s+/', ' ', $html);
        return trim($html);
    }

    private function formatHeaders($headers): array {
        $result = [];
        if ($headers instanceof \Requests_Utility_CaseInsensitiveDictionary || is_iterable($headers)) {
            foreach ($headers as $key => $value) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
