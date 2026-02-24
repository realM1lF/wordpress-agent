<?php

namespace Levi\Agent\AI\Tools;

class GetUsersTool implements ToolInterface {

    public function getName(): string {
        return 'get_users';
    }

    public function getDescription(): string {
        return 'Get a list of WordPress users. Can filter by role and search.';
    }

    public function getParameters(): array {
        return [
            'number' => [
                'type' => 'integer',
                'description' => 'Users per page (max 200)',
                'default' => 50,
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Pagination page number (starts at 1)',
                'default' => 1,
            ],
            'role' => [
                'type' => 'string',
                'description' => 'Filter by user role: administrator, editor, author, contributor, subscriber',
                'enum' => ['administrator', 'editor', 'author', 'contributor', 'subscriber'],
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search users by name, username, or email',
            ],
            'orderby' => [
                'type' => 'string',
                'description' => 'Sort by: name, registered, id',
                'enum' => ['name', 'registered', 'id', 'login'],
                'default' => 'name',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('list_users');
    }

    public function execute(array $params): array {
        $perPage = max(1, min(200, (int) ($params['number'] ?? 50)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $args = [
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => $params['orderby'] ?? 'name',
            'count_total' => true,
        ];

        if (!empty($params['role'])) {
            $args['role'] = $params['role'];
        }

        if (!empty($params['search'])) {
            $args['search'] = '*' . sanitize_text_field($params['search']) . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
        }

        $query = new \WP_User_Query($args);
        $users = $query->get_results();
        $formatted = [];

        foreach ($users as $user) {
            $formatted[] = $this->formatUser($user);
        }

        return [
            'success' => true,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($args['offset'] + count($formatted)) < (int) $query->get_total(),
            'max_pages' => (int) ceil(((int) $query->get_total()) / $perPage),
            'count' => count($formatted),
            'total' => (int) $query->get_total(),
            'users' => $formatted,
        ];
    }

    private function formatUser(\WP_User $user): array {
        return [
            'id' => $user->ID,
            'username' => $user->user_login,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'roles' => $user->roles,
            'registered' => $user->user_registered,
            'posts_url' => get_author_posts_url($user->ID),
        ];
    }
}
