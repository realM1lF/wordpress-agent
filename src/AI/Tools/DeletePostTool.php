<?php

namespace Levi\Agent\AI\Tools;

class DeletePostTool implements ToolInterface {

    public function getName(): string {
        return 'delete_post';
    }

    public function getDescription(): string {
        return 'Delete a WordPress post, page, or custom post type entry by ID. '
            . 'By default moves to trash (recoverable); set force=true to permanently delete. '
            . 'Works for any post type including WooCommerce products and custom types. '
            . 'Returns the deleted post data on success.';
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
                'error' => 'Post not found.',
                'suggestion' => 'Use get_posts to list available posts and verify the ID.',
            ];
        }

        if (!current_user_can('delete_post', $postId)) {
            return [
                'success' => false,
                'error' => 'Permission denied to delete this post',
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

        $result = [
            'success' => true,
            'post_id' => $postId,
            'title' => $title,
            'post_type' => $post->post_type,
            'permanently_deleted' => $forceDelete,
            'message' => $forceDelete ? 'Post permanently deleted.' : 'Post moved to trash.',
        ];

        if ($post->post_type !== 'post') {
            $result['note'] = sprintf('This is a %s, not a post.', $post->post_type);
        }

        return $result;
    }
}
