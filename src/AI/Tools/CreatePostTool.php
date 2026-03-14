<?php

namespace Levi\Agent\AI\Tools;

class CreatePostTool implements ToolInterface {

    public function getName(): string {
        return 'create_post';
    }

    public function getDescription(): string {
        return 'Create a new WordPress post or custom post type entry (product, event, etc.). '
            . 'Supports title, content (HTML), excerpt, categories, tags, featured image, and post_type for custom types. '
            . 'Default status is draft; set status to "publish" for immediate publication. '
            . 'For pages (Seiten), use create_page instead.';
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

        $allowedStatuses = ['publish', 'draft', 'pending', 'private'];
        $status = sanitize_key($params['status'] ?? 'draft');
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }
        if ($status === 'publish' && !current_user_can('publish_posts')) {
            $status = 'draft';
        }

        $postData = [
            'post_title'   => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status'  => $status,
            'post_type'    => sanitize_key($params['post_type'] ?? 'post'),
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

        $created = get_post($postId);
        $actualStatus = $created ? $created->post_status : $postData['post_status'];
        $actualTitle = $created ? $created->post_title : $params['title'];

        return [
            'success' => true,
            'post_id' => $postId,
            'post_type' => $postData['post_type'],
            'title' => $actualTitle,
            'status' => $actualStatus,
            'url' => get_permalink($postId),
            'edit_url' => get_edit_post_link($postId, 'raw'),
            'message' => $actualStatus === 'publish'
                ? 'Post published successfully.'
                : 'Post created as ' . $actualStatus . '.',
        ];
    }

    public function getInputExamples(): array
    {
        return [
            ['title' => 'Mein erster Beitrag', 'content' => '<p>Willkommen auf meiner Seite!</p>', 'status' => 'draft'],
            ['title' => 'Produktankuendigung', 'content' => '<h2>Neu im Shop</h2><p>Ab sofort verfuegbar...</p>', 'status' => 'publish', 'categories' => [3]],
        ];
    }
}
