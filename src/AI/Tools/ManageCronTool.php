<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\Cron\CronTaskRunner;

class ManageCronTool implements ToolInterface {

    public function getName(): string {
        return 'manage_cron';
    }

    public function getDescription(): string {
        return 'Manage WordPress scheduled tasks (WP-Cron) and Levi automation tasks. '
            . 'Actions: list_events, list_schedules, unschedule_event, schedule_task, list_tasks, update_task, delete_task, run_task. '
            . 'Max 20 active tasks. Schedules: once, hourly, twicedaily, daily, weekly. '
            . 'Use schedule="once" with start_time for one-off timed actions.';
    }

    public function getInputExamples(): array {
        return [
            ['action' => 'schedule_task', 'name' => 'Plugin Update Check', 'tool' => 'get_plugins', 'schedule' => 'daily'],
            ['action' => 'schedule_task', 'name' => 'Publish Draft Tomorrow', 'tool' => 'update_post', 'tool_params' => ['post_id' => 42, 'status' => 'publish'], 'schedule' => 'once', 'start_time' => '06:00'],
            ['action' => 'run_task', 'task_id' => 'task_abc123'],
        ];
    }

    private const ACTION_REQUIRED_PARAMS = [
        'list_events'      => [],
        'list_schedules'   => [],
        'unschedule_event' => ['hook', 'timestamp'],
        'schedule_task'    => ['name', 'tool', 'schedule'],
        'update_task'      => ['task_id'],
        'delete_task'      => ['task_id'],
        'run_task'         => ['task_id'],
        'list_tasks'       => [],
    ];

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => [
                    'list_events', 'list_schedules', 'unschedule_event',
                    'schedule_task', 'update_task', 'delete_task', 'run_task', 'list_tasks',
                ],
                'required' => true,
            ],
            'hook' => [
                'type' => 'string',
                'description' => 'Hook name (for unschedule_event)',
            ],
            'timestamp' => [
                'type' => 'integer',
                'description' => 'Unix timestamp of the specific event (for unschedule_event)',
            ],
            'task_id' => [
                'type' => 'string',
                'description' => 'Task ID (for update_task, delete_task, run_task)',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Human-readable task name (for schedule_task, update_task)',
            ],
            'tool' => [
                'type' => 'string',
                'description' => 'Tool to execute (for schedule_task, update_task). Read-only tools (no extra confirmation): '
                    . implode(', ', CronTaskRunner::ALLOWED_TOOLS)
                    . '. Write tools (user confirmation at creation = permanent approval): '
                    . implode(', ', CronTaskRunner::CONFIRMABLE_TOOLS),
                'enum' => array_merge(CronTaskRunner::ALLOWED_TOOLS, CronTaskRunner::CONFIRMABLE_TOOLS),
            ],
            'tool_params' => [
                'type' => 'string',
                'description' => 'JSON string of parameters to pass to the tool (for schedule_task, update_task)',
            ],
            'schedule' => [
                'type' => 'string',
                'description' => 'How often to run (for schedule_task, update_task)',
                'enum' => CronTaskRunner::ALLOWED_SCHEDULES,
            ],
            'active' => [
                'type' => 'boolean',
                'description' => 'Whether the task is active (for update_task)',
            ],
            'start_time' => [
                'type' => 'string',
                'description' => 'Time of day for first execution in HH:MM format, e.g. "08:00" (for schedule_task). Uses the site timezone. If omitted, starts ~1 minute from now.',
            ],
            'notify_email' => [
                'type' => 'boolean',
                'description' => 'Send task results via email to the user after each execution (for schedule_task, update_task). Default: false.',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $action = (string) ($params['action'] ?? '');

        if (isset(self::ACTION_REQUIRED_PARAMS[$action])) {
            $missing = [];
            foreach (self::ACTION_REQUIRED_PARAMS[$action] as $param) {
                if (!isset($params[$param]) || (is_string($params[$param]) && trim($params[$param]) === '')) {
                    $missing[] = $param;
                }
            }
            if (!empty($missing)) {
                return [
                    'success' => false,
                    'error' => "Action '{$action}' requires: " . implode(', ', $missing) . '.',
                ];
            }
        }

        return match ($action) {
            'list_events' => $this->listEvents(),
            'list_schedules' => $this->listSchedules(),
            'unschedule_event' => $this->unscheduleEvent($params),
            'schedule_task' => $this->scheduleTask($params),
            'update_task' => $this->updateTask($params),
            'delete_task' => $this->deleteTask($params),
            'run_task' => $this->runTask($params),
            'list_tasks' => $this->listTasks(),
            default => ['success' => false, 'error' => 'Invalid action.'],
        };
    }

    // ── WP-Cron Actions (existing) ──────────────────────────────────

    private function listEvents(): array {
        $crons = _get_cron_array();
        if (empty($crons)) {
            return ['success' => true, 'events' => [], 'message' => 'No cron events scheduled.'];
        }

        $events = [];
        $now = time();

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $entries) {
                foreach ($entries as $key => $entry) {
                    $events[] = [
                        'hook' => $hook,
                        'timestamp' => $timestamp,
                        'date' => wp_date('Y-m-d H:i:s', $timestamp),
                        'schedule' => $entry['schedule'] ?: 'once',
                        'interval' => $entry['interval'] ?? null,
                        'args' => $entry['args'],
                        'overdue' => $timestamp < $now,
                    ];
                }
            }
        }

        usort($events, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        return [
            'success' => true,
            'total' => count($events),
            'events' => array_slice($events, 0, 100),
        ];
    }

    private function listSchedules(): array {
        $schedules = wp_get_schedules();

        $result = [];
        foreach ($schedules as $name => $schedule) {
            $result[] = [
                'name' => $name,
                'display' => $schedule['display'],
                'interval_seconds' => $schedule['interval'],
                'interval_human' => human_time_diff(0, $schedule['interval']),
            ];
        }

        usort($result, fn($a, $b) => $a['interval_seconds'] <=> $b['interval_seconds']);

        return [
            'success' => true,
            'schedules' => $result,
        ];
    }

    private function unscheduleEvent(array $params): array {
        $hook = sanitize_text_field((string) ($params['hook'] ?? ''));
        $timestamp = (int) ($params['timestamp'] ?? 0);

        if ($hook === '') {
            return ['success' => false, 'error' => 'hook is required.'];
        }
        if ($timestamp <= 0) {
            return ['success' => false, 'error' => 'timestamp is required.'];
        }

        $crons = _get_cron_array();
        $args = [];
        if (isset($crons[$timestamp][$hook])) {
            $entry = reset($crons[$timestamp][$hook]);
            $args = $entry['args'] ?? [];
        }

        $result = wp_unschedule_event($timestamp, $hook, $args);

        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Could not unschedule the event.',
                'suggestion' => 'Use action "list_events" to verify the exact hook name and timestamp.',
            ];
        }

        return [
            'success' => true,
            'hook' => $hook,
            'timestamp' => $timestamp,
            'message' => 'Event unscheduled.',
        ];
    }

    // ── Levi Task Actions (new) ─────────────────────────────────────

    private function scheduleTask(array $params): array {
        $name = sanitize_text_field((string) ($params['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required.'];
        }

        $tool = (string) ($params['tool'] ?? '');
        if ($tool === '') {
            return ['success' => false, 'error' => 'tool is required.'];
        }

        $schedule = (string) ($params['schedule'] ?? 'daily');

        $toolParams = [];
        if (!empty($params['tool_params'])) {
            $decoded = json_decode((string) $params['tool_params'], true);
            if (is_array($decoded)) {
                $toolParams = $decoded;
            }
        }

        $existing = CronTaskRunner::getAllTasks();
        foreach ($existing as $t) {
            if (($t['name'] ?? '') === $name) {
                return ['success' => false, 'error' => "A task named '$name' already exists."];
            }
        }

        $id = CronTaskRunner::generateTaskId();
        $userId = get_current_user_id();
        $isWriteTool = in_array($tool, CronTaskRunner::CONFIRMABLE_TOOLS, true);

        $taskData = [
            'id' => $id,
            'name' => $name,
            'tool' => $tool,
            'tool_params' => $toolParams,
            'schedule' => $schedule,
            'active' => true,
            'created_by' => $userId,
        ];

        if (!empty($params['start_time'])) {
            $taskData['start_time'] = sanitize_text_field((string) $params['start_time']);
        }

        if (isset($params['notify_email'])) {
            $taskData['notify_email'] = (bool) $params['notify_email'];
        }

        if ($isWriteTool) {
            $taskData['confirmed_by'] = $userId;
            $taskData['confirmed_at'] = time();
        }

        return CronTaskRunner::saveTask($taskData);
    }

    private function updateTask(array $params): array {
        $taskId = (string) ($params['task_id'] ?? '');
        if ($taskId === '') {
            return ['success' => false, 'error' => 'task_id is required.'];
        }

        $task = CronTaskRunner::getTask($taskId);
        if (!$task) {
            return ['success' => false, 'error' => 'Task not found.'];
        }

        if (isset($params['name'])) {
            $task['name'] = sanitize_text_field((string) $params['name']);
        }
        if (isset($params['tool'])) {
            $newTool = (string) $params['tool'];
            $task['tool'] = $newTool;

            if (in_array($newTool, CronTaskRunner::CONFIRMABLE_TOOLS, true)) {
                $task['confirmed_by'] = get_current_user_id();
                $task['confirmed_at'] = time();
            }
        }
        if (isset($params['tool_params'])) {
            $decoded = json_decode((string) $params['tool_params'], true);
            if (is_array($decoded)) {
                $task['tool_params'] = $decoded;
            }
        }
        if (isset($params['schedule'])) {
            $task['schedule'] = (string) $params['schedule'];
        }
        if (isset($params['active'])) {
            $task['active'] = (bool) $params['active'];
        }
        if (isset($params['notify_email'])) {
            $task['notify_email'] = (bool) $params['notify_email'];
        }

        return CronTaskRunner::saveTask($task);
    }

    private function deleteTask(array $params): array {
        $taskId = (string) ($params['task_id'] ?? '');
        if ($taskId === '') {
            return ['success' => false, 'error' => 'task_id is required.'];
        }

        return CronTaskRunner::deleteTask($taskId);
    }

    private function runTask(array $params): array {
        $taskId = (string) ($params['task_id'] ?? '');
        if ($taskId === '') {
            return ['success' => false, 'error' => 'task_id is required.'];
        }

        $task = CronTaskRunner::getTask($taskId);
        if (!$task) {
            return ['success' => false, 'error' => 'Task not found.'];
        }

        $runner = new CronTaskRunner();
        return $runner->executeTask($taskId);
    }

    private function listTasks(): array {
        $tasks = CronTaskRunner::getAllTasks();

        if (empty($tasks)) {
            return ['success' => true, 'total' => 0, 'tasks' => [], 'message' => 'No Levi tasks configured.'];
        }

        $result = [];
        foreach ($tasks as $task) {
            $hook = $task['hook'] ?? '';
            $nextRun = $hook ? wp_next_scheduled($hook) : null;

            $toolName = $task['tool'] ?? '';
            $isWriteTool = in_array($toolName, CronTaskRunner::CONFIRMABLE_TOOLS, true);

            $result[] = [
                'id' => $task['id'] ?? '',
                'name' => $task['name'] ?? '',
                'tool' => $toolName,
                'tool_params' => $task['tool_params'] ?? [],
                'schedule' => $task['schedule'] ?? '',
                'start_time' => $task['start_time'] ?? null,
                'active' => !empty($task['active']),
                'type' => $isWriteTool ? 'write (confirmed)' : 'read-only',
                'notify_email' => !empty($task['notify_email']),
                'confirmed_by' => $task['confirmed_by'] ?? null,
                'created_at' => isset($task['created_at']) ? wp_date('Y-m-d H:i:s', $task['created_at']) : null,
                'last_run' => isset($task['last_run']) ? wp_date('Y-m-d H:i:s', $task['last_run']) : null,
                'last_result' => $task['last_result'] ?? null,
                'next_run' => $nextRun ? wp_date('Y-m-d H:i:s', $nextRun) : null,
            ];
        }

        return [
            'success' => true,
            'total' => count($result),
            'tasks' => $result,
        ];
    }
}
