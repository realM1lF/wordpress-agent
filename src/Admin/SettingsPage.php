<?php

namespace Levi\Agent\Admin;

class SettingsPage {
    private string $optionName = 'levi_agent_settings';
    private string $pageSlug = 'levi-agent-settings';
    private static bool $initialized = false;

    public function __construct() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_levi_repair_database', [$this, 'ajaxRepairDatabase']);
        add_action('wp_ajax_levi_run_state_snapshot', [$this, 'ajaxRunStateSnapshot']);
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

    public function enqueueAssets(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_' . $this->pageSlug) {
            return;
        }

        // Levi Settings Styles
        wp_enqueue_style(
            'levi-agent-settings',
            LEVI_AGENT_PLUGIN_URL . 'assets/css/settings-page.css',
            [],
            LEVI_AGENT_VERSION
        );

        // Levi Settings JavaScript
        wp_enqueue_script(
            'levi-agent-settings',
            LEVI_AGENT_PLUGIN_URL . 'assets/js/settings-page.js',
            ['jquery'],
            LEVI_AGENT_VERSION,
            true
        );

        wp_localize_script('levi-agent-settings', 'leviSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('levi_admin_nonce'),
            'i18n' => [
                'testing' => $this->tr('Testing…', 'Teste...'),
                'connected' => $this->tr('Connected', 'Verbunden'),
                'notConnected' => $this->tr('Not Connected', 'Nicht verbunden'),
                'connectionError' => $this->tr('Connection error', 'Verbindungsfehler'),
                'failed' => $this->tr('Failed', 'Fehlgeschlagen'),
                'reloadConfirm' => $this->tr('Reload all memories? This may take a moment.', 'Alle Memories neu laden? Das kann einen Moment dauern.'),
                'reloading' => $this->tr('Reloading…', 'Lade neu...'),
                'reloaded' => $this->tr('Reloaded:', 'Neu geladen:'),
                'identity' => $this->tr('identity', 'Identität'),
                'reference' => $this->tr('reference', 'Referenz'),
                'files' => $this->tr('files', 'Dateien'),
                'error' => $this->tr('Error', 'Fehler'),
                'done' => $this->tr('Done', 'Fertig'),
                'repairing' => $this->tr('Repairing…', 'Repariere...'),
                'saving' => $this->tr('Saving…', 'Speichere...'),
                'status_not_run' => $this->tr('Not run yet', 'Noch nicht gelaufen'),
                'status_unchanged' => $this->tr('Unchanged', 'Unverändert'),
                'status_changed_stored' => $this->tr('Updated and stored', 'Aktualisiert und gespeichert'),
            ],
        ]);
    }

    private function tr(string $english, string $german): string {
        $translated = __($english, 'levi-agent');
        if ($translated !== $english) {
            return $translated;
        }
        $locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        return str_starts_with($locale, 'de') ? $german : $english;
    }

    /** @return string Translated label for snapshot status (internal key). */
    private function translateSnapshotStatus(string $status): string {
        $labels = [
            'not_run' => $this->tr('Not run yet', 'Noch nicht gelaufen'),
            'unchanged' => $this->tr('Unchanged', 'Unveraendert'),
            'changed_stored' => $this->tr('Updated and stored', 'Aktualisiert und gespeichert'),
            'error' => $this->tr('Error', 'Fehler'),
        ];
        return $labels[$status] ?? $status;
    }

    public function registerSettings(): void {
        register_setting(
            'levi_agent_settings_group',
            $this->optionName,
            [$this, 'sanitizeSettings']
        );
    }

    public function sanitizeSettings(array $input): array {
        $sanitized = [];
        $existing = get_option($this->optionName, []);

        $provider = sanitize_key($input['ai_provider'] ?? ($existing['ai_provider'] ?? 'openrouter'));
        $providers = $this->getProviderLabels();
        $sanitized['ai_provider'] = isset($providers[$provider]) ? $provider : 'openrouter';

        $authMethod = sanitize_key($input['ai_auth_method'] ?? ($existing['ai_auth_method'] ?? 'api_key'));
        $authOptions = $this->getAuthMethodOptions($sanitized['ai_provider']);
        $sanitized['ai_auth_method'] = isset($authOptions[$authMethod]) ? $authMethod : array_key_first($authOptions);

        // API Keys
        $keyFields = ['openrouter_api_key', 'openai_api_key', 'anthropic_api_key'];
        foreach ($keyFields as $keyField) {
            $newKey = isset($input[$keyField]) ? trim((string) $input[$keyField]) : '';
            if ($newKey !== '') {
                $sanitized[$keyField] = sanitize_text_field($newKey);
            } elseif (!empty($existing[$keyField])) {
                $sanitized[$keyField] = $existing[$keyField];
            }
        }

        // Models
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

        // Numeric & Boolean Settings
        $sanitized['rate_limit'] = max(1, min(1000, absint($input['rate_limit'] ?? 50)));
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

    public function renderPage(): void {
        $settings = $this->getSettings();
        $provider = $this->getProvider();
        $apiKeyStatus = $this->getApiKeyForProvider($provider) ? 'connected' : 'disconnected';
        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        
        $tabs = [
            'general' => ['icon' => 'dashicons-admin-generic', 'label' => $this->tr('General', 'Allgemein')],
            'ai-provider' => ['icon' => 'dashicons-cloud', 'label' => $this->tr('AI Provider', 'KI-Anbieter')],
            'memory' => ['icon' => 'dashicons-database', 'label' => $this->tr('Memory', 'Memory')],
            'safety' => ['icon' => 'dashicons-shield', 'label' => $this->tr('Limits & Safety', 'Limits & Sicherheit')],
            'advanced' => ['icon' => 'dashicons-admin-tools', 'label' => $this->tr('Advanced', 'Erweitert')],
        ];
        ?>
        <div class="levi-settings-wrap">
            <!-- Header -->
            <header class="levi-settings-header">
                <div class="levi-header-content">
                    <div class="levi-logo">
                        <span class="levi-logo-icon">🤖</span>
                        <div class="levi-logo-text">
                            <h1><?php echo esc_html(__('Levi AI Agent', 'levi-agent')); ?></h1>
                            <span class="levi-version">v<?php echo esc_html(LEVI_AGENT_VERSION); ?></span>
                        </div>
                    </div>
                    <div class="levi-connection-status levi-status-<?php echo esc_attr($apiKeyStatus); ?>">
                        <span class="levi-status-dot"></span>
                        <span class="levi-status-text">
                            <?php echo $apiKeyStatus === 'connected' ? esc_html($this->tr('Connected', 'Verbunden')) : esc_html($this->tr('Not Connected', 'Nicht verbunden')); ?>
                        </span>
                    </div>
                </div>
            </header>

            <!-- Navigation -->
            <nav class="levi-settings-nav">
                <div class="levi-nav-tabs">
                    <?php foreach ($tabs as $tabId => $tabData): 
                        $isActive = $activeTab === $tabId;
                        $tabUrl = add_query_arg(['page' => $this->pageSlug, 'tab' => $tabId], admin_url('options-general.php'));
                    ?>
                        <a href="<?php echo esc_url($tabUrl); ?>" 
                           class="levi-nav-tab <?php echo $isActive ? 'levi-nav-tab-active' : ''; ?>">
                            <span class="dashicons <?php echo esc_attr($tabData['icon']); ?>"></span>
                            <span><?php echo esc_html($tabData['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="levi-settings-main">
                <form method="post" action="options.php" class="levi-settings-form">
                    <?php settings_fields('levi_agent_settings_group'); ?>
                    
                    <?php if ($activeTab === 'general'): ?>
                        <?php $this->renderGeneralTab($settings); ?>
                    <?php elseif ($activeTab === 'ai-provider'): ?>
                        <?php $this->renderAiProviderTab($settings); ?>
                    <?php elseif ($activeTab === 'memory'): ?>
                        <?php $this->renderMemoryTab($settings); ?>
                    <?php elseif ($activeTab === 'safety'): ?>
                        <?php $this->renderSafetyTab($settings); ?>
                    <?php elseif ($activeTab === 'advanced'): ?>
                        <?php $this->renderAdvancedTab($settings); ?>
                    <?php endif; ?>

                    <div class="levi-form-actions">
                        <?php submit_button($this->tr('Save Settings', 'Einstellungen speichern'), 'levi-btn-primary', 'submit', false); ?>
                        <span class="levi-save-indicator">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html($this->tr('Settings saved successfully', 'Einstellungen erfolgreich gespeichert')); ?>
                        </span>
                    </div>
                </form>
            </main>
        </div>
        <?php
    }

    private function renderGeneralTab(array $settings): void {
        ?>
        <div class="levi-settings-section">
            <div class="levi-section-header">
                <h2><?php echo esc_html($this->tr('Welcome to Levi', 'Willkommen bei Levi')); ?></h2>
                <p><?php echo esc_html($this->tr('Your AI-powered WordPress assistant. Configure the basic settings below.', 'Dein KI-Assistent fuer WordPress. Konfiguriere hier die wichtigsten Grundeinstellungen.')); ?></p>
            </div>

            <div class="levi-cards-grid">
                <!-- Quick Start Card -->
                <div class="levi-card levi-card-featured">
                    <div class="levi-card-icon">🚀</div>
                    <h3><?php echo esc_html($this->tr('Quick Start', 'Schnellstart')); ?></h3>
                    <p><?php echo esc_html($this->tr('Levi is ready to help you manage your WordPress site. Open the chat widget in the bottom right corner to get started.', 'Levi hilft dir bei deiner WordPress-Seite. Oeffne unten rechts das Chat-Widget, um zu starten.')); ?></p>
                    <a href="#" class="levi-btn levi-btn-secondary" onclick="document.getElementById('levi-chat-toggle').click(); return false;">
                        <?php echo esc_html($this->tr('Open Chat', 'Chat oeffnen')); ?>
                    </a>
                </div>

                <!-- Connection Card -->
                <div class="levi-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-cloud"></span>
                        <h3><?php echo esc_html($this->tr('Connection Status', 'Verbindungsstatus')); ?></h3>
                    </div>
                    <div class="levi-card-content">
                        <?php 
                        $provider = $this->getProvider();
                        $providerLabel = $this->getProviderLabels()[$provider] ?? ucfirst($provider);
                        $isConfigured = $this->getApiKeyForProvider($provider) ? true : false;
                        ?>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php echo esc_html($this->tr('Provider', 'Anbieter')); ?></span>
                            <span class="levi-status-value"><?php echo esc_html($providerLabel); ?></span>
                        </div>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php echo esc_html($this->tr('Model', 'Modell')); ?></span>
                            <span class="levi-status-value"><?php echo esc_html($this->getModelForProvider($provider)); ?></span>
                        </div>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php echo esc_html($this->tr('Status', 'Status')); ?></span>
                            <span class="levi-status-badge <?php echo $isConfigured ? 'levi-badge-success' : 'levi-badge-warning'; ?>">
                                <?php echo $isConfigured ? esc_html($this->tr('Connected', 'Verbunden')) : esc_html($this->tr('Setup Required', 'Einrichtung erforderlich')); ?>
                            </span>
                        </div>
                    </div>
                    <?php if (!$isConfigured): ?>
                        <div class="levi-card-footer">
                            <a href="<?php echo esc_url(add_query_arg(['tab' => 'ai-provider'], $_SERVER['REQUEST_URI'])); ?>" class="levi-btn levi-btn-small">
                                <?php echo esc_html($this->tr('Configure Now', 'Jetzt konfigurieren')); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Memory Stats Card -->
                <div class="levi-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-database"></span>
                        <h3><?php echo esc_html($this->tr('Memory Stats', 'Memory-Statistik')); ?></h3>
                    </div>
                    <div class="levi-card-content">
                        <?php 
                        $loader = new \Levi\Agent\Memory\MemoryLoader();
                        $stats = $loader->getStats();
                        ?>
                        <div class="levi-stat-item">
                            <span class="levi-stat-value"><?php echo number_format($stats['identity_vectors'] ?? 0); ?></span>
                            <span class="levi-stat-label"><?php _e('Identity', 'levi-agent'); ?></span>
                        </div>
                        <div class="levi-stat-item">
                            <span class="levi-stat-value"><?php echo number_format($stats['reference_vectors'] ?? 0); ?></span>
                            <span class="levi-stat-label"><?php _e('Reference', 'levi-agent'); ?></span>
                        </div>
                        <div class="levi-stat-item">
                            <span class="levi-stat-value"><?php echo number_format($stats['episodic_memories'] ?? 0); ?></span>
                            <span class="levi-stat-label"><?php _e('Episodic', 'levi-agent'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- State Snapshot Card -->
                <div class="levi-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-backup"></span>
                        <h3><?php echo esc_html($this->tr('WordPress Snapshot', 'WordPress-Snapshot')); ?></h3>
                    </div>
                    <div class="levi-card-content">
                        <?php 
                        $snapshotMeta = \Levi\Agent\Memory\StateSnapshotService::getLastMeta();
                        $snapshotStatus = (string) ($snapshotMeta['status'] ?? 'not_run');
                        $snapshotCapturedAt = (string) ($snapshotMeta['captured_at'] ?? '-');;
                        ?>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php echo esc_html($this->tr('Last Run', 'Letzter Lauf')); ?></span>
                            <span class="levi-status-value"><?php echo esc_html($snapshotCapturedAt); ?></span>
                        </div>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php echo esc_html($this->tr('Status', 'Status')); ?></span>
                            <span class="levi-status-badge levi-badge-<?php echo $snapshotStatus === 'changed_stored' ? 'success' : 'neutral'; ?>">
                                <?php echo esc_html($this->translateSnapshotStatus($snapshotStatus)); ?>
                            </span>
                        </div>
                    </div>
                    <p class="levi-form-help levi-hint">
                        <?php echo esc_html($this->tr('Hint: The daily snapshot indexes your WordPress state (plugins, themes, config) so Levi can answer questions about your site. Run manually here or wait for the scheduled task.', 'Hinweis: Der taegliche Snapshot indexiert deinen WordPress-Stand (Plugins, Themes, Konfiguration), damit Levi Fragen zur Seite beantworten kann. Hier manuell starten oder auf den geplanten Lauf warten.')); ?>
                    </p>
                    <div class="levi-card-footer">
                        <button type="button" id="levi-run-state-snapshot" class="levi-btn levi-btn-small levi-btn-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php echo esc_html($this->tr('Run Now', 'Jetzt ausfuehren')); ?>
                        </button>
                        <div id="levi-state-snapshot-progress-wrap" class="levi-progress-wrap" style="display:none;">
                            <div id="levi-state-snapshot-progress" class="levi-progress-bar"></div>
                        </div>
                        <span id="levi-state-snapshot-result"></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderAiProviderTab(array $settings): void {
        $provider = $this->getProvider();
        ?>
        <div class="levi-settings-section">
            <div class="levi-section-header">
                <h2><?php echo esc_html($this->tr('AI Provider Configuration', 'KI-Anbieter konfigurieren')); ?></h2>
                <p><?php echo esc_html($this->tr('Choose your AI provider and configure authentication.', 'Waehle deinen KI-Anbieter und richte die Authentifizierung ein.')); ?></p>
            </div>

            <!-- Provider Selection -->
            <div class="levi-form-card">
                <h3><?php echo esc_html($this->tr('Provider', 'Anbieter')); ?></h3>
                <div class="levi-provider-grid">
                    <?php foreach ($this->getProviderLabels() as $providerId => $providerLabel): 
                        $isActive = $provider === $providerId;
                        $providerIcons = [
                            'openrouter' => '🌐',
                            'openai' => '🤖',
                            'anthropic' => '🧠',
                        ];
                    ?>
                        <label class="levi-provider-option <?php echo $isActive ? 'levi-provider-active' : ''; ?>">
                            <input type="radio" name="<?php echo esc_attr($this->optionName); ?>[ai_provider]" 
                                   value="<?php echo esc_attr($providerId); ?>" 
                                   <?php checked($provider, $providerId); ?>
                                   class="levi-provider-input">
                            <span class="levi-provider-icon"><?php echo esc_html($providerIcons[$providerId] ?? '🔌'); ?></span>
                            <span class="levi-provider-name"><?php echo esc_html($providerLabel); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Authentication -->
            <div class="levi-form-card">
                <h3><?php echo esc_html($this->tr('Authentication', 'Authentifizierung')); ?></h3>
                
                <div class="levi-form-group">
                    <label class="levi-form-label"><?php echo esc_html($this->tr('Auth Method', 'Anmeldemethode')); ?></label>
                    <select name="<?php echo esc_attr($this->optionName); ?>[ai_auth_method]" class="levi-form-select">
                        <?php foreach ($this->getAuthMethodOptions($provider) as $id => $label): ?>
                            <option value="<?php echo esc_attr($id); ?>" <?php selected($this->getAuthMethod(), $id); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="levi-form-group">
                    <label class="levi-form-label">
                        <?php echo esc_html($this->tr('API Key', 'API-Schluessel')); ?>
                        <?php 
                        $keyField = match($provider) {
                            'openai' => 'openai_api_key',
                            'anthropic' => 'anthropic_api_key',
                            default => 'openrouter_api_key',
                        };
                        $hasKey = !empty($this->getApiKeyForProvider($provider));
                        ?>
                        <?php if ($hasKey): ?>
                            <span class="levi-badge levi-badge-success"><?php echo esc_html($this->tr('Configured', 'Konfiguriert')); ?></span>
                        <?php endif; ?>
                    </label>
                    <input type="password" 
                           name="<?php echo esc_attr($this->optionName); ?>[<?php echo esc_attr($keyField); ?>]" 
                           value="" 
                           class="levi-form-input"
                           placeholder="<?php echo $hasKey ? '••••••••••••••••••••' : 'sk-...'; ?>">
                    <p class="levi-form-help">
                        <?php if ($hasKey): ?>
                            <?php echo esc_html($this->tr('API key is saved. Enter a new key to replace it.', 'API-Schluessel ist gespeichert. Gib einen neuen ein, um ihn zu ersetzen.')); ?>
                        <?php else: ?>
                            <?php echo esc_html($this->tr('Enter your API key from the provider.', 'Bitte den API-Schluessel des Anbieters eintragen.')); ?>
                        <?php endif; ?>
                    </p>
                    <p class="levi-form-help levi-hint">
                        <?php echo esc_html($this->tr('Hint: The API key is stored in the database and never sent to third parties except the chosen provider (OpenRouter, OpenAI, or Anthropic). You can also set it via .env (OPEN_ROUTER_API_KEY, OPENAI_API_KEY, ANTHROPIC_API_KEY).', 'Hinweis: Der API-Schluessel wird in der Datenbank gespeichert und nur an den gewaehlten Anbieter (OpenRouter, OpenAI oder Anthropic) uebertragen. Alternativ per .env setzen (OPEN_ROUTER_API_KEY, OPENAI_API_KEY, ANTHROPIC_API_KEY).')); ?>
                    </p>
                </div>

                <div class="levi-form-actions-inline">
                    <button type="button" id="levi-test-connection" class="levi-btn levi-btn-secondary">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <?php echo esc_html($this->tr('Test Connection', 'Verbindung testen')); ?>
                    </button>
                    <span id="levi-test-result"></span>
                </div>
            </div>

            <!-- Model Selection -->
            <div class="levi-form-card">
                <h3><?php echo esc_html($this->tr('Model', 'Modell')); ?></h3>
                <div class="levi-form-group">
                    <?php 
                    $modelField = match($provider) {
                        'openai' => 'openai_model',
                        'anthropic' => 'anthropic_model',
                        default => 'openrouter_model',
                    };
                    $currentModel = $settings[$modelField] ?? '';
                    $models = $this->getAllowedModelsForProvider($provider);
                    ?>
                    <select name="<?php echo esc_attr($this->optionName); ?>[<?php echo esc_attr($modelField); ?>]" class="levi-form-select">
                        <?php foreach ($models as $modelId => $modelLabel): ?>
                            <option value="<?php echo esc_attr($modelId); ?>" <?php selected($currentModel, $modelId); ?>>
                                <?php echo esc_html($modelLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="levi-form-help">
                        <?php echo esc_html($this->tr('Select the AI model for chat responses.', 'Waehle das KI-Modell fuer Chat-Antworten.')); ?>
                    </p>
                    <p class="levi-form-help levi-hint">
                        <?php echo esc_html($this->tr('Hint: More capable models (e.g. GPT-4o, Claude) cost more per request. Start with a free or cheaper model for testing.', 'Hinweis: Leistungsstaerkere Modelle (z. B. GPT-4o, Claude) kosten mehr pro Anfrage. Fuer Tests zuerst ein guenstiges oder freies Modell nehmen.')); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderMemoryTab(array $settings): void {
        ?>
        <div class="levi-settings-section">
            <div class="levi-section-header">
                <h2><span class="dashicons dashicons-database"></span> <?php echo esc_html($this->tr('Memory Configuration', 'Memory konfigurieren')); ?></h2>
                <p><?php echo esc_html($this->tr('Configure how Levi remembers and retrieves information.', 'Steuere, wie Levi Informationen merkt und wiederfindet.')); ?></p>
            </div>

            <div class="levi-cards-grid levi-cards-2col">
                <!-- Vector Memory Settings -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Vector Memory', 'Vector Memory Einstellungen')); ?></h3>
                    <p class="levi-form-description">
                        <?php echo esc_html($this->tr('Control how many memories are retrieved for each query type.', 'Definiert, wie viele Eintraege pro Typ (Identity, Reference, Episodic) aus der Vector-Memory geladen werden.')); ?>
                    </p>

                    <div class="levi-form-row">
                        <div class="levi-form-group">
                            <label class="levi-form-label"><?php echo esc_html($this->tr('Identity Memories', 'Identity-Memories')); ?></label>
                            <input type="number" 
                                   name="<?php echo esc_attr($this->optionName); ?>[memory_identity_k]" 
                                   value="<?php echo esc_attr($settings['memory_identity_k']); ?>"
                                   min="1" max="20" class="levi-form-input levi-input-small">
                            <p class="levi-form-help levi-hint">
                                <?php echo esc_html($this->tr('Controls how many identity entries are loaded (persona, role, style). Higher values add more personal context but can dilute focus. Lower values are stricter and faster.', 'Steuert, wie viele Identity-Eintraege (Rolle, Persona, Stil) geladen werden. Hoehere Werte geben mehr persoenlichen Kontext, koennen aber den Fokus verwaessern. Niedrigere Werte sind strenger und schneller.')); ?>
                            </p>
                        </div>
                        <div class="levi-form-group">
                            <label class="levi-form-label"><?php echo esc_html($this->tr('Reference Memories', 'Reference-Memories')); ?></label>
                            <input type="number" 
                                   name="<?php echo esc_attr($this->optionName); ?>[memory_reference_k]" 
                                   value="<?php echo esc_attr($settings['memory_reference_k']); ?>"
                                   min="1" max="20" class="levi-form-input levi-input-small">
                            <p class="levi-form-help levi-hint">
                                <?php echo esc_html($this->tr('Controls how many knowledge/reference entries are loaded (docs, rules, facts). Higher values increase coverage but may add noise. Lower values keep answers tighter and more selective.', 'Steuert, wie viele Wissens-/Referenz-Eintraege (Dokus, Regeln, Fakten) geladen werden. Hoehere Werte erhoehen die Abdeckung, koennen aber mehr Rauschen bringen. Niedrigere Werte machen Antworten fokussierter und selektiver.')); ?>
                            </p>
                        </div>
                    </div>

                    <div class="levi-form-row">
                        <div class="levi-form-group">
                            <label class="levi-form-label"><?php echo esc_html($this->tr('Episodic Memories', 'Episodic-Memories')); ?></label>
                            <input type="number" 
                                   name="<?php echo esc_attr($this->optionName); ?>[memory_episodic_k]" 
                                   value="<?php echo esc_attr($settings['memory_episodic_k']); ?>"
                                   min="1" max="20" class="levi-form-input levi-input-small">
                            <p class="levi-form-help levi-hint">
                                <?php echo esc_html($this->tr('Controls how many recent episode entries are loaded (recent actions/outcomes). Higher values improve continuity across longer tasks, lower values reduce carry-over from old context.', 'Steuert, wie viele episodische Eintraege (juengste Aktionen/Ergebnisse) geladen werden. Hoehere Werte verbessern Kontinuitaet bei laengeren Aufgaben, niedrigere reduzieren Altkontext.')); ?>
                            </p>
                        </div>
                        <div class="levi-form-group">
                            <label class="levi-form-label"><?php echo esc_html($this->tr('Min Similarity', 'Min. Aehnlichkeit')); ?></label>
                            <input type="number" 
                                   name="<?php echo esc_attr($this->optionName); ?>[memory_min_similarity]" 
                                   value="<?php echo esc_attr($settings['memory_min_similarity']); ?>"
                                   min="0" max="1" step="0.01" class="levi-form-input levi-input-small">
                            <p class="levi-form-help levi-hint">
                                <?php echo esc_html($this->tr('Similarity threshold from 0 to 1. Higher values require closer semantic matches (fewer, more precise results). Lower values allow broader matches (more results, potentially less relevant).', 'Aehnlichkeitsschwelle von 0 bis 1. Hoehere Werte verlangen engere semantische Treffer (weniger, aber praeziser). Niedrigere Werte erlauben breitere Treffer (mehr Ergebnisse, evtl. weniger relevant).')); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Memory Actions -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Memory Management', 'Memory-Verwaltung')); ?></h3>
                    
                    <?php 
                    $loader = new \Levi\Agent\Memory\MemoryLoader();
                    $changes = $loader->checkForChanges();
                    $hasChanges = !empty($changes['identity']) || !empty($changes['reference']);
                    ?>

                    <?php if ($hasChanges): ?>
                        <div class="levi-notice levi-notice-warning">
                            <p><strong><?php echo esc_html($this->tr('Memory files have changed!', 'Memory-Dateien haben sich geaendert!')); ?></strong></p>
                            <?php if (!empty($changes['identity'])): ?>
                                <p><?php echo esc_html($this->tr('Identity:', 'Identity:')); ?> <?php echo esc_html(implode(', ', $changes['identity'])); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($changes['reference'])): ?>
                                <p><?php echo esc_html($this->tr('Reference:', 'Reference:')); ?> <?php echo esc_html(implode(', ', $changes['reference'])); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="levi-form-actions-inline">
                        <button type="button" id="levi-reload-memories" class="levi-btn levi-btn-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php echo esc_html($this->tr('Reload All Memories', 'Alle Memories neu laden')); ?>
                        </button>
                        <span id="levi-reload-result"></span>
                    </div>

                    <p class="levi-form-help">
                        <?php echo esc_html($this->tr('Reloads all .md files from identity/ and memories/ folders into the vector database.', 'Laedt alle .md-Dateien aus identity/ und memories/ in die Vector-Datenbank.')); ?>
                    </p>
                    <p class="levi-form-help levi-hint">
                        <?php echo esc_html($this->tr('Hint: After changing or adding .md files in the plugin’s identity/ or memories/ folders, click "Reload All Memories" so Levi can use the new content.', 'Hinweis: Nach Aenderungen in identity/ oder memories/ bitte "Alle Memories neu laden", damit Levi die Inhalte nutzt.')); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderSafetyTab(array $settings): void {
        ?>
        <div class="levi-settings-section">
            <div class="levi-section-header">
                <h2><?php echo esc_html($this->tr('Limits & Safety', 'Limits & Sicherheit')); ?></h2>
                <p><?php echo esc_html($this->tr('Configure safety measures and usage limits.', 'Konfiguriere Sicherheitsmechanismen und Nutzungsgrenzen.')); ?></p>
            </div>

            <div class="levi-cards-grid levi-cards-2col">
                <!-- Rate Limiting -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Rate Limiting', 'Rate-Limit')); ?></h3>
                    <div class="levi-form-group">
                        <label class="levi-form-label"><?php echo esc_html($this->tr('Requests per Hour', 'Anfragen pro Stunde')); ?></label>
                        <input type="number" 
                               name="<?php echo esc_attr($this->optionName); ?>[rate_limit]" 
                               value="<?php echo esc_attr($settings['rate_limit']); ?>"
                               min="1" max="1000" class="levi-form-input levi-input-small">
                        <p class="levi-form-help">
                            <?php echo esc_html($this->tr('Maximum API requests per user per hour to control costs.', 'Maximale API-Anfragen pro Benutzer und Stunde zur Kostenkontrolle.')); ?>
                        </p>
                        <p class="levi-form-help levi-hint">
                            <?php echo esc_html($this->tr('Hint: Lower values reduce API costs; increase if multiple editors use Levi frequently.', 'Hinweis: Niedrige Werte senken Kosten. Erhoehen, wenn mehrere Redakteure Levi oft nutzen.')); ?>
                        </p>
                    </div>
                </div>

                <!-- Confirmation Settings -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Confirmation Requirements', 'Bestaetigungsregeln')); ?></h3>
                    
                    <div class="levi-toggle-group">
                        <label class="levi-toggle">
                            <input type="checkbox" 
                                   name="<?php echo esc_attr($this->optionName); ?>[require_confirmation_destructive]" 
                                   value="1"
                                   <?php checked(!empty($settings['require_confirmation_destructive'])); ?>>
                            <span class="levi-toggle-slider"></span>
                            <span class="levi-toggle-label">
                                <?php echo esc_html($this->tr('Require confirmation for destructive actions', 'Bestaetigung fuer destruktive Aktionen erforderlich')); ?>
                            </span>
                        </label>
                    </div>

                    <div class="levi-toggle-group">
                        <label class="levi-toggle">
                            <input type="checkbox" 
                                   name="<?php echo esc_attr($this->optionName); ?>[force_exhaustive_reads]" 
                                   value="1"
                                   <?php checked(!empty($settings['force_exhaustive_reads'])); ?>>
                            <span class="levi-toggle-slider"></span>
                            <span class="levi-toggle-label">
                                <?php echo esc_html($this->tr('Force exhaustive content analysis', 'Gruendliche Inhaltsanalyse erzwingen')); ?>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Tool Iterations -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Tool Execution', 'Tool-Ausfuehrung')); ?></h3>
                    <div class="levi-form-group">
                        <label class="levi-form-label"><?php echo esc_html($this->tr('Max Tool Iterations', 'Max. Tool-Iterationen')); ?></label>
                        <input type="number" 
                               name="<?php echo esc_attr($this->optionName); ?>[max_tool_iterations]" 
                               value="<?php echo esc_attr($settings['max_tool_iterations']); ?>"
                               min="1" max="30" class="levi-form-input levi-input-small">
                        <p class="levi-form-help">
                            <?php echo esc_html($this->tr('Maximum consecutive tool rounds per request.', 'Maximale aufeinanderfolgende Tool-Runden pro Anfrage.')); ?>
                        </p>
                    </div>
                </div>

                <!-- History Context -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Conversation Context', 'Kontext-Verlauf')); ?></h3>
                    <div class="levi-form-group">
                        <label class="levi-form-label"><?php echo esc_html($this->tr('History Messages', 'Verlaufsnachrichten')); ?></label>
                        <input type="number" 
                               name="<?php echo esc_attr($this->optionName); ?>[history_context_limit]" 
                               value="<?php echo esc_attr($settings['history_context_limit']); ?>"
                               min="10" max="200" class="levi-form-input levi-input-small">
                        <p class="levi-form-help">
                            <?php echo esc_html($this->tr('Number of previous messages sent as context.', 'Anzahl vorheriger Nachrichten, die als Kontext mitgesendet werden.')); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderAdvancedTab(array $settings): void {
        ?>
        <div class="levi-settings-section">
            <div class="levi-section-header">
                <h2><?php _e('Advanced', 'levi-agent'); ?></h2>
                <p><?php _e('Advanced configuration and maintenance tools.', 'levi-agent'); ?></p>
            </div>

            <div class="levi-cards-grid">
                <!-- Database Maintenance -->
                <div class="levi-form-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-database"></span>
                        <h3><?php _e('Database', 'levi-agent'); ?></h3>
                    </div>
                    <p class="levi-form-description">
                        <?php _e('Repair and maintain database tables.', 'levi-agent'); ?>
                    </p>
                    <p class="levi-form-help levi-hint">
                        <?php _e('Hint: Use this if conversations or settings behave incorrectly. It recreates Levi’s database tables without deleting existing data.', 'levi-agent'); ?>
                    </p>
                    <div class="levi-form-actions-inline">
                        <button type="button" id="levi-repair-database" class="levi-btn levi-btn-secondary">
                            <span class="dashicons dashicons-hammer"></span>
                            <?php _e('Repair Tables', 'levi-agent'); ?>
                        </button>
                        <span id="levi-repair-result"></span>
                    </div>
                </div>

                <!-- System Info -->
                <div class="levi-form-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-info"></span>
                        <h3><?php _e('System Info', 'levi-agent'); ?></h3>
                    </div>
                    <div class="levi-card-content">
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php _e('Plugin Version', 'levi-agent'); ?></span>
                            <span class="levi-status-value"><?php echo esc_html(LEVI_AGENT_VERSION); ?></span>
                        </div>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php _e('WordPress', 'levi-agent'); ?></span>
                            <span class="levi-status-value"><?php echo esc_html(get_bloginfo('version')); ?></span>
                        </div>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php _e('PHP', 'levi-agent'); ?></span>
                            <span class="levi-status-value"><?php echo esc_html(PHP_VERSION); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // Helper Methods
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
                'api_key' => $this->tr('API Key', 'API-Schluessel'),
                'oauth' => $this->tr('Login via OpenRouter (OAuth, coming soon)', 'Login via OpenRouter (OAuth, bald verfuegbar)'),
            ],
            'anthropic' => [
                'api_key' => $this->tr('API Key (Pay-as-you-go)', 'API-Schluessel (Pay-as-you-go)'),
                'subscription_token' => $this->tr('Subscription Token (sk-ant-oat-*)', 'Subscription-Token (sk-ant-oat-*)'),
            ],
            default => [
                'api_key' => $this->tr('API Key', 'API-Schluessel'),
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

    public function ajaxRepairDatabase(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        require_once LEVI_AGENT_PLUGIN_DIR . 'src/Database/Tables.php';
        \Levi\Agent\Database\Tables::create();

        wp_send_json_success(['message' => __('Database tables created successfully.', 'levi-agent')]);
    }

    public function ajaxRunStateSnapshot(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $service = new \Levi\Agent\Memory\StateSnapshotService();
        $meta = $service->runManualSync();
        wp_send_json_success([
            'message' => __('Indexierung abgeschlossen.', 'levi-agent'),
            'meta' => $meta,
        ]);
    }
}
