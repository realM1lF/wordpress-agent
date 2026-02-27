<?php

namespace Levi\Agent\AI\Tools;

class UpdatePostTool implements ToolInterface {

    public function getName(): string {
        return 'update_post';
    }

    public function getDescription(): string {
        return 'Update an existing WordPress post. Only updates provided fields.';
    }

    public function getParameters(): array {
        return [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post ID to update',
                'required' => true,
            ],
            'title' => [
                'type' => 'string',
                'description' => 'New title (optional)',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'New content (optional)',
            ],
            'excerpt' => [
                'type' => 'string',
                'description' => 'New excerpt (optional)',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'New status: publish, draft, pending, private',
                'enum' => ['publish', 'draft', 'pending', 'private'],
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        $postId = intval($params['post_id']);

        // Check if post exists
        $post = get_post($postId);
        if (!$post) {
            return [
                'success' => false,
                'error' => 'Post not found',
            ];
        }

        // Check user can edit this specific post
        if (!current_user_can('edit_post', $postId)) {
            return [
                'success' => false,
                'error' => 'Permission denied to edit this post',
            ];
        }

        // Build update data
        $updateData = [
            'ID' => $postId,
        ];

        // Only update provided fields
        if (isset($params['title'])) {
            $updateData['post_title'] = sanitize_text_field($params['title']);
        }

        if (isset($params['content'])) {
            $updateData['post_content'] = wp_kses_post($params['content']);
        }

        if (isset($params['excerpt'])) {
            $updateData['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }

        if (isset($params['status'])) {
            // Safety: Check if user can publish
            if ($params['status'] === 'publish' && !current_user_can('publish_posts')) {
                return [
                    'success' => false,
                    'error' => 'Permission denied to publish posts',
                ];
            }
            $updateData['post_status'] = sanitize_key($params['status']);
        }

        // Create revision before updating
        wp_save_post_revision($postId);

        // Update post
        $result = wp_update_post($updateData, true);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message(),
            ];
        }

        // Log action
        $this->logAction($postId, 'update', $params);

        return [
            'success' => true,
            'post_id' => $postId,
            'title' => get_the_title($postId),
            'edit_url' => get_edit_post_link($postId, 'raw'),
            'message' => 'Post updated successfully.',
        ];
    }

    private function logAction(int $postId, string $action, array $params): void {
        $userId = get_current_user_id();
        $user = get_user_by('id', $userId);
        $userName = $user ? $user->display_name : 'Unknown';

        error_log(sprintf(
            '[Levi Agent] User %s (%d) %s post ID %d',
            $userName,
            $userId,
            $action,
            $postId
        ));
    }
}
