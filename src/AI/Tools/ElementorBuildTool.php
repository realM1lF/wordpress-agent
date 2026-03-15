<?php

namespace Levi\Agent\AI\Tools;

class ElementorBuildTool implements ToolInterface {

    private const MAX_JSON_BYTES = 512000;

    private const ACTION_REQUIRED_PARAMS = [
        'create_page'       => ['title'],
        'update_section'    => ['post_id'],
        'add_widget'        => ['post_id', 'widget_type'],
        'remove_element'    => ['post_id', 'element_id'],
        'apply_template'    => ['post_id', 'template_id'],
        'duplicate_section' => ['post_id', 'section_index'],
    ];

    public function getName(): string {
        return 'elementor_build';
    }

    public function getDescription(): string {
        return 'Edit and extend Elementor page layouts: modify content, add/remove widgets, update settings, apply templates. '
            . 'Always read the page layout with get_elementor_data first to get element IDs. '
            . 'For add_widget: use parent_id (element ID from get_elementor_data) to target the exact container. '
            . 'Use real Elementor widgets (heading, text-editor, button, image, icon-box, etc.). '
            . 'Does not create new pages — use create_page first, then edit with this tool.';
    }

    public function getInputExamples(): array {
        return [
            ['action' => 'update_section', 'post_id' => 123, 'element_id' => 'abc123', 'settings' => '{"title":"Neuer Titel"}'],
            ['action' => 'add_widget', 'post_id' => 123, 'parent_id' => 'abc123', 'widget_type' => 'heading', 'settings' => '{"title":"Willkommen","header_size":"h2"}'],
            ['action' => 'add_widget', 'post_id' => 123, 'section_index' => 0, 'column_index' => 0, 'widget_type' => 'text-editor', 'settings' => '{"editor":"<p>Inhalt</p>"}'],
            ['action' => 'remove_element', 'post_id' => 123, 'element_id' => 'abc123'],
        ];
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => ['create_page', 'update_section', 'add_widget', 'remove_element', 'apply_template', 'duplicate_section'],
                'required' => true,
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Target page/post ID (required for all actions except create_page)',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Page title (for create_page)',
            ],
            'page_template' => [
                'type' => 'string',
                'description' => 'Elementor page template (for create_page)',
                'enum' => ['elementor_canvas', 'elementor_header_footer', 'default'],
            ],
            'sections' => [
                'type' => 'string',
                'description' => 'JSON string of sections array for create_page. Each section: {"settings": {...}, "columns": [{"widgets": [{"type": "heading", "settings": {"title": "..."}}]}]}. Use "type" for container elType or widget widgetType.',
            ],
            'section_index' => [
                'type' => 'integer',
                'description' => 'Zero-based section index in the page (for update_section, add_widget, duplicate_section)',
            ],
            'element_id' => [
                'type' => 'string',
                'description' => 'Elementor element ID (for remove_element, or as alternative to section_index for update_section)',
            ],
            'parent_id' => [
                'type' => 'string',
                'description' => 'Elementor element ID of the parent container to insert into (for add_widget). Preferred over section_index/column_index. Get IDs from get_elementor_data.',
            ],
            'column_index' => [
                'type' => 'integer',
                'description' => 'Zero-based column index within section (for add_widget, fallback when parent_id is not used)',
            ],
            'position' => [
                'type' => 'integer',
                'description' => 'Zero-based insertion position within parent (for add_widget, -1 for end)',
            ],
            'widget_type' => [
                'type' => 'string',
                'description' => 'Widget type name, e.g. "heading", "text-editor", "image", "button" (for add_widget)',
            ],
            'settings' => [
                'type' => 'string',
                'description' => 'JSON string of settings object (for add_widget, update_section)',
            ],
            'template_id' => [
                'type' => 'integer',
                'description' => 'Template post ID from elementor_library (for apply_template)',
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
        if (isset(self::ACTION_REQUIRED_PARAMS[$action])) {
            $missing = [];
            foreach (self::ACTION_REQUIRED_PARAMS[$action] as $param) {
                if (!isset($params[$param]) || (is_string($params[$param]) && trim($params[$param]) === '')) {
                    $missing[] = $param;
                }
            }
            if (!empty($missing)) {
                return [
                    'success' => false,
                    'error' => "Action '{$action}' requires: " . implode(', ', $missing) . '.',
                ];
            }
        }

        $action = (string) ($params['action'] ?? '');

        return match ($action) {
            'create_page' => $this->createPage($params),
            'update_section' => $this->updateSection($params),
            'add_widget' => $this->addWidget($params),
            'remove_element' => $this->removeElement($params),
            'apply_template' => $this->applyTemplate($params),
            'duplicate_section' => $this->duplicateSection($params),
            default => ['success' => false, 'error' => 'Invalid action. Use: create_page, update_section, add_widget, remove_element, apply_template, duplicate_section'],
        };
    }

    // ── create_page ─────────────────────────────────────────────────

    private function createPage(array $params): array {
        $title = sanitize_text_field((string) ($params['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => 'title is required.'];
        }

        $sectionsJson = (string) ($params['sections'] ?? '[]');
        $sections = json_decode($sectionsJson, true);
        if (!is_array($sections)) {
            return ['success' => false, 'error' => 'sections must be a valid JSON array.'];
        }

        $postId = wp_insert_post([
            'post_title' => $title,
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_content' => '',
        ], true);

        if (is_wp_error($postId)) {
            return ['success' => false, 'error' => $postId->get_error_message()];
        }

        $elementorData = $this->buildElementorJson($sections);

        $jsonString = wp_json_encode($elementorData, JSON_UNESCAPED_UNICODE);
        if (strlen($jsonString) > self::MAX_JSON_BYTES) {
            wp_delete_post($postId, true);
            return ['success' => false, 'error' => 'Layout data exceeds maximum size of 500 KB.'];
        }

        $pageTemplate = (string) ($params['page_template'] ?? 'elementor_header_footer');
        if (!in_array($pageTemplate, ['elementor_canvas', 'elementor_header_footer', 'default'], true)) {
            $pageTemplate = 'elementor_header_footer';
        }

        update_post_meta($postId, '_elementor_data', wp_slash($jsonString));
        update_post_meta($postId, '_elementor_edit_mode', 'builder');
        update_post_meta($postId, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0');
        if ($pageTemplate !== 'default') {
            update_post_meta($postId, '_wp_page_template', $pageTemplate);
        }

        $this->clearElementorCache($postId);

        $verified = get_post_meta($postId, '_elementor_data', true);
        $verifiedData = json_decode($verified, true);
        $elementCount = is_array($verifiedData) ? $this->countElements($verifiedData) : 0;

        return [
            'success' => true,
            'post_id' => $postId,
            'title' => $title,
            'status' => 'draft',
            'page_template' => $pageTemplate,
            'element_count' => $elementCount,
            'edit_url' => admin_url('post.php?post=' . $postId . '&action=elementor'),
            'preview_url' => get_preview_post_link($postId),
            'message' => 'Elementor page created as draft.',
        ];
    }

    // ── update_section ──────────────────────────────────────────────

    private function updateSection(array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required.'];
        }

        $data = $this->loadElementorData($postId);
        if ($data === null) {
            return ['success' => false, 'error' => 'Could not load Elementor data for this page.'];
        }

        $settingsJson = (string) ($params['settings'] ?? '{}');
        $newSettings = json_decode($settingsJson, true);
        if (!is_array($newSettings)) {
            return ['success' => false, 'error' => 'settings must be a valid JSON object.'];
        }

        $newSettings = $this->sanitizeSettings($newSettings);

        $elementId = (string) ($params['element_id'] ?? '');
        $sectionIndex = isset($params['section_index']) ? (int) $params['section_index'] : -1;

        if ($elementId !== '') {
            $found = &$this->findElementByIdRef($data, $elementId);
            if ($found === null) {
                return ['success' => false, 'error' => "Element with ID '$elementId' not found."];
            }
            $found['settings'] = array_merge($found['settings'] ?? [], $newSettings);
        } elseif ($sectionIndex >= 0) {
            if (!isset($data[$sectionIndex])) {
                return ['success' => false, 'error' => "Section index $sectionIndex is out of range (page has " . count($data) . ' sections).'];
            }
            $data[$sectionIndex]['settings'] = array_merge($data[$sectionIndex]['settings'] ?? [], $newSettings);
        } else {
            return ['success' => false, 'error' => 'Provide either element_id or section_index.'];
        }

        return $this->saveElementorData($postId, $data, 'Section updated.');
    }

    // ── add_widget ──────────────────────────────────────────────────

    private function addWidget(array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required.'];
        }

        $widgetType = (string) ($params['widget_type'] ?? '');
        if ($widgetType === '') {
            return ['success' => false, 'error' => 'widget_type is required.'];
        }

        $data = $this->loadElementorData($postId);
        if ($data === null) {
            return ['success' => false, 'error' => 'Could not load Elementor data for this page.'];
        }

        $settingsJson = (string) ($params['settings'] ?? '{}');
        $settings = json_decode($settingsJson, true);
        if (!is_array($settings)) {
            $settings = [];
        }

        $widget = [
            'id' => $this->generateElementId(),
            'elType' => 'widget',
            'widgetType' => $widgetType,
            'settings' => $this->sanitizeSettings($settings),
            'elements' => [],
        ];

        $position = (int) ($params['position'] ?? -1);
        $parentId = (string) ($params['parent_id'] ?? '');

        if ($parentId !== '') {
            $parent = &$this->findElementByIdRef($data, $parentId);
            if ($parent === null) {
                return ['success' => false, 'error' => "Parent element with ID '$parentId' not found."];
            }

            if (!isset($parent['elements']) || !is_array($parent['elements'])) {
                $parent['elements'] = [];
            }

            if ($position < 0 || $position >= count($parent['elements'])) {
                $parent['elements'][] = $widget;
            } else {
                array_splice($parent['elements'], $position, 0, [$widget]);
            }

            return $this->saveElementorData($postId, $data, "Widget '$widgetType' added to element '$parentId'.");
        }

        $sectionIndex = (int) ($params['section_index'] ?? 0);
        $columnIndex = (int) ($params['column_index'] ?? 0);

        if (!isset($data[$sectionIndex])) {
            return ['success' => false, 'error' => "Section index $sectionIndex is out of range (page has " . count($data) . ' top-level elements).'];
        }

        $section = &$data[$sectionIndex];
        $children = &$section['elements'];

        if (!is_array($children) || empty($children)) {
            $children = [['id' => $this->generateElementId(), 'elType' => 'container', 'settings' => [], 'elements' => []]];
        }

        if (!isset($children[$columnIndex])) {
            return ['success' => false, 'error' => "Column index $columnIndex is out of range (section has " . count($children) . ' columns).'];
        }

        $colElements = &$children[$columnIndex]['elements'];
        if (!is_array($colElements)) {
            $colElements = [];
        }

        if ($position < 0 || $position >= count($colElements)) {
            $colElements[] = $widget;
        } else {
            array_splice($colElements, $position, 0, [$widget]);
        }

        return $this->saveElementorData($postId, $data, "Widget '$widgetType' added.");
    }

    // ── remove_element ──────────────────────────────────────────────

    private function removeElement(array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required.'];
        }

        $elementId = (string) ($params['element_id'] ?? '');
        if ($elementId === '') {
            return ['success' => false, 'error' => 'element_id is required.'];
        }

        $data = $this->loadElementorData($postId);
        if ($data === null) {
            return ['success' => false, 'error' => 'Could not load Elementor data for this page.'];
        }

        $removed = $this->removeElementById($data, $elementId);
        if (!$removed) {
            return ['success' => false, 'error' => "Element with ID '$elementId' not found."];
        }

        return $this->saveElementorData($postId, $data, "Element '$elementId' removed.");
    }

    // ── apply_template ──────────────────────────────────────────────

    private function applyTemplate(array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required.'];
        }

        $templateId = (int) ($params['template_id'] ?? 0);
        if ($templateId <= 0) {
            return ['success' => false, 'error' => 'template_id is required.'];
        }

        $post = get_post($postId);
        if (!$post) {
            return ['success' => false, 'error' => 'Target page not found.'];
        }

        $template = get_post($templateId);
        if (!$template || $template->post_type !== 'elementor_library') {
            return ['success' => false, 'error' => 'Template not found or not an Elementor template.'];
        }

        $templateData = get_post_meta($templateId, '_elementor_data', true);
        if (empty($templateData)) {
            return ['success' => false, 'error' => 'Template has no Elementor layout data.'];
        }

        $decoded = is_string($templateData) ? json_decode($templateData, true) : $templateData;
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'Could not parse template layout data.'];
        }

        $remapped = $this->remapElementIds($decoded);

        update_post_meta($postId, '_elementor_data', wp_slash(wp_json_encode($remapped, JSON_UNESCAPED_UNICODE)));
        update_post_meta($postId, '_elementor_edit_mode', 'builder');

        $this->clearElementorCache($postId);

        return [
            'success' => true,
            'post_id' => $postId,
            'template_id' => $templateId,
            'template_title' => $template->post_title,
            'element_count' => $this->countElements($remapped),
            'edit_url' => admin_url('post.php?post=' . $postId . '&action=elementor'),
            'message' => "Template '{$template->post_title}' applied to page.",
        ];
    }

    // ── duplicate_section ───────────────────────────────────────────

    private function duplicateSection(array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required.'];
        }

        $sectionIndex = isset($params['section_index']) ? (int) $params['section_index'] : -1;
        if ($sectionIndex < 0) {
            return ['success' => false, 'error' => 'section_index is required.'];
        }

        $data = $this->loadElementorData($postId);
        if ($data === null) {
            return ['success' => false, 'error' => 'Could not load Elementor data for this page.'];
        }

        if (!isset($data[$sectionIndex])) {
            return ['success' => false, 'error' => "Section index $sectionIndex is out of range."];
        }

        $clone = json_decode(wp_json_encode($data[$sectionIndex]), true);
        $clone = $this->remapElementIds([$clone])[0];

        array_splice($data, $sectionIndex + 1, 0, [$clone]);

        return $this->saveElementorData($postId, $data, 'Section duplicated at index ' . ($sectionIndex + 1) . '.');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function buildElementorJson(array $sections): array {
        $result = [];

        foreach ($sections as $section) {
            $container = [
                'id' => $this->generateElementId(),
                'elType' => 'container',
                'settings' => $this->sanitizeSettings($section['settings'] ?? []),
                'elements' => [],
            ];

            $columns = $section['columns'] ?? [['widgets' => []]];
            if (empty($columns)) {
                $columns = [['widgets' => []]];
            }

            if (count($columns) === 1) {
                $widgets = $columns[0]['widgets'] ?? [];
                foreach ($widgets as $w) {
                    $container['elements'][] = $this->buildWidget($w);
                }
            } else {
                foreach ($columns as $col) {
                    $innerContainer = [
                        'id' => $this->generateElementId(),
                        'elType' => 'container',
                        'settings' => $this->sanitizeSettings($col['settings'] ?? []),
                        'elements' => [],
                    ];

                    $widgets = $col['widgets'] ?? [];
                    foreach ($widgets as $w) {
                        $innerContainer['elements'][] = $this->buildWidget($w);
                    }

                    $container['elements'][] = $innerContainer;
                }
            }

            $result[] = $container;
        }

        return $result;
    }

    private function buildWidget(array $w): array {
        return [
            'id' => $this->generateElementId(),
            'elType' => 'widget',
            'widgetType' => $w['type'] ?? 'text-editor',
            'settings' => $this->sanitizeSettings($w['settings'] ?? []),
            'elements' => [],
        ];
    }

    private function generateElementId(): string {
        if (class_exists('\Elementor\Utils') && method_exists('\Elementor\Utils', 'generate_random_string')) {
            return \Elementor\Utils::generate_random_string(7);
        }
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }

    private function loadElementorData(int $postId): ?array {
        $post = get_post($postId);
        if (!$post) {
            return null;
        }
        if (!current_user_can('edit_post', $postId)) {
            return null;
        }

        $raw = get_post_meta($postId, '_elementor_data', true);
        if (empty($raw)) {
            return [];
        }

        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($data) ? $data : null;
    }

    private function saveElementorData(int $postId, array $data, string $message): array {
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);

        if (strlen($json) > self::MAX_JSON_BYTES) {
            return ['success' => false, 'error' => 'Layout data exceeds maximum size of 500 KB.'];
        }

        update_post_meta($postId, '_elementor_data', wp_slash($json));
        $this->clearElementorCache($postId);

        $verified = get_post_meta($postId, '_elementor_data', true);
        $verifiedData = json_decode($verified, true);
        $elementCount = is_array($verifiedData) ? $this->countElements($verifiedData) : 0;

        return [
            'success' => true,
            'post_id' => $postId,
            'element_count' => $elementCount,
            'edit_url' => admin_url('post.php?post=' . $postId . '&action=elementor'),
            'message' => $message,
        ];
    }

    private function &findElementByIdRef(array &$elements, string $id): ?array {
        $null = null;
        foreach ($elements as &$el) {
            if (($el['id'] ?? '') === $id) {
                return $el;
            }
            if (!empty($el['elements'])) {
                $found = &$this->findElementByIdRef($el['elements'], $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return $null;
    }

    private function removeElementById(array &$elements, string $id): bool {
        foreach ($elements as $i => &$el) {
            if (($el['id'] ?? '') === $id) {
                array_splice($elements, $i, 1);
                return true;
            }
            if (!empty($el['elements']) && $this->removeElementById($el['elements'], $id)) {
                return true;
            }
        }
        return false;
    }

    private function remapElementIds(array $elements): array {
        foreach ($elements as &$el) {
            $el['id'] = $this->generateElementId();
            if (!empty($el['elements'])) {
                $el['elements'] = $this->remapElementIds($el['elements']);
            }
        }
        return $elements;
    }

    private function countElements(array $elements): int {
        $count = count($elements);
        foreach ($elements as $el) {
            if (!empty($el['elements'])) {
                $count += $this->countElements($el['elements']);
            }
        }
        return $count;
    }

    private function sanitizeSettings(array $settings): array {
        $stringKeys = [
            'title', 'editor', 'text', 'heading_tag', 'header_size', 'size',
            'align', 'text_align', 'content_position',
            'background_color', 'background_background', 'background_overlay_background',
            'button_text_color', 'button_background_color', 'typography_typography',
            'typography_font_family', 'typography_font_weight',
            'color', 'html_tag', 'link_type', 'button_type',
            'content_width', 'flex_direction', 'flex_wrap',
            'justify_content', 'align_items', 'align_content',
            'overflow', 'height', 'object_fit',
            'icon', 'selected_icon', 'url', 'href',
        ];

        $dimensionKeys = [
            'padding', '_padding', 'margin', '_margin',
            'border_radius', '_border_radius',
            'border_width', '_border_width',
        ];

        $sizeKeys = [
            'custom_height', 'custom_width', 'width',
            'min_height', 'max_width', 'max_height',
            'gap', 'flex_gap',
            'typography_font_size', 'typography_line_height', 'typography_letter_spacing',
            'image_spacing', 'icon_size',
        ];

        foreach ($settings as $key => &$value) {
            if (in_array($key, $stringKeys, true) && is_array($value)) {
                $value = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }

            if (in_array($key, $dimensionKeys, true) && !is_array($value)) {
                $numVal = (string) $value;
                $value = [
                    'unit' => 'px',
                    'top' => $numVal, 'right' => $numVal,
                    'bottom' => $numVal, 'left' => $numVal,
                    'isLinked' => true,
                ];
            }

            if (in_array($key, $sizeKeys, true) && !is_array($value)) {
                $value = ['unit' => 'px', 'size' => (int) $value, 'sizes' => []];
            }
        }

        if (isset($settings['background_color']) && !isset($settings['background_background'])) {
            $settings['background_background'] = 'classic';
        }

        if (
            (isset($settings['typography_font_family']) || isset($settings['typography_font_size']) || isset($settings['typography_font_weight']))
            && !isset($settings['typography_typography'])
        ) {
            $settings['typography_typography'] = 'custom';
        }

        return $settings;
    }

    private function clearElementorCache(?int $postId = null): void {
        if (!class_exists('\Elementor\Plugin')) {
            return;
        }

        if ($postId !== null && class_exists('\Elementor\Core\Files\CSS\Post')) {
            $cssFile = \Elementor\Core\Files\CSS\Post::create($postId);
            $cssFile->update();
        }

        if (isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    }
}
