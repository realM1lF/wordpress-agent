<?php

namespace Levi\Agent\Database;

class Tables {
    public static function create(): void {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        $conversationsTable = $wpdb->prefix . 'levi_conversations';
        $actionsTable = $wpdb->prefix . 'levi_actions';
        $memoryTable = $wpdb->prefix . 'levi_memory';

        $sql = "
CREATE TABLE IF NOT EXISTS {$conversationsTable} (
    id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id varchar(64) NOT NULL,
    user_id bigint(20) UNSIGNED DEFAULT NULL,
    role enum('user', 'assistant', 'system') NOT NULL,
    content longtext NOT NULL,
    context_hash varchar(32),
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_user_time (user_id, created_at)
) {$charsetCollate};

CREATE TABLE IF NOT EXISTS {$actionsTable} (
    id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id bigint(20) UNSIGNED,
    action_type varchar(50) NOT NULL,
    object_type varchar(50) NOT NULL,
    object_id bigint(20),
    parameters longtext,
    result longtext,
    status varchar(20),
    executed_at datetime DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conversation (conversation_id),
    INDEX idx_action (action_type, object_type)
) {$charsetCollate};

CREATE TABLE IF NOT EXISTS {$memoryTable} (
    id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id bigint(20) UNSIGNED DEFAULT NULL,
    memory_type varchar(50) NOT NULL,
    content longtext NOT NULL,
    context varchar(255),
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_type (user_id, memory_type),
    FULLTEXT INDEX idx_content (content)
) {$charsetCollate};
        ";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
