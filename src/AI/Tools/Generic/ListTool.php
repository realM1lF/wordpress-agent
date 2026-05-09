<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Generic list tool — lists files, posts, pages, plugins, themes, users, media.
 */
class ListTool extends AbstractTool {

    public function getName(): string { return 'list'; }

    public function getDescription(): string {
        return "Listet Dateien, Posts, Seiten, Plugins, Themes, Benutzer oder Medien auf. "
            . "Verwende `type`, um das Ziel anzugeben. "
            . "`limit` (1-100, default 20) und `offset` für Paginierung. "
            . "Für Posts: `post_type`, `status`, `author_id`, `search` (im Titel). "
            . "Für Dateien: `directory` (absoluter Pfad), `pattern` (z.B. '*.php'). "
            . "Für Plugins/Themes: `status` (active/inactive/all). "
            . "Für Benutzer: `role`, `search`. "
            . "Für Medien: `mime_type` (z.B. 'image/'). "
            . "`fields` um nur bestimmte Felder zurückzugeben.";
    }

    public function getParameters(): array {
        return [
            'type' => [
                'type' => 'string',
                'enum' => ['file', 'post', 'page', 'plugin', 'theme', 'user', 'media'],
                'description' => 'Was aufgelistet werden soll',
                'required' => true,
            ],
            'directory' => [
                'type' => 'string',
                'description' => 'Verzeichnis (für type=file). Absoluter Pfad oder relativ zu wp-content/',
            ],
            'pattern' => [
                'type' => 'string',
                'description' => 'Glob-Pattern (z.B. "*.php") für Dateien',
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Post-Type (für type=post, z.B. post, product, page)',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Status-Filter (für Posts: publish/draft/trash; Plugins: active/inactive/all)',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Suchbegriff (für Posts, Benutzer)',
            ],
            'author_id' => [
                'type' => 'integer',
                'description' => 'Autoren-Filter (für Posts)',
            ],
            'role' => [
                'type' => 'string',
                'description' => 'Rollen-Filter (für Benutzer)',
            ],
            'mime_type' => [
                'type' => 'string',
                'description' => 'MIME-Type-Filter (für Medien, z.B. image/)',
            ],
            'limit' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
                'description' => 'Maximale Anzahl Ergebnisse',
            ],
            'offset' => [
                'type' => 'integer',
                'default' => 0,
                'description' => 'Offset für Paginierung',
            ],
            'fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Nur diese Felder zurückgeben',
            ],
        ];
    }

    public function execute(array $params): array {
        $type = (string) ($params['type'] ?? 'post');
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $offset = (int) ($params['offset'] ?? 0);

        return match ($type) {
            'file' => $this->listFiles($params, $limit, $offset),
            'post' => $this->listPosts($params, $limit, $offset),
            'page' => $this->listPosts(['post_type' => 'page', ...$params], $limit, $offset),
            'plugin' => $this->listPlugins($params, $limit, $offset),
            'theme' => $this->listThemes($params, $limit, $offset),
            'user' => $this->listUsers($params, $limit, $offset),
            'media' => $this->listMedia($params, $limit, $offset),
            default => ['success' => false, 'error' => "Unbekannter type: {$type}"],
        };
    }

    public function checkPermission(): bool {
        return current_user_can('read');
    }

    private function listFiles(array $params, int $limit, int $offset): array {
        $dir = (string) ($params['directory'] ?? '.');
        $pattern = (string) ($params['pattern'] ?? '*');

        $resolved = realpath($dir);
        if ($resolved === false) {
            $resolved = realpath(WP_CONTENT_DIR . '/' . $dir) ?: WP_CONTENT_DIR;
        }

        // Sicherheitscheck
        $allowedRoots = [realpath(WP_CONTENT_DIR), realpath(ABSPATH), realpath(wp_upload_dir()['basedir'])];
        $inAllowed = false;
        foreach ($allowedRoots as $root) {
            if ($root !== false && str_starts_with($resolved, $root)) {
                $inAllowed = true;
                break;
            }
        }
        if (!$inAllowed) {
            return ['success' => false, 'error' => 'Pfad außerhalb erlaubter Verzeichnisse'];
        }

        $files = glob($resolved . '/*');
        if ($files === false) {
            $files = [];
        }

        // Filter nach Pattern
        if ($pattern !== '*') {
            $files = array_filter($files, fn($f) => fnmatch($pattern, basename($f)));
        }

        $total = count($files);
        $files = array_slice($files, $offset, $limit);

        $items = array_map(fn($f) => [
            'name' => basename($f),
            'path' => $f,
            'is_dir' => is_dir($f),
            'size' => is_file($f) ? filesize($f) : null,
            'modified' => is_file($f) ? date('Y-m-d H:i:s', filemtime($f)) : null,
        ], $files);

        return [
            'success' => true,
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function listPosts(array $params, int $limit, int $offset): array {
        $queryArgs = [
            'post_type' => $params['post_type'] ?? 'post',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'post_status' => $params['status'] ?? 'any',
        ];

        if (!empty($params['search'])) {
            $queryArgs['s'] = (string) $params['search'];
        }
        if (!empty($params['author_id'])) {
            $queryArgs['author'] = (int) $params['author_id'];
        }

        $query = new \WP_Query($queryArgs);
        $fields = $params['fields'] ?? null;

        $items = array_map(function ($post) use ($fields) {
            $data = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'type' => $post->post_type,
                'slug' => $post->post_name,
                'date' => $post->post_date,
                'modified' => $post->post_modified,
                'author_id' => (int) $post->post_author,
            ];
            if ($fields !== null && is_array($fields)) {
                $data = array_intersect_key($data, array_flip($fields));
            }
            return $data;
        }, $query->posts);

        return [
            'success' => true,
            'items' => $items,
            'total' => (int) $query->found_posts,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function listPlugins(array $params, int $limit, int $offset): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        $status = $params['status'] ?? 'all';
        $fields = $params['fields'] ?? null;

        if ($status === 'active') {
            $all = array_filter($all, fn($_, $file) => is_plugin_active($file), ARRAY_FILTER_USE_BOTH);
        } elseif ($status === 'inactive') {
            $all = array_filter($all, fn($_, $file) => !is_plugin_active($file), ARRAY_FILTER_USE_BOTH);
        }

        $total = count($all);
        $all = array_slice($all, $offset, $limit, true);

        $items = [];
        foreach ($all as $file => $data) {
            $item = [
                'file' => $file,
                'name' => $data['Name'] ?? basename($file),
                'version' => $data['Version'] ?? 'unknown',
                'active' => is_plugin_active($file),
                'author' => $data['Author'] ?? null,
                'description' => $data['Description'] ?? null,
            ];
            if ($fields !== null && is_array($fields)) {
                $item = array_intersect_key($item, array_flip($fields));
            }
            $items[] = $item;
        }

        return [
            'success' => true,
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function listThemes(array $params, int $limit, int $offset): array {
        $all = wp_get_themes();
        $status = $params['status'] ?? 'all';
        $fields = $params['fields'] ?? null;
        $current = get_template();

        if ($status === 'active') {
            $all = array_filter($all, fn($t) => $t->get_template() === $current);
        }

        $total = count($all);
        $all = array_slice($all, $offset, $limit, true);

        $items = [];
        foreach ($all as $slug => $theme) {
            $item = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $slug === $current,
                'author' => $theme->get('Author'),
            ];
            if ($fields !== null && is_array($fields)) {
                $item = array_intersect_key($item, array_flip($fields));
            }
            $items[] = $item;
        }

        return [
            'success' => true,
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function listUsers(array $params, int $limit, int $offset): array {
        $queryArgs = [
            'number' => $limit,
            'offset' => $offset,
            'fields' => 'all',
        ];
        if (!empty($params['role'])) {
            $queryArgs['role'] = (string) $params['role'];
        }
        if (!empty($params['search'])) {
            $queryArgs['search'] = '*' . (string) $params['search'] . '*';
        }

        $users = get_users($queryArgs);
        $fields = $params['fields'] ?? null;

        $items = array_map(function ($user) use ($fields) {
            $data = [
                'id' => $user->ID,
                'login' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'roles' => $user->roles,
            ];
            if ($fields !== null && is_array($fields)) {
                $data = array_intersect_key($data, array_flip($fields));
            }
            return $data;
        }, $users);

        return [
            'success' => true,
            'items' => $items,
            'total' => (int) count_users()['total_users'] ?? 0,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function listMedia(array $params, int $limit, int $offset): array {
        $queryArgs = [
            'post_type' => 'attachment',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'post_status' => 'inherit',
        ];
        if (!empty($params['mime_type'])) {
            $queryArgs['post_mime_type'] = (string) $params['mime_type'];
        }

        $query = new \WP_Query($queryArgs);
        $fields = $params['fields'] ?? null;

        $items = array_map(function ($post) use ($fields) {
            $data = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => wp_get_attachment_url($post->ID),
                'mime_type' => $post->post_mime_type,
                'date' => $post->post_date,
            ];
            if ($fields !== null && is_array($fields)) {
                $data = array_intersect_key($data, array_flip($fields));
            }
            return $data;
        }, $query->posts);

        return [
            'success' => true,
            'items' => $items,
            'total' => (int) $query->found_posts,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
