<?php

namespace Levi\Agent\AI\Tools;

class ElementorReadTool implements ToolInterface {

    public function getName(): string {
        return 'get_elementor_data';
    }

    public function getDescription(): string {
        return 'Read and understand Elementor page layouts, templates, available widgets, and global settings. Use "get_page_layout" to inspect a page\'s full Elementor structure (sections, containers, widgets and their settings) – ALWAYS call this before editing a page. Use "get_templates" to list saved templates, "get_widgets" to see available widget types, "get_global_settings" for global colors/fonts from the active kit.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => ['get_page_layout', 'get_templates', 'get_widgets', 'get_global_settings'],
                'required' => true,
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post/page ID (required for get_page_layout)',
            ],
            'template_type' => [
                'type' => 'string',
                'description' => 'Filter templates by type (optional for get_templates)',
                'enum' => ['page', 'section', 'header', 'footer', 'single', 'archive', 'popup'],
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
            'get_page_layout' => $this->getPageLayout($params),
            'get_templates' => $this->getTemplates($params),
            'get_widgets' => $this->getWidgets(),
            'get_global_settings' => $this->getGlobalSettings(),
            default => ['success' => false, 'error' => 'Invalid action. Use: get_page_layout, get_templates, get_widgets, get_global_settings'],
        };
    }

    private function getPageLayout(array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required.'];
        }

        $post = get_post($postId);
        if (!$post) {
            return ['success' => false, 'error' => 'Post not found.'];
        }

        $editMode = get_post_meta($postId, '_elementor_edit_mode', true);
        if ($editMode !== 'builder') {
            return [
                'success' => true,
                'post_id' => $postId,
                'elementor_active' => false,
                'message' => 'This page is not edited with Elementor.',
            ];
        }

        $raw = get_post_meta($postId, '_elementor_data', true);
        if (empty($raw)) {
            return [
                'success' => true,
                'post_id' => $postId,
                'elementor_active' => true,
                'elements' => [],
                'message' => 'Page has Elementor enabled but no layout data.',
            ];
        }

        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Could not parse Elementor layout data.'];
        }

        $summary = $this->summarizeElements($data);
        $pageTemplate = get_post_meta($postId, '_wp_page_template', true);

        return [
            'success' => true,
            'post_id' => $postId,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'page_template' => $pageTemplate ?: 'default',
            'elementor_active' => true,
            'total_elements' => $summary['total'],
            'structure' => $summary['structure'],
            'edit_url' => admin_url('post.php?post=' . $postId . '&action=elementor'),
        ];
    }

    private function summarizeElements(array $elements, int $depth = 0): array {
        $structure = [];
        $total = 0;

        foreach ($elements as $el) {
            $total++;
            $item = [
                'id' => $el['id'] ?? '?',
                'type' => $el['elType'] ?? 'unknown',
            ];

            if (isset($el['widgetType'])) {
                $item['widget'] = $el['widgetType'];
            }

            $settings = $el['settings'] ?? [];
            $relevantSettings = $this->extractRelevantSettings($settings, $el['widgetType'] ?? null);
            if (!empty($relevantSettings)) {
                $item['settings'] = $relevantSettings;
            }

            if (!empty($el['elements']) && $depth < 4) {
                $childSummary = $this->summarizeElements($el['elements'], $depth + 1);
                $item['children'] = $childSummary['structure'];
                $total += $childSummary['total'];
            } elseif (!empty($el['elements'])) {
                $item['children_count'] = count($el['elements']);
                $total += count($el['elements']);
            }

            $structure[] = $item;
        }

        return ['structure' => $structure, 'total' => $total];
    }

    private function extractRelevantSettings(?array $settings, ?string $widgetType): array {
        if (empty($settings)) {
            return [];
        }

        $relevant = [];
        $interestingKeys = [
            'title', 'editor', 'heading_tag', 'header_size', 'text_align',
            'image', 'link', 'url', 'button_text', 'icon',
            'background_color', 'background_background', 'content_width',
            'columns', 'gap', 'min_height', 'padding', 'margin',
        ];

        foreach ($interestingKeys as $key) {
            if (isset($settings[$key]) && $settings[$key] !== '' && $settings[$key] !== []) {
                $val = $settings[$key];
                if (is_string($val) && mb_strlen($val) > 200) {
                    $val = mb_substr($val, 0, 200) . '...';
                }
                $relevant[$key] = $val;
            }
        }

        return $relevant;
    }

    private function getTemplates(array $params): array {
        $args = [
            'post_type' => 'elementor_library',
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        $templateType = (string) ($params['template_type'] ?? '');
        if ($templateType !== '') {
            $args['meta_query'] = [
                [
                    'key' => '_elementor_template_type',
                    'value' => $templateType,
                ],
            ];
        }

        $query = new \WP_Query($args);
        $templates = [];

        foreach ($query->posts as $post) {
            $templates[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => get_post_meta($post->ID, '_elementor_template_type', true) ?: 'unknown',
                'date' => $post->post_date,
                'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=elementor'),
            ];
        }

        return [
            'success' => true,
            'total' => count($templates),
            'filter' => $templateType ?: null,
            'templates' => $templates,
        ];
    }

    private function getWidgets(): array {
        if (!class_exists('\Elementor\Plugin')) {
            return ['success' => false, 'error' => 'Elementor Plugin class not available.'];
        }

        $widgetTypes = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
        $widgets = [];

        foreach ($widgetTypes as $widget) {
            $widgets[] = [
                'name' => $widget->get_name(),
                'title' => $widget->get_title(),
                'icon' => $widget->get_icon(),
                'categories' => $widget->get_categories(),
            ];
        }

        usort($widgets, fn($a, $b) => strcmp($a['title'], $b['title']));

        return [
            'success' => true,
            'total' => count($widgets),
            'widgets' => $widgets,
        ];
    }

    private function getGlobalSettings(): array {
        if (!class_exists('\Elementor\Plugin')) {
            return ['success' => false, 'error' => 'Elementor Plugin class not available.'];
        }

        $kitId = \Elementor\Plugin::$instance->kits_manager->get_active_id();
        if (!$kitId) {
            return ['success' => false, 'error' => 'No active Elementor kit found.'];
        }

        $kit = \Elementor\Plugin::$instance->documents->get($kitId);
        if (!$kit) {
            return ['success' => false, 'error' => 'Could not load active kit.'];
        }

        $settings = $kit->get_settings();

        $colors = [];
        if (!empty($settings['system_colors'])) {
            foreach ($settings['system_colors'] as $color) {
                $colors[] = [
                    'id' => $color['_id'] ?? '',
                    'title' => $color['title'] ?? '',
                    'color' => $color['color'] ?? '',
                ];
            }
        }

        $fonts = [];
        if (!empty($settings['system_typography'])) {
            foreach ($settings['system_typography'] as $font) {
                $fonts[] = [
                    'id' => $font['_id'] ?? '',
                    'title' => $font['title'] ?? '',
                    'family' => $font['typography_font_family'] ?? '',
                    'weight' => $font['typography_font_weight'] ?? '',
                    'size' => $font['typography_font_size'] ?? '',
                ];
            }
        }

        return [
            'success' => true,
            'kit_id' => $kitId,
            'global_colors' => $colors,
            'global_fonts' => $fonts,
            'container_width' => $settings['container_width']['size'] ?? null,
        ];
    }
}
