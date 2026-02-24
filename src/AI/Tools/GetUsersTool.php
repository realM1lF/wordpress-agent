<?php

namespace Mohami\Agent\AI\Tools;

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
                'description' => 'Number of users to retrieve (max 20)',
                'default' => 5,
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
        $args = [
            'number' => min($params['number'] ?? 5, 20),
            'orderby' => $params['orderby'] ?? 'name',
        ];

        if (!empty($params['role'])) {
            $args['role'] = $params['role'];
        }

        if (!empty($params['search'])) {
            $args['search'] = '*' . sanitize_text_field($params['search']) . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
        }

        $users = get_users($args);
        $formatted = [];

        foreach ($users as $user) {
            $formatted[] = $this->formatUser($user);
        }

        return [
            'success' => true,
            'count' => count($formatted),
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
