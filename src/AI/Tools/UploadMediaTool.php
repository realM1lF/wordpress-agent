<?php

namespace Levi\Agent\AI\Tools;

use WP_Error;

class UploadMediaTool implements ToolInterface {

    public function getName(): string {
        return 'upload_media';
    }

    public function getDescription(): string {
        return 'Upload media (images) to the WordPress media library from a URL. Downloads the image and creates a proper WordPress attachment. Useful for setting featured images, creating product images, or adding media to posts. Supports common image hosts like Unsplash, Pexels, etc.';
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

        // First, verify the URL is accessible
        $headCheck = wp_remote_head($url, [
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'sslverify' => false, // For development environments
        ]);

        if (is_wp_error($headCheck)) {
            return [
                'success' => false, 
                'error' => 'URL not accessible: ' . $headCheck->get_error_message(),
                'suggestion' => 'The image can still be used as external hotlink in post content.'
            ];
        }

        $contentType = wp_remote_retrieve_header($headCheck, 'content-type');
        if (!str_contains($contentType, 'image/')) {
            // Try to download anyway - some servers don't send correct headers
            error_log("UploadMediaTool: Content-Type mismatch for $url: $contentType");
        }

        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Add filter to allow external URLs without strict checks
        add_filter('http_request_args', [$this, 'allowExternalImageDownload'], 10, 2);
        
        $attachmentId = media_sideload_image($url, $attachToPost, $title, 'id');
        
        remove_filter('http_request_args', [$this, 'allowExternalImageDownload'], 10);

        if (is_wp_error($attachmentId)) {
            $errorMsg = $attachmentId->get_error_message();
            
            // Provide helpful fallback suggestion
            return [
                'success' => false, 
                'error' => $errorMsg,
                'suggestion' => 'The image URL works for hotlinking (embedding directly in content) but cannot be imported to the media library. You can still use the image in posts by including it as an external image.',
                'external_url' => $url,
                'can_hotlink' => true
            ];
        }

        if ($altText !== '') {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);
        }

        if ($setFeatured && $attachToPost > 0) {
            if (get_post($attachToPost)) {
                set_post_thumbnail($attachToPost, $attachmentId);
            }
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

    /**
     * Modify HTTP request args to allow downloading external images
     */
    public function allowExternalImageDownload(array $args, string $url): array {
        $args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $args['sslverify'] = false; // Allow self-signed certs in dev
        $args['timeout'] = 30; // Longer timeout for large images
        
        // Follow redirects
        $args['redirection'] = 5;
        
        return $args;
    }
}
