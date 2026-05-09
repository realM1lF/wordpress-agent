<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;
use Levi\Agent\AI\Tools\Concerns\HasFileOperations;

/**
 * Generic read tool — unified reading for files, posts, options, users, and media.
 */
class ReadTool extends AbstractTool {
    use HasFileOperations;

    public function getName(): string { return 'read'; }

    public function getDescription(): string {
        return "Liest Dateien, Posts, Seiten, Optionen, Benutzer oder Medien. "
            . "Verwende `type`, um das Ziel anzugeben. "
            . "Für Dateien: `path` (absoluter Pfad oder relativ zu wp-content). "
            . "Für Posts/Seiten: `type=post|page` und `id` (Post-ID) oder `slug`. "
            . "Für Optionen: `type=option` und `name`. "
            . "Für Benutzer: `type=user` und `id` oder `email`. "
            . "Für Medien: `type=media` und `id`. "
            . "`start_line` und `end_line` für Datei-Selektivlesen. "
            . "`fields` um nur bestimmte Felder zurückzugeben (z.B. [post_title,post_content]).";
    }

    public function getParameters(): array {
        return [
            'type' => [
                'type' => 'string',
                'enum' => ['file', 'post', 'page', 'option', 'user', 'media'],
                'description' => 'Was gelesen werden soll',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Pfad zur Datei (für type=file). Absolut oder relativ zu wp-content/',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'ID (für post, page, media, user)',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'Post-Slug oder Benutzer-Login (Alternative zu id)',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Options-Name (für type=option)',
            ],
            'email' => [
                'type' => 'string',
                'description' => 'E-Mail-Adresse (für type=user)',
            ],
            'start_line' => [
                'type' => 'integer',
                'description' => 'Erste Zeile (1-basiert, optional, für Dateien)',
            ],
            'end_line' => [
                'type' => 'integer',
                'description' => 'Letzte Zeile (optional, für Dateien)',
            ],
            'fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Nur diese Felder zurückgeben (z.B. ["post_title","post_content"])',
            ],
        ];
    }

    public function execute(array $params): array {
        $type = (string) ($params['type'] ?? 'file');

        return match ($type) {
            'file' => $this->readFile(
                (string) ($params['path'] ?? ''),
                $params['start_line'] ?? null,
                $params['end_line'] ?? null,
            ),
            'post', 'page' => $this->readPost($type, $params),
            'option' => $this->readOption($params),
            'user' => $this->readUser($params),
            'media' => $this->readMedia($params),
            default => ['success' => false, 'error' => "Unbekannter type: {$type}"],
        };
    }

    public function checkPermission(): bool {
        return current_user_can('read');
    }

    private function readPost(string $type, array $params): array {
        $postType = $type === 'page' ? 'page' : ($params['post_type'] ?? 'post');
        $fields = $params['fields'] ?? null;

        if (!empty($params['id'])) {
            $post = get_post((int) $params['id']);
        } elseif (!empty($params['slug'])) {
            $post = get_page_by_path((string) $params['slug'], OBJECT, $postType);
        } else {
            return ['success' => false, 'error' => 'Erforderlich: id oder slug'];
        }

        if (!$post instanceof \WP_Post) {
            return ['success' => false, 'error' => ucfirst($type) . ' nicht gefunden'];
        }

        $data = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'slug' => $post->post_name,
            'author_id' => (int) $post->post_author,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'parent' => (int) $post->post_parent,
        ];

        if ($fields !== null && is_array($fields)) {
            $data = array_intersect_key($data, array_flip($fields));
        }

        return ['success' => true, 'data' => $data];
    }

    private function readOption(array $params): array {
        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'Erforderlich: name'];
        }
        $value = get_option($name);
        return [
            'success' => true,
            'name' => $name,
            'value' => $value,
            'serialized' => is_serialized($value),
        ];
    }

    private function readUser(array $params): array {
        if (!empty($params['id'])) {
            $user = get_user_by('id', (int) $params['id']);
        } elseif (!empty($params['email'])) {
            $user = get_user_by('email', (string) $params['email']);
        } elseif (!empty($params['slug'])) {
            $user = get_user_by('login', (string) $params['slug']);
        } else {
            return ['success' => false, 'error' => 'Erforderlich: id, email oder slug'];
        }

        if (!$user instanceof \WP_User) {
            return ['success' => false, 'error' => 'Benutzer nicht gefunden'];
        }

        return [
            'success' => true,
            'data' => [
                'id' => $user->ID,
                'login' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'roles' => $user->roles,
                'registered' => $user->user_registered,
            ],
        ];
    }

    private function readMedia(array $params): array {
        if (empty($params['id'])) {
            return ['success' => false, 'error' => 'Erforderlich: id'];
        }
        $post = get_post((int) $params['id']);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return ['success' => false, 'error' => 'Medium nicht gefunden'];
        }

        $meta = wp_get_attachment_metadata($post->ID);
        return [
            'success' => true,
            'data' => [
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => wp_get_attachment_url($post->ID),
                'mime_type' => $post->post_mime_type,
                'alt' => get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                'metadata' => $meta,
            ],
        ];
    }
}
