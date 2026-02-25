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
        add_action('wp_ajax_levi_repair_database', [$this, 'ajaxRepairDatabase']);
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

        add_settings_field(
            'ai_provider',
            __('AI Provider', 'levi-agent'),
            [$this, 'renderProviderField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'ai_auth_method',
            __('Authentication Method', 'levi-agent'),
            [$this, 'renderAuthMethodField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        // API Key Field
        add_settings_field(
            'provider_api_key',
            __('Provider API Key', 'levi-agent'),
            [$this, 'renderApiKeyField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        // Model Selection
        add_settings_field(
            'provider_model',
            __('Chat Model', 'levi-agent'),
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

        add_settings_field(
            'max_tool_iterations',
            __('Max Tool Iterations per Request', 'levi-agent'),
            [$this, 'renderMaxToolIterationsField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'history_context_limit',
            __('History Context Messages', 'levi-agent'),
            [$this, 'renderHistoryContextLimitField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'force_exhaustive_reads',
            __('Force Exhaustive Reads', 'levi-agent'),
            [$this, 'renderForceExhaustiveReadsField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'require_confirmation_destructive',
            __('Require Confirmation for Destructive Tools', 'levi-agent'),
            [$this, 'renderRequireConfirmationDestructiveField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'memory_identity_k',
            __('Memory Top-K (Identity)', 'levi-agent'),
            [$this, 'renderMemoryIdentityKField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'memory_reference_k',
            __('Memory Top-K (Reference)', 'levi-agent'),
            [$this, 'renderMemoryReferenceKField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'memory_episodic_k',
            __('Memory Top-K (Episodic)', 'levi-agent'),
            [$this, 'renderMemoryEpisodicKField'],
            $this->pageSlug,
            'levi_agent_general'
        );

        add_settings_field(
            'memory_min_similarity',
            __('Memory Min Similarity', 'levi-agent'),
            [$this, 'renderMemoryMinSimilarityField'],
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
        $existing = get_option($this->optionName, []);

        $provider = sanitize_key($input['ai_provider'] ?? ($existing['ai_provider'] ?? 'openrouter'));
        $providers = $this->getProviderLabels();
        if (!isset($providers[$provider])) {
            $provider = 'openrouter';
        }
        $sanitized['ai_provider'] = $provider;

        $authMethod = sanitize_key($input['ai_auth_method'] ?? ($existing['ai_auth_method'] ?? 'api_key'));
        $authOptions = $this->getAuthMethodOptions($provider);
        if (!isset($authOptions[$authMethod])) {
            $authMethod = array_key_first($authOptions);
        }
        $sanitized['ai_auth_method'] = $authMethod;

        // Keep existing keys if no replacement is entered.
        $keyFields = ['openrouter_api_key', 'openai_api_key', 'anthropic_api_key'];
        foreach ($keyFields as $keyField) {
            $newKey = isset($input[$keyField]) ? trim((string) $input[$keyField]) : '';
            if ($newKey !== '') {
                $sanitized[$keyField] = sanitize_text_field($newKey);
            } elseif (!empty($existing[$keyField])) {
                $sanitized[$keyField] = $existing[$keyField];
            }
        }

        $modelFields = [
            'openrouter' => 'openrouter_model',
            'openai' => 'openai_model',
            'anthropic' => 'anthropic_model',
        ];
        foreach ($modelFields as $modelProvider => $settingKey) {
            $allowedModels = $this->getAllowedModelsForProvider($modelProvider);
            $candidate = sanitize_text_field($input[$settingKey] ?? ($existing[$settingKey] ?? ''));
            $sanitized[$settingKey] = isset($allowedModels[$candidate]) ? $candidate : array_key_first($allowedModels);
        }

        $sanitized['rate_limit'] = absint($input['rate_limit'] ?? 50);
        $sanitized['max_tool_iterations'] = max(1, min(30, absint($input['max_tool_iterations'] ?? 12)));
        $sanitized['history_context_limit'] = max(10, min(200, absint($input['history_context_limit'] ?? 50)));
        $sanitized['force_exhaustive_reads'] = !empty($input['force_exhaustive_reads']) ? 1 : 0;
        $sanitized['require_confirmation_destructive'] = !empty($input['require_confirmation_destructive']) ? 1 : 0;
        $sanitized['memory_identity_k'] = max(1, min(20, absint($input['memory_identity_k'] ?? 5)));
        $sanitized['memory_reference_k'] = max(1, min(20, absint($input['memory_reference_k'] ?? 5)));
        $sanitized['memory_episodic_k'] = max(1, min(20, absint($input['memory_episodic_k'] ?? 4)));
        $sanitized['memory_min_similarity'] = max(0.0, min(1.0, (float) ($input['memory_min_similarity'] ?? 0.6)));

        return $sanitized;
    }

    public function getProviderLabels(): array {
        return [
            'openrouter' => 'OpenRouter',
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
        ];
    }

    public function getProvider(): string {
        $settings = $this->getSettings();
        $provider = sanitize_key((string) ($settings['ai_provider'] ?? 'openrouter'));
        $labels = $this->getProviderLabels();
        return isset($labels[$provider]) ? $provider : 'openrouter';
    }

    public function getAuthMethodOptions(string $provider): array {
        return match ($provider) {
            'openrouter' => [
                'api_key' => __('API Key', 'levi-agent'),
                'oauth' => __('Login via OpenRouter (OAuth, coming soon)', 'levi-agent'),
            ],
            'anthropic' => [
                'api_key' => __('API Key (Pay-as-you-go)', 'levi-agent'),
                'subscription_token' => __('Subscription Token (sk-ant-oat-*)', 'levi-agent'),
            ],
            default => [
                'api_key' => __('API Key', 'levi-agent'),
            ],
        };
    }

    public function getAuthMethod(): string {
        $settings = $this->getSettings();
        $provider = $this->getProvider();
        $method = sanitize_key((string) ($settings['ai_auth_method'] ?? 'api_key'));
        $options = $this->getAuthMethodOptions($provider);
        return isset($options[$method]) ? $method : array_key_first($options);
    }

    public function getApiKey(): ?string {
        return $this->getApiKeyForProvider($this->getProvider());
    }

    public function getApiKeyForProvider(string $provider): ?string {
        $envVar = match ($provider) {
            'openai' => 'OPENAI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            default => 'OPEN_ROUTER_API_KEY',
        };

        $envKey = $this->getApiKeyFromEnvVar($envVar);
        if ($envKey) {
            return $envKey;
        }

        $settings = $this->getSettings();
        $settingKey = match ($provider) {
            'openai' => 'openai_api_key',
            'anthropic' => 'anthropic_api_key',
            default => 'openrouter_api_key',
        };
        $key = $settings[$settingKey] ?? '';
        $key = is_string($key) ? trim($key) : '';

        return $key !== '' ? $key : null;
    }

    private function getApiKeyFromEnvVar(string $envVar): ?string {
        $possiblePaths = [
            dirname(ABSPATH) . '/.env',
            dirname(dirname(ABSPATH)) . '/.env',
            ABSPATH . '../.env',
        ];

        foreach ($possiblePaths as $envPath) {
            if (!file_exists($envPath)) {
                continue;
            }
            $content = file_get_contents($envPath);
            if (!is_string($content)) {
                continue;
            }
            if (preg_match('/^' . preg_quote($envVar, '/') . '=(.+)$/m', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    public function getAllowedModels(): array {
        return $this->getAllowedModelsForProvider($this->getProvider());
    }

    public function getAllowedModelsForProvider(string $provider): array {
        return match ($provider) {
            'openai' => [
                'gpt-4o-mini' => 'GPT-4o Mini (günstig)',
                'gpt-4o' => 'GPT-4o',
                'gpt-4.1-mini' => 'GPT-4.1 Mini',
                'gpt-4.1' => 'GPT-4.1',
            ],
            'anthropic' => [
                'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku',
                'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet',
                'claude-3-opus-20240229' => 'Claude 3 Opus',
            ],
            default => [
                'meta-llama/llama-3.1-70b-instruct:free' => 'Llama 3.1 70B (kostenlos)',
                'moonshotai/kimi-k2.5' => 'Kimi K2.5 (Moonshot)',
                'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet',
                'openai/gpt-4o' => 'GPT-4o',
                'google/gemini-2.0-flash-001' => 'Gemini 2.0 Flash',
                'meta-llama/llama-3.1-70b-instruct' => 'Llama 3.1 70B (kostenpflichtig)',
            ],
        };
    }

    public function getModel(): string {
        return $this->getModelForProvider($this->getProvider());
    }

    public function getModelForProvider(string $provider): string {
        $settings = $this->getSettings();
        $settingKey = match ($provider) {
            'openai' => 'openai_model',
            'anthropic' => 'anthropic_model',
            default => 'openrouter_model',
        };
        $allowed = $this->getAllowedModelsForProvider($provider);
        $model = (string) ($settings[$settingKey] ?? array_key_first($allowed));
        return isset($allowed[$model]) ? $model : array_key_first($allowed);
    }

    public function getSettings(): array {
        $defaults = [
            'ai_provider' => 'openrouter',
            'ai_auth_method' => 'api_key',
            'openrouter_api_key' => '',
            'openai_api_key' => '',
            'anthropic_api_key' => '',
            'openrouter_model' => 'meta-llama/llama-3.1-70b-instruct:free',
            'openai_model' => 'gpt-4o-mini',
            'anthropic_model' => 'claude-3-5-sonnet-20241022',
            'rate_limit' => 50,
            'max_tool_iterations' => 12,
            'history_context_limit' => 50,
            'force_exhaustive_reads' => 1,
            'require_confirmation_destructive' => 1,
            'memory_identity_k' => 5,
            'memory_reference_k' => 5,
            'memory_episodic_k' => 4,
            'memory_min_similarity' => 0.6,
        ];

        $settings = get_option($this->optionName, []);
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
        $provider = $this->getProvider();
        $providerLabel = $this->getProviderLabels()[$provider] ?? ucfirst($provider);
        $apiKeyStatus = $this->getApiKeyForProvider($provider) ? 'configured' : 'missing';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-<?php echo $apiKeyStatus === 'configured' ? 'success' : 'warning'; ?>">
                <p>
                    <strong><?php echo esc_html($providerLabel); ?> Status:</strong>
                    <?php echo $apiKeyStatus === 'configured' 
                        ? '✅ Connection credentials are configured'
                        : '⚠️ Please configure authentication credentials'; ?>
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
            
            <h2><?php esc_html_e('Database', 'levi-agent'); ?></h2>
            <p>
                <button type="button" id="levi-repair-database" class="button button-secondary">
                    <?php esc_html_e('Repair Database Tables', 'levi-agent'); ?>
                </button>
                <span id="levi-repair-result" style="margin-left: 10px;"></span>
            </p>
            <p class="description">
                <?php esc_html_e('Creates missing database tables (e.g. if you get "Table doesn\'t exist" errors).', 'levi-agent'); ?>
            </p>

            <hr>
            
            <h2>Test Connection</h2>
            <button type="button" id="levi-test-connection" class="button button-secondary">
                Test Provider Connection
            </button>
            <span id="levi-test-result"></span>
        </div>
        <?php
    }

    public function renderGeneralSection(): void {
        echo '<p>' . esc_html__('Configure provider, authentication, and model. Then test the connection.', 'levi-agent') . '</p>';
    }

    public function renderProviderField(): void {
        $settings = $this->getSettings();
        $current = $settings['ai_provider'] ?? 'openrouter';
        $providers = $this->getProviderLabels();
        ?>
        <select name="<?php echo esc_attr($this->optionName); ?>[ai_provider]" class="regular-text">
            <?php foreach ($providers as $id => $label): ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($current, $id); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('OpenRouter is easiest for most users. OpenAI and Anthropic can be configured directly.', 'levi-agent'); ?></p>
        <?php
    }

    public function renderAuthMethodField(): void {
        $provider = $this->getProvider();
        $current = $this->getAuthMethod();
        $options = $this->getAuthMethodOptions($provider);
        ?>
        <select name="<?php echo esc_attr($this->optionName); ?>[ai_auth_method]" class="regular-text">
            <?php foreach ($options as $id => $label): ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($current, $id); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php if ($provider === 'openai'): ?>
                <?php esc_html_e('OpenAI API requires API keys. ChatGPT Plus/Pro subscriptions do not include API access.', 'levi-agent'); ?>
            <?php elseif ($provider === 'anthropic'): ?>
                <?php esc_html_e('Anthropic supports API keys and subscription-style tokens (sk-ant-oat-*).', 'levi-agent'); ?>
            <?php else: ?>
                <?php esc_html_e('OpenRouter supports API keys. OAuth login flow can be added later.', 'levi-agent'); ?>
            <?php endif; ?>
        </p>
        <?php
    }

    public function renderApiKeyField(): void {
        $settings = $this->getSettings();
        $provider = $this->getProvider();
        $settingKey = $provider === 'openai' ? 'openai_api_key' : ($provider === 'anthropic' ? 'anthropic_api_key' : 'openrouter_api_key');
        $hasKey = !empty(trim((string) ($settings[$settingKey] ?? '')));
        $placeholder = $provider === 'openai' ? 'sk-...' : ($provider === 'anthropic' ? 'sk-ant-...' : 'sk-or-v1-...');
        $helpUrl = $provider === 'openai'
            ? 'https://platform.openai.com/api-keys'
            : ($provider === 'anthropic' ? 'https://console.anthropic.com/settings/keys' : 'https://openrouter.ai/keys');
        ?>
        <input 
            type="password" 
            name="<?php echo esc_attr($this->optionName); ?>[<?php echo esc_attr($settingKey); ?>]" 
            value="" 
            class="regular-text"
            placeholder="<?php echo $hasKey ? '••••••••••••••••••••' : esc_attr($placeholder); ?>"
            autocomplete="new-password"
        >
        <p class="description">
            <?php if ($hasKey): ?>
                <?php esc_html_e('API Key is saved. Enter a new key to replace it.', 'levi-agent'); ?>
            <?php endif; ?>
            <a href="<?php echo esc_url($helpUrl); ?>" target="_blank"><?php echo esc_html(preg_replace('#^https?://#', '', $helpUrl)); ?></a>
        </p>
        <?php
    }

    public function renderModelField(): void {
        $settings = $this->getSettings();
        $provider = $this->getProvider();
        $settingKey = $provider === 'openai' ? 'openai_model' : ($provider === 'anthropic' ? 'anthropic_model' : 'openrouter_model');
        $models = $this->getAllowedModelsForProvider($provider);
        $current = (string) ($settings[$settingKey] ?? array_key_first($models));
        ?>
        <select name="<?php echo esc_attr($this->optionName); ?>[<?php echo esc_attr($settingKey); ?>]" class="regular-text">
            <?php foreach ($models as $id => $label): ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($current, $id); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php if ($provider === 'openrouter'): ?>
                <?php esc_html_e('Default for new installs: Llama 3.1 70B (kostenlos).', 'levi-agent'); ?>
            <?php elseif ($provider === 'openai'): ?>
                <?php esc_html_e('Recommended default: GPT-4o Mini for cost/performance.', 'levi-agent'); ?>
            <?php else: ?>
                <?php esc_html_e('Recommended default: Claude 3.5 Sonnet.', 'levi-agent'); ?>
            <?php endif; ?>
        </p>
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

    public function renderMaxToolIterationsField(): void {
        $settings = $this->getSettings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr($this->optionName); ?>[max_tool_iterations]"
            value="<?php echo esc_attr($settings['max_tool_iterations']); ?>"
            min="1"
            max="30"
            class="small-text"
        >
        <p class="description">How many chained tool rounds Levi can run in one response.</p>
        <?php
    }

    public function renderHistoryContextLimitField(): void {
        $settings = $this->getSettings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr($this->optionName); ?>[history_context_limit]"
            value="<?php echo esc_attr($settings['history_context_limit']); ?>"
            min="10"
            max="200"
            class="small-text"
        >
        <p class="description">How many past chat messages are sent back as context.</p>
        <?php
    }

    public function renderForceExhaustiveReadsField(): void {
        $settings = $this->getSettings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr($this->optionName); ?>[force_exhaustive_reads]"
                value="1"
                <?php checked(!empty($settings['force_exhaustive_reads'])); ?>
            >
            Always force full-content pagination for content analysis tasks.
        </label>
        <?php
    }

    public function renderRequireConfirmationDestructiveField(): void {
        $settings = $this->getSettings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr($this->optionName); ?>[require_confirmation_destructive]"
                value="1"
                <?php checked(!empty($settings['require_confirmation_destructive'])); ?>
            >
            Require explicit "ja/confirm" before destructive tools are executed.
        </label>
        <?php
    }

    public function renderMemoryIdentityKField(): void {
        $settings = $this->getSettings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr($this->optionName); ?>[memory_identity_k]"
            value="<?php echo esc_attr($settings['memory_identity_k']); ?>"
            min="1"
            max="20"
            class="small-text"
        >
        <?php
    }

    public function renderMemoryReferenceKField(): void {
        $settings = $this->getSettings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr($this->optionName); ?>[memory_reference_k]"
            value="<?php echo esc_attr($settings['memory_reference_k']); ?>"
            min="1"
            max="20"
            class="small-text"
        >
        <?php
    }

    public function renderMemoryEpisodicKField(): void {
        $settings = $this->getSettings();
        ?>
        <input
            type="number"
            name="<?php echo esc_attr($this->optionName); ?>[memory_episodic_k]"
            value="<?php echo esc_attr($settings['memory_episodic_k']); ?>"
            min="1"
            max="20"
            class="small-text"
        >
        <?php
    }

    public function renderMemoryMinSimilarityField(): void {
        $settings = $this->getSettings();
        ?>
        <input
            type="number"
            step="0.01"
            name="<?php echo esc_attr($this->optionName); ?>[memory_min_similarity]"
            value="<?php echo esc_attr($settings['memory_min_similarity']); ?>"
            min="0"
            max="1"
            class="small-text"
        >
        <p class="description">Lower value = broader memory recall, higher value = stricter match.</p>
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
        echo '<button type="button" id="levi-reload-memories" class="button button-secondary">';
        echo esc_html__('Reload All Memories', 'levi-agent');
        echo '</button>';
        echo '<span id="levi-reload-result" style="margin-left: 10px;"></span>';
        echo '</p>';
        
        echo '<p class="description">';
        echo esc_html__('This will reload all .md files from identity/ and memories/ folders into the vector database.', 'levi-agent');
        echo '</p>';
    }

    public function ajaxRepairDatabase(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        require_once LEVI_AGENT_PLUGIN_DIR . 'src/Database/Tables.php';
        Levi\Agent\Database\Tables::create();

        wp_send_json_success(['message' => __('Database tables created successfully.', 'levi-agent')]);
    }
}
