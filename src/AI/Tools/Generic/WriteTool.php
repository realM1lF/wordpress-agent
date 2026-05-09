<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;
use Levi\Agent\AI\Tools\Concerns\HasFileOperations;

/**
 * Generic write tool — unified writing for files, posts, pages, options, users.
 */
class WriteTool extends AbstractTool {
    use HasFileOperations;

    public function getName(): string { return 'write'; }

    public function getDescription(): string {
        return "Erstellt oder überschreibt Dateien, Posts, Seiten, Optionen oder Benutzer. "
            . "Verwende `type`, um das Ziel anzugeben. "
            . "Für Dateien: `path` + `content`. `overwrite=true` um bestehende Dateien zu überschreiben. "
            . "Für Posts/Seiten: `type=post|page` + `title` + `content` (+ optional `status`, `slug`, `parent`). "
            . "Für Optionen: `type=option` + `name` + `value`. "
            . "Für Benutzer: `type=user` + `username` + `email` + `password` (+ `role`).";
    }

    public function getParameters(): array {
        return [
            'type' => [
                'type' => 'string',
                'enum' => ['file', 'post', 'page', 'option', 'user'],
                'description' => 'Was geschrieben werden soll',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Pfad zur Datei (für type=file)',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Inhalt (für Dateien, Posts, Seiten)',
            ],
            'overwrite' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Bestehende Datei überschreiben',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'ID zum Aktualisieren (für Post, Page, User)',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Titel (für Post, Page)',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'draft', 'private', 'pending', 'trash'],
                'default' => 'publish',
                'description' => 'Post-Status',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'URL-Slug (für Post, Page)',
            ],
            'parent' => [
                'type' => 'integer',
                'description' => 'Eltern-Seiten-ID (für Pages)',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Options-Name (für type=option)',
            ],
            'value' => [
                'type' => 'string',
                'description' => 'Options-Wert (für type=option)',
            ],
            'username' => [
                'type' => 'string',
                'description' => 'Benutzername (für type=user)',
            ],
            'email' => [
                'type' => 'string',
                'description' => 'E-Mail (für type=user)',
            ],
            'password' => [
                'type' => 'string',
                'description' => 'Passwort (für type=user)',
            ],
            'role' => [
                'type' => 'string',
                'default' => 'subscriber',
                'description' => 'Rolle (für type=user)',
            ],
            'meta' => [
                'type' => 'object',
                'description' => 'Meta-Daten als Key-Value-Paare (für Post, User)',
            ],
        ];
    }

    public function execute(array $params): array {
        $type = (string) ($params['type'] ?? 'file');

        return match ($type) {
            'file' => $this->writeFile(
                (string) ($params['path'] ?? ''),
                (string) ($params['content'] ?? ''),
                (bool) ($params['overwrite'] ?? false),
            ),
            'post', 'page' => $this->writePost($type, $params),
            'option' => $this->writeOption($params),
            'user' => $this->writeUser($params),
            default => ['success' => false, 'error' => "Unbekannter type: {$type}"],
        };
    }

    public function checkPermission(): bool {
        return current_user_can('publish_posts') || current_user_can('edit_files');
    }

    private function writePost(string $type, array $params): array {
        if (!current_user_can('publish_posts') && !current_user_can('edit_posts')) {
            return ['success' => false, 'error' => 'Keine Berechtigung zum Erstellen/Bearbeiten von Posts'];
        }

        $postData = [
            'post_type' => $type === 'page' ? 'page' : ($params['post_type'] ?? 'post'),
            'post_status' => $params['status'] ?? 'publish',
        ];

        if (!empty($params['id'])) {
            $postData['ID'] = (int) $params['id'];
        }
        if (!empty($params['title'])) {
            $postData['post_title'] = sanitize_text_field((string) $params['title']);
        }
        if (isset($params['content'])) {
            $postData['post_content'] = (string) $params['content'];
        }
        if (!empty($params['slug'])) {
            $postData['post_name'] = sanitize_title((string) $params['slug']);
        }
        if (!empty($params['parent'])) {
            $postData['post_parent'] = (int) $params['parent'];
        }

        $result = wp_insert_post($postData, true);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        if (!empty($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                update_post_meta($result, $key, $value);
            }
        }

        return [
            'success' => true,
            'id' => $result,
            'type' => $type,
            'url' => get_permalink($result),
            'edit_url' => admin_url("post.php?post={$result}&action=edit"),
        ];
    }

    private function writeOption(array $params): array {
        $name = (string) ($params['name'] ?? '');
        $value = $params['value'] ?? '';

        if ($name === '') {
            return ['success' => false, 'error' => 'Erforderlich: name'];
        }

        $dangerous = [
            'show_on_front', 'page_on_front', 'page_for_posts',
            'blogname', 'blogdescription', 'permalink_structure',
            'default_role', 'users_can_register', 'template', 'stylesheet',
        ];
        if (in_array($name, $dangerous, true) && !current_user_can('manage_options')) {
            return ['success' => false, 'error' => "Option '{$name}' erfordert manage_options."];
        }

        $oldValue = get_option($name);
        $updated = update_option($name, $value);

        return [
            'success' => true,
            'name' => $name,
            'updated' => $updated,
            'old_value' => $oldValue,
        ];
    }

    private function writeUser(array $params): array {
        if (!current_user_can('create_users')) {
            return ['success' => false, 'error' => 'Keine Berechtigung zum Erstellen von Benutzern'];
        }

        $userData = [];
        if (!empty($params['id'])) {
            $userData['ID'] = (int) $params['id'];
        }
        if (!empty($params['username'])) {
            $userData['user_login'] = sanitize_user((string) $params['username']);
        }
        if (!empty($params['email'])) {
            $userData['user_email'] = sanitize_email((string) $params['email']);
        }
        if (!empty($params['password'])) {
            $userData['user_pass'] = (string) $params['password'];
        }
        if (!empty($params['role'])) {
            $userData['role'] = (string) $params['role'];
        }

        $result = wp_insert_user($userData);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'id' => $result,
            'username' => $userData['user_login'] ?? null,
        ];
    }
}
