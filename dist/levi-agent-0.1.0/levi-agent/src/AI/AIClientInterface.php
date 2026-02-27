<?php

namespace Levi\Agent\AI;

use WP_Error;

interface AIClientInterface {
    public function isConfigured(): bool;

    /**
     * @return array|WP_Error OpenAI-compatible response shape
     */
    public function chat(array $messages, array $tools = []): array|WP_Error;

    public function streamChat(array $messages, callable $onChunk): array|WP_Error;

    public function testConnection(): array|WP_Error;
}
