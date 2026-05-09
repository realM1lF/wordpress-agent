<?php

namespace Levi\Agent\AI\Tools;

/**
 * Update metadata of existing media items (images, attachments).
 * Use get_media to find attachment IDs, then this tool to edit alt text, title, caption, description.
 */
class UpdateMediaTool implements ToolInterface {

    public function getName(): string {
        return 'update_media';
    }

    public function getDescription(): string {
        return 'Update metadata of existing media items in the WordPress Media Library. '
            . 'Use get_media to find attachment IDs, then this tool to edit alt text, title, caption, and description. '
            . 'Only the fields you provide are changed — all other fields remain untouched. '
            . 'Essential for SEO: alt text describes images for screen readers and search engines.';
    }

    public function getParameters(): array {
        return [
            'attachment_id' => [
                'type' => 'integer',
                'description' => 'The attachment ID from get_media',
                'required' => true,
            ],
            'alt_text' => [
                'type' => 'string',
                'description' => 'Alt text for the image (SEO, accessibility). Empty string to clear.',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Attachment title (displayed on hover, used in media library)',
            ],
            'caption' => [
                'type' => 'string',
                'description' => 'Caption shown below the image when inserted in content',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Description (stored in attachment, rarely displayed)',
            ],
        ];
    }

    public function getInputExamples(): array {
        return [
            ['attachment_id' => 42, 'alt_text' => 'Produktfoto blaues T-Shirt'],
            ['attachment_id' => 99, 'title' => 'Teamfoto 2024', 'caption' => 'Unser Team beim Sommerfest'],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('upload_files');
    }

    public function execute(array $params): array {
        $attachmentId = (int) ($params['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            return ['success' => false, 'error' => 'Valid attachment_id is required.'];
        }

        $attachment = get_post($attachmentId);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return [
                'success' => false,
                'error' => 'Attachment not found.',
                'suggestion' => 'Use get_media to list media and verify the attachment ID.',
            ];
        }

        if (!current_user_can('edit_post', $attachmentId)) {
            return ['success' => false, 'error' => 'Permission denied to edit this media item.'];
        }

        $updated = [];
        $postData = ['ID' => $attachmentId];

        if (array_key_exists('title', $params)) {
            $postData['post_title'] = sanitize_text_field((string) $params['title']);
            $updated['title'] = $postData['post_title'];
        }

        if (array_key_exists('caption', $params)) {
            $postData['post_excerpt'] = sanitize_textarea_field((string) $params['caption']);
            $updated['caption'] = $postData['post_excerpt'];
        }

        if (array_key_exists('description', $params)) {
            $postData['post_content'] = sanitize_textarea_field((string) $params['description']);
            $updated['description'] = $postData['post_content'];
        }

        if (count($postData) > 1) {
            $result = wp_update_post($postData, true);
            if (is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }
        }

        if (array_key_exists('alt_text', $params)) {
            $altText = sanitize_text_field((string) $params['alt_text']);
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);
            $updated['alt_text'] = $altText;
        }

        if (empty($updated)) {
            return [
                'success' => false,
                'error' => 'No fields to update. Provide at least one of: alt_text, title, caption, description.',
            ];
        }

        clean_post_cache($attachmentId);

        $verify = [];
        if (array_key_exists('title', $params)) {
            $verify[] = ['type' => 'post_field', 'post_id' => $attachmentId, 'field' => 'post_title', 'expected' => $postData['post_title'] ?? sanitize_text_field((string) $params['title'])];
        }
        if (array_key_exists('caption', $params)) {
            $verify[] = ['type' => 'post_field', 'post_id' => $attachmentId, 'field' => 'post_excerpt', 'expected' => $postData['post_excerpt'] ?? sanitize_textarea_field((string) $params['caption'])];
        }
        if (array_key_exists('description', $params)) {
            $verify[] = ['type' => 'post_field', 'post_id' => $attachmentId, 'field' => 'post_content', 'expected' => $postData['post_content'] ?? sanitize_textarea_field((string) $params['description'])];
        }
        if (array_key_exists('alt_text', $params)) {
            $verify[] = ['type' => 'post_meta', 'post_id' => $attachmentId, 'meta_key' => '_wp_attachment_image_alt', 'expected' => sanitize_text_field((string) $params['alt_text'])];
        }

        $result = [
            'success' => true,
            'attachment_id' => $attachmentId,
            'updated' => $updated,
            'message' => 'Media metadata updated.',
        ];

        if (!empty($verify)) {
            $result['_verify'] = $verify;
        }

        return $result;
    }
}
