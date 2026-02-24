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
            'openrouter_model',
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

        // API Key - plain text, no encryption (fixes save/load issues)
        $newKey = isset($input['openrouter_api_key']) ? trim($input['openrouter_api_key']) : '';
        if ($newKey !== '') {
            $sanitized['openrouter_api_key'] = sanitize_text_field($newKey);
        } elseif (!empty($existing['openrouter_api_key'])) {
            $sanitized['openrouter_api_key'] = $existing['openrouter_api_key'];
        }

        $allowedModels = $this->getAllowedModels();
        $model = sanitize_text_field($input['openrouter_model'] ?? '');
        $sanitized['openrouter_model'] = isset($allowedModels[$model]) ? $model : array_key_first($allowedModels);

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

    public function getApiKey(): ?string {
        $envKey = $this->getApiKeyFromEnv();
        if ($envKey) {
            return $envKey;
        }

        $settings = get_option($this->optionName);
        $key = $settings['openrouter_api_key'] ?? '';
        $key = is_string($key) ? trim($key) : '';

        return $key !== '' ? $key : null;
    }

    private function getApiKeyFromEnv(): ?string {
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

    public function getAllowedModels(): array {
        return [
            'meta-llama/llama-3.1-70b-instruct:free' => 'Llama 3.1 70B (kostenlos)',
            'moonshotai/kimi-k2.5' => 'Kimi K2.5 (Moonshot)',
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet',
            'openai/gpt-4o' => 'GPT-4o',
            'google/gemini-2.0-flash-001' => 'Gemini 2.0 Flash',
            'meta-llama/llama-3.1-70b-instruct' => 'Llama 3.1 70B (kostenpflichtig)',
        ];
    }

    public function getModel(): string {
        $settings = $this->getSettings();
        $model = $settings['openrouter_model'] ?? 'meta-llama/llama-3.1-70b-instruct:free';
        $allowed = $this->getAllowedModels();
        return isset($allowed[$model]) ? $model : 'meta-llama/llama-3.1-70b-instruct:free';
    }

    public function getSettings(): array {
        $defaults = [
            'openrouter_model' => 'meta-llama/llama-3.1-70b-instruct:free',
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
                Test OpenRouter Connection
            </button>
            <span id="levi-test-result"></span>
        </div>
        <?php
    }

    public function renderGeneralSection(): void {
        echo '<p>' . esc_html__('Configure your AI assistant settings.', 'levi-agent') . '</p>';
    }

    public function renderApiKeyField(): void {
        $settings = $this->getSettings();
        $hasKey = !empty(trim($settings['openrouter_api_key'] ?? ''));
        ?>
        <input 
            type="password" 
            name="<?php echo esc_attr($this->optionName); ?>[openrouter_api_key]" 
            value="" 
            class="regular-text"
            placeholder="<?php echo $hasKey ? '••••••••••••••••••••' : 'sk-or-v1-...'; ?>"
            autocomplete="new-password"
        >
        <p class="description">
            <?php if ($hasKey): ?>
                <?php esc_html_e('API Key is saved. Enter a new key to replace it.', 'levi-agent'); ?>
            <?php endif; ?>
            <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>
        </p>
        <?php
    }

    public function renderModelField(): void {
        $settings = $this->getSettings();
        $current = $settings['openrouter_model'] ?? 'meta-llama/llama-3.1-70b-instruct:free';
        $models = $this->getAllowedModels();
        ?>
        <select name="<?php echo esc_attr($this->optionName); ?>[openrouter_model]" class="regular-text">
            <?php foreach ($models as $id => $label): ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($current, $id); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('Llama 3.1 70B (kostenlos) ist Standard für Neuinstallationen. Kimi und weitere Modelle verfügbar.', 'levi-agent'); ?></p>
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
