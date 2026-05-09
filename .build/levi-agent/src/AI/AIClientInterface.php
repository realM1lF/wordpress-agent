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

    /**
     * Set tool_choice for the next API call. Resets after each call.
     * @param string|null $toolChoice  'auto' (default), 'required', or 'none'.
     */
    public function setToolChoice(?string $toolChoice): void;

    public function testConnection(): array|WP_Error;

    /**
     * Override the API key at runtime (e.g. for pre-save test).
     */
    public function overrideApiKey(string $key): void;
}
