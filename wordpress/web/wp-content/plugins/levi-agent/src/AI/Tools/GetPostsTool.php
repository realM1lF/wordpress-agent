<?php

namespace Levi\Agent\AI\Tools;

class GetPostsTool implements ToolInterface {

    public function getName(): string {
        return 'get_posts';
    }

    public function getDescription(): string {
        return 'Get a list of WordPress posts. Can filter by status, category, author, and more.';
    }

    public function getParameters(): array {
        return [
            'number' => [
                'type' => 'integer',
                'description' => 'Number of posts to retrieve (max 20)',
                'default' => 5,
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Post status: publish, draft, pending, future, private',
                'enum' => ['publish', 'draft', 'pending', 'future', 'private', 'any'],
                'default' => 'publish',
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
        $args = [
            'post_type' => 'post',
            'posts_per_page' => min($params['number'] ?? 5, 20),
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
            $posts[] = $this->formatPost($post);
        }

        return [
            'success' => true,
            'count' => count($posts),
            'total' => $query->found_posts,
            'posts' => $posts,
        ];
    }

    private function formatPost(\WP_Post $post): array {
        $author = get_user_by('id', $post->post_author);
        $categories = get_the_category($post->ID);
        
        return [
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
    }
}
