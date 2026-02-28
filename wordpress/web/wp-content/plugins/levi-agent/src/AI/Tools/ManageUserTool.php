<?php

namespace Levi\Agent\AI\Tools;

class ManageUserTool implements ToolInterface {

    public function getName(): string {
        return 'manage_user';
    }

    public function getDescription(): string {
        return 'Create or update WordPress users.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action: create, update',
                'enum' => ['create', 'update'],
                'required' => true,
            ],
            'user_id' => [
                'type' => 'integer',
                'description' => 'User ID (required for update/delete)',
            ],
            'username' => [
                'type' => 'string',
                'description' => 'Username (required for create)',
            ],
            'email' => [
                'type' => 'string',
                'description' => 'Email address',
            ],
            'role' => [
                'type' => 'string',
                'description' => 'User role: subscriber, contributor, author, editor, administrator',
                'enum' => ['subscriber', 'contributor', 'author', 'editor', 'administrator'],
            ],
            'password' => [
                'type' => 'string',
                'description' => 'Password (only for create)',
            ],
            'display_name' => [
                'type' => 'string',
                'description' => 'Display name',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('create_users') || current_user_can('edit_users');
    }

    public function execute(array $params): array {
        $action = $params['action'];

        return match ($action) {
            'create' => !current_user_can('create_users')
                ? ['success' => false, 'error' => 'Permission denied to create users']
                : $this->createUser($params),
            'update' => !current_user_can('edit_users')
                ? ['success' => false, 'error' => 'Permission denied to update users']
                : $this->updateUser($params),
            default => ['success' => false, 'error' => 'Unknown action. Allowed: create, update'],
        };
    }

    private function createUser(array $params): array {
        if (empty($params['username']) || empty($params['email'])) {
            return [
                'success' => false,
                'error' => 'Username and email required',
            ];
        }

        $userdata = [
            'user_login' => sanitize_user($params['username']),
            'user_email' => sanitize_email($params['email']),
            'user_pass' => $params['password'] ?? wp_generate_password(),
            'role' => $params['role'] ?? 'subscriber',
        ];

        if (!empty($params['display_name'])) {
            $userdata['display_name'] = sanitize_text_field($params['display_name']);
        }

        $userId = wp_insert_user($userdata);

        if (is_wp_error($userId)) {
            return [
                'success' => false,
                'error' => $userId->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'user_id' => $userId,
            'username' => $params['username'],
            'role' => $userdata['role'],
            'message' => 'User created successfully.',
        ];
    }

    private function updateUser(array $params): array {
        if (empty($params['user_id'])) {
            return [
                'success' => false,
                'error' => 'User ID required',
            ];
        }

        $userdata = ['ID' => intval($params['user_id'])];

        if (!empty($params['email'])) {
            $userdata['user_email'] = sanitize_email($params['email']);
        }
        if (!empty($params['role'])) {
            if (!current_user_can('promote_users')) {
                return [
                    'success' => false,
                    'error' => 'Permission denied to change user roles',
                ];
            }
            $userdata['role'] = $params['role'];
        }
        if (!empty($params['display_name'])) {
            $userdata['display_name'] = sanitize_text_field($params['display_name']);
        }

        $result = wp_update_user($userdata);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'user_id' => $params['user_id'],
            'message' => 'User updated successfully.',
        ];
    }

}
