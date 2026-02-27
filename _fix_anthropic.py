import re

path = 'src/AI/AnthropicClient.php'
content = open(path, 'r').read()

# 1. Replace property declaration
content = content.replace(
    "private int $timeout = 120;",
    "private int $timeout;\n    private int $maxTokens;"
)

# 2. Replace constructor to add settings reading
old_ctor = "$this->model = $settings->getModelForProvider('anthropic');\n    }"
new_ctor = """$this->model = $settings->getModelForProvider('anthropic');
        $allSettings = $settings->getSettings();
        $this->timeout = max(1, (int) ($allSettings['ai_timeout'] ?? 120));
        $this->maxTokens = max(1, (int) ($allSettings['max_tokens'] ?? 4096));
    }"""
content = content.replace(old_ctor, new_ctor)

# 3. Replace hardcoded max_tokens in toAnthropicPayload
content = content.replace(
    "'max_tokens' => 4096,\n            'messages' => $anthropicMessages,",
    "'max_tokens' => $this->maxTokens,\n            'messages' => $anthropicMessages,"
)

open(path, 'w').write(content)
print('Done')
