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
     * Get appropriate model for a query based on complexity.
     * Returns alternative model for simple queries, default model for complex ones.
     * 
     * @param string $query The user query
     * @return string|null The model to use, or null for default
     */
    public static function getModelForQuery(string $query): ?string {
        $settings = new SettingsPage();
        $provider = $settings->getProvider();
        
        // Only OpenRouter supports alternative models currently
        if ($provider !== 'openrouter') {
            return null;
        }

        $altModel = $settings->getAltModel();
        $defaultModel = $settings->getModelForProvider('openrouter');
        
        // If alternative model is same as default, always use default
        if ($altModel === $defaultModel) {
            return null;
        }

        // Check if query is simple
        try {
            $classifier = new QueryClassifier();
            if (!$classifier->needsDeepRetrieval($query)) {
                // Simple query - use alternative (faster) model
                return $altModel;
            }
        } catch (\Throwable $e) {
            // On error, fall back to default model
            return null;
        }

        // Complex query - use default model
        return null;
    }
}
