<?php

namespace Levi\Agent\AI\Tools;

class DeletePostTool implements ToolInterface {

    public function getName(): string {
        return 'delete_post';
    }

    public function getDescription(): string {
        return 'Delete a WordPress post or move to trash. Use with caution.';
    }

    public function getParameters(): array {
        return [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post ID to delete',
                'required' => true,
            ],
            'force_delete' => [
                'type' => 'boolean',
                'description' => 'Permanently delete (true) or move to trash (false)',
                'default' => false,
            ],
        ];
    }

    public function checkPermission(): bool {
        // Full admin access
        return current_user_can('manage_options') || current_user_can('delete_posts');
    }

    public function execute(array $params): array {
        $postId = intval($params['post_id']);
        $forceDelete = $params['force_delete'] ?? false;

        $post = get_post($postId);
        if (!$post) {
            return [
                'success' => false,
                'error' => 'Post not found',
            ];
        }

        $title = $post->post_title;
        $result = wp_delete_post($postId, $forceDelete);

        if (!$result) {
            return [
                'success' => false,
                'error' => 'Failed to delete post',
            ];
        }

        return [
            'success' => true,
            'post_id' => $postId,
            'title' => $title,
            'permanently_deleted' => $forceDelete,
            'message' => $forceDelete ? 'Post permanently deleted.' : 'Post moved to trash.',
        ];
    }
}
