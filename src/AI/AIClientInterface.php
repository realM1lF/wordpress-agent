<?php

namespace Levi\Agent\AI;

use WP_Error;

interface AIClientInterface {
    public function isConfigured(): bool;

    /**
     * @return array|WP_Error OpenAI-compatible response shape
     * @param string $toolChoice 'auto'|'required'|'none' – only OpenRouter respects 'required'
     */
    public function chat(array $messages, array $tools = [], ?callable $heartbeat = null, string $toolChoice = 'auto'): array|WP_Error;

    public function streamChat(array $messages, callable $onChunk): array|WP_Error;

    public function testConnection(): array|WP_Error;
}
