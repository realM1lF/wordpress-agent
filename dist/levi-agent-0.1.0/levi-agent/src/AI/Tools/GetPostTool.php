<?php

namespace Levi\Agent\AI\Tools;

class GetPostTool implements ToolInterface {

    public function getName(): string {
        return 'get_post';
    }

    public function getDescription(): string {
        return 'Get detailed information about a specific post by ID or title.';
    }

    public function getParameters(): array {
        return [
            'id' => [
                'type' => 'integer',
                'description' => 'The post ID',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'The post title (used if ID not provided)',
            ],
            'include_content' => [
                'type' => 'boolean',
                'description' => 'Whether to include full post content',
                'default' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        $post = null;

        // Get by ID
        if (!empty($params['id'])) {
            $post = get_post(intval($params['id']));
        }
        // Get by title
        elseif (!empty($params['title'])) {
            $query = new \WP_Query([
                'post_type' => 'post',
                'title' => $params['title'],
                'posts_per_page' => 1,
            ]);
            if (!empty($query->posts)) {
                $post = $query->posts[0];
            }
        }

        if (!$post) {
            return [
                'success' => false,
                'error' => 'Post not found',
            ];
        }

        // Check if user can read this post
        if ($post->post_status !== 'publish' && !current_user_can('read_post', $post->ID)) {
            return [
                'success' => false,
                'error' => 'Permission denied to read this post',
            ];
        }

        return [
            'success' => true,
            'post' => $this->formatPost($post, $params['include_content'] ?? true),
        ];
    }

    private function formatPost(\WP_Post $post, bool $includeContent): array {
        $author = get_user_by('id', $post->post_author);
        $categories = get_the_category($post->ID);
        $tags = get_the_tags($post->ID);
        
        $data = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => wp_trim_words($post->post_content, 50),
            'status' => $post->post_status,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'author' => $author ? [
                'id' => $author->ID,
                'name' => $author->display_name,
                'email' => $author->user_email,
            ] : null,
            'categories' => array_map(fn($cat) => [
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
            ], $categories),
            'tags' => $tags ? array_map(fn($tag) => [
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ], $tags) : [],
            'permalink' => get_permalink($post->ID),
            'comment_count' => $post->comment_count,
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
        ];

        if ($includeContent) {
            $data['content'] = $post->post_content;
            $data['content_rendered'] = apply_filters('the_content', $post->post_content);
        }

        return $data;
    }
}
