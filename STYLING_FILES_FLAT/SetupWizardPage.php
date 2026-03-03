<?php

namespace Levi\Agent\Admin;

use Levi\Agent\Memory\StateSnapshotService;

class SetupWizardPage {
    private string $pageSlug = 'levi-agent-setup-wizard';
    private string $settingsOption = 'levi_agent_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'maybeHandleRedirect']);
        add_action('admin_init', [$this, 'maybeHandlePost']);
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'options-general.php',
            'Levi Setup',
            'Levi Setup',
            'manage_options',
            $this->pageSlug,
            [$this, 'renderPage']
        );

        if ((int) get_option('levi_setup_completed', 0) === 1) {
            add_action('admin_head', [$this, 'hideCompletedWizardMenuItem']);
        }
    }

    public function hideCompletedWizardMenuItem(): void {
        $slug = esc_attr($this->pageSlug);
        echo '<style>#adminmenu a[href*="page=' . $slug . '"] { display: none !important; }</style>' . "\n";
    }

    public function enqueueAssets(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_' . $this->pageSlug) {
            return;
        }

        wp_enqueue_style(
            'levi-agent-settings',
            LEVI_AGENT_PLUGIN_URL . 'assets/css/settings-page.css',
            [],
            LEVI_AGENT_VERSION
        );

        wp_enqueue_style(
            'levi-agent-setup-wizard',
            LEVI_AGENT_PLUGIN_URL . 'assets/css/setup-wizard.css',
            ['levi-agent-settings'],
            LEVI_AGENT_VERSION
        );
    }

    public function maybeHandleRedirect(): void {
        if (!is_admin() || !current_user_can('manage_options') || wp_doing_ajax()) {
            return;
        }

        $pending = (int) get_option('levi_setup_wizard_pending', 0) === 1;
        if (!$pending) {
            return;
        }

        $currentPage = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($currentPage === $this->pageSlug) {
            return;
        }

        update_option('levi_setup_wizard_pending', 0);
        wp_safe_redirect(admin_url('options-general.php?page=' . $this->pageSlug . '&step=1'));
        exit;
    }

    public function maybeHandlePost(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_key((string) ($_POST['levi_setup_action'] ?? ''));
        if ($action === '') {
            return;
        }

        switch ($action) {
            case 'save_pro_setup':
                $this->handleSaveProSetup();
                break;
            case 'save_tuning':
                $this->handleSaveTuning();
                break;
            case 'complete_setup':
                $this->handleCompleteSetup();
                break;
        }
    }

    private function handleSaveProSetup(): void {
        check_admin_referer('levi_setup_wizard_step2');

        $apiKey = trim((string) ($_POST['levi_openrouter_api_key'] ?? ''));
        if ($apiKey === '') {
            wp_safe_redirect(admin_url('options-general.php?page=' . $this->pageSlug . '&step=2&error=missing_key'));
            exit;
        }

        $settings = $this->getSettings();
        $settings['ai_provider'] = 'openrouter';
        $settings['ai_auth_method'] = 'api_key';
        $settings['openrouter_api_key'] = sanitize_text_field($apiKey);
        $settings['openrouter_model'] = 'moonshotai/kimi-k2.5';
        $settings['tool_profile'] = 'standard';
        $settings['force_exhaustive_reads'] = 1;
        $settings['require_confirmation_destructive'] = 1;
        $settings['max_tool_iterations'] = 12;
        $settings['history_context_limit'] = 50;

        $this->saveSettings($settings);

        update_option('levi_plan_tier', 'pro');

        wp_safe_redirect(admin_url('options-general.php?page=' . $this->pageSlug . '&step=3&saved=pro'));
        exit;
    }

    private function handleSaveTuning(): void {
        check_admin_referer('levi_setup_wizard_step4');

        $thoroughness = sanitize_key((string) ($_POST['levi_thoroughness'] ?? 'balanced'));
        $safety = sanitize_key((string) ($_POST['levi_safety_mode'] ?? 'strict'));
        $speed = sanitize_key((string) ($_POST['levi_speed_mode'] ?? 'balanced'));
        $toolProfile = sanitize_key((string) ($_POST['levi_tool_profile'] ?? 'standard'));

        $settings = $this->getSettings();

        if (in_array($toolProfile, \Levi\Agent\AI\Tools\Registry::VALID_PROFILES, true)) {
            $settings['tool_profile'] = $toolProfile;
        }

        if ($thoroughness === 'high') {
            $settings['force_exhaustive_reads'] = 1;
            $settings['history_context_limit'] = 80;
        } elseif ($thoroughness === 'low') {
            $settings['force_exhaustive_reads'] = 0;
            $settings['history_context_limit'] = 30;
        } else {
            $settings['force_exhaustive_reads'] = 1;
            $settings['history_context_limit'] = 50;
        }

        if ($safety === 'standard') {
            $settings['require_confirmation_destructive'] = 0;
        } else {
            $settings['require_confirmation_destructive'] = 1;
        }

        if ($speed === 'fast') {
            $settings['max_tool_iterations'] = 6;
        } elseif ($speed === 'careful') {
            $settings['max_tool_iterations'] = 16;
        } else {
            $settings['max_tool_iterations'] = 10;
        }

        $this->saveSettings($settings);

        update_option('levi_setup_tuning_mode', [
            'thoroughness' => $thoroughness,
            'safety' => $safety,
            'speed' => $speed,
        ]);

        wp_safe_redirect(admin_url('options-general.php?page=' . $this->pageSlug . '&step=4&saved=tuning'));
        exit;
    }

    private function handleCompleteSetup(): void {
        check_admin_referer('levi_setup_wizard_step5');

        update_option('levi_setup_completed', 1);
        update_option('levi_setup_wizard_pending', 0);
        update_option('levi_setup_completed_at', current_time('mysql'));

        $snapshotStatus = 'skipped';
        try {
            $service = new StateSnapshotService();
            $meta = $service->runManualSync();
            $snapshotStatus = (string) ($meta['status'] ?? 'done');
        } catch (\Throwable $e) {
            $snapshotStatus = 'error';
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . $this->pageSlug . '&step=4&done=1&snapshot=' . urlencode($snapshotStatus)));
        exit;
    }

    private function getSettings(): array {
        $settings = get_option($this->settingsOption, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        return $settings;
    }

    private function saveSettings(array $raw): void {
        $settingsPage = new SettingsPage();
        $sanitized = $settingsPage->sanitizeSettings($raw);
        update_option($this->settingsOption, $sanitized);
    }


    private function getTuningMode(): array {
        $mode = get_option('levi_setup_tuning_mode', []);
        if (!is_array($mode)) {
            $mode = [];
        }
        $settings = $this->getSettings();
        return [
            'thoroughness' => (string) ($mode['thoroughness'] ?? 'balanced'),
            'safety' => (string) ($mode['safety'] ?? 'strict'),
            'speed' => (string) ($mode['speed'] ?? 'balanced'),
            'tool_profile' => (string) ($settings['tool_profile'] ?? 'standard'),
        ];
    }

    private function translateSnapshotStatus(string $status): string {
        $labels = [
            'not_run' => 'Noch nicht ausgeführt',
            'unchanged' => 'Unverändert',
            'changed_stored' => 'Aktualisiert und gespeichert',
            'changed_not_embedded' => 'Geändert (Embedding fehlgeschlagen)',
            'error' => 'Fehler',
            'skipped' => 'Übersprungen',
            'done' => 'Fertig',
        ];
        return $labels[$status] ?? $status;
    }

    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'levi-agent'));
        }

        $step = isset($_GET['step']) ? absint($_GET['step']) : 1;
        if (!in_array($step, [1, 2, 3, 4], true)) {
            $step = 1;
        }
        $saved = sanitize_key((string) ($_GET['saved'] ?? ''));
        $error = sanitize_key((string) ($_GET['error'] ?? ''));
        $done = isset($_GET['done']) && (int) $_GET['done'] === 1;
        $snapshot = sanitize_text_field((string) ($_GET['snapshot'] ?? ''));
        $tuning = $this->getTuningMode();
        $planTier = (string) get_option('levi_plan_tier', '');
        $progressPercent = max(0, min(100, ($step - 1) * 33));
        ?>
        <div class="levi-settings-wrap levi-setup-wrap">
            <header class="levi-settings-header">
                <div class="levi-header-content">
                    <div class="levi-logo">
                        <span class="levi-logo-icon">🤖</span>
                        <div class="levi-logo-text">
                            <h1><?php echo esc_html('Levi Einrichtungsassistent'); ?></h1>
                            <span class="levi-version"><?php echo esc_html(sprintf('Schritt %d von 4', $step)); ?></span>
                        </div>
                    </div>
                    <div>
                        <a class="levi-btn levi-btn-secondary levi-btn-small" href="<?php echo esc_url(admin_url('options-general.php?page=levi-agent-settings')); ?>"><?php echo esc_html('Assistent überspringen →'); ?></a>
                    </div>
                </div>
            </header>

            <main class="levi-settings-main">
                <div class="levi-setup-progress">
                    <div class="levi-setup-progress-fill" style="width: <?php echo esc_attr((string) $progressPercent); ?>%;"></div>
                </div>

                <?php if ($step === 1): ?>
                    <section class="levi-form-card levi-setup-card">
                        <h2><?php _e('Willkommen bei Levi', 'levi-agent'); ?></h2>
                        <p><?php _e('Danke, dass du Levi nutzt! Levi ist dein KI-Assistent für WordPress. Er kann:', 'levi-agent'); ?></p>
                        <ul class="levi-setup-list">
                            <li><?php _e('Seiten und Beiträge erstellen, bearbeiten und verwalten', 'levi-agent'); ?></li>
                            <li><?php _e('Plugins installieren, aktivieren und konfigurieren', 'levi-agent'); ?></li>
                            <li><?php _e('Fragen zu deiner WordPress-Seite beantworten', 'levi-agent'); ?></li>
                            <li><?php _e('Code generieren und technische Aufgaben übernehmen', 'levi-agent'); ?></li>
                        </ul>
                        <p><?php _e('Auf den nächsten Seiten führen wir dich sicher durch die Einrichtung.', 'levi-agent'); ?></p>

                        <div class="levi-form-actions">
                            <a class="levi-btn levi-btn-primary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=2')); ?>"><?php _e('Los geht\'s', 'levi-agent'); ?></a>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($step === 2): ?>
                    <section class="levi-form-card levi-setup-card">
                        <h2><?php _e('Schritt 2: API-Key', 'levi-agent'); ?></h2>
                        <p><?php _e('Levi nutzt OpenRouter mit Kimi K2.5 als KI-Modell. Erstelle einen API-Key auf openrouter.ai — die Kosten werden direkt von deinem OpenRouter-Konto abgebucht.', 'levi-agent'); ?></p>

                        <div class="levi-setup-info-box">
                            <strong><?php _e('So bekommst du deinen Key:', 'levi-agent'); ?></strong>
                            <ol class="levi-setup-list levi-setup-list-numbered">
                                <li><?php _e('Gehe zu', 'levi-agent'); ?> <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a></li>
                                <li><?php _e('Erstelle einen Account (Google/GitHub Login)', 'levi-agent'); ?></li>
                                <li><?php _e('Lade Credits auf (mind. 5 $) und erstelle einen API-Key', 'levi-agent'); ?></li>
                                <li><?php _e('Füge den Key unten ein — fertig!', 'levi-agent'); ?></li>
                            </ol>
                        </div>

                        <?php if ($error === 'missing_key'): ?>
                            <div class="levi-notice levi-notice-error">
                                <p><?php _e('Bitte gib einen API-Key ein.', 'levi-agent'); ?></p>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <?php wp_nonce_field('levi_setup_wizard_step2'); ?>
                            <input type="hidden" name="levi_setup_action" value="save_pro_setup">

                            <div class="levi-form-group">
                                <label class="levi-form-label" for="levi_openrouter_api_key"><?php _e('OpenRouter API-Key', 'levi-agent'); ?></label>
                                <input id="levi_openrouter_api_key" name="levi_openrouter_api_key" type="password" class="levi-form-input" placeholder="sk-or-..." required>
                                <p class="levi-form-help"><?php _e('Dein Key wird sicher gespeichert und nur an OpenRouter gesendet.', 'levi-agent'); ?></p>
                            </div>

                            <p class="levi-form-help"><?php _e('Modell: Kimi K2.5 (Moonshot)', 'levi-agent'); ?></p>

                            <div class="levi-form-actions">
                                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=1')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
                                <button type="submit" class="levi-btn levi-btn-primary"><?php _e('Weiter', 'levi-agent'); ?></button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($step === 3): ?>
                    <section class="levi-form-card levi-setup-card">
                        <h2><?php _e('Schritt 3: Levi auf dich abstimmen', 'levi-agent'); ?></h2>
                        <p><?php _e('Hier stellst du ein, wie gründlich, sicher und schnell Levi arbeiten soll. Du kannst alles später in den Einstellungen ändern.', 'levi-agent'); ?></p>

                        <?php if ($saved === 'pro'): ?>
                            <div class="levi-notice levi-notice-success">
                                <p><?php _e('Pro-Setup wurde erfolgreich gespeichert!', 'levi-agent'); ?></p>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <?php wp_nonce_field('levi_setup_wizard_step4'); ?>
                            <input type="hidden" name="levi_setup_action" value="save_tuning">

                            <div class="levi-form-group">
                                <label class="levi-form-label"><?php _e('Welche Fähigkeiten soll Levi haben?', 'levi-agent'); ?></label>
                                <p class="levi-form-help" style="margin-bottom: 0.75rem;"><?php _e('Bestimmt, welche Tools Levi nutzen darf. Später in den Einstellungen änderbar.', 'levi-agent'); ?></p>
                                <?php
                                $profiles = \Levi\Agent\AI\Tools\Registry::getProfileLabels();
                                $currentProfile = $tuning['tool_profile'];
                                foreach ($profiles as $profileKey => $profileData):
                                    $isActive = $currentProfile === $profileKey;
                                ?>
                                <label class="levi-setup-profile-option <?php echo $isActive ? 'levi-setup-profile-option-active' : ''; ?>">
                                    <input type="radio" name="levi_tool_profile" value="<?php echo esc_attr($profileKey); ?>" <?php checked($currentProfile, $profileKey); ?>>
                                    <span class="levi-setup-profile-option-content">
                                        <strong><?php echo esc_html($profileData['label']); ?></strong>
                                        <span class="levi-setup-profile-option-desc"><?php echo esc_html($profileData['description']); ?></span>
                                    </span>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="levi-form-group">
                                <label class="levi-form-label" for="levi_thoroughness"><?php _e('Wie gründlich soll Levi lesen?', 'levi-agent'); ?></label>
                                <select id="levi_thoroughness" name="levi_thoroughness" class="levi-form-select">
                                    <option value="low" <?php selected($tuning['thoroughness'], 'low'); ?>><?php _e('Schnell — liest nur das Nötigste', 'levi-agent'); ?></option>
                                    <option value="balanced" <?php selected($tuning['thoroughness'], 'balanced'); ?>><?php _e('Ausgewogen (empfohlen) — guter Kompromiss', 'levi-agent'); ?></option>
                                    <option value="high" <?php selected($tuning['thoroughness'], 'high'); ?>><?php _e('Sehr gründlich — liest mehr Inhalte, braucht länger', 'levi-agent'); ?></option>
                                </select>
                                <p class="levi-form-help"><?php _e('Beeinflusst, wie viel Kontext Levi bei jeder Anfrage berücksichtigt.', 'levi-agent'); ?></p>
                            </div>

                            <div class="levi-form-group">
                                <label class="levi-form-label" for="levi_safety_mode"><?php _e('Wie vorsichtig soll Levi bei Änderungen sein?', 'levi-agent'); ?></label>
                                <select id="levi_safety_mode" name="levi_safety_mode" class="levi-form-select">
                                    <option value="strict" <?php selected($tuning['safety'], 'strict'); ?>><?php _e('Sicher — fragt vor dem Löschen/Ändern nach', 'levi-agent'); ?></option>
                                    <option value="standard" <?php selected($tuning['safety'], 'standard'); ?>><?php _e('Standard — weniger Nachfragen, schneller', 'levi-agent'); ?></option>
                                </select>
                                <p class="levi-form-help"><?php _e('Im sicheren Modus bestätigt Levi jede Lösch- oder Änderungs-Aktion mit dir.', 'levi-agent'); ?></p>
                            </div>

                            <div class="levi-form-group">
                                <label class="levi-form-label" for="levi_speed_mode"><?php _e('Wie schnell soll Levi antworten?', 'levi-agent'); ?></label>
                                <select id="levi_speed_mode" name="levi_speed_mode" class="levi-form-select">
                                    <option value="fast" <?php selected($tuning['speed'], 'fast'); ?>><?php _e('Schnell — weniger Schritte, kürzere Antworten', 'levi-agent'); ?></option>
                                    <option value="balanced" <?php selected($tuning['speed'], 'balanced'); ?>><?php _e('Ausgewogen (empfohlen)', 'levi-agent'); ?></option>
                                    <option value="careful" <?php selected($tuning['speed'], 'careful'); ?>><?php _e('Sorgfältig — mehr Schritte, gründlichere Ergebnisse', 'levi-agent'); ?></option>
                                </select>
                                <p class="levi-form-help"><?php _e('Bestimmt, wie viele Tool-Schritte Levi pro Anfrage ausführen darf.', 'levi-agent'); ?></p>
                            </div>

                            <div class="levi-form-actions">
                                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=2')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
                                <button type="submit" class="levi-btn levi-btn-primary"><?php _e('Einstellungen übernehmen', 'levi-agent'); ?></button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($step === 4): ?>
                    <section class="levi-form-card levi-setup-card">
                        <h2><?php _e('Schritt 4: Abschluss', 'levi-agent'); ?></h2>

                        <?php if ($saved === 'tuning'): ?>
                            <div class="levi-notice levi-notice-success">
                                <p><?php _e('Deine Einstellungen wurden gespeichert!', 'levi-agent'); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($done): ?>
                            <div class="levi-setup-payment-state is-active">
                                <strong><?php _e('Levi ist eingerichtet!', 'levi-agent'); ?></strong>
                                <p><?php echo esc_html(sprintf('Snapshot-Status: %s', $this->translateSnapshotStatus($snapshot !== '' ? $snapshot : 'done'))); ?></p>
                                <?php if ($planTier !== ''): ?>
                                    <p><?php echo esc_html(sprintf('Aktiver Plan: %s', 'Pro')); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="levi-form-actions">
                                <a class="levi-btn levi-btn-primary" href="<?php echo esc_url(admin_url('options-general.php?page=levi-agent-settings')); ?>"><?php _e('Zu den Einstellungen', 'levi-agent'); ?></a>
                                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url()); ?>" onclick="setTimeout(function(){ var t = document.getElementById('levi-chat-toggle'); if(t) t.click(); }, 500); return true;"><?php _e('Chat öffnen', 'levi-agent'); ?></a>
                            </div>
                        <?php else: ?>
                            <p><?php _e('Zum Abschluss erstellt Levi einen Snapshot deiner WordPress-Instanz. Damit kennt er deine Seite, Plugins und Einstellungen.', 'levi-agent'); ?></p>
                            <p class="levi-form-help levi-hint"><?php _e('Das kann einige Sekunden dauern. Bitte die Seite nicht schließen.', 'levi-agent'); ?></p>

                            <form method="post" action="" id="levi-setup-complete-form">
                                <?php wp_nonce_field('levi_setup_wizard_step5'); ?>
                                <input type="hidden" name="levi_setup_action" value="complete_setup">
                                <div class="levi-form-actions">
                                    <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=3')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
                                    <button type="submit" class="levi-btn levi-btn-primary" id="levi-setup-complete-btn"><?php _e('Levi jetzt starten', 'levi-agent'); ?></button>
                                </div>
                            </form>
                            <script>
                            (function(){
                                var form = document.getElementById('levi-setup-complete-form');
                                if (!form) return;
                                form.addEventListener('submit', function() {
                                    var btn = document.getElementById('levi-setup-complete-btn');
                                    if (btn) {
                                        btn.disabled = true;
                                        btn.textContent = '<?php echo esc_js('Levi wird initialisiert…'); ?>';
                                    }
                                });
                            })();
                            </script>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </main>
        </div>
        <?php
    }
}
