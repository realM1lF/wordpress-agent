<?php

namespace Levi\Agent\AI\Tools;

class UploadMediaTool implements ToolInterface {

    public function getName(): string {
        return 'upload_media';
    }

    public function getDescription(): string {
        return 'Upload media (images) to the WordPress media library from a URL. Downloads the image and creates a proper WordPress attachment. Useful for setting featured images, creating product images, or adding media to posts.';
    }

    public function getParameters(): array {
        return [
            'url' => [
                'type' => 'string',
                'description' => 'Source URL of the image to download and upload',
                'required' => true,
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Title for the media attachment',
            ],
            'alt_text' => [
                'type' => 'string',
                'description' => 'Alt text for the image',
            ],
            'attach_to_post' => [
                'type' => 'integer',
                'description' => 'Post ID to attach this media to (optional)',
            ],
            'set_featured' => [
                'type' => 'boolean',
                'description' => 'Set as featured image for attach_to_post (default false)',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('upload_files');
    }

    public function execute(array $params): array {
        $url = esc_url_raw(trim((string) ($params['url'] ?? '')));
        $title = sanitize_text_field((string) ($params['title'] ?? ''));
        $altText = sanitize_text_field((string) ($params['alt_text'] ?? ''));
        $attachToPost = (int) ($params['attach_to_post'] ?? 0);
        $setFeatured = (bool) ($params['set_featured'] ?? false);

        if ($url === '') {
            return ['success' => false, 'error' => 'URL is required.'];
        }

        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachmentId = media_sideload_image($url, $attachToPost, $title, 'id');

        if (is_wp_error($attachmentId)) {
            return ['success' => false, 'error' => $attachmentId->get_error_message()];
        }

        if ($altText !== '') {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);
        }

        if ($setFeatured && $attachToPost > 0) {
            set_post_thumbnail($attachToPost, $attachmentId);
        }

        return [
            'success' => true,
            'attachment_id' => $attachmentId,
            'url' => wp_get_attachment_url($attachmentId),
            'title' => get_the_title($attachmentId),
            'set_as_featured' => $setFeatured && $attachToPost > 0,
            'message' => 'Media uploaded successfully.',
        ];
    }
}
