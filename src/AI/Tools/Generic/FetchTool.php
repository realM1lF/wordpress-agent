<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Generic fetch tool — HTTP requests for external data.
 */
class FetchTool extends AbstractTool
{
    public function getName(): string
    {
        return "fetch";
    }

    public function getDescription(): string
    {
        return "Führt HTTP-Requests durch. Nutze für externe APIs, Doku-Seiten, oder wenn du Inhalte von anderen Websites benötigst. " .
            "`url` (erforderlich), `method` (GET/POST/PUT/DELETE, default GET). " .
            "`headers` als Key-Value-Paare. " .
            "`body` für POST/PUT. " .
            "`timeout` (default 30). " .
            "Maximale Antwortgröße: 1MB.";
    }

    public function getParameters(): array
    {
        return [
            "url" => [
                "type" => "string",
                "description" => "URL zum Abrufen",
                "required" => true,
            ],
            "method" => [
                "type" => "string",
                "enum" => ["GET", "POST", "PUT", "DELETE", "PATCH", "HEAD"],
                "default" => "GET",
                "description" => "HTTP-Methode",
            ],
            "headers" => [
                "type" => "object",
                "description" => "HTTP-Header als Key-Value-Paare",
            ],
            "body" => [
                "type" => "string",
                "description" => "Request-Body (für POST/PUT/PATCH)",
            ],
            "timeout" => [
                "type" => "integer",
                "default" => 30,
                "description" => "Timeout in Sekunden",
            ],
        ];
    }

    public function execute(array $params): array
    {
        $url = (string) ($params["url"] ?? "");
        if ($url === "") {
            return ["success" => false, "error" => "Erforderlich: url"];
        }

        $method = strtoupper((string) ($params["method"] ?? "GET"));
        $headers = $params["headers"] ?? [];
        $body = $params["body"] ?? null;
        $timeout = (int) ($params["timeout"] ?? 30);

        $args = [
            "method" => $method,
            "timeout" => $timeout,
            "redirection" => 5,
            "httpversion" => "1.1",
            "headers" => $headers,
            "sslverify" => true,
        ];

        if (
            $body !== null &&
            in_array($method, ["POST", "PUT", "PATCH"], true)
        ) {
            $args["body"] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                "success" => false,
                "error" => $response->get_error_message(),
                "code" => $response->get_error_code(),
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);
        $responseHeaders = wp_remote_retrieve_headers($response)->getAll();

        // Truncate large responses
        $maxLen = 1024 * 1024; // 1MB
        $truncated = false;
        if (strlen($responseBody) > $maxLen) {
            $responseBody =
                mb_substr($responseBody, 0, $maxLen) .
                "\n\n[... Antwort auf 1MB gekürzt ...]";
            $truncated = true;
        }

        // Try to decode JSON
        $decoded = json_decode($responseBody, true);
        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
            $responseBody = $decoded;
        }

        return [
            "success" => $status >= 200 && $status < 400,
            "status" => $status,
            "headers" => $responseHeaders,
            "body" => $responseBody,
            "truncated" => $truncated,
        ];
    }

    public function checkPermission(): bool
    {
        return current_user_can("read");
    }
}
