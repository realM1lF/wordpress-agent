<?php

namespace Levi\Agent\Memory;

use WP_Error;

class StateSnapshotService {
    public const EVENT_HOOK = 'levi_agent_daily_state_snapshot';
    public const MEMORY_SYNC_HOOK = 'levi_agent_daily_memory_sync';
    public const DOCS_FETCH_HOOK = 'levi_daily_docs_fetch_and_sync';
    public const EVENT_SNAPSHOT_HOOK = 'levi_agent_event_snapshot';
    private const META_OPTION = 'levi_agent_state_snapshot_meta';
    private const LAST_OPTION = 'levi_agent_state_snapshot_last';
    private const MEMORY_SYNC_OPTION = 'levi_agent_memory_sync_meta';
    private const EVENT_DEBOUNCE_KEY = 'levi_snapshot_event_debounce';

    public function __construct() {
        add_action('init', [$this, 'ensureSchedule']);
        add_action(self::EVENT_HOOK, [$this, 'runDailySync']);
        add_action(self::MEMORY_SYNC_HOOK, [self::class, 'runMemorySync']);
        add_action(self::MEMORY_SYNC_HOOK . '_initial', [self::class, 'runInitialSync']);
        add_action(self::DOCS_FETCH_HOOK, [self::class, 'runDocsFetchAndSync']);
        add_action('admin_init', [$this, 'maybeRunOverdueTasks']);

        add_action(self::EVENT_SNAPSHOT_HOOK, [$this, 'runDailySync']);
        add_action('activated_plugin', [self::class, 'scheduleSnapshotUpdate']);
        add_action('deactivated_plugin', [self::class, 'scheduleSnapshotUpdate']);
        add_action('switch_theme', [self::class, 'scheduleSnapshotUpdate']);
    }

    public function ensureSchedule(): void {
        // State snapshot at 12:00
        $snapshotNextRun = self::calculateNextRunTimestamp(12, 0);
        $timestamp = wp_next_scheduled(self::EVENT_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::EVENT_HOOK);
        }
        wp_schedule_event($snapshotNextRun, 'daily', self::EVENT_HOOK);

        // Docs fetch + memory sync at 04:00
        $docsNextRun = self::calculateNextRunTimestamp(4, 0);
        $timestamp = wp_next_scheduled(self::DOCS_FETCH_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::DOCS_FETCH_HOOK);
        }
        wp_schedule_event($docsNextRun, 'daily', self::DOCS_FETCH_HOOK);

        // Unschedule the old standalone memory sync hook (migrated into docs fetch)
        $timestamp = wp_next_scheduled(self::MEMORY_SYNC_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::MEMORY_SYNC_HOOK);
        }
    }

    /**
     * On admin page loads, nudge WP-Cron if our events are overdue.
     * Also triggers a one-time initial docs fetch + memory sync after first activation.
     */
    public function maybeRunOverdueTasks(): void {
        if (wp_doing_ajax() || !current_user_can('manage_options')) {
            return;
        }

        $now = time();

        foreach ([self::EVENT_HOOK, self::DOCS_FETCH_HOOK] as $hook) {
            $nextScheduled = wp_next_scheduled($hook);
            if ($nextScheduled !== false && $nextScheduled <= $now) {
                spawn_cron();
                return;
            }
        }

        if (get_option('levi_initial_memory_sync_done', false) === false) {
            update_option('levi_initial_memory_sync_done', 'pending', false);
            wp_schedule_single_event(time(), self::MEMORY_SYNC_HOOK . '_initial');
            spawn_cron();
            return;
        }

        $this->maybeRetryPartialSync();
    }

    private function maybeRetryPartialSync(): void {
        if (get_transient('levi_partial_retry_lock')) {
            return;
        }

        $retries = (int) get_option('levi_partial_sync_retries', 0);
        if ($retries >= 6) {
            return;
        }

        $needsRetry = false;
        $syncMeta = get_option(self::MEMORY_SYNC_OPTION, []);

        if (!empty($syncMeta['has_partials'])) {
            $needsRetry = true;
        }

        if (!$needsRetry) {
            if (get_transient('levi_changes_check_cooldown')) {
                return;
            }
            set_transient('levi_changes_check_cooldown', '1', 15 * MINUTE_IN_SECONDS);

            $loader = new MemoryLoader();
            $changes = $loader->checkForChanges();
            if (!empty($changes['identity']) || !empty($changes['reference'])) {
                $needsRetry = true;
            }
        }

        if (!$needsRetry) {
            return;
        }

        update_option('levi_partial_sync_retries', $retries + 1, false);
        set_transient('levi_partial_retry_lock', time(), 10 * MINUTE_IN_SECONDS);

        if (!wp_next_scheduled(self::MEMORY_SYNC_HOOK)) {
            wp_schedule_single_event(time(), self::MEMORY_SYNC_HOOK);
        }
        spawn_cron();
    }

    /**
     * Schedule a snapshot update with debounce (max 1 per 5 minutes).
     * Uses wp_schedule_single_event with a 5-second delay so WordPress
     * has finished updating its internal state before we capture.
     */
    public static function scheduleSnapshotUpdate(): void {
        if (get_transient(self::EVENT_DEBOUNCE_KEY)) {
            return;
        }
        set_transient(self::EVENT_DEBOUNCE_KEY, '1', 5 * MINUTE_IN_SECONDS);

        if (!wp_next_scheduled(self::EVENT_SNAPSHOT_HOOK)) {
            wp_schedule_single_event(time() + 5, self::EVENT_SNAPSHOT_HOOK);
        }
    }

    public static function scheduleEvent(): void {
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event(self::calculateNextRunTimestamp(12, 0), 'daily', self::EVENT_HOOK);
        }
        if (!wp_next_scheduled(self::DOCS_FETCH_HOOK)) {
            wp_schedule_event(self::calculateNextRunTimestamp(4, 0), 'daily', self::DOCS_FETCH_HOOK);
        }
    }

    public static function unscheduleEvent(): void {
        $hooks = [
            self::EVENT_HOOK,
            self::MEMORY_SYNC_HOOK,
            self::MEMORY_SYNC_HOOK . '_initial',
            self::DOCS_FETCH_HOOK,
            self::EVENT_SNAPSHOT_HOOK,
        ];
        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            while ($timestamp !== false) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
        delete_option('levi_initial_memory_sync_done');
        delete_transient(self::EVENT_DEBOUNCE_KEY);
    }

    public function runDailySync(): void {
        $snapshot = $this->collectSnapshot();
        $normalized = $snapshot;
        unset($normalized['captured_at']);
        $snapshotHash = hash('sha256', (string) wp_json_encode($normalized));

        $previous = get_option(self::LAST_OPTION, []);
        if (!is_array($previous)) {
            $previous = [];
        }
        $previousHash = (string) ($previous['hash'] ?? '');
        $previousSnapshot = is_array($previous['snapshot'] ?? null) ? $previous['snapshot'] : [];

        $meta = [
            'checked_at' => current_time('mysql'),
            'captured_at' => $snapshot['captured_at'],
            'hash' => $snapshotHash,
            'snapshot_stored' => false,
            'memory_type' => 'state_snapshot',
            'status' => 'unchanged',
            'last_diff_summary' => 'No structural changes detected since previous snapshot.',
        ];

        if ($snapshotHash === $previousHash) {
            update_option(self::META_OPTION, $meta, false);
            return;
        }

        $diffSummary = $this->buildDiffSummary($previousSnapshot, $snapshot);
        $memoryText = $this->buildMemoryText($snapshot, $diffSummary, $snapshotHash);
        $storeResult = $this->storeInVectorMemory($memoryText, $snapshot['captured_at']);

        if (is_wp_error($storeResult)) {
            $meta['status'] = 'changed_not_embedded';
            $meta['embedding_error'] = $storeResult->get_error_message();
            $meta['snapshot_stored'] = false;
        } else {
            $vectorStore = new VectorStore();
            $vectorStore->pruneMemoryType('state_snapshot', 60);
            $meta['status'] = 'changed_stored';
            $meta['snapshot_stored'] = true;
        }
        $meta['last_diff_summary'] = $diffSummary;

        update_option(self::LAST_OPTION, [
            'hash' => $snapshotHash,
            'snapshot' => $snapshot,
        ], false);
        update_option(self::META_OPTION, $meta, false);
    }

    /**
     * Manually trigger daily sync and return latest meta status.
     */
    public function runManualSync(): array {
        $this->runDailySync();
        return self::getLastMeta();
    }

    /**
     * Fetch docs from the internet, then incrementally sync changed files into SQLite.
     * Runs daily at 04:00 via WordPress cron.
     */
    public static function runDocsFetchAndSync(): void {
        @set_time_limit(600);

        $fetcher = new DocsFetcher();
        $fetchResult = $fetcher->fetchAll();

        self::runMemorySync();
    }

    /**
     * Initial sync on first activation: fetch docs first, then sync.
     */
    public static function runInitialSync(): void {
        update_option('levi_initial_memory_sync_done', 'done', false);
        self::runDocsFetchAndSync();
    }

    /**
     * Sync memory files (identity + reference) into the vector database.
     * Uses incremental approach: only re-embeds files that have changed.
     */
    public static function runMemorySync(): void {
        update_option('levi_initial_memory_sync_done', 'done', false);
        @set_time_limit(300);

        $vectorStore = new VectorStore();
        if (!$vectorStore->isAvailable()) {
            update_option(self::MEMORY_SYNC_OPTION, [
                'synced_at' => current_time('mysql'),
                'status' => 'skipped',
                'reason' => 'VectorStore not available (SQLite3 missing or data dir not writable).',
            ], false);
            return;
        }

        $loader = new MemoryLoader();
        $changes = $loader->checkForChanges();
        $hasChanges = !empty($changes['identity']) || !empty($changes['reference']);

        if (!$hasChanges) {
            update_option(self::MEMORY_SYNC_OPTION, [
                'synced_at' => current_time('mysql'),
                'status' => 'unchanged',
                'message' => 'All memory files already loaded and up to date.',
            ], false);
            return;
        }

        $results = $loader->reloadChangedFiles();
        $hasPartials = self::detectPartials($results);

        update_option(self::MEMORY_SYNC_OPTION, [
            'synced_at' => current_time('mysql'),
            'status' => empty($results['errors']) ? 'synced' : 'synced_with_errors',
            'changed_identity' => $results['changed_identity'],
            'changed_reference' => $results['changed_reference'],
            'loaded' => count($results['loaded']),
            'errors' => $results['errors'],
            'has_partials' => $hasPartials,
        ], false);

        if (!$hasPartials) {
            delete_option('levi_partial_sync_retries');
        }
    }

    /**
     * Update sync meta after a manual reload (AJAX). Ensures "Last sync" shows correctly.
     */
    public static function updateSyncMetaFromReload(array $results): void {
        $hasPartials = self::detectPartials($results);

        update_option(self::MEMORY_SYNC_OPTION, [
            'synced_at' => current_time('mysql'),
            'status' => empty($results['errors']) ? 'synced' : 'synced_with_errors',
            'changed_identity' => $results['changed_identity'] ?? [],
            'changed_reference' => $results['changed_reference'] ?? [],
            'loaded' => count($results['loaded'] ?? []),
            'errors' => $results['errors'] ?? [],
            'has_partials' => $hasPartials,
        ], false);

        if (!$hasPartials) {
            delete_option('levi_partial_sync_retries');
        }
    }

    /**
     * Check reload results for incomplete (partial) file syncs.
     */
    private static function detectPartials(array $results): bool {
        foreach (($results['loaded'] ?? []) as $fileResult) {
            if (is_array($fileResult) && empty($fileResult['complete'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get last memory sync metadata.
     */
    public static function getLastMemorySyncMeta(): array {
        $meta = get_option(self::MEMORY_SYNC_OPTION, []);
        return is_array($meta) ? $meta : [];
    }

    public static function getLastMeta(): array {
        $meta = get_option(self::META_OPTION, []);
        return is_array($meta) ? $meta : [];
    }

    public static function getPromptContext(): string {
        $meta = get_option(self::META_OPTION, []);
        if (!is_array($meta) || empty($meta['captured_at'])) {
            return 'No baseline snapshot is available yet. For critical checks, always verify live via WordPress tools.';
        }

        $capturedAt = (string) $meta['captured_at'];
        $capturedTs = strtotime($capturedAt) ?: 0;
        $nowTs = (int) current_time('timestamp');
        $ageHours = $capturedTs > 0 ? max(0, (int) floor(($nowTs - $capturedTs) / 3600)) : null;
        $freshness = 'unknown';
        if ($ageHours !== null) {
            if ($ageHours <= 6) {
                $freshness = 'high';
            } elseif ($ageHours <= 24) {
                $freshness = 'medium';
            } else {
                $freshness = 'low';
            }
        }

        $status = (string) ($meta['status'] ?? 'unknown');
        $diff = trim((string) ($meta['last_diff_summary'] ?? ''));
        if ($diff !== '' && mb_strlen($diff) > 1200) {
            $diff = mb_substr($diff, 0, 1200) . "\n...[truncated]";
        }

        $lines = [
            '- Last baseline snapshot: ' . $capturedAt,
            '- Snapshot age (hours): ' . ($ageHours === null ? 'unknown' : (string) $ageHours),
            '- Freshness: ' . $freshness,
            '- Sync status: ' . $status,
            '- Important: This is a daily baseline only. Changes may have happened after this snapshot. For risky actions, verify live before executing.',
        ];

        $lastData = get_option(self::LAST_OPTION, []);
        $snapshot = is_array($lastData['snapshot'] ?? null) ? $lastData['snapshot'] : [];
        $envLines = self::formatEnvironmentContext($snapshot);
        if (!empty($envLines)) {
            $lines[] = '';
            $lines[] = '## Environment Configuration';
            $lines = array_merge($lines, $envLines);
        }

        if ($diff !== '') {
            $lines[] = '';
            $lines[] = '- Last recorded daily diff:';
            $lines[] = $diff;
        }

        return implode("\n", $lines);
    }

    private static function calculateNextRunTimestamp(int $hour = 12, int $minute = 0): int {
        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $next = $now->setTime($hour, $minute, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        return $next->getTimestamp();
    }

    private function collectSnapshot(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $allPlugins = function_exists('get_plugins') ? get_plugins() : [];
        $activePlugins = get_option('active_plugins', []);
        if (!is_array($activePlugins)) {
            $activePlugins = [];
        }

        $plugins = [];
        foreach ($allPlugins as $pluginFile => $pluginData) {
            $plugins[] = [
                'file' => (string) $pluginFile,
                'name' => (string) ($pluginData['Name'] ?? $pluginFile),
                'version' => (string) ($pluginData['Version'] ?? ''),
                'active' => in_array($pluginFile, $activePlugins, true),
            ];
        }
        usort($plugins, fn($a, $b) => strcmp($a['file'], $b['file']));

        $theme = wp_get_theme();
        $settingsWhitelist = [
            'blogname',
            'blogdescription',
            'home',
            'siteurl',
            'timezone_string',
            'permalink_structure',
        ];
        $settings = [];
        foreach ($settingsWhitelist as $key) {
            $settings[$key] = get_option($key);
        }

        return [
            'captured_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'wp_version' => get_bloginfo('version'),
            'active_theme' => [
                'stylesheet' => $theme->get_stylesheet(),
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'is_block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
            ],
            'plugins' => $plugins,
            'counts' => [
                'plugins_total' => count($plugins),
                'plugins_active' => count(array_filter($plugins, fn($p) => !empty($p['active']))),
            ],
            'settings' => $settings,
            'environment' => $this->collectEnvironmentInfo($activePlugins),
        ];
    }

    private function buildDiffSummary(array $previous, array $current): string {
        if (empty($previous)) {
            return 'Initial baseline snapshot created.';
        }

        $changes = [];
        if (($previous['wp_version'] ?? '') !== ($current['wp_version'] ?? '')) {
            $changes[] = sprintf(
                'WordPress version changed: %s -> %s',
                (string) ($previous['wp_version'] ?? 'unknown'),
                (string) ($current['wp_version'] ?? 'unknown')
            );
        }

        $prevTheme = (string) (($previous['active_theme']['stylesheet'] ?? '') . '@' . ($previous['active_theme']['version'] ?? ''));
        $currTheme = (string) (($current['active_theme']['stylesheet'] ?? '') . '@' . ($current['active_theme']['version'] ?? ''));
        if ($prevTheme !== $currTheme) {
            $changes[] = sprintf('Active theme changed: %s -> %s', $prevTheme, $currTheme);
        }

        $prevPlugins = $this->indexPluginsByFile($previous['plugins'] ?? []);
        $currPlugins = $this->indexPluginsByFile($current['plugins'] ?? []);

        $added = array_diff(array_keys($currPlugins), array_keys($prevPlugins));
        $removed = array_diff(array_keys($prevPlugins), array_keys($currPlugins));

        foreach (array_slice($added, 0, 12) as $file) {
            $p = $currPlugins[$file];
            $changes[] = sprintf('Plugin added: %s (%s)', $file, (string) ($p['version'] ?? ''));
        }
        foreach (array_slice($removed, 0, 12) as $file) {
            $p = $prevPlugins[$file];
            $changes[] = sprintf('Plugin removed: %s (%s)', $file, (string) ($p['version'] ?? ''));
        }

        foreach ($currPlugins as $file => $plugin) {
            if (!isset($prevPlugins[$file])) {
                continue;
            }
            $prev = $prevPlugins[$file];
            if (($prev['version'] ?? '') !== ($plugin['version'] ?? '')) {
                $changes[] = sprintf(
                    'Plugin version changed: %s %s -> %s',
                    $file,
                    (string) ($prev['version'] ?? ''),
                    (string) ($plugin['version'] ?? '')
                );
            }
            if ((bool) ($prev['active'] ?? false) !== (bool) ($plugin['active'] ?? false)) {
                $changes[] = sprintf(
                    'Plugin activation changed: %s (%s -> %s)',
                    $file,
                    !empty($prev['active']) ? 'active' : 'inactive',
                    !empty($plugin['active']) ? 'active' : 'inactive'
                );
            }
        }

        foreach (['blogname', 'blogdescription', 'timezone_string', 'permalink_structure'] as $optionKey) {
            if (($previous['settings'][$optionKey] ?? null) !== ($current['settings'][$optionKey] ?? null)) {
                $changes[] = sprintf(
                    'Setting changed: %s',
                    $optionKey
                );
            }
        }

        $prevEnv = $previous['environment'] ?? [];
        $currEnv = $current['environment'] ?? [];
        if (!empty($currEnv)) {
            foreach (['editor', 'widgets'] as $envKey) {
                if (($prevEnv[$envKey] ?? '') !== ($currEnv[$envKey] ?? '')) {
                    $changes[] = sprintf('Environment changed: %s (%s -> %s)', $envKey, (string) ($prevEnv[$envKey] ?? 'unknown'), (string) ($currEnv[$envKey] ?? 'unknown'));
                }
            }
            if ((bool) ($prevEnv['woocommerce_active'] ?? false) !== (bool) ($currEnv['woocommerce_active'] ?? false)) {
                $changes[] = 'WooCommerce activation changed: ' . (!empty($currEnv['woocommerce_active']) ? 'activated' : 'deactivated');
            }
            $prevWcPages = $prevEnv['woocommerce_pages'] ?? [];
            $currWcPages = $currEnv['woocommerce_pages'] ?? [];
            foreach ($currWcPages as $page => $type) {
                if (($prevWcPages[$page] ?? '') !== $type) {
                    $changes[] = sprintf('WooCommerce page type changed: %s (%s -> %s)', $page, (string) ($prevWcPages[$page] ?? 'unknown'), $type);
                }
            }
        }

        if (empty($changes)) {
            return 'No structural changes detected since previous snapshot.';
        }

        return implode("\n", array_slice($changes, 0, 40));
    }

    private function buildMemoryText(array $snapshot, string $diffSummary, string $snapshotHash): string {
        $json = wp_json_encode($snapshot, JSON_PRETTY_PRINT);
        $json = is_string($json) ? $json : '';
        if (mb_strlen($json) > 12000) {
            $json = mb_substr($json, 0, 12000) . "\n...[truncated]";
        }

        return implode("\n\n", [
            '# Daily WordPress State Snapshot',
            'Captured at: ' . (string) ($snapshot['captured_at'] ?? ''),
            'Snapshot hash: ' . $snapshotHash,
            'This snapshot is a daily baseline. Changes can happen after capture time.',
            "## Daily Diff\n" . $diffSummary,
            "## Snapshot Data\n" . $json,
        ]);
    }

    private function storeInVectorMemory(string $content, string $capturedAt): bool|WP_Error {
        $vectorStore = new VectorStore();
        $embedding = $vectorStore->generateEmbedding($content);
        if (is_wp_error($embedding) || empty($embedding)) {
            return is_wp_error($embedding)
                ? $embedding
                : new WP_Error('embedding_failed', 'Could not generate embedding for state snapshot.');
        }

        $sourceFile = 'state-snapshot-' . preg_replace('/[^0-9]/', '', $capturedAt);
        $stored = $vectorStore->storeVector($content, $embedding, 'state_snapshot', (string) $sourceFile, 0);
        if (!$stored) {
            return new WP_Error('store_failed', 'Could not store state snapshot in vector memory.');
        }

        return true;
    }

    /**
     * Format environment info from a stored snapshot into human-readable prompt lines.
     * @return string[]
     */
    private static function formatEnvironmentContext(array $snapshot): array {
        $env = $snapshot['environment'] ?? [];
        if (empty($env) || !is_array($env)) {
            return [];
        }

        $lines = [];

        $wpVersion = (string) ($snapshot['wp_version'] ?? 'unknown');
        $lines[] = '- WordPress: ' . $wpVersion;

        $isBlockTheme = !empty($snapshot['active_theme']['is_block_theme']);
        $themeName = (string) ($snapshot['active_theme']['name'] ?? 'unknown');
        $lines[] = '- Theme: ' . $themeName . ' (' . ($isBlockTheme ? 'Block-Theme / FSE' : 'Classic Theme') . ')';
        $lines[] = '- Editor: ' . (($env['editor'] ?? 'gutenberg') === 'classic' ? 'Classic Editor' : 'Gutenberg (Block Editor)');
        $lines[] = '- Widgets: ' . (($env['widgets'] ?? 'block') === 'block' ? 'Block-basiert' : 'Classic Widgets');

        if (!empty($env['woocommerce_active'])) {
            $wcVersion = defined('WC_VERSION') ? WC_VERSION : 'unknown';
            $lines[] = '- WooCommerce: ' . $wcVersion;
            $wcPages = $env['woocommerce_pages'] ?? [];
            if (!empty($wcPages) && is_array($wcPages)) {
                $pageLabels = ['cart' => 'Warenkorb', 'checkout' => 'Checkout', 'myaccount' => 'Mein Konto'];
                foreach ($wcPages as $key => $type) {
                    $label = $pageLabels[$key] ?? $key;
                    $typeLabel = match ($type) {
                        'block' => 'WooCommerce Block (klassische Hooks wie woocommerce_before_cart feuern NICHT)',
                        'shortcode' => 'Classic Shortcode (klassische Hooks funktionieren)',
                        'not_set' => 'Seite nicht konfiguriert',
                        'not_found' => 'Konfigurierte Seite nicht gefunden',
                        default => 'unbekannt',
                    };
                    $lines[] = "  - $label: $typeLabel";
                }
            }
        } else {
            $lines[] = '- WooCommerce: nicht aktiv';
        }

        if (!empty($env['elementor_active'])) {
            $lines[] = '- Elementor: aktiv';
        }

        return $lines;
    }

    /**
     * Detect how key WordPress features are configured (blocks vs. classic, editor type, etc.).
     * Helps the AI choose the right hooks and APIs for implementation.
     */
    private function collectEnvironmentInfo(array $activePluginFiles): array {
        $env = [];

        $env['editor'] = in_array('classic-editor/classic-editor.php', $activePluginFiles, true)
            ? 'classic'
            : 'gutenberg';

        $env['widgets'] = function_exists('wp_use_widgets_block_editor') && wp_use_widgets_block_editor()
            ? 'block'
            : 'classic';

        $env['woocommerce_active'] = in_array('woocommerce/woocommerce.php', $activePluginFiles, true);

        if ($env['woocommerce_active'] && function_exists('wc_get_page_id')) {
            $env['woocommerce_pages'] = $this->detectWooCommercePageTypes();
        }

        $env['elementor_active'] = in_array('elementor/elementor.php', $activePluginFiles, true);

        return $env;
    }

    /**
     * Check whether key WooCommerce pages use blocks or classic shortcodes.
     * @return array<string, string> e.g. ['cart' => 'block', 'checkout' => 'shortcode']
     */
    private function detectWooCommercePageTypes(): array {
        $pages = [];
        $wcPages = [
            'cart' => 'woocommerce/cart',
            'checkout' => 'woocommerce/checkout',
            'myaccount' => 'woocommerce/my-account',
        ];

        foreach ($wcPages as $key => $blockName) {
            $pageId = wc_get_page_id($key);
            if ($pageId <= 0) {
                $pages[$key] = 'not_set';
                continue;
            }

            $post = get_post($pageId);
            if (!$post) {
                $pages[$key] = 'not_found';
                continue;
            }

            if (has_block($blockName, $post)) {
                $pages[$key] = 'block';
            } elseif (has_shortcode($post->post_content, 'woocommerce_' . $key)) {
                $pages[$key] = 'shortcode';
            } else {
                $pages[$key] = 'unknown';
            }
        }

        return $pages;
    }

    /**
     * @param array<int,array<string,mixed>> $plugins
     * @return array<string,array<string,mixed>>
     */
    private function indexPluginsByFile(array $plugins): array {
        $indexed = [];
        foreach ($plugins as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }
            $file = (string) ($plugin['file'] ?? '');
            if ($file === '') {
                continue;
            }
            $indexed[$file] = $plugin;
        }
        return $indexed;
    }
}

