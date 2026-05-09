<?php

namespace Levi\Agent\AI;

use WP_Error;

interface AIClientInterface {
    public function isConfigured(): bool;

    /**
     * @return array|WP_Error OpenAI-compatible response shape
     */
    public function chat(array $messages, array $tools = []): array|WP_Error;

    /**
     * Stream a chat response token-by-token via a callback.
     *
     * @param callable $onChunk  Called with each text delta (string).
     * @param array    $tools    Optional tool definitions (OpenAI format).
     * @return array|WP_Error    On success: ['content'=>string, 'finish_reason'=>string, 'usage'=>array, 'model'=>string, 'has_tool_calls'=>bool, 'tool_calls'=>array]
     */
    public function streamChat(array $messages, callable $onChunk, array $tools = []): array|WP_Error;

    public function testConnection(): array|WP_Error;
}
