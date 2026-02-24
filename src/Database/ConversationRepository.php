<?php

namespace Mohami\Agent\Database;

class ConversationRepository {
    private string $tableConversations;
    private string $tableActions;

    public function __construct() {
        global $wpdb;
        $this->tableConversations = $wpdb->prefix . 'mohami_conversations';
        $this->tableActions = $wpdb->prefix . 'mohami_actions';
    }

    public function saveMessage(string $sessionId, int $userId, string $role, string $content, ?string $contextHash = null): int {
        global $wpdb;

        $result = $wpdb->insert(
            $this->tableConversations,
            [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'role' => $role,
                'content' => $content,
                'context_hash' => $contextHash,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s']
        );

        return $result ? $wpdb->insert_id : 0;
    }

    public function getHistory(string $sessionId, int $limit = 50): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->tableConversations} 
             WHERE session_id = %s 
             ORDER BY created_at ASC 
             LIMIT %d",
            $sessionId,
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function getSessionMessages(string $sessionId): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT role, content FROM {$this->tableConversations} 
             WHERE session_id = %s 
             AND role IN ('user', 'assistant', 'system')
             ORDER BY created_at ASC",
            $sessionId
        );

        $results = $wpdb->get_results($sql, ARRAY_A) ?: [];
        
        // Format for OpenRouter API
        return array_map(function ($row) {
            return [
                'role' => $row['role'],
                'content' => $row['content'],
            ];
        }, $results);
    }

    public function getUserSessions(int $userId, int $limit = 10): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT session_id, MAX(created_at) as last_activity, 
                    COUNT(*) as message_count
             FROM {$this->tableConversations}
             WHERE user_id = %d
             GROUP BY session_id
             ORDER BY last_activity DESC
             LIMIT %d",
            $userId,
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function deleteSession(string $sessionId): bool {
        global $wpdb;

        $wpdb->delete(
            $this->tableConversations,
            ['session_id' => $sessionId],
            ['%s']
        );

        $wpdb->delete(
            $this->tableActions,
            ['conversation_id' => $sessionId],
            ['%s']
        );

        return true;
    }

    public function cleanupOldSessions(int $days = 30): int {
        global $wpdb;

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->tableConversations} WHERE created_at < %s",
            $cutoffDate
        ));

        return $result !== false ? $result : 0;
    }
}
