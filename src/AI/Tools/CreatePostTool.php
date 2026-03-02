<?php

namespace Levi\Agent\AI\Tools;

class CreatePostTool implements ToolInterface {

    public function getName(): string {
        return 'create_post';
    }

    public function getDescription(): string {
        return 'Create a new WordPress post or custom post type (product, event, etc.). Can publish directly or save as draft.';
    }

    public function getParameters(): array {
        return [
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type to create. Default: post. Use "product" for WooCommerce, or any custom post type.',
                'default' => 'post',
            ],
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
            'status' => [
                'type' => 'string',
                'description' => 'Post status: publish, draft, pending, private',
                'enum' => ['publish', 'draft', 'pending', 'private'],
                'default' => 'draft',
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
            'allow_duplicate' => [
                'type' => 'boolean',
                'description' => 'If true, create even when an item with same title/slug exists',
                'default' => false,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        if (empty($params['title'])) {
            return [
                'success' => false,
                'error' => 'Title is required',
            ];
        }

        $postType = sanitize_key($params['post_type'] ?? 'post');
        if (!post_type_exists($postType)) {
            return [
                'success' => false,
                'error' => sprintf('Unknown post type: %s', $postType),
            ];
        }

        $title = sanitize_text_field($params['title']);
        $allowDuplicate = !empty($params['allow_duplicate']);
        if (!$allowDuplicate) {
            $existing = $this->findExistingByTitleOrSlug($postType, $title);
            if ($existing !== null) {
                return [
                    'success' => false,
                    'duplicate_found' => true,
                    'existing_id' => (int) $existing['ID'],
                    'existing_title' => (string) $existing['post_title'],
                    'existing_status' => (string) $existing['post_status'],
                    'error' => 'An item with this title already exists.',
                    'message' => 'Ein Eintrag mit diesem Titel existiert bereits. Ich habe nichts doppelt erstellt.',
                ];
            }
        }

        $allowedStatuses = ['publish', 'draft', 'pending', 'private'];
        $status = sanitize_key($params['status'] ?? 'draft');
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }
        if ($status === 'publish' && !current_user_can('publish_posts')) {
            $status = 'draft';
        }

        $postData = [
            'post_title'   => $title,
            'post_content' => wp_kses_post($params['content']),
            'post_status'  => $status,
            'post_type'    => $postType,
            'post_author'  => get_current_user_id(),
        ];

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
                    $term = get_term_by('name', $cat, 'category');
                    if ($term) {
                        $categoryIds[] = $term->term_id;
                    } else {
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

        $postId = wp_insert_post($postData, true);

        if (is_wp_error($postId)) {
            return [
                'success' => false,
                'error' => $postId->get_error_message(),
            ];
        }

        if (!empty($params['featured_image'])) {
            set_post_thumbnail($postId, intval($params['featured_image']));
        }

        $status = $postData['post_status'];
        
        return [
            'success' => true,
            'post_id' => $postId,
            'title' => $params['title'],
            'status' => $status,
            'url' => get_permalink($postId),
            'edit_url' => get_edit_post_link($postId, 'raw'),
            'message' => $status === 'publish' 
                ? 'Post published successfully.' 
                : 'Post created as ' . $status . '.',
        ];
    }

    private function findExistingByTitleOrSlug(string $postType, string $title): ?array {
        global $wpdb;
        $slug = sanitize_title($title);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_title, post_status
             FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status IN ('publish','draft','pending','private','future','trash')
               AND (post_title = %s OR post_name = %s)
             ORDER BY FIELD(post_status, 'publish','draft','pending','private','future','trash'), ID ASC
             LIMIT 1",
            $postType,
            $title,
            $slug
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }
}
