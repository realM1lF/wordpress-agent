<?php

namespace Mohami\Agent\AI\Tools;

class GetMediaTool implements ToolInterface {

    public function getName(): string {
        return 'get_media';
    }

    public function getDescription(): string {
        return 'Get a list of media files from the WordPress Media Library.';
    }

    public function getParameters(): array {
        return [
            'number' => [
                'type' => 'integer',
                'description' => 'Number of media items to retrieve (max 20)',
                'default' => 10,
            ],
            'type' => [
                'type' => 'string',
                'description' => 'Filter by media type: image, video, audio, application',
                'enum' => ['image', 'video', 'audio', 'application', 'any'],
                'default' => 'any',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search media by title or filename',
            ],
            'month' => [
                'type' => 'integer',
                'description' => 'Filter by month (1-12)',
            ],
            'year' => [
                'type' => 'integer',
                'description' => 'Filter by year',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('upload_files');
    }

    public function execute(array $params): array {
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => min($params['number'] ?? 10, 20),
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Media type filter
        if (!empty($params['type']) && $params['type'] !== 'any') {
            $args['post_mime_type'] = $params['type'];
        }

        // Search
        if (!empty($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }

        // Date filter
        if (!empty($params['year'])) {
            $args['year'] = intval($params['year']);
        }
        if (!empty($params['month'])) {
            $args['monthnum'] = intval($params['month']);
        }

        $query = new \WP_Query($args);
        $media = [];

        foreach ($query->posts as $attachment) {
            $media[] = $this->formatMedia($attachment);
        }

        return [
            'success' => true,
            'count' => count($media),
            'total' => $query->found_posts,
            'media' => $media,
        ];
    }

    private function formatMedia(\WP_Post $attachment): array {
        $metadata = wp_get_attachment_metadata($attachment->ID);
        $url = wp_get_attachment_url($attachment->ID);
        
        $data = [
            'id' => $attachment->ID,
            'title' => $attachment->post_title,
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'alt_text' => get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
            'url' => $url,
            'type' => $attachment->post_mime_type,
            'date' => $attachment->post_date,
        ];

        // Add image-specific data
        if (strpos($attachment->post_mime_type, 'image/') === 0) {
            $data['sizes'] = [];
            
            if (!empty($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $sizeData) {
                    $data['sizes'][$size] = [
                        'url' => wp_get_attachment_image_url($attachment->ID, $size),
                        'width' => $sizeData['width'],
                        'height' => $sizeData['height'],
                    ];
                }
            }

            $data['width'] = $metadata['width'] ?? null;
            $data['height'] = $metadata['height'] ?? null;
        }

        return $data;
    }
}
