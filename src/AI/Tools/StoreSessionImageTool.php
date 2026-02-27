<?php

namespace Levi\Agent\AI\Tools;

class StoreSessionImageTool implements ToolInterface {

    public function getName(): string {
        return 'store_session_image';
    }

    public function getDescription(): string {
        return 'Save a session-uploaded image to the WordPress media library. Use only when the user wants to use the image on the site (e.g. featured image, product image) or explicitly asks to save it. The session_file_id is provided in the system prompt under "Session File Context".';
    }

    public function getParameters(): array {
        return [
            'session_file_id' => [
                'type' => 'string',
                'description' => 'ID of the uploaded session file (e.g. f_abc123)',
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
        $fileId = trim((string) ($params['session_file_id'] ?? ''));
        $title = sanitize_text_field((string) ($params['title'] ?? ''));
        $altText = sanitize_text_field((string) ($params['alt_text'] ?? ''));
        $attachToPost = (int) ($params['attach_to_post'] ?? 0);
        $setFeatured = (bool) ($params['set_featured'] ?? false);

        if ($fileId === '') {
            return ['success' => false, 'error' => 'session_file_id is required.'];
        }

        $userId = get_current_user_id();
        $imageData = $this->findSessionImage($fileId, $userId);
        if ($imageData === null) {
            return ['success' => false, 'error' => 'Session image not found. Make sure the file was uploaded in this session.'];
        }

        $base64 = $imageData['image_base64'] ?? '';
        $name = $imageData['name'] ?? 'image.jpg';

        if (!preg_match('#^data:image/([a-z]+);base64,(.+)$#i', $base64, $m)) {
            return ['success' => false, 'error' => 'Invalid image data.'];
        }

        $raw = base64_decode($m[2]);
        if ($raw === false || $raw === '') {
            return ['success' => false, 'error' => 'Could not decode image data.'];
        }

        $uploadDir = wp_upload_dir();
        if (!empty($uploadDir['error'])) {
            return ['success' => false, 'error' => 'Upload directory not writable: ' . $uploadDir['error']];
        }

        $safeName = sanitize_file_name($name);
        $tmpPath = $uploadDir['path'] . '/' . wp_unique_filename($uploadDir['path'], $safeName);

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $wp_filesystem->put_contents($tmpPath, $raw, FS_CHMOD_FILE);
        if (!file_exists($tmpPath)) {
            return ['success' => false, 'error' => 'Could not write temporary file.'];
        }

        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $mime = $mimeMap[$ext] ?? 'image/jpeg';

        $attachment = [
            'post_title' => $title !== '' ? $title : pathinfo($safeName, PATHINFO_FILENAME),
            'post_mime_type' => $mime,
            'post_status' => 'inherit',
        ];

        $attachmentId = wp_insert_attachment($attachment, $tmpPath, $attachToPost);
        if (is_wp_error($attachmentId)) {
            @unlink($tmpPath);
            return ['success' => false, 'error' => $attachmentId->get_error_message()];
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $tmpPath);
        wp_update_attachment_metadata($attachmentId, $metadata);

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
            'message' => 'Bild in Mediathek gespeichert.',
        ];
    }

    private function findSessionImage(string $fileId, int $userId): ?array {
        $sessions = $this->getRecentSessionIds($userId);
        foreach ($sessions as $sid) {
            $key = 'levi_files_' . md5($sid . '|' . $userId);
            $files = get_transient($key);
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                if (is_array($file) && ($file['id'] ?? '') === $fileId && !empty($file['image_base64'])) {
                    return $file;
                }
            }
        }
        return null;
    }

    private function getRecentSessionIds(int $userId): array {
        global $wpdb;
        $table = $wpdb->prefix . 'levi_conversations';
        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT session_id FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 5",
            $userId
        ));
        return is_array($results) ? $results : [];
    }
}
