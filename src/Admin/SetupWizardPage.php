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
            'levi-agent-settings',
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
        if (!$screen || !str_contains($screen->id, $this->pageSlug)) {
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
        wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=1'));
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
            wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=2&error=missing_key'));
            exit;
        }

        $settings = $this->getSettings();
        $settings['ai_provider'] = 'openrouter';
        $settings['ai_auth_method'] = 'api_key';
        $settings['openrouter_api_key'] = sanitize_text_field($apiKey);
        $settings['openrouter_model'] = 'moonshotai/kimi-k2.5';
        $settings['tool_profile'] = 'standard';
        $settings['require_confirmation_destructive'] = 1;
        $settings['max_tool_iterations'] = 12;
        $settings['history_context_limit'] = 50;

        $this->saveSettings($settings);

        update_option('levi_plan_tier', 'pro');

        wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=3&saved=pro'));
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
            $settings['history_context_limit'] = 80;
        } elseif ($thoroughness === 'low') {
            $settings['history_context_limit'] = 30;
        } else {
            $settings['history_context_limit'] = 50;
        }

        if ($safety === 'standard') {
            $settings['require_confirmation_destructive'] = 0;
        } else {
            $settings['require_confirmation_destructive'] = 1;
        }

        if ($speed === 'fast') {
            $settings['max_tool_iterations'] = 8;
        } elseif ($speed === 'careful') {
            $settings['max_tool_iterations'] = 18;
        } else {
            $settings['max_tool_iterations'] = 12;
        }

        $this->saveSettings($settings);

        update_option('levi_setup_tuning_mode', [
            'thoroughness' => $thoroughness,
            'safety' => $safety,
            'speed' => $speed,
        ]);

        wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=4&saved=tuning'));
        exit;
    }

    private function handleCompleteSetup(): void {
        check_admin_referer('levi_setup_wizard_step5');

        update_option('levi_setup_completed', 1);
        update_option('levi_setup_wizard_pending', 0);
        update_option('levi_setup_completed_at', current_time('mysql'));

        wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=4&done=1'));
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
                            <h1><?php echo esc_html('Levi Einrichtungsassistent'); ?></h1>
                            <span class="levi-version"><?php echo esc_html(sprintf('Schritt %d von 4', $step)); ?></span>
                        </div>
                    </div>
                    <div>
                        <a class="levi-btn levi-btn-secondary levi-btn-small" href="<?php echo esc_url(admin_url('admin.php?page=levi-agent-settings')); ?>"><?php echo esc_html('Assistent überspringen →'); ?></a>
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
                            <a class="levi-btn levi-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=2')); ?>"><?php _e('Los geht\'s', 'levi-agent'); ?></a>
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
                                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=1')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
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
                                <label class="levi-form-label" for="levi_thoroughness"><?php _e('Wie viel Chat-Verlauf soll Levi berücksichtigen?', 'levi-agent'); ?></label>
                                <select id="levi_thoroughness" name="levi_thoroughness" class="levi-form-select">
                                    <option value="low" <?php selected($tuning['thoroughness'], 'low'); ?>><?php _e('Wenig (30 Nachrichten)', 'levi-agent'); ?></option>
                                    <option value="balanced" <?php selected($tuning['thoroughness'], 'balanced'); ?>><?php _e('Mittel (50 Nachrichten, empfohlen)', 'levi-agent'); ?></option>
                                    <option value="high" <?php selected($tuning['thoroughness'], 'high'); ?>><?php _e('Viel (80 Nachrichten)', 'levi-agent'); ?></option>
                                </select>
                                <p class="levi-form-help"><?php _e('Levi lädt die letzten X Nachrichten aus eurem Chat als Kontext. Mehr = besseres Gedächtnis, aber langsamere Antworten.', 'levi-agent'); ?></p>
                            </div>

                            <div class="levi-form-group">
                                <label class="levi-form-label" for="levi_safety_mode"><?php _e('Bestätigung vor kritischen Aktionen?', 'levi-agent'); ?></label>
                                <select id="levi_safety_mode" name="levi_safety_mode" class="levi-form-select">
                                    <option value="strict" <?php selected($tuning['safety'], 'strict'); ?>><?php _e('Ja — Levi fragt vor dem Löschen oder Ändern', 'levi-agent'); ?></option>
                                    <option value="standard" <?php selected($tuning['safety'], 'standard'); ?>><?php _e('Nein — Levi führt direkt aus', 'levi-agent'); ?></option>
                                </select>
                                <p class="levi-form-help"><?php _e('Wenn aktiv, fragt Levi bei destruktiven Aktionen (Löschen, Theme-Wechsel, Plugin-Installation) erst nach deiner Bestätigung.', 'levi-agent'); ?></p>
                            </div>

                            <div class="levi-form-group">
                                <label class="levi-form-label" for="levi_speed_mode"><?php _e('Wie viele Arbeitsschritte pro Anfrage?', 'levi-agent'); ?></label>
                                <select id="levi_speed_mode" name="levi_speed_mode" class="levi-form-select">
                                    <option value="fast" <?php selected($tuning['speed'], 'fast'); ?>><?php _e('Wenig (8 Schritte)', 'levi-agent'); ?></option>
                                    <option value="balanced" <?php selected($tuning['speed'], 'balanced'); ?>><?php _e('Standard (12 Schritte, empfohlen)', 'levi-agent'); ?></option>
                                    <option value="careful" <?php selected($tuning['speed'], 'careful'); ?>><?php _e('Viel (18 Schritte)', 'levi-agent'); ?></option>
                                </select>
                                <p class="levi-form-help"><?php _e('Jede Tool-Aktion (Seite lesen, Plugin schreiben, etc.) zählt als ein Schritt. Komplexe Aufgaben brauchen mehr Schritte.', 'levi-agent'); ?></p>
                            </div>

                            <div class="levi-form-actions">
                                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=2')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
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
                            </div>
                            <div class="levi-form-actions">
                                <a class="levi-btn levi-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=levi-agent-settings')); ?>"><?php _e('Zu den Einstellungen', 'levi-agent'); ?></a>
                                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url()); ?>" onclick="setTimeout(function(){ var t = document.getElementById('levi-chat-toggle'); if(t) t.click(); }, 500); return true;"><?php _e('Chat öffnen', 'levi-agent'); ?></a>
                            </div>
                        <?php else: ?>
                            <p><?php _e('Levi lädt jetzt seine Wissensdatenbank herunter und bereitet sie vor. Das kann je nach Server 2–5 Minuten dauern.', 'levi-agent'); ?></p>
                            <p class="levi-form-help levi-hint"><?php _e('Bitte die Seite nicht schließen, bis alle Schritte abgeschlossen sind.', 'levi-agent'); ?></p>

                            <div id="levi-wizard-sync" class="levi-wizard-sync">
                                <div class="levi-wizard-sync-step" data-phase="fetch_docs">
                                    <span class="levi-wizard-sync-icon" aria-hidden="true">⏳</span>
                                    <span class="levi-wizard-sync-label"><?php _e('Dokumentation herunterladen (WordPress, WooCommerce, Elementor)', 'levi-agent'); ?></span>
                                    <span class="levi-wizard-sync-status"></span>
                                </div>
                                <div class="levi-wizard-sync-step" data-phase="sync_memory">
                                    <span class="levi-wizard-sync-icon" aria-hidden="true">⏳</span>
                                    <span class="levi-wizard-sync-label"><?php _e('Wissensdatenbank aufbauen', 'levi-agent'); ?></span>
                                    <span class="levi-wizard-sync-status"></span>
                                </div>
                                <div class="levi-wizard-sync-step" data-phase="snapshot">
                                    <span class="levi-wizard-sync-icon" aria-hidden="true">⏳</span>
                                    <span class="levi-wizard-sync-label"><?php _e('Website-Snapshot erstellen', 'levi-agent'); ?></span>
                                    <span class="levi-wizard-sync-status"></span>
                                </div>
                            </div>

                            <div id="levi-wizard-sync-error" class="levi-notice levi-notice-error" style="display:none;">
                                <p id="levi-wizard-sync-error-msg"></p>
                            </div>

                            <div class="levi-form-actions">
                                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=3')); ?>" id="levi-wizard-back-btn"><?php _e('Zurück', 'levi-agent'); ?></a>
                                <button type="button" class="levi-btn levi-btn-primary" id="levi-wizard-start-btn"><?php _e('Levi jetzt starten', 'levi-agent'); ?></button>
                            </div>

                            <form method="post" action="" id="levi-wizard-complete-form" style="display:none;">
                                <?php wp_nonce_field('levi_setup_wizard_step5'); ?>
                                <input type="hidden" name="levi_setup_action" value="complete_setup">
                            </form>

                            <script>
                            (function() {
                                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                                var nonce = <?php echo wp_json_encode(wp_create_nonce('levi_admin_nonce')); ?>;
                                var startBtn = document.getElementById('levi-wizard-start-btn');
                                var backBtn = document.getElementById('levi-wizard-back-btn');
                                var syncContainer = document.getElementById('levi-wizard-sync');
                                var errorBox = document.getElementById('levi-wizard-sync-error');
                                var errorMsg = document.getElementById('levi-wizard-sync-error-msg');
                                var completeForm = document.getElementById('levi-wizard-complete-form');

                                if (!startBtn) return;

                                function setStepState(phase, state, statusText) {
                                    var el = syncContainer.querySelector('[data-phase="' + phase + '"]');
                                    if (!el) return;
                                    var icon = el.querySelector('.levi-wizard-sync-icon');
                                    var status = el.querySelector('.levi-wizard-sync-status');
                                    if (state === 'running') {
                                        icon.textContent = '⏳';
                                        el.classList.add('is-running');
                                        el.classList.remove('is-done', 'is-error');
                                    } else if (state === 'done') {
                                        icon.textContent = '✅';
                                        el.classList.add('is-done');
                                        el.classList.remove('is-running', 'is-error');
                                    } else if (state === 'error') {
                                        icon.textContent = '❌';
                                        el.classList.add('is-error');
                                        el.classList.remove('is-running', 'is-done');
                                    }
                                    if (statusText) status.textContent = statusText;
                                }

                                function showError(msg) {
                                    errorMsg.textContent = msg;
                                    errorBox.style.display = '';
                                }

                                function runPhase(phase) {
                                    return new Promise(function(resolve, reject) {
                                        setStepState(phase, 'running', '');
                                        var fd = new FormData();
                                        fd.append('action', 'levi_wizard_sync');
                                        fd.append('nonce', nonce);
                                        fd.append('phase', phase);

                                        var xhr = new XMLHttpRequest();
                                        xhr.open('POST', ajaxUrl, true);
                                        xhr.timeout = 600000;
                                        xhr.onload = function() {
                                            try {
                                                var resp = JSON.parse(xhr.responseText);
                                                if (resp.success) {
                                                    resolve(resp.data);
                                                } else {
                                                    reject(resp.data || 'Unbekannter Fehler');
                                                }
                                            } catch(e) {
                                                reject('Ungültige Server-Antwort');
                                            }
                                        };
                                        xhr.onerror = function() { reject('Netzwerkfehler'); };
                                        xhr.ontimeout = function() { reject('Zeitüberschreitung — bitte erneut versuchen'); };
                                        xhr.send(fd);
                                    });
                                }

                                async function runAllPhases() {
                                    startBtn.disabled = true;
                                    startBtn.textContent = <?php echo wp_json_encode(__('Levi wird initialisiert…', 'levi-agent')); ?>;
                                    backBtn.style.display = 'none';
                                    errorBox.style.display = 'none';

                                    try {
                                        // Phase 1: Fetch docs
                                        var fetchResult = await runPhase('fetch_docs');
                                        var sources = fetchResult.result && fetchResult.result.sources ? fetchResult.result.sources : {};
                                        var fetchInfo = Object.keys(sources).length + ' Quellen geladen';
                                        setStepState('fetch_docs', 'done', fetchInfo);

                                        // Phase 2: Sync memory (with retry for partials)
                                        var maxRetries = 5;
                                        var attempt = 0;
                                        var totalVectors = 0;
                                        while (attempt < maxRetries) {
                                            var syncResult = await runPhase('sync_memory');
                                            var loaded = syncResult.results && syncResult.results.loaded ? syncResult.results.loaded : {};
                                            for (var f in loaded) {
                                                if (loaded[f] && loaded[f].vectors) totalVectors += (loaded[f].new_vectors || 0);
                                            }
                                            if (!syncResult.has_partials) break;
                                            attempt++;
                                            setStepState('sync_memory', 'running', 'Fortsetzen… (Durchlauf ' + (attempt + 1) + ')');
                                        }
                                        var syncInfo = totalVectors + ' Vektoren erstellt';
                                        if (syncResult.has_partials) {
                                            syncInfo += ' (wird im Hintergrund fortgesetzt)';
                                        }
                                        setStepState('sync_memory', syncResult.has_partials ? 'error' : 'done', syncInfo);

                                        // Phase 3: Snapshot
                                        await runPhase('snapshot');
                                        setStepState('snapshot', 'done', '');

                                        // All done — submit the completion form
                                        completeForm.submit();

                                    } catch(err) {
                                        showError(typeof err === 'string' ? err : 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.');
                                        startBtn.disabled = false;
                                        startBtn.textContent = <?php echo wp_json_encode(__('Sync fortsetzen', 'levi-agent')); ?>;
                                        backBtn.style.display = '';
                                    }
                                }

                                startBtn.addEventListener('click', function() {
                                    runAllPhases();
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
