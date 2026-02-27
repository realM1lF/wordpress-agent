<?php

namespace Levi\Agent\AI;

class AIClientFactory {
    public static function create(string $provider): AIClientInterface {
        return match ($provider) {
            'openai' => new OpenAIClient(),
            'anthropic' => new AnthropicClient(),
            default => new OpenRouterClient(),
        };
    }
}
