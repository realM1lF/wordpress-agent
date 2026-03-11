<?php

namespace Levi\Agent\Cron;

use Levi\Agent\AI\Tools\Registry;

class CronTaskRunner {

    private const OPTION_KEY = 'levi_custom_cron_tasks';
    private const HOOK_PREFIX = 'levi_custom_task_';
    private const MAX_TASKS = 20;
    private const MAX_RESULT_LENGTH = 5000;

    /** Read-only tools — always allowed, no confirmation needed */
    public const ALLOWED_TOOLS = [
        'get_posts', 'get_post', 'get_pages', 'get_users',
        'get_plugins', 'get_options', 'get_media',
        'read_error_log', 'list_plugin_files', 'read_plugin_file',
        'list_theme_files', 'read_theme_file',
        'discover_rest_api', 'discover_content_types',
        'get_woocommerce_data', 'get_woocommerce_shop',
        'get_elementor_data',
    ];

    /** Write tools — allowed in crons only if the user confirmed during creation */
    public const CONFIRMABLE_TOOLS = [
        'create_post', 'update_post', 'delete_post', 'create_page',
        'install_plugin',
        'update_option',
        'manage_post_meta', 'manage_taxonomy',
        'upload_media',
        'manage_woocommerce',
        'manage_menu',
    ];

    public const ALLOWED_SCHEDULES = ['once', 'hourly', 'twicedaily', 'daily', 'weekly'];

    public function __construct() {
        $this->registerTaskHooks();
    }

    private function registerTaskHooks(): void {
        $tasks = self::getAllTasks();
        foreach ($tasks as $task) {
            if (!empty($task['active']) && !empty($task['hook'])) {
                add_action($task['hook'], function () use ($task) {
                    $this->executeTask($task['id']);
                });
            }
        }
    }

    public function executeTask(string $taskId): array {
        $tasks = self::getAllTasks();
        if (!isset($tasks[$taskId])) {
            return ['success' => false, 'error' => 'Task not found.'];
        }

        $task = $tasks[$taskId];
        $toolName = $task['tool'] ?? '';

        $isReadOnly = in_array($toolName, self::ALLOWED_TOOLS, true);
        $isConfirmable = in_array($toolName, self::CONFIRMABLE_TOOLS, true);

        if (!$isReadOnly && !$isConfirmable) {
            $result = ['success' => false, 'error' => "Tool '$toolName' is not allowed for automated tasks."];
            self::updateTaskResult($taskId, $result);
            return $result;
        }

        if ($isConfirmable && empty($task['confirmed_by'])) {
            $result = ['success' => false, 'error' => "Tool '$toolName' requires user confirmation. Task was not properly confirmed."];
            self::updateTaskResult($taskId, $result);
            return $result;
        }

        $registry = new Registry('full');
        $tool = $registry->get($toolName);

        if (!$tool) {
            $result = ['success' => false, 'error' => "Tool '$toolName' not found in registry."];
            self::updateTaskResult($taskId, $result);
            return $result;
        }

        try {
            $params = $task['tool_params'] ?? [];
            $result = $tool->execute($params);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
        }

        self::updateTaskResult($taskId, $result);
        self::maybeSendEmailNotification($task, $result);

        if (($task['schedule'] ?? '') === 'once') {
            self::deleteTask($taskId);
        }

        return $result;
    }

    // ── Static CRUD Methods ─────────────────────────────────────────

    public static function getAllTasks(): array {
        $tasks = get_option(self::OPTION_KEY, []);
        return is_array($tasks) ? $tasks : [];
    }

    public static function getTask(string $id): ?array {
        $tasks = self::getAllTasks();
        return $tasks[$id] ?? null;
    }

    public static function saveTask(array $task): array {
        $id = $task['id'] ?? '';
        if ($id === '') {
            return ['success' => false, 'error' => 'Task ID is required.'];
        }

        $tasks = self::getAllTasks();

        $isNew = !isset($tasks[$id]);

        if ($isNew) {
            $activeCount = count(array_filter($tasks, fn($t) => !empty($t['active'])));
            if ($activeCount >= self::MAX_TASKS) {
                return ['success' => false, 'error' => 'Maximum of ' . self::MAX_TASKS . ' active tasks reached.'];
            }
        }

        $toolName = $task['tool'] ?? '';
        $isReadOnly = in_array($toolName, self::ALLOWED_TOOLS, true);
        $isConfirmable = in_array($toolName, self::CONFIRMABLE_TOOLS, true);

        if (!$isReadOnly && !$isConfirmable) {
            return ['success' => false, 'error' => "Tool '$toolName' is not allowed for automated tasks."];
        }

        if ($isConfirmable && empty($task['confirmed_by'])) {
            return ['success' => false, 'error' => "Tool '$toolName' is a write tool and requires user confirmation to schedule."];
        }

        $schedule = $task['schedule'] ?? 'daily';
        if (!in_array($schedule, self::ALLOWED_SCHEDULES, true)) {
            return ['success' => false, 'error' => "Schedule '$schedule' is not valid. Use: " . implode(', ', self::ALLOWED_SCHEDULES)];
        }

        $hook = self::HOOK_PREFIX . $id;

        if ($isNew) {
            $task['hook'] = $hook;
            $task['created_at'] = time();
            $task['last_run'] = null;
            $task['last_result'] = null;
        }

        $task['active'] = $task['active'] ?? true;

        $existingTimestamp = wp_next_scheduled($hook);
        if ($existingTimestamp) {
            wp_unschedule_event($existingTimestamp, $hook);
        }

        if (!empty($task['active'])) {
            $firstRun = self::calculateFirstRun($task['start_time'] ?? null);
            if ($schedule === 'once') {
                wp_schedule_single_event($firstRun, $hook);
            } else {
                wp_schedule_event($firstRun, $schedule, $hook);
            }
        }

        $tasks[$id] = $task;
        update_option(self::OPTION_KEY, $tasks, false);

        $nextRun = wp_next_scheduled($hook);

        $responseData = [
            'success' => true,
            'task_id' => $id,
            'name' => $task['name'] ?? '',
            'tool' => $toolName,
            'schedule' => $schedule,
            'start_time' => $task['start_time'] ?? null,
            'active' => !empty($task['active']),
            'requires_confirmation' => $isConfirmable,
            'confirmed_by' => $task['confirmed_by'] ?? null,
            'next_run' => $nextRun ? wp_date('Y-m-d H:i:s', $nextRun) : null,
            'message' => $isNew ? 'Task created.' : 'Task updated.',
        ];

        if ($isConfirmable) {
            $responseData['message'] .= ' (Write tool confirmed by user #' . ($task['confirmed_by'] ?? '?') . ')';
        }

        return $responseData;
    }

    public static function deleteTask(string $id): array {
        $tasks = self::getAllTasks();
        if (!isset($tasks[$id])) {
            return ['success' => false, 'error' => 'Task not found.'];
        }

        $hook = self::HOOK_PREFIX . $id;
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }

        $name = $tasks[$id]['name'] ?? $id;
        unset($tasks[$id]);
        update_option(self::OPTION_KEY, $tasks, false);

        return [
            'success' => true,
            'task_id' => $id,
            'name' => $name,
            'message' => "Task '$name' deleted.",
        ];
    }

    public static function toggleTask(string $id): array {
        $tasks = self::getAllTasks();
        if (!isset($tasks[$id])) {
            return ['success' => false, 'error' => 'Task not found.'];
        }

        $task = $tasks[$id];
        $wasActive = !empty($task['active']);
        $task['active'] = !$wasActive;

        $hook = self::HOOK_PREFIX . $id;

        if ($wasActive) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        } else {
            $schedule = $task['schedule'] ?? 'daily';
            if (!wp_next_scheduled($hook)) {
                $firstRun = self::calculateFirstRun($task['start_time'] ?? null);
                if ($schedule === 'once') {
                    wp_schedule_single_event($firstRun, $hook);
                } else {
                    wp_schedule_event($firstRun, $schedule, $hook);
                }
            }
        }

        $tasks[$id] = $task;
        update_option(self::OPTION_KEY, $tasks, false);

        $nextRun = wp_next_scheduled($hook);

        return [
            'success' => true,
            'task_id' => $id,
            'name' => $task['name'] ?? '',
            'active' => !$wasActive,
            'next_run' => $nextRun ? wp_date('Y-m-d H:i:s', $nextRun) : null,
            'message' => $wasActive ? 'Task paused.' : 'Task resumed.',
        ];
    }

    public static function generateTaskId(): string {
        return substr(bin2hex(random_bytes(6)), 0, 8);
    }

    /**
     * Calculate the first run timestamp from an optional HH:MM start_time.
     * Uses the site's timezone. If the time has already passed today, schedules for tomorrow.
     */
    private static function calculateFirstRun(?string $startTime): int {
        if ($startTime === null || !preg_match('/^(\d{1,2}):(\d{2})$/', $startTime, $m)) {
            return time() + 60;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return time() + 60;
        }

        try {
            $tz = new \DateTimeZone(wp_timezone_string());
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        $now = new \DateTimeImmutable('now', $tz);
        $target = $now->setTime($hour, $minute, 0);

        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }

        return $target->getTimestamp();
    }

    // ── Private Helpers ─────────────────────────────────────────────

    private static function maybeSendEmailNotification(array $task, array $result): void {
        if (empty($task['notify_email'])) {
            return;
        }

        $userId = $task['created_by'] ?? 0;
        $user = $userId ? get_user_by('id', $userId) : null;
        $email = $user ? $user->user_email : get_option('admin_email');

        if (empty($email)) {
            return;
        }

        $taskName = $task['name'] ?? $task['id'] ?? 'Unbenannt';
        $toolName = $task['tool'] ?? '—';
        $success = !empty($result['success']);
        $statusLabel = $success ? 'Erfolgreich' : 'Fehler';
        $siteName = get_bloginfo('name') ?: wp_parse_url(get_site_url(), PHP_URL_HOST);
        $timestamp = wp_date('d.m.Y H:i:s');

        $subject = sprintf('[%s] Levi Cron: %s — %s', $siteName, $taskName, $statusLabel);

        $resultJson = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (mb_strlen($resultJson) > 5000) {
            $resultJson = mb_substr($resultJson, 0, 5000) . "\n...[gekuerzt]";
        }

        $body = sprintf(
            "Aufgabe: %s\nTool: %s\nStatus: %s\nZeitpunkt: %s\n\n--- Ergebnis ---\n\n%s\n\n---\nDiese Nachricht wurde automatisch von Levi AI gesendet.\n%s",
            $taskName,
            $toolName,
            $statusLabel,
            $timestamp,
            $resultJson,
            admin_url('admin.php?page=levi-agent-settings&tab=cron-tasks')
        );

        wp_mail($email, $subject, $body);
    }

    private static function updateTaskResult(string $taskId, array $result): void {
        $tasks = self::getAllTasks();
        if (!isset($tasks[$taskId])) {
            return;
        }

        $resultJson = wp_json_encode($result, JSON_UNESCAPED_UNICODE);
        if (mb_strlen($resultJson) > self::MAX_RESULT_LENGTH) {
            $resultJson = mb_substr($resultJson, 0, self::MAX_RESULT_LENGTH) . '...[truncated]';
        }

        $tasks[$taskId]['last_run'] = time();
        $tasks[$taskId]['last_result'] = $resultJson;
        update_option(self::OPTION_KEY, $tasks, false);
    }
}
