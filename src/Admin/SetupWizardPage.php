<?php

namespace Levi\Agent\Admin;

use Levi\Agent\Memory\StateSnapshotService;

class SetupWizardPage {
    private string $pageSlug = 'levi-agent-setup-wizard';
    private string $settingsOption = 'levi_agent_settings';

    private const STEP_LABELS = [
        1 => 'Willkommen',
        2 => 'Verbinden',
        3 => 'Sicherheit',
        4 => 'Vorbereitung',
        5 => 'Fertig',
    ];

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
            case 'save_safety':
                $this->handleSaveSafety();
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
        $settings['max_tool_iterations'] = 30;
        $settings['history_context_limit'] = 20;

        $this->saveSettings($settings);
        update_option('levi_plan_tier', 'pro');

        wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=3&saved=pro'));
        exit;
    }

    private function handleSaveSafety(): void {
        check_admin_referer('levi_setup_wizard_step3');

        $safetyMode = sanitize_key((string) ($_POST['levi_safety_mode'] ?? 'safe'));

        $settings = $this->getSettings();
        $settings['require_confirmation_destructive'] = ($safetyMode === 'fast') ? 0 : 1;
        $settings['tool_profile'] = 'standard';
        $settings['max_tool_iterations'] = 30;
        $settings['history_context_limit'] = 20;

        $this->saveSettings($settings);

        wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=4&saved=safety'));
        exit;
    }

    private function handleCompleteSetup(): void {
        check_admin_referer('levi_setup_wizard_step5');

        update_option('levi_setup_completed', 1);
        update_option('levi_setup_wizard_pending', 0);
        update_option('levi_setup_completed_at', current_time('mysql'));

        wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=5&done=1'));
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

    /* ------------------------------------------------------------------
     *  RENDER
     * ----------------------------------------------------------------*/

    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'levi-agent'));
        }

        $step = isset($_GET['step']) ? absint($_GET['step']) : 1;
        if (!in_array($step, [1, 2, 3, 4, 5], true)) {
            $step = 1;
        }
        $saved = sanitize_key((string) ($_GET['saved'] ?? ''));
        $error = sanitize_key((string) ($_GET['error'] ?? ''));
        $done = isset($_GET['done']) && (int) $_GET['done'] === 1;
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
                            <h1><?php echo esc_html('Levi einrichten'); ?></h1>
                        </div>
                    </div>
                    <div>
                        <a class="levi-btn levi-btn-secondary levi-btn-small" href="<?php echo esc_url(admin_url('admin.php?page=levi-agent-settings')); ?>"><?php esc_html_e('Einrichtung überspringen', 'levi-agent'); ?></a>
                    </div>
                </div>
            </header>

            <main class="levi-settings-main">
                <?php $this->renderStepDots($step); ?>

                <?php
                switch ($step) {
                    case 1:
                        $this->renderStepWelcome();
                        break;
                    case 2:
                        $this->renderStepConnect($saved, $error);
                        break;
                    case 3:
                        $this->renderStepSafety($saved);
                        break;
                    case 4:
                        $this->renderStepSync($done);
                        break;
                    case 5:
                        $this->renderStepDone();
                        break;
                }
                ?>
            </main>
        </div>
        <?php
    }

    private function renderStepDots(int $current): void {
        ?>
        <nav class="levi-step-dots" aria-label="Fortschritt">
            <?php foreach (self::STEP_LABELS as $num => $label): ?>
                <div class="levi-step-dot <?php echo $num < $current ? 'is-done' : ($num === $current ? 'is-current' : ''); ?>">
                    <span class="levi-step-dot-circle">
                        <?php if ($num < $current): ?>
                            <span class="dashicons dashicons-yes"></span>
                        <?php else: ?>
                            <?php echo esc_html((string) $num); ?>
                        <?php endif; ?>
                    </span>
                    <span class="levi-step-dot-label"><?php echo esc_html($label); ?></span>
                </div>
                <?php if ($num < count(self::STEP_LABELS)): ?>
                    <div class="levi-step-dot-line <?php echo $num < $current ? 'is-done' : ''; ?>"></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /* ---- Step 1: Welcome ------------------------------------------ */

    private function renderStepWelcome(): void {
        ?>
        <section class="levi-form-card levi-setup-card levi-setup-card-welcome">
            <div class="levi-welcome-hero">
                <div class="levi-welcome-avatar">
                    <img
                        src="<?php echo esc_url(LEVI_AGENT_PLUGIN_URL . 'assets/images/levi-avatar-icon.webp'); ?>"
                        alt="Levi"
                        width="80"
                        height="80"
                    >
                </div>
                <div class="levi-welcome-bubble">
                    <p><?php esc_html_e('Hi! Ich bin Levi, dein KI-Assistent für WordPress. Ich kann dir beim Erstellen von Seiten helfen, Plugins einrichten, Code anpassen und Fragen zu deiner Website beantworten.', 'levi-agent'); ?></p>
                    <p class="levi-welcome-bubble-sub"><?php esc_html_e('In 5–10 Minuten bin ich einsatzbereit!', 'levi-agent'); ?></p>
                </div>
            </div>

            <div class="levi-feature-cards">
                <div class="levi-feature-card">
                    <span class="levi-feature-card-icon">&#128221;</span>
                    <strong><?php esc_html_e('Inhalte', 'levi-agent'); ?></strong>
                    <p><?php esc_html_e('Seiten, Beiträge und Menüs erstellen und bearbeiten', 'levi-agent'); ?></p>
                </div>
                <div class="levi-feature-card">
                    <span class="levi-feature-card-icon">&#128295;</span>
                    <strong><?php esc_html_e('Technik', 'levi-agent'); ?></strong>
                    <p><?php esc_html_e('Plugins, Themes und Code — ich kümmere mich drum', 'levi-agent'); ?></p>
                </div>
                <div class="levi-feature-card">
                    <span class="levi-feature-card-icon">&#128172;</span>
                    <strong><?php esc_html_e('Fragen', 'levi-agent'); ?></strong>
                    <p><?php esc_html_e('Frag mich alles über deine WordPress-Seite', 'levi-agent'); ?></p>
                </div>
            </div>

            <div class="levi-form-actions levi-form-actions-center">
                <a class="levi-btn levi-btn-primary levi-btn-lg" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=2')); ?>">
                    <?php esc_html_e('Levi einrichten', 'levi-agent'); ?> &rarr;
                </a>
            </div>
        </section>
        <?php
    }

    /* ---- Step 2: Connect ------------------------------------------ */

    private function renderStepConnect(string $saved, string $error): void {
        $oauth = new OpenRouterOAuth();
        $isOAuthConnected = $oauth->isOAuthConnected();
        ?>
        <section class="levi-form-card levi-setup-card">
            <h2><?php esc_html_e('Ich brauche Zugang zu einem KI-Modell', 'levi-agent'); ?></h2>
            <p><?php esc_html_e('Damit ich denken kann, brauche ich Zugang zu einem KI-Modell. Dafür nutze ich OpenRouter — einen Dienst, der verschiedene KI-Modelle anbietet. Du zahlst dort nur, was du tatsächlich verbrauchst.', 'levi-agent'); ?></p>

            <?php if ($isOAuthConnected): ?>
                <div class="levi-notice levi-notice-success">
                    <p><span class="dashicons dashicons-yes-alt" style="color: var(--levi-success);"></span> <?php esc_html_e('Erfolgreich mit OpenRouter verbunden!', 'levi-agent'); ?></p>
                </div>
                <div class="levi-form-actions">
                    <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=1')); ?>"><?php esc_html_e('Zurück', 'levi-agent'); ?></a>
                    <a class="levi-btn levi-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=3&saved=pro')); ?>"><?php esc_html_e('Weiter', 'levi-agent'); ?> &rarr;</a>
                </div>

            <?php else: ?>
                <?php if (!empty($_GET['oauth_error'])): ?>
                    <div class="levi-notice levi-notice-error" style="margin-bottom: 16px;">
                        <p><?php esc_html_e('Verbindung fehlgeschlagen. Bitte versuche es erneut oder nutze einen manuellen API-Key.', 'levi-agent'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($error === 'missing_key'): ?>
                    <div class="levi-notice levi-notice-error">
                        <p><?php esc_html_e('Bitte gib einen API-Key ein.', 'levi-agent'); ?></p>
                    </div>
                <?php endif; ?>

                <?php $oauthUrl = $oauth->getAuthUrl('wizard'); ?>

                <div class="levi-connect-primary">
                    <a href="<?php echo esc_url($oauthUrl); ?>" class="levi-btn levi-btn-primary levi-btn-lg levi-btn-connect">
                        <span class="dashicons dashicons-admin-links"></span>
                        <?php esc_html_e('Mit OpenRouter verbinden', 'levi-agent'); ?>
                    </a>
                    <p class="levi-form-help"><?php esc_html_e('Du wirst kurz zu OpenRouter weitergeleitet. Falls du noch kein Konto hast, kannst du dort in 30 Sekunden eins erstellen.', 'levi-agent'); ?></p>
                </div>

                <div class="levi-cost-box">
                    <strong><?php esc_html_e('Was kostet das?', 'levi-agent'); ?></strong>
                    <p><?php esc_html_e('Eine normale Nachricht an Levi kostet ca. 1–5 Cent. Für komplexere Aufgaben (z.B. ein Plugin bauen) können es 10–50 Cent pro Nachricht sein.', 'levi-agent'); ?></p>
                    <div class="levi-cost-box-highlight">
                        <?php esc_html_e('5 $ Startguthaben reichen für Hunderte Nachrichten!', 'levi-agent'); ?>
                    </div>
                </div>

                <div class="levi-divider-or">
                    <span><?php esc_html_e('oder', 'levi-agent'); ?></span>
                </div>

                <details class="levi-collapsible-section">
                    <summary class="levi-collapsible-trigger"><?php esc_html_e('Ich habe schon einen API-Key', 'levi-agent'); ?></summary>
                    <div class="levi-collapsible-content">
                        <form method="post" action="">
                            <?php wp_nonce_field('levi_setup_wizard_step2'); ?>
                            <input type="hidden" name="levi_setup_action" value="save_pro_setup">

                            <div class="levi-form-group">
                                <label class="levi-form-label" for="levi_openrouter_api_key"><?php esc_html_e('OpenRouter API-Key', 'levi-agent'); ?></label>
                                <input id="levi_openrouter_api_key" name="levi_openrouter_api_key" type="password" class="levi-form-input" placeholder="sk-or-..." required>
                                <p class="levi-form-help"><?php esc_html_e('Hole deinen Key auf', 'levi-agent'); ?> <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a></p>
                            </div>

                            <button type="submit" class="levi-btn levi-btn-primary"><?php esc_html_e('Key speichern & weiter', 'levi-agent'); ?></button>
                        </form>
                    </div>
                </details>

                <div class="levi-form-actions">
                    <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=1')); ?>"><?php esc_html_e('Zurück', 'levi-agent'); ?></a>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /* ---- Step 3: Safety ------------------------------------------- */

    private function renderStepSafety(string $saved): void {
        $settings = $this->getSettings();
        $currentSafety = ((int) ($settings['require_confirmation_destructive'] ?? 1)) === 1 ? 'safe' : 'fast';
        ?>
        <section class="levi-form-card levi-setup-card">
            <h2><?php esc_html_e('Wie sicher soll Levi arbeiten?', 'levi-agent'); ?></h2>
            <p><?php esc_html_e('Soll Levi dich fragen, bevor er etwas Wichtiges ändert? Zum Beispiel Seiten löschen, Plugins installieren oder Themes wechseln.', 'levi-agent'); ?></p>

            <?php if ($saved === 'pro'): ?>
                <div class="levi-notice levi-notice-success">
                    <p><?php esc_html_e('Verbindung erfolgreich gespeichert!', 'levi-agent'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('levi_setup_wizard_step3'); ?>
                <input type="hidden" name="levi_setup_action" value="save_safety">

                <div class="levi-safety-cards">
                    <label class="levi-safety-card <?php echo $currentSafety === 'safe' ? 'is-selected' : ''; ?>">
                        <input type="radio" name="levi_safety_mode" value="safe" <?php checked($currentSafety, 'safe'); ?>>
                        <span class="levi-safety-card-icon">&#128737;&#65039;</span>
                        <strong><?php esc_html_e('Sicher', 'levi-agent'); ?></strong>
                        <span class="levi-safety-card-badge"><?php esc_html_e('empfohlen', 'levi-agent'); ?></span>
                        <p><?php esc_html_e('Levi fragt bei kritischen Aktionen nach deiner Bestätigung, bevor er etwas ändert.', 'levi-agent'); ?></p>
                    </label>

                    <label class="levi-safety-card <?php echo $currentSafety === 'fast' ? 'is-selected' : ''; ?>">
                        <input type="radio" name="levi_safety_mode" value="fast" <?php checked($currentSafety, 'fast'); ?>>
                        <span class="levi-safety-card-icon">&#9889;</span>
                        <strong><?php esc_html_e('Schnell', 'levi-agent'); ?></strong>
                        <p><?php esc_html_e('Levi führt alles sofort aus, ohne vorher zu fragen. Für erfahrene Nutzer.', 'levi-agent'); ?></p>
                    </label>
                </div>

                <p class="levi-form-help" style="margin-top: var(--levi-space-md);"><?php esc_html_e('Du kannst das jederzeit in den Einstellungen ändern.', 'levi-agent'); ?></p>

                <div class="levi-form-actions">
                    <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=2')); ?>"><?php esc_html_e('Zurück', 'levi-agent'); ?></a>
                    <button type="submit" class="levi-btn levi-btn-primary"><?php esc_html_e('Weiter', 'levi-agent'); ?> &rarr;</button>
                </div>
            </form>
        </section>
        <?php
    }

    /* ---- Step 4: Sync --------------------------------------------- */

    private function renderStepSync(bool $done): void {
        if ($done) {
            wp_safe_redirect(admin_url('admin.php?page=' . $this->pageSlug . '&step=5&done=1'));
            exit;
        }
        ?>
        <section class="levi-form-card levi-setup-card">
            <h2><?php esc_html_e('Levi lernt deine Website kennen', 'levi-agent'); ?></h2>
            <p><?php esc_html_e('Levi schaut sich jetzt deine Website an und bereitet sich vor. Das dauert ein paar Minuten — du kannst einfach zusehen.', 'levi-agent'); ?></p>
            <p class="levi-form-help levi-hint"><?php esc_html_e('Bitte lass diese Seite offen, bis alle Schritte abgeschlossen sind.', 'levi-agent'); ?></p>

            <div id="levi-wizard-sync" class="levi-wizard-sync">
                <div class="levi-wizard-sync-step" data-phase="fetch_docs">
                    <span class="levi-wizard-sync-icon" aria-hidden="true">&#128218;</span>
                    <span class="levi-wizard-sync-label"><?php esc_html_e('Anleitungen herunterladen ...', 'levi-agent'); ?></span>
                    <span class="levi-wizard-sync-status"></span>
                </div>
                <div class="levi-wizard-sync-step" data-phase="sync_memory">
                    <span class="levi-wizard-sync-icon" aria-hidden="true">&#129504;</span>
                    <span class="levi-wizard-sync-label"><?php esc_html_e('Deine Plugins und Themes kennenlernen ...', 'levi-agent'); ?></span>
                    <span class="levi-wizard-sync-status"></span>
                </div>
                <div class="levi-wizard-sync-step" data-phase="snapshot">
                    <span class="levi-wizard-sync-icon" aria-hidden="true">&#128248;</span>
                    <span class="levi-wizard-sync-label"><?php esc_html_e('Schnappschuss deiner Seite erstellen ...', 'levi-agent'); ?></span>
                    <span class="levi-wizard-sync-status"></span>
                </div>
            </div>

            <div id="levi-wizard-sync-error" class="levi-notice levi-notice-error" style="display:none;">
                <p id="levi-wizard-sync-error-msg"></p>
            </div>

            <div class="levi-form-actions">
                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . $this->pageSlug . '&step=3')); ?>" id="levi-wizard-back-btn"><?php esc_html_e('Zurück', 'levi-agent'); ?></a>
                <button type="button" class="levi-btn levi-btn-primary" id="levi-wizard-start-btn"><?php esc_html_e('Jetzt starten', 'levi-agent'); ?></button>
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
                    el.classList.remove('is-running', 'is-done', 'is-error');
                    if (state === 'running') {
                        el.classList.add('is-running');
                    } else if (state === 'done') {
                        icon.innerHTML = '&#10004;';
                        el.classList.add('is-done');
                    } else if (state === 'error') {
                        icon.innerHTML = '&#10060;';
                        el.classList.add('is-error');
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
                    startBtn.textContent = <?php echo wp_json_encode(__('Levi wird vorbereitet …', 'levi-agent')); ?>;
                    backBtn.style.display = 'none';
                    errorBox.style.display = 'none';

                    try {
                        await runPhase('fetch_docs');
                        setStepState('fetch_docs', 'done', '');

                        var maxRetries = 5;
                        var attempt = 0;
                        var syncResult;
                        while (attempt < maxRetries) {
                            syncResult = await runPhase('sync_memory');
                            if (!syncResult.has_partials) break;
                            attempt++;
                            setStepState('sync_memory', 'running', 'Fortsetzen … (Durchlauf ' + (attempt + 1) + ')');
                        }
                        setStepState('sync_memory', syncResult.has_partials ? 'error' : 'done', '');

                        await runPhase('snapshot');
                        setStepState('snapshot', 'done', '');

                        completeForm.submit();

                    } catch(err) {
                        showError(typeof err === 'string' ? err : 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.');
                        startBtn.disabled = false;
                        startBtn.textContent = <?php echo wp_json_encode(__('Erneut versuchen', 'levi-agent')); ?>;
                        backBtn.style.display = '';
                    }
                }

                startBtn.addEventListener('click', function() {
                    runAllPhases();
                });
            })();
            </script>
        </section>
        <?php
    }

    /* ---- Step 5: Done --------------------------------------------- */

    private function renderStepDone(): void {
        ?>
        <section class="levi-form-card levi-setup-card levi-setup-card-done">
            <div class="levi-done-hero">
                <span class="levi-done-checkmark" aria-hidden="true">&#127881;</span>
                <h2><?php esc_html_e('Levi ist bereit!', 'levi-agent'); ?></h2>
                <p><?php esc_html_e('Du findest Levi unten rechts als Chat-Fenster — auf jeder Seite im Admin-Bereich.', 'levi-agent'); ?></p>
            </div>

            <div class="levi-done-tip">
                <strong><?php esc_html_e('Probier es aus:', 'levi-agent'); ?></strong>
                <p><?php esc_html_e('Schreib „Hallo Levi!" oder frag „Was kann ich an meiner Website verbessern?"', 'levi-agent'); ?></p>
            </div>

            <div class="levi-form-actions levi-form-actions-center">
                <a class="levi-btn levi-btn-primary levi-btn-lg" href="<?php echo esc_url(admin_url()); ?>" onclick="setTimeout(function(){ var t = document.getElementById('levi-chat-toggle'); if(t) t.click(); }, 500); return true;">
                    &#128172; <?php esc_html_e('Mit Levi chatten', 'levi-agent'); ?>
                </a>
                <a class="levi-btn levi-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=levi-agent-settings')); ?>">
                    <?php esc_html_e('Zu den Einstellungen', 'levi-agent'); ?>
                </a>
            </div>
        </section>
        <?php
    }
}
