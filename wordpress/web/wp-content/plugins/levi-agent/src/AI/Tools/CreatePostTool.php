<?php

namespace Levi\Agent\AI\Tools;

class CreatePostTool implements ToolInterface {

    public function getName(): string {
        return 'create_post';
    }

    public function getDescription(): string {
        return 'Create a new WordPress post. Always creates as draft first for safety.';
    }

    public function getParameters(): array {
        return [
            'title' => [
                'type' => 'string',
                'description' => 'The post title',
                'required' => true,
            ],
            'content' => [
                'type' => 'string',
                'description' => 'The post content (HTML or Gutenberg blocks)',
                'required' => true,
            ],
            'excerpt' => [
                'type' => 'string',
                'description' => 'Optional post excerpt',
            ],
            'categories' => [
                'type' => 'array',
                'description' => 'Category IDs or names',
                'items' => ['type' => 'string'],
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'Tag names',
                'items' => ['type' => 'string'],
            ],
            'featured_image' => [
                'type' => 'integer',
                'description' => 'Attachment ID for featured image',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('publish_posts');
    }

    public function execute(array $params): array {
        // Validate required fields
        if (empty($params['title'])) {
            return [
                'success' => false,
                'error' => 'Title is required',
            ];
        }

        // Prepare post data
        $postData = [
            'post_title'   => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status'  => 'draft', // ALWAYS draft for safety
            'post_type'    => 'post',
            'post_author'  => get_current_user_id(),
        ];

        // Add excerpt if provided
        if (!empty($params['excerpt'])) {
            $postData['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }

        // Handle categories
        if (!empty($params['categories'])) {
            $categoryIds = [];
            foreach ($params['categories'] as $cat) {
                if (is_numeric($cat)) {
                    $categoryIds[] = intval($cat);
                } else {
                    // Get or create category by name
                    $term = get_term_by('name', $cat, 'category');
                    if ($term) {
                        $categoryIds[] = $term->term_id;
                    } else {
                        // Create category
                        $newTerm = wp_insert_term($cat, 'category');
                        if (!is_wp_error($newTerm)) {
                            $categoryIds[] = $newTerm['term_id'];
                        }
                    }
                }
            }
            $postData['post_category'] = $categoryIds;
        }

        // Handle tags
        if (!empty($params['tags'])) {
            $postData['tags_input'] = array_map('sanitize_text_field', $params['tags']);
        }

        // Insert post
        $postId = wp_insert_post($postData, true);

        if (is_wp_error($postId)) {
            return [
                'success' => false,
                'error' => $postId->get_error_message(),
            ];
        }

        // Set featured image if provided
        if (!empty($params['featured_image'])) {
            set_post_thumbnail($postId, intval($params['featured_image']));
        }

        // Log action
        $this->logAction($postId, 'create', $params);

        return [
            'success' => true,
            'post_id' => $postId,
            'title' => $params['title'],
            'status' => 'draft',
            'edit_url' => get_edit_post_link($postId, 'raw'),
            'preview_url' => get_preview_post_link($postId),
            'message' => 'Post created as draft. Review before publishing.',
        ];
    }

    private function logAction(int $postId, string $action, array $params): void {
        $userId = get_current_user_id();
        $user = get_user_by('id', $userId);
        $userName = $user ? $user->display_name : 'Unknown';

        error_log(sprintf(
            '[Levi Agent] User %s (%d) %s post "%s" (ID: %d)',
            $userName,
            $userId,
            $action,
            $params['title'] ?? 'Untitled',
            $postId
        ));
    }
}
