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

        wp_enqueue_style(
            'levi-agent-admin-tailwind',
            LEVI_AGENT_PLUGIN_URL . 'assets/css/admin-tailwind.css',
            ['levi-agent-setup-wizard'],
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
        <div class="levi-settings-wrap levi-setup-wrap min-h-screen bg-base-100 text-base-content font-mono" data-theme="levi">
            <header class="border-b border-base-300 bg-base-200 px-6 py-6 md:px-12">
                <div class="mx-auto flex max-w-4xl items-center justify-between">
                    <div class="flex items-center gap-4">
                        <span class="text-4xl">🤖</span>
                        <div>
                            <h1 class="m-0 text-2xl font-extrabold text-base-content"><?php echo esc_html('Levi Einrichtungsassistent'); ?></h1>
                            <span class="mt-1 inline-block rounded-full border border-base-300 bg-base-300 px-2 py-0.5 text-xs font-semibold text-base-content/70"><?php echo esc_html(sprintf('Schritt %d von 4', $step)); ?></span>
                        </div>
                    </div>
                    <a class="btn btn-outline btn-secondary btn-sm" href="<?php echo esc_url(admin_url('options-general.php?page=levi-agent-settings')); ?>"><?php echo esc_html('Assistent überspringen →'); ?></a>
                </div>
            </header>

            <main class="mx-auto max-w-4xl px-6 py-12 md:px-12">
                <div class="mb-8 h-2 w-full overflow-hidden rounded-full bg-primary/20">
                    <div class="h-full rounded-full bg-primary transition-[width] duration-300" style="width: <?php echo esc_attr((string) $progressPercent); ?>%;"></div>
                </div>

                <?php if ($step === 1): ?>
                    <section class="card card-border bg-base-300 p-6">
                        <h2 class="mb-4 text-xl font-bold"><?php _e('Willkommen bei Levi', 'levi-agent'); ?></h2>
                        <p class="mb-4 text-base-content/70"><?php _e('Danke, dass du Levi nutzt! Levi ist dein KI-Assistent für WordPress. Er kann:', 'levi-agent'); ?></p>
                        <ul class="mb-4 list-disc pl-6 text-base-content/70">
                            <li class="mb-2"><?php _e('Seiten und Beiträge erstellen, bearbeiten und verwalten', 'levi-agent'); ?></li>
                            <li class="mb-2"><?php _e('Plugins installieren, aktivieren und konfigurieren', 'levi-agent'); ?></li>
                            <li class="mb-2"><?php _e('Fragen zu deiner WordPress-Seite beantworten', 'levi-agent'); ?></li>
                            <li class="mb-2"><?php _e('Code generieren und technische Aufgaben übernehmen', 'levi-agent'); ?></li>
                        </ul>
                        <p class="mb-6 text-base-content/70"><?php _e('Auf den nächsten Seiten führen wir dich sicher durch die Einrichtung.', 'levi-agent'); ?></p>

                        <div class="flex gap-4">
                            <a class="btn btn-primary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=2')); ?>"><?php _e('Los geht\'s', 'levi-agent'); ?></a>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($step === 2): ?>
                    <section class="card card-border bg-base-300 p-6">
                        <h2 class="mb-4 text-xl font-bold"><?php _e('Schritt 2: API-Key', 'levi-agent'); ?></h2>
                        <p class="mb-4 text-base-content/70"><?php _e('Levi nutzt OpenRouter mit Kimi K2.5 als KI-Modell. Erstelle einen API-Key auf openrouter.ai — die Kosten werden direkt von deinem OpenRouter-Konto abgebucht.', 'levi-agent'); ?></p>

                        <div class="mb-6 rounded-lg border border-primary/30 bg-primary/10 px-4 py-4 text-base-content/70">
                            <strong class="mb-2 block text-base-content"><?php _e('So bekommst du deinen Key:', 'levi-agent'); ?></strong>
                            <ol class="list-decimal pl-6">
                                <li class="mb-1"><?php _e('Gehe zu', 'levi-agent'); ?> <a href="https://openrouter.ai/keys" target="_blank" rel="noopener" class="link link-primary">openrouter.ai/keys</a></li>
                                <li class="mb-1"><?php _e('Erstelle einen Account (Google/GitHub Login)', 'levi-agent'); ?></li>
                                <li class="mb-1"><?php _e('Lade Credits auf (mind. 5 $) und erstelle einen API-Key', 'levi-agent'); ?></li>
                                <li class="mb-1"><?php _e('Füge den Key unten ein — fertig!', 'levi-agent'); ?></li>
                            </ol>
                        </div>

                        <?php if ($error === 'missing_key'): ?>
                            <div class="alert alert-error mb-4">
                                <p class="m-0"><?php _e('Bitte gib einen API-Key ein.', 'levi-agent'); ?></p>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <?php wp_nonce_field('levi_setup_wizard_step2'); ?>
                            <input type="hidden" name="levi_setup_action" value="save_pro_setup">

                            <div class="form-control mb-4">
                                <label class="label-text mb-1 font-medium" for="levi_openrouter_api_key"><?php _e('OpenRouter API-Key', 'levi-agent'); ?></label>
                                <input id="levi_openrouter_api_key" name="levi_openrouter_api_key" type="password" class="input input-bordered w-full" placeholder="sk-or-..." required>
                                <p class="helper-text mt-1"><?php _e('Dein Key wird sicher gespeichert und nur an OpenRouter gesendet.', 'levi-agent'); ?></p>
                            </div>

                            <p class="helper-text mb-6"><?php _e('Modell: Kimi K2.5 (Moonshot)', 'levi-agent'); ?></p>

                            <div class="flex gap-4">
                                <a class="btn btn-outline btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=1')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
                                <button type="submit" class="btn btn-primary"><?php _e('Weiter', 'levi-agent'); ?></button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($step === 3): ?>
                    <section class="card card-border bg-base-300 p-6">
                        <h2 class="mb-4 text-xl font-bold"><?php _e('Schritt 3: Levi auf dich abstimmen', 'levi-agent'); ?></h2>
                        <p class="mb-6 text-base-content/70"><?php _e('Hier stellst du ein, wie gründlich, sicher und schnell Levi arbeiten soll. Du kannst alles später in den Einstellungen ändern.', 'levi-agent'); ?></p>

                        <?php if ($saved === 'pro'): ?>
                            <div class="alert alert-success mb-6">
                                <p class="m-0"><?php _e('Pro-Setup wurde erfolgreich gespeichert!', 'levi-agent'); ?></p>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <?php wp_nonce_field('levi_setup_wizard_step4'); ?>
                            <input type="hidden" name="levi_setup_action" value="save_tuning">

                            <div class="form-control mb-6">
                                <label class="label-text mb-2 font-medium"><?php _e('Welche Fähigkeiten soll Levi haben?', 'levi-agent'); ?></label>
                                <p class="helper-text mb-3"><?php _e('Bestimmt, welche Tools Levi nutzen darf. Später in den Einstellungen änderbar.', 'levi-agent'); ?></p>
                                <?php
                                $profiles = \Levi\Agent\AI\Tools\Registry::getProfileLabels();
                                $currentProfile = $tuning['tool_profile'];
                                foreach ($profiles as $profileKey => $profileData):
                                    $isActive = $currentProfile === $profileKey;
                                ?>
                                <label class="mb-2 flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors <?php echo $isActive ? 'border-primary bg-primary/10' : 'border-base-300 hover:border-primary/50'; ?>">
                                    <input type="radio" name="levi_tool_profile" value="<?php echo esc_attr($profileKey); ?>" <?php checked($currentProfile, $profileKey); ?> class="radio radio-primary mt-1">
                                    <span>
                                        <strong class="block"><?php echo esc_html($profileData['label']); ?></strong>
                                        <span class="text-sm text-base-content/70"><?php echo esc_html($profileData['description']); ?></span>
                                    </span>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="form-control mb-6">
                                <label class="label-text mb-1 font-medium" for="levi_thoroughness"><?php _e('Wie gründlich soll Levi lesen?', 'levi-agent'); ?></label>
                                <select id="levi_thoroughness" name="levi_thoroughness" class="select select-bordered w-full">
                                    <option value="low" <?php selected($tuning['thoroughness'], 'low'); ?>><?php _e('Schnell — liest nur das Nötigste', 'levi-agent'); ?></option>
                                    <option value="balanced" <?php selected($tuning['thoroughness'], 'balanced'); ?>><?php _e('Ausgewogen (empfohlen) — guter Kompromiss', 'levi-agent'); ?></option>
                                    <option value="high" <?php selected($tuning['thoroughness'], 'high'); ?>><?php _e('Sehr gründlich — liest mehr Inhalte, braucht länger', 'levi-agent'); ?></option>
                                </select>
                                <p class="helper-text mt-1"><?php _e('Beeinflusst, wie viel Kontext Levi bei jeder Anfrage berücksichtigt.', 'levi-agent'); ?></p>
                            </div>

                            <div class="form-control mb-6">
                                <label class="label-text mb-1 font-medium" for="levi_safety_mode"><?php _e('Wie vorsichtig soll Levi bei Änderungen sein?', 'levi-agent'); ?></label>
                                <select id="levi_safety_mode" name="levi_safety_mode" class="select select-bordered w-full">
                                    <option value="strict" <?php selected($tuning['safety'], 'strict'); ?>><?php _e('Sicher — fragt vor dem Löschen/Ändern nach', 'levi-agent'); ?></option>
                                    <option value="standard" <?php selected($tuning['safety'], 'standard'); ?>><?php _e('Standard — weniger Nachfragen, schneller', 'levi-agent'); ?></option>
                                </select>
                                <p class="helper-text mt-1"><?php _e('Im sicheren Modus bestätigt Levi jede Lösch- oder Änderungs-Aktion mit dir.', 'levi-agent'); ?></p>
                            </div>

                            <div class="form-control mb-6">
                                <label class="label-text mb-1 font-medium" for="levi_speed_mode"><?php _e('Wie schnell soll Levi antworten?', 'levi-agent'); ?></label>
                                <select id="levi_speed_mode" name="levi_speed_mode" class="select select-bordered w-full">
                                    <option value="fast" <?php selected($tuning['speed'], 'fast'); ?>><?php _e('Schnell — weniger Schritte, kürzere Antworten', 'levi-agent'); ?></option>
                                    <option value="balanced" <?php selected($tuning['speed'], 'balanced'); ?>><?php _e('Ausgewogen (empfohlen)', 'levi-agent'); ?></option>
                                    <option value="careful" <?php selected($tuning['speed'], 'careful'); ?>><?php _e('Sorgfältig — mehr Schritte, gründlichere Ergebnisse', 'levi-agent'); ?></option>
                                </select>
                                <p class="helper-text mt-1"><?php _e('Bestimmt, wie viele Tool-Schritte Levi pro Anfrage ausführen darf.', 'levi-agent'); ?></p>
                            </div>

                            <div class="flex gap-4">
                                <a class="btn btn-outline btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=2')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
                                <button type="submit" class="btn btn-primary"><?php _e('Einstellungen übernehmen', 'levi-agent'); ?></button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($step === 4): ?>
                    <section class="card card-border bg-base-300 p-6">
                        <h2 class="mb-4 text-xl font-bold"><?php _e('Schritt 4: Abschluss', 'levi-agent'); ?></h2>

                        <?php if ($saved === 'tuning'): ?>
                            <div class="alert alert-success mb-6">
                                <p class="m-0"><?php _e('Deine Einstellungen wurden gespeichert!', 'levi-agent'); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($done): ?>
                            <div class="mb-6 rounded-lg border border-success/50 bg-success/10 p-4 text-success">
                                <strong class="block"><?php _e('Levi ist eingerichtet!', 'levi-agent'); ?></strong>
                                <p class="m-0 mt-1"><?php echo esc_html(sprintf('Snapshot-Status: %s', $this->translateSnapshotStatus($snapshot !== '' ? $snapshot : 'done'))); ?></p>
                                <?php if ($planTier !== ''): ?>
                                    <p class="m-0 mt-1"><?php echo esc_html(sprintf('Aktiver Plan: %s', 'Pro')); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="flex gap-4">
                                <a class="btn btn-primary" href="<?php echo esc_url(admin_url('options-general.php?page=levi-agent-settings')); ?>"><?php _e('Zu den Einstellungen', 'levi-agent'); ?></a>
                                <a class="btn btn-outline btn-secondary" href="<?php echo esc_url(admin_url()); ?>" onclick="setTimeout(function(){ var t = document.getElementById('levi-chat-toggle'); if(t) t.click(); }, 500); return true;"><?php _e('Chat öffnen', 'levi-agent'); ?></a>
                            </div>
                        <?php else: ?>
                            <p class="mb-4 text-base-content/70"><?php _e('Zum Abschluss erstellt Levi einen Snapshot deiner WordPress-Instanz. Damit kennt er deine Seite, Plugins und Einstellungen.', 'levi-agent'); ?></p>
                            <p class="mb-6 rounded-r border-l-4 border-primary bg-primary/10 px-4 py-2 text-sm text-base-content/70"><?php _e('Das kann einige Sekunden dauern. Bitte die Seite nicht schließen.', 'levi-agent'); ?></p>

                            <form method="post" action="" id="levi-setup-complete-form">
                                <?php wp_nonce_field('levi_setup_wizard_step5'); ?>
                                <input type="hidden" name="levi_setup_action" value="complete_setup">
                                <div class="flex gap-4">
                                    <a class="btn btn-outline btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=' . $this->pageSlug . '&step=3')); ?>"><?php _e('Zurück', 'levi-agent'); ?></a>
                                    <button type="submit" class="btn btn-primary" id="levi-setup-complete-btn"><?php _e('Levi jetzt starten', 'levi-agent'); ?></button>
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
