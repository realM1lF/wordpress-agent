<?php

namespace Levi\Agent\Database;

class Tables {
    public static function create(): void {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        $conversationsTable = $wpdb->prefix . 'levi_conversations';
        $actionsTable = $wpdb->prefix . 'levi_actions';
        $memoryTable = $wpdb->prefix . 'levi_memory';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sqlConversations = "CREATE TABLE {$conversationsTable} (
    id bigint(20) unsigned NOT NULL auto_increment,
    session_id varchar(64) NOT NULL,
    user_id bigint(20) unsigned DEFAULT NULL,
    role varchar(20) NOT NULL,
    content longtext NOT NULL,
    context_hash varchar(32) DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_session (session_id),
    KEY idx_user_time (user_id, created_at)
) {$charsetCollate};";
        dbDelta($sqlConversations);

        $sqlActions = "CREATE TABLE {$actionsTable} (
    id bigint(20) unsigned NOT NULL auto_increment,
    conversation_id bigint(20) unsigned DEFAULT NULL,
    action_type varchar(50) NOT NULL,
    object_type varchar(50) NOT NULL,
    object_id bigint(20) DEFAULT NULL,
    parameters longtext,
    result longtext,
    status varchar(20) DEFAULT NULL,
    executed_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_conversation (conversation_id),
    KEY idx_action (action_type, object_type)
) {$charsetCollate};";
        dbDelta($sqlActions);

        $sqlMemory = "CREATE TABLE {$memoryTable} (
    id bigint(20) unsigned NOT NULL auto_increment,
    user_id bigint(20) unsigned DEFAULT NULL,
    memory_type varchar(50) NOT NULL,
    content longtext NOT NULL,
    context varchar(255) DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_user_type (user_id, memory_type),
    FULLTEXT KEY idx_content (content)
) {$charsetCollate};";
        dbDelta($sqlMemory);

        $auditLogTable = $wpdb->prefix . 'levi_audit_log';
        $sqlAuditLog = "CREATE TABLE {$auditLogTable} (
    id bigint(20) unsigned NOT NULL auto_increment,
    user_id bigint(20) unsigned DEFAULT NULL,
    session_id varchar(64) DEFAULT NULL,
    tool_name varchar(100) NOT NULL,
    tool_args longtext,
    success tinyint(1) NOT NULL DEFAULT 0,
    result_summary varchar(255) DEFAULT NULL,
    executed_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_user_tool (user_id, tool_name),
    KEY idx_executed_at (executed_at)
) {$charsetCollate};";
        dbDelta($sqlAuditLog);

        $rateLimitsTable = $wpdb->prefix . 'levi_rate_limits';
        $sqlRateLimits = "CREATE TABLE {$rateLimitsTable} (
    id bigint(20) unsigned NOT NULL auto_increment,
    user_id bigint(20) unsigned NOT NULL,
    window_start datetime NOT NULL,
    request_count int(11) NOT NULL DEFAULT 1,
    PRIMARY KEY  (id),
    KEY idx_user_window (user_id, window_start)
) {$charsetCollate};";
        dbDelta($sqlRateLimits);
    }
}
