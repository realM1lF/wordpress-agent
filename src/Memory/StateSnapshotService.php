<?php

namespace Levi\Agent\Memory;

use WP_Error;

class StateSnapshotService {
    public const EVENT_HOOK = 'levi_agent_daily_state_snapshot';
    public const MEMORY_SYNC_HOOK = 'levi_agent_daily_memory_sync';
    private const META_OPTION = 'levi_agent_state_snapshot_meta';
    private const LAST_OPTION = 'levi_agent_state_snapshot_last';
    private const MEMORY_SYNC_OPTION = 'levi_agent_memory_sync_meta';

    public function __construct() {
        add_action('init', [$this, 'ensureSchedule']);
        add_action(self::EVENT_HOOK, [$this, 'runDailySync']);
        add_action(self::MEMORY_SYNC_HOOK, [self::class, 'runMemorySync']);
        add_action(self::MEMORY_SYNC_HOOK . '_initial', [self::class, 'runMemorySync']);
        add_action('admin_init', [$this, 'maybeRunOverdueTasks']);
    }

    public function ensureSchedule(): void {
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event(self::calculateNextRunTimestamp(), 'daily', self::EVENT_HOOK);
        }
        if (!wp_next_scheduled(self::MEMORY_SYNC_HOOK)) {
            wp_schedule_event(self::calculateNextRunTimestamp(), 'daily', self::MEMORY_SYNC_HOOK);
        }
    }

    /**
     * On admin page loads, nudge WP-Cron if our events are overdue.
     * Covers: DISABLE_WP_CRON, aggressive page caching, low-traffic sites.
     * Also triggers a one-time initial memory sync after first activation.
     */
    public function maybeRunOverdueTasks(): void {
        if (wp_doing_ajax() || !current_user_can('manage_options')) {
            return;
        }

        $now = time();

        foreach ([self::EVENT_HOOK, self::MEMORY_SYNC_HOOK] as $hook) {
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
        }
    }

    public static function scheduleEvent(): void {
        $nextRun = self::calculateNextRunTimestamp();
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event($nextRun, 'daily', self::EVENT_HOOK);
        }
        if (!wp_next_scheduled(self::MEMORY_SYNC_HOOK)) {
            wp_schedule_event($nextRun, 'daily', self::MEMORY_SYNC_HOOK);
        }
    }

    public static function unscheduleEvent(): void {
        foreach ([self::EVENT_HOOK, self::MEMORY_SYNC_HOOK, self::MEMORY_SYNC_HOOK . '_initial'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            while ($timestamp !== false) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
        delete_option('levi_initial_memory_sync_done');
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
     * Sync memory files (identity + reference) into the vector database.
     * Only reloads files that are new or have changed since the last sync.
     */
    public static function runMemorySync(): void {
        update_option('levi_initial_memory_sync_done', 'done', false);

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

        $results = $loader->loadAllMemories();

        $identityErrors = $results['identity']['errors'] ?? [];
        $referenceErrors = $results['reference']['errors'] ?? [];
        $allErrors = array_merge($identityErrors, $referenceErrors);

        update_option(self::MEMORY_SYNC_OPTION, [
            'synced_at' => current_time('mysql'),
            'status' => empty($allErrors) ? 'synced' : 'synced_with_errors',
            'changed_identity' => $changes['identity'],
            'changed_reference' => $changes['reference'],
            'identity_loaded' => count($results['identity']['loaded'] ?? []),
            'reference_loaded' => count($results['reference']['loaded'] ?? []),
            'errors' => $allErrors,
        ], false);
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

        if ($diff !== '') {
            $lines[] = '- Last recorded daily diff:';
            $lines[] = $diff;
        }

        return implode("\n", $lines);
    }

    private static function calculateNextRunTimestamp(): int {
        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $next = $now->setTime(0, 7, 0);
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
            ],
            'plugins' => $plugins,
            'counts' => [
                'plugins_total' => count($plugins),
                'plugins_active' => count(array_filter($plugins, fn($p) => !empty($p['active']))),
            ],
            'settings' => $settings,
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

