<?php

namespace Levi\Agent\AI\Tools;

class ManageCronTool implements ToolInterface {

    public function getName(): string {
        return 'manage_cron';
    }

    public function getDescription(): string {
        return 'View and manage WordPress scheduled tasks (WP-Cron). List all cron events, see schedules, and unschedule specific events. Helpful for diagnosing performance issues or debugging scheduled tasks.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => ['list_events', 'list_schedules', 'unschedule_event'],
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
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $action = (string) ($params['action'] ?? '');

        return match ($action) {
            'list_events' => $this->listEvents(),
            'list_schedules' => $this->listSchedules(),
            'unschedule_event' => $this->unscheduleEvent($params),
            default => ['success' => false, 'error' => 'Invalid action. Use: list_events, list_schedules, unschedule_event'],
        };
    }

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
            return ['success' => false, 'error' => 'Could not unschedule the event.'];
        }

        return [
            'success' => true,
            'hook' => $hook,
            'timestamp' => $timestamp,
            'message' => 'Event unscheduled.',
        ];
    }
}
