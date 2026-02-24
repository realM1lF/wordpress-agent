<?php

namespace Levi\Agent\AI\Tools;

class GetPagesTool implements ToolInterface {

    public function getName(): string {
        return 'get_pages';
    }

    public function getDescription(): string {
        return 'Get a list of WordPress pages. Can filter by status, parent, and more.';
    }

    public function getParameters(): array {
        return [
            'number' => [
                'type' => 'integer',
                'description' => 'Number of pages to retrieve (max 20)',
                'default' => 5,
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Page status: publish, draft, pending, private',
                'enum' => ['publish', 'draft', 'pending', 'private', 'any'],
                'default' => 'publish',
            ],
            'parent' => [
                'type' => 'integer',
                'description' => 'Parent page ID (0 for top-level pages)',
            ],
            'orderby' => [
                'type' => 'string',
                'description' => 'Sort by: date, title, modified, menu_order',
                'enum' => ['date', 'title', 'modified', 'menu_order', 'id'],
                'default' => 'menu_order',
            ],
            'order' => [
                'type' => 'string',
                'description' => 'Sort order: ASC or DESC',
                'enum' => ['ASC', 'DESC'],
                'default' => 'ASC',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_pages');
    }

    public function execute(array $params): array {
        $args = [
            'post_type' => 'page',
            'posts_per_page' => min($params['number'] ?? 5, 20),
            'post_status' => $params['status'] ?? 'publish',
            'orderby' => $params['orderby'] ?? 'menu_order',
            'order' => $params['order'] ?? 'ASC',
        ];

        if (isset($params['parent'])) {
            $args['post_parent'] = intval($params['parent']);
        }

        $query = new \WP_Query($args);
        $pages = [];

        foreach ($query->posts as $page) {
            $pages[] = $this->formatPage($page);
        }

        return [
            'success' => true,
            'count' => count($pages),
            'total' => $query->found_posts,
            'pages' => $pages,
        ];
    }

    private function formatPage(\WP_Post $page): array {
        $author = get_user_by('id', $page->post_author);
        
        return [
            'id' => $page->ID,
            'title' => $page->post_title,
            'excerpt' => wp_trim_words($page->post_content, 30),
            'status' => $page->post_status,
            'date' => $page->post_date,
            'modified' => $page->post_modified,
            'author' => $author ? $author->display_name : 'Unknown',
            'parent' => $page->post_parent,
            'menu_order' => $page->menu_order,
            'permalink' => get_permalink($page->ID),
        ];
    }
}
