<?php

namespace Levi\Agent\Admin;

class SettingsPage {
    private string $optionName = 'levi_agent_settings';
    private string $pageSlug = 'levi-agent-settings';
    private static bool $initialized = false;

    public function __construct() {
        // Prevent multiple initializations
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenuPage(): void {
        add_options_page(
            __('Levi AI Agent', 'levi-agent'),
            __('Levi AI', 'levi-agent'),
            'manage_options',
            $this->pageSlug,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void {
        register_setting(
            'levi_agent_settings_group',
            $this->optionName,
            [$this, 'sanitizeSettings']
        );

        // General Settings Section
        add_settings_section(
            'levi_agent_general',
            __('General Settings', 'levi-agent'),
            [$this, 'renderGeneralSection'],
            $this->pageSlug
        );

        // API Key Field
        add_settings_field(
            'openrouter_api_key',
            __('OpenRouter API Key', 'levi-agent'),
            [$this, 'renderApiKeyField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        // Model Selection
        add_settings_field(
            'model',
            __('Model', 'levi-agent'),
            [$this, 'renderModelField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        // Rate Limiting
        add_settings_field(
            'rate_limit',
            __('Rate Limit (requests per hour)', 'levi-agent'),
            [$this, 'renderRateLimitField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        // Memory Section
        add_settings_section(
            'levi_agent_memory',
            __('Memory', 'levi-agent'),
            [$this, 'renderMemorySection'],
            $this->pageSlug
        );
    }

    public function sanitizeSettings(array $input): array {
        $sanitized = [];

        // API Key - encrypt if provided
        if (!empty($input['openrouter_api_key'])) {
            $sanitized['openrouter_api_key'] = $this->encryptApiKey($input['openrouter_api_key']);
        } else {
            // Keep existing key if not changed
            $existing = get_option($this->optionName);
            if (isset($existing['openrouter_api_key'])) {
                $sanitized['openrouter_api_key'] = $existing['openrouter_api_key'];
            }
        }

        $sanitized['model'] = sanitize_text_field($input['model'] ?? 'anthropic/claude-3.5-sonnet');
        $sanitized['rate_limit'] = absint($input['rate_limit'] ?? 50);

        return $sanitized;
    }

    private function encryptApiKey(string $apiKey): string {
        // Use WordPress SALT for encryption
        $key = wp_salt('auth');
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($apiKey, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function getApiKey(): ?string {
        // First check .env file (for development)
        $envKey = $this->getApiKeyFromEnv();
        if ($envKey) {
            return $envKey;
        }

        // Then check database
        $settings = get_option($this->optionName);
        if (empty($settings['openrouter_api_key'])) {
            return null;
        }

        return $this->decryptApiKey($settings['openrouter_api_key']);
    }

    private function getApiKeyFromEnv(): ?string {
        // Look for .env in parent directories (for development)
        $possiblePaths = [
            dirname(ABSPATH) . '/.env',
            dirname(dirname(ABSPATH)) . '/.env',
            ABSPATH . '../.env',
        ];

        foreach ($possiblePaths as $envPath) {
            if (file_exists($envPath)) {
                $content = file_get_contents($envPath);
                if (preg_match('/OPEN_ROUTER_API_KEY=(.+)/', $content, $matches)) {
                    return trim($matches[1]);
                }
            }
        }

        return null;
    }

    private function decryptApiKey(string $encrypted): ?string {
        try {
            $data = base64_decode($encrypted);
            $iv = substr($data, 0, 16);
            $cipherText = substr($data, 16);
            $key = wp_salt('auth');
            
            $decrypted = openssl_decrypt($cipherText, 'AES-256-CBC', $key, 0, $iv);
            return $decrypted !== false ? $decrypted : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getSettings(): array {
        $defaults = [
            'model' => 'anthropic/claude-3.5-sonnet',
            'rate_limit' => 50,
        ];

        $settings = get_option($this->optionName, []);
        
        // Handle case where settings might be stored as JSON string
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?: [];
        }
        
        if (!is_array($settings)) {
            $settings = [];
        }
        
        return array_merge($defaults, $settings);
    }

    public function renderPage(): void {
        $settings = $this->getSettings();
        $apiKeyStatus = $this->getApiKey() ? 'configured' : 'missing';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-<?php echo $apiKeyStatus === 'configured' ? 'success' : 'warning'; ?>">
                <p>
                    <strong>API Key Status:</strong> 
                    <?php echo $apiKeyStatus === 'configured' 
                        ? '✅ OpenRouter API Key is configured' 
                        : '⚠️ Please configure your OpenRouter API Key'; ?>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php 
                settings_fields('levi_agent_settings_group');
                do_settings_sections($this->pageSlug);
                submit_button();
                ?>
            </form>

            <hr>
            
            <h2>Test Connection</h2>
            <button type="button" id="mohami-test-connection" class="button button-secondary">
                Test OpenRouter Connection
            </button>
            <span id="mohami-test-result"></span>
        </div>
        <?php
    }

    public function renderGeneralSection(): void {
        echo '<p>' . esc_html__('Configure your AI assistant settings.', 'levi-agent') . '</p>';
    }

    public function renderApiKeyField(): void {
        $settings = $this->getSettings();
        $hasKey = !empty($settings['openrouter_api_key']);
        ?>
        <input 
            type="password" 
            name="<?php echo esc_attr($this->optionName); ?>[openrouter_api_key]" 
            value="" 
            class="regular-text"
            placeholder="<?php echo $hasKey ? '••••••••••••••••' : 'sk-or-v1-...'; ?>"
        >
        <p class="description">
            <?php if ($hasKey): ?>
                API Key is saved. Leave empty to keep current key.
            <?php else: ?>
                Get your key from <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>
            <?php endif; ?>
        </p>
        <?php
    }

    public function renderModelField(): void {
        $settings = $this->getSettings();
        $models = [
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet (Recommended)',
            'anthropic/claude-3-opus' => 'Claude 3 Opus',
            'anthropic/claude-3-haiku' => 'Claude 3 Haiku (Fast)',
            'openai/gpt-4o' => 'GPT-4o',
            'openai/gpt-4o-mini' => 'GPT-4o Mini (Cheap)',
            'meta-llama/llama-3.1-70b-instruct' => 'Llama 3.1 70B (Open Source)',
        ];
        ?>
        <select name="<?php echo esc_attr($this->optionName); ?>[model]">
            <?php foreach ($models as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" 
                    <?php selected($settings['model'], $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function renderRateLimitField(): void {
        $settings = $this->getSettings();
        ?>
        <input 
            type="number" 
            name="<?php echo esc_attr($this->optionName); ?>[rate_limit]" 
            value="<?php echo esc_attr($settings['rate_limit']); ?>"
            min="1"
            max="1000"
            class="small-text"
        >
        <p class="description">Limit API requests per user per hour to control costs.</p>
        <?php
    }

    public function renderMemorySection(): void {
        echo '<p>' . esc_html__('Manage the agent\'s knowledge and memories.', 'levi-agent') . '</p>';
        
        // Show stats
        $loader = new \Levi\Agent\Memory\MemoryLoader();
        $stats = $loader->getStats();
        
        echo '<div class="mohami-memory-stats">';
        echo '<h4>' . esc_html__('Memory Statistics', 'levi-agent') . '</h4>';
        echo '<ul>';
        echo '<li>' . sprintf(esc_html__('Identity Vectors: %d', 'levi-agent'), $stats['identity_vectors'] ?? 0) . '</li>';
        echo '<li>' . sprintf(esc_html__('Reference Vectors: %d', 'levi-agent'), $stats['reference_vectors'] ?? 0) . '</li>';
        echo '<li>' . sprintf(esc_html__('Episodic Memories: %d', 'levi-agent'), $stats['episodic_memories'] ?? 0) . '</li>';
        echo '</ul>';
        echo '</div>';
        
        // Check for changes
        $changes = $loader->checkForChanges();
        $hasChanges = !empty($changes['identity']) || !empty($changes['reference']);
        
        if ($hasChanges) {
            echo '<div class="notice notice-warning inline">';
            echo '<p><strong>' . esc_html__('Memory files have changed!', 'levi-agent') . '</strong></p>';
            if (!empty($changes['identity'])) {
                echo '<p>' . esc_html__('Identity files: ', 'levi-agent') . esc_html(implode(', ', $changes['identity'])) . '</p>';
            }
            if (!empty($changes['reference'])) {
                echo '<p>' . esc_html__('Reference files: ', 'levi-agent') . esc_html(implode(', ', $changes['reference'])) . '</p>';
            }
            echo '</div>';
        }
        
        // Reload button
        echo '<p>';
        echo '<button type="button" id="mohami-reload-memories" class="button button-secondary">';
        echo esc_html__('Reload All Memories', 'levi-agent');
        echo '</button>';
        echo '<span id="mohami-reload-result" style="margin-left: 10px;"></span>';
        echo '</p>';
        
        echo '<p class="description">';
        echo esc_html__('This will reload all .md files from identity/ and memories/ folders into the vector database.', 'levi-agent');
        echo '</p>';
    }
}
