<?php

namespace Levi\Agent\AI\Tools;

class CreatePageTool implements ToolInterface {

    public function getName(): string {
        return 'create_page';
    }

    public function getDescription(): string {
        return 'Create a new WordPress page. Always creates as draft first.';
    }

    public function getParameters(): array {
        return [
            'title' => [
                'type' => 'string',
                'description' => 'The page title',
                'required' => true,
            ],
            'content' => [
                'type' => 'string',
                'description' => 'The page content (HTML or Gutenberg blocks)',
                'required' => true,
            ],
            'parent' => [
                'type' => 'integer',
                'description' => 'Parent page ID (optional)',
            ],
            'template' => [
                'type' => 'string',
                'description' => 'Page template (optional)',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('publish_pages');
    }

    public function execute(array $params): array {
        if (empty($params['title'])) {
            return [
                'success' => false,
                'error' => 'Title is required',
            ];
        }

        $pageData = [
            'post_title'   => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status'  => 'draft',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
        ];

        if (!empty($params['parent'])) {
            $pageData['post_parent'] = intval($params['parent']);
        }

        $pageId = wp_insert_post($pageData, true);

        if (is_wp_error($pageId)) {
            return [
                'success' => false,
                'error' => $pageId->get_error_message(),
            ];
        }

        // Set page template if provided
        if (!empty($params['template'])) {
            update_post_meta($pageId, '_wp_page_template', sanitize_text_field($params['template']));
        }

        return [
            'success' => true,
            'page_id' => $pageId,
            'title' => $params['title'],
            'status' => 'draft',
            'edit_url' => get_edit_post_link($pageId, 'raw'),
            'preview_url' => get_preview_post_link($pageId),
            'message' => 'Page created as draft. Review before publishing.',
        ];
    }
}
