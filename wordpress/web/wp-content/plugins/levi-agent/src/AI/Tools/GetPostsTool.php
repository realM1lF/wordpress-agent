<?php

namespace Levi\Agent\AI\Tools;

class GetPostsTool implements ToolInterface {

    public function getName(): string {
        return 'get_posts';
    }

    public function getDescription(): string {
        return 'Get WordPress posts with optional full content. Supports pagination to fetch all posts.';
    }

    public function getParameters(): array {
        return [
            'number' => [
                'type' => 'integer',
                'description' => 'Number of posts to retrieve per request (max 200)',
                'default' => 20,
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Pagination page number (starts at 1)',
                'default' => 1,
            ],
            'include_content' => [
                'type' => 'boolean',
                'description' => 'Include full post content in response',
                'default' => false,
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Post status: publish, draft, pending, future, private',
                'enum' => ['publish', 'draft', 'pending', 'future', 'private', 'any'],
                'default' => 'any',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Category name or slug to filter by',
            ],
            'author' => [
                'type' => 'string',
                'description' => 'Author name or ID to filter by',
            ],
            'orderby' => [
                'type' => 'string',
                'description' => 'Sort by: date, title, modified',
                'enum' => ['date', 'title', 'modified', 'id'],
                'default' => 'date',
            ],
            'order' => [
                'type' => 'string',
                'description' => 'Sort order: ASC or DESC',
                'enum' => ['ASC', 'DESC'],
                'default' => 'DESC',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search term to filter posts',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        $perPage = (int) ($params['number'] ?? 20);
        if ($perPage < 1) {
            $perPage = 1;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $pageNum = (int) ($params['page'] ?? 1);
        if ($pageNum < 1) {
            $pageNum = 1;
        }

        $includeContent = (bool) ($params['include_content'] ?? false);

        $args = [
            'post_type' => 'post',
            'posts_per_page' => $perPage,
            'paged' => $pageNum,
            'post_status' => $params['status'] ?? 'publish',
            'orderby' => $params['orderby'] ?? 'date',
            'order' => $params['order'] ?? 'DESC',
        ];

        // Category filter
        if (!empty($params['category'])) {
            $category = get_category_by_slug($params['category']);
            if (!$category) {
                $category = get_cat_ID($params['category']);
            }
            if ($category) {
                $args['cat'] = is_object($category) ? $category->term_id : $category;
            }
        }

        // Author filter
        if (!empty($params['author'])) {
            if (is_numeric($params['author'])) {
                $args['author'] = intval($params['author']);
            } else {
                $user = get_user_by('login', $params['author']);
                if ($user) {
                    $args['author'] = $user->ID;
                }
            }
        }

        // Search
        if (!empty($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }

        $query = new \WP_Query($args);
        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = $this->formatPost($post, $includeContent);
        }

        return [
            'success' => true,
            'page' => $pageNum,
            'per_page' => $perPage,
            'has_more' => $query->max_num_pages > $pageNum,
            'max_pages' => (int) $query->max_num_pages,
            'count' => count($posts),
            'total' => $query->found_posts,
            'posts' => $posts,
        ];
    }

    private function formatPost(\WP_Post $post, bool $includeContent): array {
        $author = get_user_by('id', $post->post_author);
        $categories = get_the_category($post->ID);
        $result = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => wp_trim_words($post->post_content, 30),
            'status' => $post->post_status,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'author' => $author ? $author->display_name : 'Unknown',
            'categories' => array_map(fn($cat) => $cat->name, $categories),
            'permalink' => get_permalink($post->ID),
            'comment_count' => $post->comment_count,
        ];

        if ($includeContent) {
            $result['content'] = $post->post_content;
            $result['content_rendered'] = apply_filters('the_content', $post->post_content);
        }

        return $result;
    }
}
