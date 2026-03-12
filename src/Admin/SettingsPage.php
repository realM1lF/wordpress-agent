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
        add_action('wp_ajax_levi_clear_audit_log', [$this, 'ajaxClearAuditLog']);
        add_action('wp_ajax_levi_run_cron_task', [$this, 'ajaxRunCronTask']);
        add_action('wp_ajax_levi_toggle_cron_task', [$this, 'ajaxToggleCronTask']);
        add_action('wp_ajax_levi_delete_cron_task', [$this, 'ajaxDeleteCronTask']);
        add_action('wp_ajax_levi_toggle_cron_email', [$this, 'ajaxToggleCronEmail']);
        add_action('wp_dashboard_setup', [$this, 'registerDashboardWidget']);
    }

    public function addMenuPage(): void {
        add_menu_page(
            __('Levi AI Agent', 'levi-agent'),
            __('Levi AI', 'levi-agent'),
            'manage_options',
            $this->pageSlug,
            [$this, 'renderPage'],
            'dashicons-format-chat',
            81
        );
    }

    public function enqueueAssets(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'toplevel_page_' . $this->pageSlug) {
            return;
        }

        $settingsCss = LEVI_AGENT_PLUGIN_DIR . 'assets/css/settings-page.css';
        wp_enqueue_style(
            'levi-agent-settings',
            LEVI_AGENT_PLUGIN_URL . 'assets/css/settings-page.css',
            [],
            file_exists($settingsCss) ? (string) filemtime($settingsCss) : LEVI_AGENT_VERSION
        );

        $settingsJs = LEVI_AGENT_PLUGIN_DIR . 'assets/js/settings-page.js';
        wp_enqueue_script(
            'levi-agent-settings',
            LEVI_AGENT_PLUGIN_URL . 'assets/js/settings-page.js',
            ['jquery'],
            file_exists($settingsJs) ? (string) filemtime($settingsJs) : LEVI_AGENT_VERSION,
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
                'reloading' => $this->tr('Syncing…', 'Synchronisiere...'),
                'confirmDisconnect' => $this->tr('Disconnect from OpenRouter? You will need to reconnect or enter an API key manually.', 'Von OpenRouter trennen? Du musst dich danach erneut verbinden oder einen API-Key manuell eingeben.'),
                'fetchDocsConfirm' => $this->tr('Fetch latest docs from WooCommerce, WordPress & Elementor and sync? This may take several minutes.', 'Aktuelle Docs von WooCommerce, WordPress & Elementor abrufen und synchronisieren? Das kann einige Minuten dauern.'),
                'fetchingDocs' => $this->tr('Fetching docs & syncing…', 'Docs werden abgerufen & synchronisiert...'),
                'error' => $this->tr('Error', 'Fehler'),
                'done' => $this->tr('Done', 'Fertig'),
                'repairing' => $this->tr('Repairing…', 'Repariere...'),
                'saving' => $this->tr('Saving…', 'Speichere...'),
                'clearAuditConfirm' => $this->tr('Delete all audit log entries now?', 'Alle Audit-Log-Eintraege jetzt loeschen?'),
                'clearing' => $this->tr('Deleting…', 'Loesche...'),
                'cleared' => $this->tr('Audit log deleted.', 'Audit-Log geloescht.'),
                'status_not_run' => $this->tr('Not run yet', 'Noch nicht gelaufen'),
                'status_unchanged' => $this->tr('Unchanged', 'Unverändert'),
                'status_changed_stored' => $this->tr('Updated and stored', 'Aktualisiert und gespeichert'),
                'confirmDelete' => $this->tr('Delete this task permanently?', 'Diese Aufgabe endgültig löschen?'),
                'emailOn' => $this->tr('Email notifications on — click to disable', 'E-Mail aktiv — klicken zum Deaktivieren'),
                'emailOff' => $this->tr('Enable email notifications', 'E-Mail deaktiviert — klicken zum Aktivieren'),
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

    private function deriveThoroughness(int $historyLimit, array $tuningMode): string {
        if (isset($tuningMode['thoroughness']) && in_array($tuningMode['thoroughness'], ['low', 'balanced', 'high'], true)) {
            $expected = match ($tuningMode['thoroughness']) {
                'low' => 10, 'high' => 40, default => 20,
            };
            if ($historyLimit === $expected) {
                return $tuningMode['thoroughness'];
            }
        }
        return match (true) {
            $historyLimit === 10 => 'low',
            $historyLimit === 20 => 'balanced',
            $historyLimit === 40 => 'high',
            default => 'custom',
        };
    }

    private function deriveSpeed(int $maxIterations, array $tuningMode): string {
        if (isset($tuningMode['speed']) && in_array($tuningMode['speed'], ['fast', 'balanced', 'careful'], true)) {
            $expected = match ($tuningMode['speed']) {
                'fast' => 15, 'careful' => 30, default => 25,
            };
            if ($maxIterations === $expected) {
                return $tuningMode['speed'];
            }
        }
        return match (true) {
            $maxIterations <= 15 => 'fast',
            $maxIterations <= 25 => 'balanced',
            $maxIterations <= 30 => 'careful',
            default => 'custom',
        };
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
        $existing = get_option($this->optionName, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        // Start with defaults merged with existing saved values.
        // Only fields actually present in $input will be overwritten below.
        $sanitized = array_merge($this->getDefaults(), $existing);

        // --- Provider & Auth (ai-provider tab) ---
        if (array_key_exists('ai_provider', $input)) {
            $provider = sanitize_key($input['ai_provider']);
            $providers = $this->getProviderLabels();
            $sanitized['ai_provider'] = isset($providers[$provider]) ? $provider : 'openrouter';
        }

        if (array_key_exists('ai_auth_method', $input)) {
            $authMethod = sanitize_key($input['ai_auth_method']);
            $authOptions = $this->getAuthMethodOptions($sanitized['ai_provider']);
            $sanitized['ai_auth_method'] = isset($authOptions[$authMethod]) ? $authMethod : array_key_first($authOptions);
        }

        // API Keys — only overwrite if explicitly submitted
        $keyFields = ['openrouter_api_key', 'openai_api_key', 'anthropic_api_key'];
        foreach ($keyFields as $keyField) {
            if (isset($input[$keyField])) {
                $newKey = trim((string) $input[$keyField]);
                if ($newKey !== '') {
                    $sanitized[$keyField] = sanitize_text_field($newKey);
                }
            }
        }

        // Models — only overwrite if submitted
        $modelFields = [
            'openrouter' => 'openrouter_model',
            'openai' => 'openai_model',
            'anthropic' => 'anthropic_model',
        ];
        foreach ($modelFields as $modelProvider => $settingKey) {
            if (array_key_exists($settingKey, $input)) {
                $allowedModels = $this->getAllowedModelsForProvider($modelProvider);
                $candidate = sanitize_text_field($input[$settingKey]);
                $sanitized[$settingKey] = isset($allowedModels[$candidate]) ? $candidate : array_key_first($allowedModels);
            }
        }

        if (array_key_exists('openrouter_alt_model', $input)) {
            $allowedAltModels = $this->getAllowedAltModelsForProvider('openrouter');
            $altCandidate = sanitize_text_field($input['openrouter_alt_model']);
            $sanitized['openrouter_alt_model'] = isset($allowedAltModels[$altCandidate]) ? $altCandidate : array_key_first($allowedAltModels);
        }

        // Web search toggle (has hidden input value="0", so key present = tab was rendered)
        if (array_key_exists('web_search_enabled', $input)) {
            $sanitized['web_search_enabled'] = !empty($input['web_search_enabled']) ? 1 : 0;
        }

        if (array_key_exists('compact_model', $input)) {
            $sanitized['compact_model'] = sanitize_text_field((string) ($input['compact_model'] ?? ''));
        }
        if (array_key_exists('summary_model', $input)) {
            $sanitized['summary_model'] = sanitize_text_field((string) ($input['summary_model'] ?? ''));
        }

        // --- Numeric settings (safety / advanced tabs) ---
        if (array_key_exists('rate_limit', $input)) {
            $sanitized['rate_limit'] = max(1, min(1000, absint($input['rate_limit'])));
        }
        if (array_key_exists('max_tokens', $input)) {
            $sanitized['max_tokens'] = max(1, min(131072, absint($input['max_tokens'])));
        }
        if (array_key_exists('ai_timeout', $input)) {
            $sanitized['ai_timeout'] = max(1, absint($input['ai_timeout']));
        }
        if (array_key_exists('php_time_limit', $input)) {
            $sanitized['php_time_limit'] = max(0, absint($input['php_time_limit']));
        }
        if (array_key_exists('max_context_tokens', $input)) {
            $sanitized['max_context_tokens'] = max(1000, min(500000, absint($input['max_context_tokens'])));
        }

        // --- Behavior presets (safety tab / wizard) ---
        $thoroughness = sanitize_key($input['levi_thoroughness'] ?? '');
        $safetyMode = sanitize_key($input['levi_safety_mode'] ?? '');
        $speedMode = sanitize_key($input['levi_speed_mode'] ?? '');

        if (in_array($thoroughness, ['low', 'balanced', 'high'], true)) {
            $sanitized['history_context_limit'] = match ($thoroughness) {
                'high' => 40,
                'low' => 10,
                default => 20,
            };
        } elseif (array_key_exists('history_context_limit', $input)) {
            $sanitized['history_context_limit'] = max(10, min(200, absint($input['history_context_limit'])));
        }

        if (in_array($safetyMode, ['strict', 'standard'], true)) {
            $sanitized['allow_destructive'] = $safetyMode === 'strict' ? 0 : 1;
        }

        if (in_array($speedMode, ['fast', 'balanced', 'careful'], true)) {
            $sanitized['max_tool_iterations'] = match ($speedMode) {
                'fast' => 15,
                'careful' => 30,
                default => 25,
            };
        } elseif (array_key_exists('max_tool_iterations', $input)) {
            $sanitized['max_tool_iterations'] = max(4, min(30, absint($input['max_tool_iterations'])));
        }

        if ($thoroughness !== '' || $safetyMode !== '' || $speedMode !== '') {
            update_option('levi_setup_tuning_mode', [
                'thoroughness' => in_array($thoroughness, ['low', 'balanced', 'high'], true) ? $thoroughness : 'custom',
                'safety' => in_array($safetyMode, ['strict', 'standard'], true) ? $safetyMode : 'custom',
                'speed' => in_array($speedMode, ['fast', 'balanced', 'careful'], true) ? $speedMode : 'custom',
            ]);
        }

        // --- Tool profile ---
        if (array_key_exists('tool_profile', $input)) {
            $profileCandidate = sanitize_key($input['tool_profile']);
            $sanitized['tool_profile'] = in_array($profileCandidate, \Levi\Agent\AI\Tools\Registry::VALID_PROFILES, true)
                ? $profileCandidate
                : 'standard';
        }
        if (array_key_exists('allowed_plugin_slugs_manual', $input)) {
            $raw = sanitize_textarea_field((string) $input['allowed_plugin_slugs_manual']);
            $parts = preg_split('/[\s,;]+/u', $raw) ?: [];
            $clean = [];
            foreach ($parts as $part) {
                $slug = sanitize_title((string) $part);
                if ($slug !== '') {
                    $clean[] = $slug;
                }
            }
            $sanitized['allowed_plugin_slugs_manual'] = implode("\n", array_values(array_unique($clean)));
        }

        // --- Memory settings (memory tab) ---
        if (array_key_exists('memory_identity_k', $input)) {
            $sanitized['memory_identity_k'] = max(1, min(20, absint($input['memory_identity_k'])));
        }
        if (array_key_exists('memory_reference_k', $input)) {
            $sanitized['memory_reference_k'] = max(1, min(20, absint($input['memory_reference_k'])));
        }
        if (array_key_exists('memory_min_similarity', $input)) {
            $sanitized['memory_min_similarity'] = max(0.0, min(1.0, (float) $input['memory_min_similarity']));
        }

        // --- Safety tab booleans (hidden input ensures key is present when tab is rendered) ---
        if (array_key_exists('pii_redaction', $input)) {
            $sanitized['pii_redaction'] = !empty($input['pii_redaction']) ? 1 : 0;
        }
        if (array_key_exists('blocked_post_types', $input)) {
            $sanitized['blocked_post_types'] = sanitize_textarea_field($input['blocked_post_types']);
        }

        return $sanitized;
    }

    public function renderPage(): void {
        $settings = $this->getSettings();
        $provider = $this->getProvider();
        $apiKeyStatus = $this->getApiKeyForProvider($provider) ? 'connected' : 'disconnected';
        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        
        $tabs = [
            'general' => ['icon' => 'dashicons-dashboard', 'label' => $this->tr('Dashboard', 'Dashboard')],
            'ai-provider' => ['icon' => 'dashicons-cloud', 'label' => $this->tr('AI Provider', 'KI-Anbieter')],
            'memory' => ['icon' => 'dashicons-database', 'label' => $this->tr('Memory', 'Memory')],
            'safety' => ['icon' => 'dashicons-shield', 'label' => $this->tr('Limits & Safety', 'Limits & Sicherheit')],
            'advanced' => ['icon' => 'dashicons-admin-tools', 'label' => $this->tr('Advanced', 'Erweitert')],
            'cron-tasks' => ['icon' => 'dashicons-clock', 'label' => $this->tr('Scheduled Tasks', 'Geplante Aufgaben')],
        ];
        ?>
        <div class="levi-settings-wrap">
            <!-- Header -->
            <header class="levi-settings-header">
                <div class="levi-header-content">
                    <div class="levi-logo">
                        <span class="levi-logo-icon levi-logo-icon-avatar" aria-hidden="true">
                            <span class="levi-logo-avatar-frame">
                                <img
                                    src="<?php echo esc_url(LEVI_AGENT_PLUGIN_URL . 'assets/images/levi-avatar-icon.webp'); ?>"
                                    alt=""
                                    class="levi-logo-avatar"
                                    loading="lazy"
                                    decoding="async"
                                >
                            </span>
                        </span>
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
                        $tabUrl = add_query_arg(['page' => $this->pageSlug, 'tab' => $tabId], admin_url('admin.php'));
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
                <?php if ($activeTab === 'cron-tasks'): ?>
                    <?php $this->renderCronTasksTab(); ?>
                <?php else: ?>
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
                <?php endif; ?>
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
                            <span class="levi-status-value"><?php echo esc_html($this->getAltModelForProvider($provider)); ?></span>
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
                            <span class="levi-stat-value"><?php echo number_format($stats['identity_files'] ?? 0); ?></span>
                            <span class="levi-stat-label"><?php _e('Identity', 'levi-agent'); ?></span>
                            <?php
                            $identityNames = $stats['identity_file_names'] ?? [];
                            if (!empty($identityNames)):
                                ?>
                                <p class="levi-stat-files"><?php echo esc_html(implode(', ', $identityNames)); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="levi-stat-item">
                            <span class="levi-stat-value"><?php echo number_format($stats['reference_files'] ?? 0); ?></span>
                            <span class="levi-stat-label"><?php _e('Reference', 'levi-agent'); ?></span>
                            <?php
                            $referenceNames = $stats['reference_file_names'] ?? [];
                            if (!empty($referenceNames)):
                                ?>
                                <p class="levi-stat-files"><?php echo esc_html(implode(', ', $referenceNames)); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Plan & Wizard Card -->
                <div class="levi-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-admin-users"></span>
                        <h3><?php echo esc_html($this->tr('Plan & Setup', 'Plan & Einrichtung')); ?></h3>
                    </div>
                    <div class="levi-card-content">
                        <?php
                        $planTier = (string) get_option('levi_plan_tier', '');
                        $setupDone = (int) get_option('levi_setup_completed', 0) === 1;
                        ?>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php echo esc_html($this->tr('Active Plan', 'Aktiver Plan')); ?></span>
                            <span class="levi-status-value">
                                <?php if ($planTier === 'pro'): ?>
                                    <span class="levi-badge levi-badge-success">Pro</span>
                                <?php else: ?>
                                    <span class="levi-badge levi-badge-warning"><?php echo esc_html($this->tr('Not set', 'Nicht gesetzt')); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="levi-status-row">
                            <span class="levi-status-label"><?php echo esc_html($this->tr('Setup Wizard', 'Einrichtungsassistent')); ?></span>
                            <span class="levi-status-value">
                                <?php echo $setupDone ? esc_html($this->tr('Completed', 'Abgeschlossen')) : esc_html($this->tr('Pending', 'Ausstehend')); ?>
                            </span>
                        </div>
                    </div>
                    <div class="levi-card-footer">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=levi-agent-setup-wizard&step=1')); ?>" class="levi-btn levi-btn-small levi-btn-secondary">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php echo esc_html($this->tr('Run Setup Wizard', 'Einrichtungsassistent starten')); ?>
                        </a>
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
                <h2><?php echo esc_html($this->tr('OpenRouter Configuration', 'OpenRouter konfigurieren')); ?></h2>
                <p><?php echo esc_html($this->tr('Levi uses OpenRouter with Kimi K2.5. Configure your API key below.', 'Levi nutzt OpenRouter mit Kimi K2.5. Richte deinen API-Schluessel ein.')); ?></p>
            </div>

            <input type="hidden" name="<?php echo esc_attr($this->optionName); ?>[ai_provider]" value="openrouter">

            <!-- Authentication -->
            <div class="levi-form-card">
                <h3><?php echo esc_html($this->tr('Authentication', 'Authentifizierung')); ?></h3>
                
                <?php
                $oauth = new OpenRouterOAuth();
                $isOAuth = $oauth->isOAuthConnected();
                $hasKey = !empty($this->getApiKeyForProvider($provider));
                $keyField = match($provider) {
                    'openai' => 'openai_api_key',
                    'anthropic' => 'anthropic_api_key',
                    default => 'openrouter_api_key',
                };
                ?>

                <?php if ($isOAuth): ?>
                    <input type="hidden" name="<?php echo esc_attr($this->optionName); ?>[ai_auth_method]" value="oauth">
                    <div class="levi-oauth-connected">
                        <div class="levi-oauth-status">
                            <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 24px;"></span>
                            <div>
                                <strong><?php echo esc_html($this->tr('Connected via OpenRouter OAuth', 'Verbunden ueber OpenRouter OAuth')); ?></strong>
                                <p class="levi-form-help" style="margin-top: 4px;">
                                    <?php
                                    $connectedAt = $settings['oauth_connected_at'] ?? null;
                                    if ($connectedAt) {
                                        printf(
                                            esc_html($this->tr('Connected since %s', 'Verbunden seit %s')),
                                            esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $connectedAt))
                                        );
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                        <button type="button" id="levi-oauth-disconnect" class="levi-btn levi-btn-danger levi-btn-small" style="margin-top: 12px;">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php echo esc_html($this->tr('Disconnect', 'Verbindung trennen')); ?>
                        </button>
                    </div>

                <?php else: ?>
                    <?php
                    $oauthUrl = $oauth->getAuthUrl('settings');
                    ?>
                    <div class="levi-oauth-connect">
                        <p class="levi-form-description" style="margin-bottom: 8px;">
                            <?php echo esc_html($this->tr(
                                'Connect your OpenRouter account with one click. You will be redirected to OpenRouter to authorize Levi. Costs are billed to your own OpenRouter account.',
                                'Verbinde dein OpenRouter-Konto mit einem Klick. Du wirst zu OpenRouter weitergeleitet, um Levi zu autorisieren. Die Kosten werden ueber dein eigenes OpenRouter-Konto abgerechnet.'
                            )); ?>
                        </p>
                        <div class="levi-cost-hint" style="margin-bottom: 16px; padding: 10px 14px; background: rgba(124, 58, 237, 0.08); border-left: 4px solid var(--levi-accent, #7c3aed); border-radius: 4px; font-size: 13px; color: var(--levi-text-secondary, #94a3b8);">
                            <strong style="color: var(--levi-text-primary, #f1f5f9);"><?php echo esc_html($this->tr('Typical costs:', 'Typische Kosten:')); ?></strong>
                            <?php echo esc_html($this->tr(
                                'Approx. $0.01–0.05 per message for simple questions. Complex tasks like plugin development use significantly more tokens (approx. $0.10–0.50 per message), as Levi generates code, verifies it, and performs multiple tool calls. Default model Kimi K2.5: $0.60/$3.00 per 1M tokens — one of the most cost-effective options.',
                                'Ca. 0,01–0,05 $ pro Nachricht bei einfachen Fragen. Bei komplexen Aufgaben wie Plugin-Entwicklung werden deutlich mehr Tokens verbraucht (ca. 0,10–0,50 $ pro Nachricht), da Levi Code generiert, prueft und mehrere Tool-Aufrufe durchfuehrt. Standard-Modell Kimi K2.5: 0,60$/3,00$ pro 1M Tokens — eines der guenstigsten Modelle.'
                            )); ?>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(124, 58, 237, 0.15);">
                                <strong style="color: var(--levi-text-primary, #f1f5f9); font-size: 14px;"><?php echo esc_html($this->tr('$5 credit is plenty to get started!', '5$ Guthaben reichen fuer den Einstieg locker aus!')); ?></strong>
                            </div>
                        </div>
                        <a href="<?php echo esc_url($oauthUrl); ?>" class="levi-btn levi-btn-primary" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php echo esc_html($this->tr('Connect with OpenRouter', 'Mit OpenRouter verbinden')); ?>
                        </a>
                    </div>

                    <div class="levi-oauth-divider" style="margin: 20px 0; display: flex; align-items: center; gap: 12px;">
                        <hr style="flex: 1; border: none; border-top: 1px solid #ddd;">
                        <span style="color: #999; font-size: 13px;"><?php echo esc_html($this->tr('or enter manually', 'oder manuell eingeben')); ?></span>
                        <hr style="flex: 1; border: none; border-top: 1px solid #ddd;">
                    </div>

                    <input type="hidden" name="<?php echo esc_attr($this->optionName); ?>[ai_auth_method]" value="api_key">
                    <div class="levi-form-group">
                        <label class="levi-form-label">
                            <?php echo esc_html($this->tr('API Key', 'API-Schluessel')); ?>
                            <?php if ($hasKey && !$isOAuth): ?>
                                <span class="levi-badge levi-badge-success"><?php echo esc_html($this->tr('Configured', 'Konfiguriert')); ?></span>
                            <?php endif; ?>
                        </label>
                        <input type="password" 
                               name="<?php echo esc_attr($this->optionName); ?>[<?php echo esc_attr($keyField); ?>]" 
                               value="" 
                               class="levi-form-input"
                               placeholder="<?php echo $hasKey ? '••••••••••••••••••••' : 'sk-or-...'; ?>">
                        <p class="levi-form-help">
                            <?php if ($hasKey): ?>
                                <?php echo esc_html($this->tr('API key is saved. Enter a new key to replace it.', 'API-Schluessel ist gespeichert. Gib einen neuen ein, um ihn zu ersetzen.')); ?>
                            <?php else: ?>
                                <?php echo esc_html($this->tr(
                                    'Get your key at openrouter.ai/keys — or use the button above for easier setup.',
                                    'Hole deinen Key auf openrouter.ai/keys — oder nutze den Button oben fuer einfacheres Setup.'
                                )); ?>
                            <?php endif; ?>
                        </p>
                        <p class="levi-form-help levi-hint">
                            <?php echo esc_html($this->tr(
                                'Hint: The API key is stored in the database and only sent to OpenRouter. You can also set it via .env (OPEN_ROUTER_API_KEY).',
                                'Hinweis: Der API-Schluessel wird in der Datenbank gespeichert und nur an OpenRouter uebertragen. Alternativ per .env setzen (OPEN_ROUTER_API_KEY).'
                            )); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($_GET['oauth_success'])): ?>
                    <div class="levi-notice levi-notice-success" style="margin-top: 12px; padding: 10px 14px; background: #ecf7ed; border-left: 4px solid #46b450; border-radius: 4px;">
                        <?php echo esc_html($this->tr(
                            'Successfully connected with OpenRouter!',
                            'Erfolgreich mit OpenRouter verbunden!'
                        )); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($_GET['oauth_error'])): ?>
                    <div class="levi-notice levi-notice-error" style="margin-top: 12px; padding: 10px 14px; background: #fbeaea; border-left: 4px solid #dc3232; border-radius: 4px;">
                        <?php
                        $errorCode = sanitize_key($_GET['oauth_error']);
                        $errorMessages = [
                            'verifier_expired' => $this->tr(
                                'OAuth session expired. Please try again.',
                                'OAuth-Sitzung abgelaufen. Bitte erneut versuchen.'
                            ),
                            'exchange_failed' => $this->tr(
                                'Could not exchange authorization code. Please try again or use a manual API key.',
                                'Autorisierungscode konnte nicht eingeloest werden. Bitte erneut versuchen oder manuellen API-Key nutzen.'
                            ),
                        ];
                        echo esc_html($errorMessages[$errorCode] ?? $this->tr('OAuth error. Please try again.', 'OAuth-Fehler. Bitte erneut versuchen.'));
                        $details = isset($_GET['oauth_details']) ? sanitize_text_field(wp_unslash($_GET['oauth_details'])) : '';
                        if ($details !== '') {
                            echo ' <small>(' . esc_html($details) . ')</small>';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <div class="levi-form-actions-inline" style="margin-top: 16px;">
                    <button type="button" id="levi-test-connection" class="levi-btn levi-btn-secondary">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <?php echo esc_html($this->tr('Test Connection', 'Verbindung testen')); ?>
                    </button>
                    <span id="levi-test-result"></span>
                </div>
            </div>

            <!-- Model Selection -->
            <div class="levi-form-card">
                <h3><?php echo esc_html($this->tr('AI Model', 'KI-Modell')); ?></h3>
                <p class="levi-form-description">
                    <?php echo esc_html($this->tr('Select the AI model to use for all queries. This model will handle everything from simple questions to complex coding tasks.', 'Wähle das KI-Modell für alle Anfragen. Dieses Modell bearbeitet alles von einfachen Fragen bis zu komplexen Coding-Aufgaben.')); ?>
                </p>
                <div class="levi-form-group">
                    <?php 
                    $models = $this->getAllowedAltModelsForProvider('openrouter');
                    $currentModel = $settings['openrouter_alt_model'] ?? 'moonshotai/kimi-k2.5';
                    ?>
                    <select name="<?php echo esc_attr($this->optionName); ?>[openrouter_alt_model]" class="levi-form-select" id="levi-model-select">
                        <?php foreach ($models as $modelId => $modelLabel): ?>
                            <option value="<?php echo esc_attr($modelId); ?>" <?php selected($currentModel, $modelId); ?>>
                                <?php echo esc_html($modelLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Model Comparison Tables -->
                <div class="levi-model-info">
                    <h4><?php echo esc_html($this->tr('📊 Model Comparison', 'Modell-Vergleich')); ?></h4>
                    
                    <div class="levi-table-wrap">
                        <table class="levi-data-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html($this->tr('Model', 'Modell')); ?></th>
                                    <th><?php echo esc_html($this->tr('💰 Price (In/Out)', 'Preis (In/Out)')); ?></th>
                                    <th><?php echo esc_html($this->tr('🧠 Intelligence', 'Intelligenz')); ?></th>
                                    <th><?php echo esc_html($this->tr('📖 Context', 'Kontext')); ?></th>
                                    <th><?php echo esc_html($this->tr('⚡ Speed', 'Geschwindigkeit')); ?></th>
                                    <th><?php echo esc_html($this->tr('🎯 Best For', 'Beste für')); ?></th>
                                    <th><?php echo esc_html($this->tr('⭐ Value', 'Preis-Leistung')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="levi-row-featured">
                                    <td><span class="levi-model-name">Kimi K2.5</span></td>
                                    <td><span class="levi-price">$0.60 / $3.00</span></td>
                                    <td><span class="levi-score">87%</span> <span class="levi-badge">Sehr hoch</span></td>
                                    <td>262K</td>
                                    <td><?php echo esc_html($this->tr('Fast', 'Schnell')); ?></td>
                                    <td><?php echo esc_html($this->tr('Allrounder, Coding, Analysis', 'Allrounder, Coding, Analyse')); ?></td>
                                    <td><span class="levi-rating">⭐⭐⭐⭐⭐ KING</span></td>
                                </tr>
                                <tr>
                                    <td>GPT 5.3 Codex</td>
                                    <td><span class="levi-price">$1.75 / $14.00</span></td>
                                    <td><span class="levi-score">86%</span> <span class="levi-badge">Sehr hoch</span></td>
                                    <td>400K</td>
                                    <td><?php echo esc_html($this->tr('Medium', 'Mittel')); ?></td>
                                    <td><?php echo esc_html($this->tr('Max coding quality, large codebases', 'Max. Coding-Qualität, große Codebases')); ?></td>
                                    <td><span class="levi-rating">⭐⭐⭐⭐ Sehr gut</span></td>
                                </tr>
                                <tr>
                                    <td>Claude Opus 4.6</td>
                                    <td><span class="levi-price levi-price-high">$5.00 / $25.00</span></td>
                                    <td><span class="levi-score">88%</span> <span class="levi-badge">Höchst</span></td>
                                    <td>1M</td>
                                    <td><?php echo esc_html($this->tr('Slower', 'Langsamer')); ?></td>
                                    <td><?php echo esc_html($this->tr('Complex reasoning, research', 'Komplexes Reasoning, Research')); ?></td>
                                    <td><span class="levi-rating">⭐⭐⭐ Premium</span></td>
                                </tr>
                                <tr>
                                    <td>Claude 3.5 Sonnet</td>
                                    <td><span class="levi-price levi-price-high">$3.00 / $15.00</span></td>
                                    <td><span class="levi-score">89%</span> <span class="levi-badge">Höchst</span></td>
                                    <td>200K</td>
                                    <td><?php echo esc_html($this->tr('Fast', 'Schnell')); ?></td>
                                    <td><?php echo esc_html($this->tr('Backup option', 'Backup-Option')); ?></td>
                                    <td><span class="levi-rating">⭐⭐⭐ Okay</span></td>
                                </tr>
                                <tr>
                                    <td>GPT-4o Mini</td>
                                    <td><span class="levi-price levi-price-low">$0.15 / $0.60</span></td>
                                    <td><span class="levi-score">82%</span> <span class="levi-badge">Hoch</span></td>
                                    <td>128K</td>
                                    <td><?php echo esc_html($this->tr('Very fast', 'Sehr schnell')); ?></td>
                                    <td><?php echo esc_html($this->tr('Budget option, simple tasks', 'Budget-Option, einfache Aufgaben')); ?></td>
                                    <td><span class="levi-rating">⭐⭐⭐⭐ Gut</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h4><?php echo esc_html($this->tr('🎯 Quick Selection Guide', 'Schnellauswahl')); ?></h4>
                    <div class="levi-table-wrap">
                        <table class="levi-data-table levi-guide-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html($this->tr('If you want...', 'Wenn du willst...')); ?></th>
                                    <th><?php echo esc_html($this->tr('Choose...', 'Wähle...')); ?></th>
                                    <th><?php echo esc_html($this->tr('Why?', 'Warum?')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="levi-row-featured">
                                    <td><?php echo esc_html($this->tr('The best value for money', 'Das Beste fürs Geld')); ?></td>
                                    <td><span class="levi-model-name">Kimi K2.5</span></td>
                                    <td><?php echo esc_html($this->tr('9x cheaper than Opus, almost equally smart', '9x günstiger als Opus, fast gleich smart')); ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo esc_html($this->tr('Huge codebases (400K+ context)', 'Riesige Codebases (400K+ Kontext)')); ?></td>
                                    <td>GPT 5.3 Codex</td>
                                    <td><?php echo esc_html($this->tr('Largest context, best coding worldwide', 'Größter Kontext, bestes Coding weltweit')); ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo esc_html($this->tr('Extremely complex analysis', 'Extrem komplexe Analysen')); ?></td>
                                    <td>Claude Opus 4.6</td>
                                    <td><?php echo esc_html($this->tr('Highest intelligence, 1M context', 'Höchste Intelligenz, 1M Kontext')); ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo esc_html($this->tr('Watch your budget', 'Aufs Geld achten')); ?></td>
                                    <td>GPT-4o Mini</td>
                                    <td><?php echo esc_html($this->tr('4x cheaper than Kimi, still good', '4x günstiger als Kimi, trotzdem gut')); ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo esc_html($this->tr('Prefer Claude', 'Claude bevorzugen')); ?></td>
                                    <td>Claude 3.5 Sonnet</td>
                                    <td><?php echo esc_html($this->tr('Good balance, but 5x more expensive than Kimi', 'Gute Balance, aber 5x teurer als Kimi')); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
                    <h3><?php echo esc_html($this->tr('Memory Files', 'Memory-Dateien')); ?></h3>
                    
                    <?php 
                    $loader = new \Levi\Agent\Memory\MemoryLoader();
                    $changes = $loader->checkForChanges();
                    $hasIdentityChanges = !empty($changes['identity']);
                    $hasReferenceChanges = !empty($changes['reference']);
                    $syncMeta = \Levi\Agent\Memory\StateSnapshotService::getLastMemorySyncMeta();
                    $fetchMeta = \Levi\Agent\Memory\DocsFetcher::getLastFetchMeta();
                    ?>

                    <?php if ($hasIdentityChanges || $hasReferenceChanges): ?>
                        <div id="levi-memory-changes-warning" class="levi-notice levi-notice-warning">
                            <p><strong><?php echo esc_html($this->tr('Files have changed and need syncing:', 'Dateien haben sich geaendert und muessen synchronisiert werden:')); ?></strong></p>
                            <?php if ($hasIdentityChanges): ?>
                                <p><?php echo esc_html($this->tr('Identity:', 'Identity:')); ?> <?php echo esc_html(implode(', ', $changes['identity'])); ?></p>
                            <?php endif; ?>
                            <?php if ($hasReferenceChanges): ?>
                                <p><?php echo esc_html($this->tr('Reference:', 'Referenz:')); ?> <?php echo esc_html(implode(', ', $changes['reference'])); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($syncMeta)): ?>
                        <div class="levi-notice levi-notice-<?php echo ($syncMeta['status'] ?? '') === 'synced' ? 'success' : (($syncMeta['status'] ?? '') === 'unchanged' ? 'info' : 'warning'); ?>" style="margin-bottom: 1rem;">
                            <p><strong><?php echo esc_html($this->tr('Last sync:', 'Letzter Sync:')); ?></strong> <?php echo esc_html($syncMeta['synced_at'] ?? '—'); ?> — <?php echo esc_html($syncMeta['status'] ?? 'unknown'); ?></p>
                            <?php if (!empty($syncMeta['errors'])): ?>
                                <p class="levi-hint"><?php echo esc_html(implode(', ', $syncMeta['errors'])); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="levi-form-actions-inline" style="gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" id="levi-reload-memories" class="levi-btn levi-btn-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php echo esc_html($this->tr('Sync Changed Files', 'Geaenderte Dateien syncen')); ?>
                        </button>
                        <button type="button" id="levi-fetch-docs" class="levi-btn levi-btn-secondary">
                            <span class="dashicons dashicons-download"></span>
                            <?php echo esc_html($this->tr('Fetch Docs & Sync All', 'Docs abrufen & alles syncen')); ?>
                        </button>
                        <span id="levi-reload-result"></span>
                    </div>

                    <p class="levi-form-help">
                        <?php echo esc_html($this->tr(
                            'Sync: Re-embeds changed identity & reference files. Fetch: Downloads latest docs from WooCommerce, WordPress & Elementor, then syncs.',
                            'Sync: Bettet geaenderte Identity- & Referenz-Dateien ein. Fetch: Laedt aktuelle Docs von WooCommerce, WordPress & Elementor herunter und synchronisiert.'
                        )); ?>
                    </p>
                    <?php if (!empty($fetchMeta['fetched_at'])): ?>
                        <p class="levi-form-help levi-hint">
                            <?php echo esc_html($this->tr('Last docs fetch:', 'Letzter Docs-Abruf:')); ?> <?php echo esc_html($fetchMeta['fetched_at']); ?> (<?php echo esc_html($fetchMeta['status'] ?? 'unknown'); ?>)
                        </p>
                    <?php else: ?>
                        <p class="levi-form-help levi-hint">
                            <?php echo esc_html($this->tr('Docs are fetched automatically daily at 04:00. Click "Fetch Docs" to trigger manually.', 'Docs werden taeglich um 04:00 Uhr automatisch abgerufen. Klicke "Docs abrufen" fuer manuellen Abruf.')); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- State Snapshot Card -->
                <div class="levi-form-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-backup"></span>
                        <h3><?php echo esc_html($this->tr('WordPress Snapshot', 'WordPress-Snapshot')); ?></h3>
                    </div>
                    <div class="levi-card-content">
                        <?php 
                        $snapshotMeta = \Levi\Agent\Memory\StateSnapshotService::getLastMeta();
                        $snapshotStatus = (string) ($snapshotMeta['status'] ?? 'not_run');
                        $snapshotCapturedAt = (string) ($snapshotMeta['captured_at'] ?? '-');
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

    private function renderSafetyTab(array $settings): void {
        $auditRows = $this->getAuditLogRows(20);
        ?>
        <div class="levi-settings-section">
            <div class="levi-section-header">
                <h2><?php echo esc_html($this->tr('Limits & Safety', 'Limits & Sicherheit')); ?></h2>
                <p><?php echo esc_html($this->tr('Configure safety measures and usage limits.', 'Konfiguriere Sicherheitsmechanismen und Nutzungsgrenzen.')); ?></p>
            </div>

            <!-- Tool Profile (full-width) -->
            <div class="levi-form-card" style="margin-bottom: 1.5rem;">
                <h3><?php echo esc_html($this->tr('Tool Profile', 'Tool-Profil')); ?></h3>
                <p class="levi-form-description">
                    <?php echo esc_html($this->tr(
                        'Controls which tools Levi can use. Choose a profile that matches your comfort level.',
                        'Steuert, welche Tools Levi nutzen darf. Waehle ein Profil passend zu deinem Erfahrungslevel.'
                    )); ?>
                </p>
                <div class="levi-form-group">
                    <?php
                    $profiles = \Levi\Agent\AI\Tools\Registry::getProfileLabels();
                    $currentProfile = $settings['tool_profile'] ?? 'standard';
                    foreach ($profiles as $profileKey => $profileData): ?>
                        <label class="levi-radio-card <?php echo $currentProfile === $profileKey ? 'levi-radio-card-active' : ''; ?>" style="display:flex; align-items:flex-start; gap:0.75rem; padding:0.75rem 1rem; border:1px solid <?php echo $currentProfile === $profileKey ? '#7c3aed' : '#374151'; ?>; border-radius:8px; margin-bottom:0.5rem; cursor:pointer; background:<?php echo $currentProfile === $profileKey ? 'rgba(124,58,237,0.08)' : 'transparent'; ?>;">
                            <input type="radio"
                                   name="<?php echo esc_attr($this->optionName); ?>[tool_profile]"
                                   value="<?php echo esc_attr($profileKey); ?>"
                                   <?php checked($currentProfile, $profileKey); ?>
                                   style="margin-top:3px;">
                            <div>
                                <strong><?php echo esc_html($profileData['label']); ?></strong>
                                <p class="levi-form-help" style="margin:0.25rem 0 0;"><?php echo esc_html($profileData['description']); ?></p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="levi-form-help levi-hint">
                    <?php echo esc_html($this->tr(
                        'Hint: "Standard" is recommended for most users. Switch to "Full" only if you need PHP code execution or HTTP fetching.',
                        'Hinweis: "Standard" wird fuer die meisten Nutzer empfohlen. Wechsle nur zu "Voll", wenn du PHP-Code-Ausfuehrung oder HTTP-Fetch brauchst.'
                    )); ?>
                </p>
            </div>

            <!-- Plugin Ownership Allowlist -->
            <div class="levi-form-card" style="margin-bottom: 1.5rem;">
                <h3><?php echo esc_html($this->tr('Plugin Ownership Guard', 'Plugin-Ownership-Schutz')); ?></h3>
                <p class="levi-form-description">
                    <?php echo esc_html($this->tr(
                        'Levi may only edit plugin files for plugins it created itself or that you explicitly allow here.',
                        'Levi darf Plugin-Dateien nur bei Plugins bearbeiten, die es selbst erstellt hat oder die du hier explizit freigibst.'
                    )); ?>
                </p>
                <div class="levi-form-group">
                    <label class="levi-form-label"><?php echo esc_html($this->tr('Manual allowed plugin slugs', 'Manuell erlaubte Plugin-Slugs')); ?></label>
                    <textarea name="<?php echo esc_attr($this->optionName); ?>[allowed_plugin_slugs_manual]"
                              rows="3" class="levi-form-input" style="font-family:monospace; font-size:13px;"
                              placeholder="figur-musik-rabatt&#10;mein-eigenes-plugin"
                    ><?php echo esc_textarea($settings['allowed_plugin_slugs_manual'] ?? ''); ?></textarea>
                    <p class="levi-form-help">
                        <?php echo esc_html($this->tr(
                            'One slug per line. Use this only for your own trusted plugins that were not created by Levi in this installation.',
                            'Ein Slug pro Zeile. Nur fuer eigene vertrauenswuerdige Plugins nutzen, die Levi in dieser Installation nicht selbst erstellt hat.'
                        )); ?>
                    </p>
                    <p class="levi-form-help levi-hint">
                        <?php echo esc_html($this->tr(
                            'Third-party plugins remain protected. Example: "woocommerce" should usually stay blocked.',
                            'Drittanbieter-Plugins bleiben geschuetzt. Beispiel: "woocommerce" sollte normalerweise blockiert bleiben.'
                        )); ?>
                    </p>
                </div>
            </div>

            <!-- Data Protection (full-width) -->
            <div class="levi-form-card" style="margin-bottom: 1.5rem;">
                <h3>🛡️ <?php echo esc_html($this->tr('Data Protection', 'Datenschutz')); ?></h3>
                <p class="levi-form-description">
                    <?php echo esc_html($this->tr(
                        'PII redaction masks personal data (emails, phone numbers, IBANs) before sending to the AI provider. Blocked post types and meta keys prevent Levi from reading sensitive form submissions and payment data.',
                        'PII-Redaction maskiert personenbezogene Daten (E-Mails, Telefonnummern, IBANs) bevor sie an den KI-Anbieter gesendet werden. Blockierte Post-Types und Meta-Keys verhindern, dass Levi sensible Formulareingaben und Zahlungsdaten liest.'
                    )); ?>
                </p>

                <div class="levi-toggle-group" style="margin-bottom: 1rem;">
                    <label class="levi-toggle">
                        <input type="checkbox"
                               name="<?php echo esc_attr($this->optionName); ?>[pii_redaction]"
                               value="1"
                               <?php checked(!isset($settings['pii_redaction']) || !empty($settings['pii_redaction'])); ?>>
                        <span class="levi-toggle-slider"></span>
                        <span class="levi-toggle-label">
                            <?php echo esc_html($this->tr('Enable PII redaction & post type / meta key blocking', 'PII-Redaction & Post-Type- / Meta-Key-Blocking aktivieren')); ?>
                        </span>
                    </label>
                </div>

                <div class="levi-form-group">
                    <label class="levi-form-label"><?php echo esc_html($this->tr('Additional blocked post types', 'Zusaetzlich blockierte Post-Types')); ?></label>
                    <textarea name="<?php echo esc_attr($this->optionName); ?>[blocked_post_types]"
                              rows="3" class="levi-form-input" style="font-family:monospace; font-size:13px;"
                              placeholder="custom_form_entry&#10;support_ticket"
                    ><?php echo esc_textarea($settings['blocked_post_types'] ?? ''); ?></textarea>
                    <p class="levi-form-help">
                        <?php echo esc_html($this->tr(
                            'One post type per line. These are blocked in addition to the built-in defaults (WPForms, Flamingo, Ninja Forms, EDD, etc.).',
                            'Ein Post-Type pro Zeile. Diese werden zusaetzlich zu den eingebauten Defaults blockiert (WPForms, Flamingo, Ninja Forms, EDD usw.).'
                        )); ?>
                    </p>
                </div>
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

                <!-- Levi Behavior Settings -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Levi Behavior', 'Levi-Verhalten')); ?></h3>
                    <p class="levi-form-help" style="margin-bottom: 1rem;">
                        <?php echo esc_html($this->tr(
                            'These settings control how Levi works. Choose a preset or enter a custom value.',
                            'Diese Einstellungen steuern wie Levi arbeitet. Waehle eine Voreinstellung oder gib einen eigenen Wert ein.'
                        )); ?>
                    </p>

                    <?php
                    $tuningMode = get_option('levi_setup_tuning_mode', []);
                    if (!is_array($tuningMode)) { $tuningMode = []; }
                    $curHistoryLimit = (int) ($settings['history_context_limit'] ?? 20);
                    $curThoroughness = $this->deriveThoroughness($curHistoryLimit, $tuningMode);
                    $curSafety = empty($settings['allow_destructive']) ? 'strict' : 'standard';
                    $curMaxIterations = (int) ($settings['max_tool_iterations'] ?? 25);
                    $curSpeed = $this->deriveSpeed($curMaxIterations, $tuningMode);
                    ?>

                    <div class="levi-form-group">
                        <label class="levi-form-label" for="levi_thoroughness"><?php echo esc_html($this->tr('How much chat history should Levi consider?', 'Wie viel Chat-Verlauf soll Levi beruecksichtigen?')); ?></label>
                        <div style="display: flex; gap: 0.75rem; align-items: center;">
                            <select id="levi_thoroughness" name="<?php echo esc_attr($this->optionName); ?>[levi_thoroughness]" class="levi-form-select" style="flex: 1;">
                                <option value="low" <?php selected($curThoroughness, 'low'); ?>><?php echo esc_html($this->tr('Few (10 messages)', 'Wenig (10 Nachrichten)')); ?></option>
                                <option value="balanced" <?php selected($curThoroughness, 'balanced'); ?>><?php echo esc_html($this->tr('Medium (20 messages, recommended)', 'Mittel (20 Nachrichten, empfohlen)')); ?></option>
                                <option value="high" <?php selected($curThoroughness, 'high'); ?>><?php echo esc_html($this->tr('Many (40 messages)', 'Viel (40 Nachrichten)')); ?></option>
                                <option value="custom" <?php selected($curThoroughness, 'custom'); ?>><?php echo esc_html($this->tr('Custom', 'Benutzerdefiniert')); ?></option>
                            </select>
                            <input type="number" id="levi_history_value"
                                   name="<?php echo esc_attr($this->optionName); ?>[history_context_limit]"
                                   value="<?php echo esc_attr($curHistoryLimit); ?>"
                                   min="10" max="200" class="levi-form-input levi-input-small" style="width: 80px;">
                        </div>
                        <p class="levi-form-help"><?php echo esc_html($this->tr(
                            'Levi loads the last X messages from your chat as context. More = better memory in long conversations, but slower responses.',
                            'Levi laedt die letzten X Nachrichten aus eurem Chat als Kontext. Mehr = besseres Gedaechtnis in langen Gespraechen, aber langsamere Antworten.'
                        )); ?></p>
                    </div>

                    <div class="levi-form-group">
                        <label class="levi-form-label" for="levi_safety_mode"><?php echo esc_html($this->tr('Allow destructive actions?', 'Destruktive Aktionen erlauben?')); ?></label>
                        <select id="levi_safety_mode" name="<?php echo esc_attr($this->optionName); ?>[levi_safety_mode]" class="levi-form-select">
                            <option value="strict" <?php selected($curSafety, 'strict'); ?>><?php echo esc_html($this->tr('No — Levi cannot delete or remove anything (safer)', 'Nein — Levi darf nichts loeschen oder entfernen (sicherer)')); ?></option>
                            <option value="standard" <?php selected($curSafety, 'standard'); ?>><?php echo esc_html($this->tr('Yes — Levi may delete posts, users, etc.', 'Ja — Levi darf Beitraege, Benutzer usw. loeschen')); ?></option>
                        </select>
                        <p class="levi-form-help"><?php echo esc_html($this->tr(
                            'When disabled, Levi will refuse destructive actions like deleting posts or managing users. He will inform you that this setting needs to be changed.',
                            'Wenn deaktiviert, verweigert Levi destruktive Aktionen wie Beitraege loeschen oder Benutzer verwalten. Er weist dich darauf hin, dass diese Einstellung geaendert werden muss.'
                        )); ?></p>
                    </div>

                    <div class="levi-form-group">
                        <label class="levi-form-label" for="levi_speed_mode"><?php echo esc_html($this->tr('How many work steps per request?', 'Wie viele Arbeitsschritte pro Anfrage?')); ?></label>
                        <div style="display: flex; gap: 0.75rem; align-items: center;">
                            <select id="levi_speed_mode" name="<?php echo esc_attr($this->optionName); ?>[levi_speed_mode]" class="levi-form-select" style="flex: 1;">
                                <option value="fast" <?php selected($curSpeed, 'fast'); ?>><?php echo esc_html($this->tr('Few (15 steps)', 'Wenig (15 Schritte)')); ?></option>
                                <option value="balanced" <?php selected($curSpeed, 'balanced'); ?>><?php echo esc_html($this->tr('Standard (25 steps, recommended)', 'Standard (25 Schritte, empfohlen)')); ?></option>
                                <option value="careful" <?php selected($curSpeed, 'careful'); ?>><?php echo esc_html($this->tr('Many (30 steps)', 'Viel (30 Schritte)')); ?></option>
                                <option value="custom" <?php selected($curSpeed, 'custom'); ?>><?php echo esc_html($this->tr('Custom', 'Benutzerdefiniert')); ?></option>
                            </select>
                            <input type="number" id="levi_iterations_value"
                                   name="<?php echo esc_attr($this->optionName); ?>[max_tool_iterations]"
                                   value="<?php echo esc_attr($curMaxIterations); ?>"
                                   min="4" max="30" class="levi-form-input levi-input-small" style="width: 80px;">
                        </div>
                        <p class="levi-form-help"><?php echo esc_html($this->tr(
                            'Each tool action (read page, write plugin, etc.) counts as one step. Complex tasks need more steps. If too few, Levi stops and asks you to continue.',
                            'Jede Tool-Aktion (Seite lesen, Plugin schreiben, etc.) zaehlt als ein Schritt. Komplexe Aufgaben brauchen mehr Schritte. Bei zu wenigen bricht Levi ab und bittet dich, fortzufahren.'
                        )); ?></p>
                    </div>

                    <script>
                    (function() {
                        var thoroughnessMap = {low: 30, balanced: 50, high: 80};
                        var speedMap = {fast: 15, balanced: 25, careful: 30};

                        var tSelect = document.getElementById('levi_thoroughness');
                        var tInput = document.getElementById('levi_history_value');
                        var sSelect = document.getElementById('levi_speed_mode');
                        var sInput = document.getElementById('levi_iterations_value');

                        if (tSelect && tInput) {
                            tSelect.addEventListener('change', function() {
                                if (thoroughnessMap[this.value] !== undefined) {
                                    tInput.value = thoroughnessMap[this.value];
                                }
                            });
                            tInput.addEventListener('input', function() {
                                var val = parseInt(this.value, 10);
                                var matched = false;
                                for (var k in thoroughnessMap) {
                                    if (thoroughnessMap[k] === val) { tSelect.value = k; matched = true; break; }
                                }
                                if (!matched) { tSelect.value = 'custom'; }
                            });
                        }
                        if (sSelect && sInput) {
                            sSelect.addEventListener('change', function() {
                                if (speedMap[this.value] !== undefined) {
                                    sInput.value = speedMap[this.value];
                                }
                            });
                            sInput.addEventListener('input', function() {
                                var val = parseInt(this.value, 10);
                                var matched = false;
                                for (var k in speedMap) {
                                    if (speedMap[k] === val) { sSelect.value = k; matched = true; break; }
                                }
                                if (!matched) { sSelect.value = 'custom'; }
                            });
                        }
                    })();
                    </script>
                </div>

                <!-- AI Response Settings -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('AI Response', 'KI-Antwort')); ?></h3>
                    <div class="levi-form-row">
                        <div class="levi-form-group">
                            <label class="levi-form-label"><?php echo esc_html($this->tr('Max Tokens', 'Max. Tokens')); ?></label>
                            <input type="number" 
                                   name="<?php echo esc_attr($this->optionName); ?>[max_tokens]" 
                                   value="<?php echo esc_attr($settings['max_tokens']); ?>"
                                   min="1" max="131072" class="levi-form-input levi-input-small">
                            <p class="levi-form-help">
                                <?php echo esc_html($this->tr('Maximum tokens per AI response (max 131072). The AI only uses what it needs, but the provider reserves this space.', 'Maximale Tokens pro KI-Antwort (max 131072). Die KI nutzt nur so viel wie noetig, aber der Provider reserviert diesen Platz.')); ?>
                            </p>
                        </div>
                        <div class="levi-form-group">
                            <label class="levi-form-label"><?php echo esc_html($this->tr('AI Timeout (seconds)', 'KI-Timeout (Sekunden)')); ?></label>
                            <input type="number" 
                                   name="<?php echo esc_attr($this->optionName); ?>[ai_timeout]" 
                                   value="<?php echo esc_attr($settings['ai_timeout']); ?>"
                                   min="1" class="levi-form-input levi-input-small">
                            <p class="levi-form-help">
                                <?php echo esc_html($this->tr('How long to wait for the AI provider to respond.', 'Wie lange auf Antwort des KI-Anbieters gewartet wird.')); ?>
                            </p>
                        </div>
                    </div>
                    <div class="levi-form-group">
                        <label class="levi-form-label"><?php echo esc_html($this->tr('PHP Time Limit (seconds)', 'PHP-Zeitlimit (Sekunden)')); ?></label>
                        <input type="number" 
                               name="<?php echo esc_attr($this->optionName); ?>[php_time_limit]" 
                               value="<?php echo esc_attr($settings['php_time_limit']); ?>"
                               min="0" class="levi-form-input levi-input-small">
                        <p class="levi-form-help">
                            <?php echo esc_html($this->tr('PHP set_time_limit() for chat requests. 0 = unlimited. Increase for complex multi-step tasks.', 'PHP set_time_limit() fuer Chat-Anfragen. 0 = unbegrenzt. Bei komplexen mehrstufigen Aufgaben erhoehen.')); ?>
                        </p>
                    </div>
                </div>

                <!-- Context Budget -->
                <div class="levi-form-card">
                    <h3><?php echo esc_html($this->tr('Conversation Context', 'Kontext-Verlauf')); ?></h3>
                    <div class="levi-form-group">
                        <label class="levi-form-label"><?php echo esc_html($this->tr('Max Context Tokens', 'Max. Kontext-Tokens')); ?></label>
                        <input type="number" 
                               name="<?php echo esc_attr($this->optionName); ?>[max_context_tokens]" 
                               value="<?php echo esc_attr($settings['max_context_tokens']); ?>"
                               min="1000" max="500000" step="1000" class="levi-form-input levi-input-small">
                        <p class="levi-form-help">
                            <?php echo esc_html($this->tr('Maximum input tokens sent to the AI. Older messages are trimmed if exceeded. Older messages are summarized automatically instead of being lost.', 'Maximale Input-Tokens an die KI. Aeltere Nachrichten werden gekuerzt wenn ueberschritten. Aeltere Nachrichten werden dabei automatisch zusammengefasst statt verworfen.')); ?>
                        </p>
                    </div>

                    <div class="levi-form-group">
                        <label class="levi-form-label"><?php echo esc_html($this->tr('Compaction Model (optional)', 'Compaction-Modell (optional)')); ?></label>
                        <input type="text"
                               name="<?php echo esc_attr($this->optionName); ?>[compact_model]"
                               value="<?php echo esc_attr($settings['compact_model'] ?? $settings['summary_model'] ?? ''); ?>"
                               placeholder="google/gemini-2.5-flash-lite"
                               class="levi-form-input">
                        <p class="levi-form-help">
                            <?php echo esc_html($this->tr('Cheap model for compacting older messages when context limit is reached. Falls back to primary model on failure. Leave empty for provider default.', 'Guenstiges Modell fuer die Komprimierung aelterer Nachrichten bei Kontext-Ueberschreitung. Bei Fehler wird automatisch das Hauptmodell verwendet. Leer lassen fuer Provider-Standard.')); ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="levi-form-card" style="margin-top: 1.5rem;">
                <h3><?php echo esc_html($this->tr('Audit Log (Tool Executions)', 'Audit-Log (Tool-Ausfuehrungen)')); ?></h3>
                <p class="levi-form-description">
                    <?php echo esc_html($this->tr(
                        'Shows recent tool executions for security traceability. Entries older than 7 days are removed automatically.',
                        'Zeigt aktuelle Tool-Ausfuehrungen zur Nachvollziehbarkeit. Eintraege aelter als 7 Tage werden automatisch entfernt.'
                    )); ?>
                </p>
                <div class="levi-form-actions-inline" style="margin-bottom: 0.75rem;">
                    <button type="button" id="levi-clear-audit-log" class="levi-btn levi-btn-secondary">
                        <span class="dashicons dashicons-trash"></span>
                        <?php echo esc_html($this->tr('Delete Audit Log', 'Audit-Log loeschen')); ?>
                    </button>
                    <span id="levi-audit-clear-result"></span>
                </div>

                <?php if (empty($auditRows)): ?>
                    <p class="levi-form-help"><?php echo esc_html($this->tr('No audit entries yet.', 'Noch keine Audit-Eintraege vorhanden.')); ?></p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="widefat striped" style="margin-top: 0.25rem;">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html($this->tr('Time', 'Zeit')); ?></th>
                                    <th><?php echo esc_html($this->tr('Session', 'Session')); ?></th>
                                    <th><?php echo esc_html($this->tr('User', 'User')); ?></th>
                                    <th><?php echo esc_html($this->tr('Tool', 'Tool')); ?></th>
                                    <th><?php echo esc_html($this->tr('Status', 'Status')); ?></th>
                                    <th><?php echo esc_html($this->tr('Summary', 'Zusammenfassung')); ?></th>
                                    <th><?php echo esc_html($this->tr('Arguments', 'Argumente')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($auditRows as $row): ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['executed_at'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($row['session_id'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html((string) ($row['user_id'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($row['tool_name'] ?? '')); ?></code></td>
                                    <td><?php echo !empty($row['success']) ? '✅' : '❌'; ?></td>
                                    <td><?php echo esc_html((string) ($row['result_summary'] ?? '')); ?></td>
                                    <td><code style="white-space:pre-wrap; word-break:break-word;"><?php echo esc_html((string) ($row['tool_args'] ?? '')); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="levi-form-help" style="margin-top: 0.5rem;">
                        <?php echo esc_html($this->tr('Showing latest 20 entries.', 'Es werden die letzten 20 Eintraege angezeigt.')); ?>
                    </p>
                <?php endif; ?>
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

                <!-- Web Search -->
                <div class="levi-form-card">
                    <div class="levi-card-header">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <h3><?php echo esc_html($this->tr('Web Search', 'Web-Suche')); ?></h3>
                    </div>
                    <p class="levi-form-description">
                        <?php echo esc_html($this->tr(
                            'When enabled, a globe button appears in the chat input. Click it before sending a message to let Levi search the web for current information.',
                            'Wenn aktiviert, erscheint ein Globus-Button im Chat. Klicke ihn vor dem Senden einer Nachricht, damit Levi im Internet nach aktuellen Infos suchen kann.'
                        )); ?>
                    </p>
                    <div class="levi-form-group">
                        <label class="levi-toggle-label">
                            <input type="hidden" name="<?php echo esc_attr($this->optionName); ?>[web_search_enabled]" value="0">
                            <input type="checkbox" 
                                   name="<?php echo esc_attr($this->optionName); ?>[web_search_enabled]" 
                                   value="1" 
                                   <?php checked(!empty($settings['web_search_enabled'])); ?>
                                   class="levi-toggle-input">
                            <span class="levi-toggle-switch"></span>
                            <span class="levi-toggle-text"><?php echo esc_html($this->tr('Enable Web Search', 'Web-Suche aktivieren')); ?></span>
                        </label>
                        <p class="levi-form-help levi-hint">
                            <?php echo esc_html($this->tr(
                                'Note: Web search incurs additional costs per request at OpenRouter. The user controls when to use it via the chat toggle.',
                                'Hinweis: Web-Suche verursacht zusätzliche Kosten pro Anfrage bei OpenRouter. Der Nutzer steuert per Toggle im Chat, wann sie genutzt wird.'
                            )); ?>
                        </p>
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
        return ['openrouter' => 'OpenRouter'];
    }

    public function getProvider(): string {
        $settings = $this->getSettings();
        $provider = sanitize_key((string) ($settings['ai_provider'] ?? 'openrouter'));
        $labels = $this->getProviderLabels();
        return isset($labels[$provider]) ? $provider : 'openrouter';
    }

    public function getAuthMethodOptions(string $provider): array {
        return [
            'oauth' => $this->tr('Connect with OpenRouter (recommended)', 'Mit OpenRouter verbinden (empfohlen)'),
            'api_key' => $this->tr('Manual API Key', 'Manueller API-Schluessel'),
        ];
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
        return [
            'moonshotai/kimi-k2.5' => 'Kimi K2.5 (Moonshot)',
        ];
    }

    public function getAllowedAltModelsForProvider(string $provider): array {
        if ($provider !== 'openrouter') {
            return [];
        }
        return [
            'moonshotai/kimi-k2.5' => 'Kimi K2.5',
            'openai/gpt-5.3-codex' => 'GPT 5.3 Codex',
            'anthropic/claude-opus-4.6' => 'Claude Opus 4.6',
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet',
            'openai/gpt-4o-mini' => 'GPT-4o Mini',
        ];
    }

    public function getModel(): string {
        return $this->getModelForProvider($this->getProvider());
    }

    public function isWebSearchEnabled(): bool {
        return !empty($this->getSettings()['web_search_enabled']);
    }

    public function getModelForProvider(string $provider): string {
        $settings = $this->getSettings();
        
        // For OpenRouter, use the selected model (openrouter_alt_model stores the choice)
        if ($provider === 'openrouter') {
            $allowed = $this->getAllowedAltModelsForProvider($provider);
            $model = (string) ($settings['openrouter_alt_model'] ?? array_key_first($allowed));
            return isset($allowed[$model]) ? $model : array_key_first($allowed);
        }
        
        $settingKey = match ($provider) {
            'openai' => 'openai_model',
            'anthropic' => 'anthropic_model',
            default => 'openrouter_model',
        };
        $allowed = $this->getAllowedModelsForProvider($provider);
        $model = (string) ($settings[$settingKey] ?? array_key_first($allowed));
        return isset($allowed[$model]) ? $model : array_key_first($allowed);
    }

    public function getAltModel(): string {
        // Deprecated: Use getModel() instead - kept for backwards compatibility
        return $this->getModel();
    }

    public function getAltModelForProvider(string $provider): string {
        // Deprecated: Use getModelForProvider() instead - kept for backwards compatibility
        return $this->getModelForProvider($provider);
    }

    public function getDefaults(): array {
        return [
            'ai_provider' => 'openrouter',
            'ai_auth_method' => 'api_key',
            'oauth_connected_at' => null,
            'openrouter_api_key' => '',
            'openai_api_key' => '',
            'anthropic_api_key' => '',
            'openrouter_model' => 'moonshotai/kimi-k2.5',
            'openrouter_alt_model' => 'moonshotai/kimi-k2.5',
            'openai_model' => 'gpt-4o-mini',
            'anthropic_model' => 'claude-3-5-sonnet-20241022',
            'rate_limit' => 100,
            'max_tool_iterations' => 30,
            'max_tokens' => 131072,
            'ai_timeout' => 120,
            'php_time_limit' => 0,
            'max_context_tokens' => 100000,
            'history_context_limit' => 20,
            'tool_profile' => 'standard',
            'allowed_plugin_slugs_manual' => '',
            'allow_destructive' => 0,
            'memory_identity_k' => 5,
            'memory_reference_k' => 5,
            'memory_min_similarity' => 0.6,
            'pii_redaction' => 1,
            'blocked_post_types' => '',
            'web_search_enabled' => 0,
            'compact_model' => '',
            'summary_model' => '',
        ];
    }

    public function getSettings(): array {
        $settings = get_option($this->optionName, []);
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?: [];
        }
        if (!is_array($settings)) {
            $settings = [];
        }

        // Migrate old setting key (inverted logic: old 1=confirm → new 0=not allowed)
        if (isset($settings['require_confirmation_destructive']) && !isset($settings['allow_destructive'])) {
            $settings['allow_destructive'] = empty($settings['require_confirmation_destructive']) ? 1 : 0;
        }

        return array_merge($this->getDefaults(), $settings);
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

    public function ajaxClearAuditLog(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'levi_audit_log';
        $deleted = $wpdb->query("DELETE FROM {$table}");
        if ($deleted === false) {
            wp_send_json_error($this->tr('Could not delete audit log.', 'Audit-Log konnte nicht geloescht werden.'));
        }

        wp_send_json_success([
            'message' => $this->tr('Audit log deleted.', 'Audit-Log geloescht.'),
        ]);
    }

    // ── Cron Tasks Tab ────────────────────────────────────────────

    private function renderCronTasksTab(): void {
        $tasks = \Levi\Agent\Cron\CronTaskRunner::getAllTasks();
        $scheduleLabels = [
            'hourly' => $this->tr('Hourly', 'Stündlich'),
            'twicedaily' => $this->tr('Twice Daily', '2x täglich'),
            'daily' => $this->tr('Daily', 'Täglich'),
            'weekly' => $this->tr('Weekly', 'Wöchentlich'),
        ];
        ?>
        <div class="levi-settings-section">
            <div class="levi-section-header">
                <h2><?php echo esc_html($this->tr("Levi's Tasks", 'Levis Aufgaben')); ?></h2>
                <p><?php echo esc_html($this->tr(
                    'Recurring read-only tasks that Levi executes automatically. Ask Levi in chat to create new tasks.',
                    'Wiederkehrende Lese-Aufgaben, die Levi automatisch ausführt. Bitte Levi im Chat, neue Aufgaben anzulegen.'
                )); ?></p>
            </div>

            <?php if (empty($tasks)): ?>
                <div class="levi-notice levi-notice-info">
                    <p><?php echo esc_html($this->tr(
                        'No scheduled tasks yet. Ask Levi in the chat to create one, e.g.: "Check daily if there are plugin updates."',
                        'Noch keine geplanten Aufgaben. Bitte Levi im Chat, z.B.: „Prüfe täglich ob es Plugin-Updates gibt."'
                    )); ?></p>
                </div>
            <?php else: ?>
                <div class="levi-table-wrap">
                    <table class="levi-data-table levi-cron-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html($this->tr('Name', 'Name')); ?></th>
                                <th><?php echo esc_html($this->tr('Tool', 'Tool')); ?></th>
                                <th><?php echo esc_html($this->tr('Interval', 'Intervall')); ?></th>
                                <th><?php echo esc_html($this->tr('Last Run', 'Letzter Lauf')); ?></th>
                                <th><?php echo esc_html($this->tr('Next Run', 'Nächster Lauf')); ?></th>
                                <th><?php echo esc_html($this->tr('Status', 'Status')); ?></th>
                                <th><?php echo esc_html($this->tr('Actions', 'Aktionen')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tasks as $task):
                            $isActive = !empty($task['active']);
                            $hook = $task['hook'] ?? '';
                            $nextRun = $hook ? wp_next_scheduled($hook) : null;
                            $lastResult = $task['last_result'] ?? null;
                            $hasError = $lastResult && str_contains($lastResult, '"success":false');
                            $isWriteTask = in_array($task['tool'] ?? '', \Levi\Agent\Cron\CronTaskRunner::CONFIRMABLE_TOOLS, true);
                            $statusClass = $isActive ? ($hasError ? 'levi-status-error' : 'levi-status-active') : 'levi-status-paused';
                            $statusLabel = $isActive ? ($hasError ? $this->tr('Error', 'Fehler') : $this->tr('Active', 'Aktiv')) : $this->tr('Paused', 'Pausiert');
                        ?>
                            <tr data-task-id="<?php echo esc_attr($task['id']); ?>">
                                <td>
                                    <strong><?php echo esc_html($task['name'] ?? ''); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html($task['tool'] ?? ''); ?></code>
                                    <?php if ($isWriteTask): ?>
                                        <span class="levi-badge levi-badge-confirmed" title="<?php echo esc_attr($this->tr('Write tool — confirmed by user', 'Schreib-Tool — vom Nutzer bestätigt')); ?>">write</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($scheduleLabels[$task['schedule'] ?? ''] ?? ($task['schedule'] ?? '-')); ?></td>
                                <td>
                                    <?php if (!empty($task['last_run'])): ?>
                                        <?php echo esc_html(wp_date('d.m.Y H:i', $task['last_run'])); ?>
                                    <?php else: ?>
                                        <span class="levi-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($nextRun): ?>
                                        <?php echo esc_html(wp_date('d.m.Y H:i', $nextRun)); ?>
                                        <?php if ($nextRun < time()): ?>
                                            <span class="levi-badge levi-badge-warning"><?php echo esc_html($this->tr('overdue', 'überfällig')); ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="levi-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="levi-badge <?php echo esc_attr($statusClass); ?>"><?php echo esc_html($statusLabel); ?></span>
                                </td>
                                <td class="levi-cron-actions">
                                    <button type="button" class="levi-btn levi-btn-small levi-btn-secondary levi-cron-run" data-task-id="<?php echo esc_attr($task['id']); ?>" title="<?php echo esc_attr($this->tr('Run now', 'Jetzt ausführen')); ?>">
                                        <span class="dashicons dashicons-controls-play"></span>
                                    </button>
                                    <button type="button" class="levi-btn levi-btn-small levi-btn-secondary levi-cron-toggle" data-task-id="<?php echo esc_attr($task['id']); ?>" title="<?php echo esc_attr($isActive ? $this->tr('Pause', 'Pausieren') : $this->tr('Resume', 'Fortsetzen')); ?>">
                                        <span class="dashicons <?php echo $isActive ? 'dashicons-controls-pause' : 'dashicons-controls-forward'; ?>"></span>
                                    </button>
                                    <button type="button" class="levi-btn levi-btn-small levi-btn-danger levi-cron-delete" data-task-id="<?php echo esc_attr($task['id']); ?>" title="<?php echo esc_attr($this->tr('Delete', 'Löschen')); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                    <?php $emailActive = !empty($task['notify_email']); ?>
                                    <button type="button" class="levi-btn levi-btn-small <?php echo $emailActive ? 'levi-btn-email-active' : 'levi-btn-secondary'; ?> levi-cron-email-toggle" data-task-id="<?php echo esc_attr($task['id']); ?>" title="<?php echo esc_attr($emailActive ? $this->tr('Email notifications on — click to disable', 'E-Mail-Benachrichtigung aktiv — klicken zum Deaktivieren') : $this->tr('Enable email notifications', 'E-Mail-Benachrichtigung aktivieren')); ?>">
                                        <span class="dashicons dashicons-email-alt"></span>
                                    </button>
                                    <?php if ($lastResult): ?>
                                        <button type="button" class="levi-btn levi-btn-small levi-btn-secondary levi-cron-expand" data-task-id="<?php echo esc_attr($task['id']); ?>" title="<?php echo esc_attr($this->tr('Show result', 'Ergebnis anzeigen')); ?>">
                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($lastResult): ?>
                            <tr class="levi-cron-detail" data-detail-for="<?php echo esc_attr($task['id']); ?>" style="display:none;">
                                <td colspan="7">
                                    <div class="levi-cron-result">
                                        <strong><?php echo esc_html($this->tr('Last Result:', 'Letztes Ergebnis:')); ?></strong>
                                        <pre><?php echo esc_html($lastResult); ?></pre>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- All WordPress Cron Events (read-only) -->
        <div class="levi-settings-section levi-cron-all-events">
            <div class="levi-section-header levi-collapsible-header" id="levi-cron-all-toggle">
                <h2>
                    <span class="dashicons dashicons-arrow-right-alt2 levi-collapse-icon"></span>
                    <?php echo esc_html($this->tr('All WordPress Cron Events', 'Alle WordPress Cron-Events')); ?>
                </h2>
                <p><?php echo esc_html($this->tr(
                    'Read-only overview of all scheduled WordPress events (including other plugins).',
                    'Nur-Lese-Übersicht aller geplanten WordPress-Events (inkl. anderer Plugins).'
                )); ?></p>
            </div>
            <div class="levi-collapsible-content" id="levi-cron-all-content" style="display:none;">
                <?php
                $crons = _get_cron_array();
                $allEvents = [];
                $now = time();
                if (is_array($crons)) {
                    foreach ($crons as $timestamp => $hooks) {
                        foreach ($hooks as $hook => $entries) {
                            foreach ($entries as $entry) {
                                $allEvents[] = [
                                    'hook' => $hook,
                                    'timestamp' => $timestamp,
                                    'schedule' => $entry['schedule'] ?: 'once',
                                    'overdue' => $timestamp < $now,
                                    'is_levi' => str_starts_with($hook, 'levi_'),
                                ];
                            }
                        }
                    }
                }
                usort($allEvents, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
                ?>
                <div class="levi-table-wrap">
                    <table class="levi-data-table">
                        <thead>
                            <tr>
                                <th>Hook</th>
                                <th><?php echo esc_html($this->tr('Schedule', 'Zeitplan')); ?></th>
                                <th><?php echo esc_html($this->tr('Next Run', 'Nächste Ausführung')); ?></th>
                                <th><?php echo esc_html($this->tr('Status', 'Status')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($allEvents, 0, 100) as $event): ?>
                            <tr class="<?php echo $event['is_levi'] ? 'levi-row-featured' : ''; ?>">
                                <td><code><?php echo esc_html($event['hook']); ?></code></td>
                                <td><?php echo esc_html($scheduleLabels[$event['schedule']] ?? $event['schedule']); ?></td>
                                <td><?php echo esc_html(wp_date('d.m.Y H:i:s', $event['timestamp'])); ?></td>
                                <td>
                                    <?php if ($event['overdue']): ?>
                                        <span class="levi-badge levi-badge-warning"><?php echo esc_html($this->tr('overdue', 'überfällig')); ?></span>
                                    <?php else: ?>
                                        <span class="levi-badge levi-status-active"><?php echo esc_html($this->tr('scheduled', 'geplant')); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Cron AJAX Handlers ──────────────────────────────────────────

    public function ajaxRunCronTask(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $taskId = sanitize_text_field($_POST['task_id'] ?? '');
        if ($taskId === '') {
            wp_send_json_error($this->tr('Task ID missing.', 'Task-ID fehlt.'));
        }

        $runner = new \Levi\Agent\Cron\CronTaskRunner();
        $result = $runner->executeTask($taskId);

        if (!empty($result['success'])) {
            wp_send_json_success([
                'message' => $this->tr('Task executed.', 'Aufgabe ausgeführt.'),
                'result' => $result,
            ]);
        } else {
            wp_send_json_error($result['error'] ?? $this->tr('Task failed.', 'Aufgabe fehlgeschlagen.'));
        }
    }

    public function ajaxToggleCronTask(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $taskId = sanitize_text_field($_POST['task_id'] ?? '');
        if ($taskId === '') {
            wp_send_json_error($this->tr('Task ID missing.', 'Task-ID fehlt.'));
        }

        $result = \Levi\Agent\Cron\CronTaskRunner::toggleTask($taskId);

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? $this->tr('Could not toggle task.', 'Task konnte nicht geändert werden.'));
        }
    }

    public function ajaxDeleteCronTask(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $taskId = sanitize_text_field($_POST['task_id'] ?? '');
        if ($taskId === '') {
            wp_send_json_error($this->tr('Task ID missing.', 'Task-ID fehlt.'));
        }

        $result = \Levi\Agent\Cron\CronTaskRunner::deleteTask($taskId);

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? $this->tr('Could not delete task.', 'Task konnte nicht gelöscht werden.'));
        }
    }

    private function getAuditLogRows(int $limit = 20): array {
        global $wpdb;
        $table = $wpdb->prefix . 'levi_audit_log';
        $safeLimit = max(1, min(100, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, session_id, tool_name, tool_args, success, result_summary, executed_at
                 FROM {$table}
                 ORDER BY executed_at DESC
                 LIMIT %d",
                $safeLimit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    // ── Dashboard Widget ────────────────────────────────────────────

    public function registerDashboardWidget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            'levi_cron_results_widget',
            'Levi — Geplante Aufgaben',
            [$this, 'renderDashboardWidget'],
            null,
            [],
            'normal',
            'high'
        );
    }

    public function renderDashboardWidget(): void {
        $tasks = \Levi\Agent\Cron\CronTaskRunner::getAllTasks();

        if (empty($tasks)) {
            echo '<p class="levi-dw-empty">Keine geplanten Aufgaben konfiguriert. <a href="' . esc_url(admin_url('admin.php?page=levi-agent-settings&tab=cron-tasks')) . '">Aufgaben verwalten</a></p>';
            return;
        }

        $tasksWithResults = array_filter($tasks, fn($t) => !empty($t['last_result']));
        usort($tasksWithResults, fn($a, $b) => ($b['last_run'] ?? 0) <=> ($a['last_run'] ?? 0));

        if (empty($tasksWithResults)) {
            echo '<p class="levi-dw-empty">Noch keine Ergebnisse vorhanden — Aufgaben wurden noch nicht ausgefuehrt.</p>';
            return;
        }

        echo '<div class="levi-dw-list">';
        foreach ($tasksWithResults as $task) {
            $name = esc_html($task['name'] ?? $task['id']);
            $tool = esc_html($task['tool'] ?? '');
            $lastRun = !empty($task['last_run']) ? wp_date('d.m.Y H:i', $task['last_run']) : '—';
            $rawResult = $task['last_result'] ?? '';
            $hasError = str_contains($rawResult, '"success":false');
            $statusClass = $hasError ? 'levi-dw-error' : 'levi-dw-success';
            $statusIcon = $hasError ? '⚠' : '✓';
            $taskId = esc_attr($task['id'] ?? '');

            $decoded = json_decode($rawResult, true);
            $summary = $this->buildResultSummary($decoded, $rawResult);

            echo '<div class="levi-dw-item ' . $statusClass . '">';
            echo   '<div class="levi-dw-header" data-toggle="levi-dw-detail-' . $taskId . '">';
            echo     '<span class="levi-dw-status-icon">' . $statusIcon . '</span>';
            echo     '<div class="levi-dw-meta">';
            echo       '<strong class="levi-dw-name">' . $name . '</strong>';
            echo       '<span class="levi-dw-info"><code>' . $tool . '</code> · ' . esc_html($lastRun) . '</span>';
            echo     '</div>';
            echo     '<span class="levi-dw-chevron dashicons dashicons-arrow-down-alt2"></span>';
            echo   '</div>';
            echo   '<div class="levi-dw-summary">' . $summary . '</div>';
            echo   '<div class="levi-dw-detail" id="levi-dw-detail-' . $taskId . '" style="display:none;">';
            echo     '<pre class="levi-dw-raw">' . esc_html($this->formatJsonForDisplay($rawResult)) . '</pre>';
            echo   '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<p class="levi-dw-footer"><a href="' . esc_url(admin_url('admin.php?page=levi-agent-settings&tab=cron-tasks')) . '">Alle Aufgaben verwalten →</a></p>';

        $this->renderDashboardWidgetStyles();
        $this->renderDashboardWidgetScript();
    }

    private function buildResultSummary(?array $decoded, string $raw): string {
        if (!is_array($decoded)) {
            $excerpt = mb_substr(strip_tags($raw), 0, 120);
            return '<span class="levi-dw-text">' . esc_html($excerpt) . (mb_strlen($raw) > 120 ? '…' : '') . '</span>';
        }

        if (!empty($decoded['error'])) {
            return '<span class="levi-dw-text levi-dw-error-text">Fehler: ' . esc_html(mb_substr($decoded['error'], 0, 150)) . '</span>';
        }

        $parts = [];

        if (isset($decoded['updated']) && is_int($decoded['updated'])) {
            $parts[] = $decoded['updated'] . ' aktualisiert';
        }
        if (isset($decoded['total']) && is_int($decoded['total'])) {
            $parts[] = $decoded['total'] . ' Eintraege';
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            $parts[] = mb_substr($decoded['message'], 0, 100);
        }
        if (!empty($decoded['posts']) && is_array($decoded['posts'])) {
            $parts[] = count($decoded['posts']) . ' Beitraege';
        }
        if (!empty($decoded['plugins']) && is_array($decoded['plugins'])) {
            $parts[] = count($decoded['plugins']) . ' Plugins';
        }

        if (empty($parts)) {
            if (!empty($decoded['success'])) {
                return '<span class="levi-dw-text">Erfolgreich ausgefuehrt</span>';
            }
            $excerpt = mb_substr($raw, 0, 120);
            return '<span class="levi-dw-text">' . esc_html($excerpt) . '</span>';
        }

        return '<span class="levi-dw-text">' . esc_html(implode(' · ', $parts)) . '</span>';
    }

    private function formatJsonForDisplay(string $json): string {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }

    private function renderDashboardWidgetStyles(): void {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <style>
            .levi-dw-list { display: flex; flex-direction: column; gap: 8px; }
            .levi-dw-item { border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; background: #fff; }
            .levi-dw-item.levi-dw-error { border-left: 3px solid #dc3545; }
            .levi-dw-item.levi-dw-success { border-left: 3px solid #28a745; }
            .levi-dw-header { display: flex; align-items: center; gap: 8px; padding: 10px 12px; cursor: pointer; user-select: none; }
            .levi-dw-header:hover { background: #f8f9fa; }
            .levi-dw-status-icon { font-size: 16px; flex-shrink: 0; width: 20px; text-align: center; }
            .levi-dw-error .levi-dw-status-icon { color: #dc3545; }
            .levi-dw-success .levi-dw-status-icon { color: #28a745; }
            .levi-dw-meta { flex: 1; min-width: 0; }
            .levi-dw-name { display: block; font-size: 13px; line-height: 1.3; color: #1d2327; }
            .levi-dw-info { display: block; font-size: 11px; color: #646970; margin-top: 2px; }
            .levi-dw-info code { font-size: 11px; background: #f0f0f1; padding: 1px 5px; border-radius: 3px; }
            .levi-dw-chevron { flex-shrink: 0; color: #8c8f94; font-size: 16px !important; width: 16px !important; height: 16px !important; transition: transform 0.2s; }
            .levi-dw-item.levi-dw-open .levi-dw-chevron { transform: rotate(180deg); }
            .levi-dw-summary { padding: 0 12px 10px 40px; font-size: 12px; color: #50575e; }
            .levi-dw-text { line-height: 1.4; }
            .levi-dw-error-text { color: #dc3545; }
            .levi-dw-detail { padding: 0 12px 12px 12px; }
            .levi-dw-raw { background: #f6f7f7; border: 1px solid #e0e0e0; border-radius: 4px; padding: 10px; font-size: 11px; line-height: 1.5; max-height: 300px; overflow: auto; white-space: pre-wrap; word-break: break-word; margin: 0; }
            .levi-dw-footer { margin: 10px 0 0; text-align: right; font-size: 12px; }
            .levi-dw-empty { color: #646970; font-style: italic; }
        </style>
        <?php
    }

    private function renderDashboardWidgetScript(): void {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <script>
        jQuery(function($) {
            $(document).on('click', '.levi-dw-header[data-toggle]', function() {
                var targetId = $(this).attr('data-toggle');
                var $detail = $('#' + targetId);
                var $item = $(this).closest('.levi-dw-item');
                $detail.slideToggle(200);
                $item.toggleClass('levi-dw-open');
            });
        });
        </script>
        <?php
    }

    // ── Cron Email Toggle AJAX ──────────────────────────────────────

    public function ajaxToggleCronEmail(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $taskId = sanitize_text_field($_POST['task_id'] ?? '');
        if ($taskId === '') {
            wp_send_json_error($this->tr('Task ID missing.', 'Task-ID fehlt.'));
        }

        $tasks = \Levi\Agent\Cron\CronTaskRunner::getAllTasks();
        if (!isset($tasks[$taskId])) {
            wp_send_json_error($this->tr('Task not found.', 'Aufgabe nicht gefunden.'));
        }

        $tasks[$taskId]['notify_email'] = empty($tasks[$taskId]['notify_email']);
        update_option('levi_custom_cron_tasks', $tasks, false);

        wp_send_json_success([
            'task_id' => $taskId,
            'notify_email' => $tasks[$taskId]['notify_email'],
            'message' => $tasks[$taskId]['notify_email']
                ? $this->tr('Email notifications enabled.', 'E-Mail-Benachrichtigungen aktiviert.')
                : $this->tr('Email notifications disabled.', 'E-Mail-Benachrichtigungen deaktiviert.'),
        ]);
    }
}
