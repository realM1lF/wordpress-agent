<?php

namespace Levi\Agent\AI\Tools;

class GetPagesTool implements ToolInterface {

    public function getName(): string {
        return 'get_pages';
    }

    public function getDescription(): string {
        return 'Get WordPress pages with optional full content. Supports pagination to fetch all pages.';
    }

    public function getParameters(): array {
        return [
            'number' => [
                'type' => 'integer',
                'description' => 'Number of pages to retrieve per request (max 200)',
                'default' => 20,
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Pagination page number (starts at 1)',
                'default' => 1,
            ],
            'include_content' => [
                'type' => 'boolean',
                'description' => 'Include full page content in response',
                'default' => false,
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Page status: publish, draft, pending, private, any',
                'enum' => ['publish', 'draft', 'pending', 'private', 'any'],
                'default' => 'any',
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
            'post_type' => 'page',
            'posts_per_page' => $perPage,
            'paged' => $pageNum,
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
            $pages[] = $this->formatPage($page, $includeContent);
        }

        return [
            'success' => true,
            'page' => $pageNum,
            'per_page' => $perPage,
            'has_more' => $query->max_num_pages > $pageNum,
            'max_pages' => (int) $query->max_num_pages,
            'count' => count($pages),
            'total' => $query->found_posts,
            'pages' => $pages,
        ];
    }

    private function formatPage(\WP_Post $page, bool $includeContent): array {
        $author = get_user_by('id', $page->post_author);
        $result = [
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

        if ($includeContent) {
            $result['content'] = $page->post_content;
            $result['content_rendered'] = apply_filters('the_content', $page->post_content);
        }

        return $result;
    }
}
