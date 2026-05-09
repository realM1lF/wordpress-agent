<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;

class AIClientFactory {
    public static function create(string $provider): AIClientInterface {
        return match ($provider) {
            'openai' => new OpenAIClient(),
            'anthropic' => new AnthropicClient(),
            default => new OpenRouterClient(),
        };
    }

    /**
     * Create AI client with a specific model override.
     * Useful for switching to alternative (faster) models for simple queries.
     * 
     * @param string $provider The provider (openrouter, openai, anthropic)
     * @param string|null $modelOverride Optional model to use instead of default
     * @return AIClientInterface
     */
    public static function createWithModel(string $provider, ?string $modelOverride = null): AIClientInterface {
        $client = match ($provider) {
            'openai' => new OpenAIClient($modelOverride),
            'anthropic' => new AnthropicClient($modelOverride),
            default => new OpenRouterClient($modelOverride),
        };
        
        return $client;
    }

    /**
     * Get appropriate model for a query.
     * Always returns null (use default model). The model is configured by the user
     * in settings — no automatic switching based on query classification.
     *
     * @param string $query The user query (unused, kept for API compatibility)
     * @return string|null Always null (use default model)
     */
    public static function getModelForQuery(string $query): ?string {
        return null;
    }
}
