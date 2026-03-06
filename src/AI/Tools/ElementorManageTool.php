<?php

namespace Levi\Agent\AI\Tools;

class ElementorManageTool implements ToolInterface {

    public function getName(): string {
        return 'manage_elementor';
    }

    public function getDescription(): string {
        return 'Manage Elementor system tasks: clear CSS cache after layout changes, import/export templates as JSON, or delete templates. Import and delete require admin permissions and confirmation.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => ['clear_css_cache', 'import_template', 'export_template', 'delete_template'],
                'required' => true,
            ],
            'template_id' => [
                'type' => 'integer',
                'description' => 'Template post ID (for export_template, delete_template)',
            ],
            'template_json' => [
                'type' => 'string',
                'description' => 'JSON string of template data to import (for import_template). Must be valid Elementor template JSON.',
            ],
            'template_title' => [
                'type' => 'string',
                'description' => 'Title for the imported template (for import_template)',
            ],
            'template_type' => [
                'type' => 'string',
                'description' => 'Template type (for import_template)',
                'enum' => ['page', 'section'],
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        if (!did_action('elementor/loaded')) {
            return ['success' => false, 'error' => 'Elementor is not active on this site.'];
        }

        $action = (string) ($params['action'] ?? '');

        return match ($action) {
            'clear_css_cache' => $this->clearCssCache(),
            'import_template' => $this->importTemplate($params),
            'export_template' => $this->exportTemplate($params),
            'delete_template' => $this->deleteTemplate($params),
            default => ['success' => false, 'error' => 'Invalid action. Use: clear_css_cache, import_template, export_template, delete_template'],
        };
    }

    private function clearCssCache(): array {
        if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->files_manager)) {
            return ['success' => false, 'error' => 'Elementor files manager not available.'];
        }

        \Elementor\Plugin::$instance->files_manager->clear_cache();

        return [
            'success' => true,
            'message' => 'Elementor CSS cache cleared. Pages will regenerate CSS on next load.',
        ];
    }

    private function importTemplate(array $params): array {
        if (!current_user_can('manage_options')) {
            return ['success' => false, 'error' => 'Admin permission required to import templates.'];
        }

        $title = sanitize_text_field((string) ($params['template_title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => 'template_title is required.'];
        }

        $jsonString = (string) ($params['template_json'] ?? '');
        $templateData = json_decode($jsonString, true);
        if (!is_array($templateData)) {
            return ['success' => false, 'error' => 'template_json must be a valid JSON array of Elementor elements.'];
        }

        $type = (string) ($params['template_type'] ?? 'section');
        if (!in_array($type, ['page', 'section'], true)) {
            $type = 'section';
        }

        $postId = wp_insert_post([
            'post_title' => $title,
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'post_content' => '',
        ], true);

        if (is_wp_error($postId)) {
            return ['success' => false, 'error' => $postId->get_error_message()];
        }

        update_post_meta($postId, '_elementor_data', wp_slash(wp_json_encode($templateData, JSON_UNESCAPED_UNICODE)));
        update_post_meta($postId, '_elementor_template_type', $type);
        update_post_meta($postId, '_elementor_edit_mode', 'builder');
        update_post_meta($postId, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0');

        return [
            'success' => true,
            'template_id' => $postId,
            'title' => $title,
            'type' => $type,
            'edit_url' => admin_url('post.php?post=' . $postId . '&action=elementor'),
            'message' => "Template '$title' imported successfully.",
        ];
    }

    private function exportTemplate(array $params): array {
        $templateId = (int) ($params['template_id'] ?? 0);
        if ($templateId <= 0) {
            return ['success' => false, 'error' => 'template_id is required.'];
        }

        $post = get_post($templateId);
        if (!$post || $post->post_type !== 'elementor_library') {
            return ['success' => false, 'error' => 'Template not found or not an Elementor template.'];
        }

        $data = get_post_meta($templateId, '_elementor_data', true);
        $type = get_post_meta($templateId, '_elementor_template_type', true);

        return [
            'success' => true,
            'template_id' => $templateId,
            'title' => $post->post_title,
            'type' => $type ?: 'unknown',
            'elementor_data' => $data ?: '[]',
            'message' => 'Template exported. The elementor_data field contains the full layout JSON.',
        ];
    }

    private function deleteTemplate(array $params): array {
        if (!current_user_can('manage_options')) {
            return ['success' => false, 'error' => 'Admin permission required to delete templates.'];
        }

        $templateId = (int) ($params['template_id'] ?? 0);
        if ($templateId <= 0) {
            return ['success' => false, 'error' => 'template_id is required.'];
        }

        $post = get_post($templateId);
        if (!$post || $post->post_type !== 'elementor_library') {
            return ['success' => false, 'error' => 'Template not found or not an Elementor template.'];
        }

        $title = $post->post_title;
        $result = wp_delete_post($templateId, true);

        if (!$result) {
            return ['success' => false, 'error' => 'Failed to delete template.'];
        }

        return [
            'success' => true,
            'template_id' => $templateId,
            'title' => $title,
            'message' => "Template '$title' deleted.",
        ];
    }
}
