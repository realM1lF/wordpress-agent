<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Generic Elementor management tool — templates, widgets, settings.
 */
class ManageElementorTool extends AbstractTool
{
    public function getName(): string
    {
        return "manage_elementor";
    }

    public function getDescription(): string
    {
        return "Verwaltet Elementor-Templates, Widgets und Seiten. " .
            "`entity=template|page|widget|setting`. " .
            "Template: `action=list|get|create|update|delete`, `type` (page/section/kit). " .
            "Seite: `action=edit_with_elementor`, `page_id`, `elements` (JSON-Struktur). " .
            "Widget: `action=get_registered` listet verfügbare Widgets. " .
            "Einstellung: `action=get|update`, `key` (elementor-Option).";
    }

    public function getParameters(): array
    {
        return [
            "entity" => [
                "type" => "string",
                "enum" => ["template", "page", "widget", "setting"],
                "description" => "Elementor-Entität",
                "required" => true,
            ],
            "action" => [
                "type" => "string",
                "description" => "Aktion",
                "required" => true,
            ],
            "id" => [
                "type" => "integer",
                "description" => "ID (für get, update, delete)",
            ],
            "type" => [
                "type" => "string",
                "enum" => ["page", "section", "container", "kit"],
                "description" => "Template-Typ",
            ],
            "title" => [
                "type" => "string",
                "description" => "Titel (für Template-Erstellung)",
            ],
            "content" => [
                "type" => "string",
                "description" => "Elementor-JSON-Inhalt (für Template/Seite)",
            ],
            "page_id" => [
                "type" => "integer",
                "description" => "Seiten-ID (für page-Aktionen)",
            ],
            "elements" => [
                "type" => "object",
                "description" => "Elementor-Elemente als JSON-Struktur",
            ],
            "key" => [
                "type" => "string",
                "description" => "Einstellungs-Key",
            ],
            "value" => [
                "type" => "string",
                "description" => "Einstellungs-Wert",
            ],
            "limit" => [
                "type" => "integer",
                "default" => 20,
                "description" => "Maximale Ergebnisse",
            ],
            "offset" => [
                "type" => "integer",
                "default" => 0,
                "description" => "Offset",
            ],
        ];
    }

    public function execute(array $params): array
    {
        if (!did_action("elementor/loaded")) {
            return ["success" => false, "error" => "Elementor ist nicht aktiv"];
        }

        $entity = (string) ($params["entity"] ?? "");
        $action = (string) ($params["action"] ?? "");

        return match ($entity) {
            "template" => $this->handleTemplate($action, $params),
            "page" => $this->handlePage($action, $params),
            "widget" => $this->handleWidget($action, $params),
            "setting" => $this->handleSetting($action, $params),
            default => [
                "success" => false,
                "error" => "Unbekannte Entität: {$entity}",
            ],
        };
    }

    public function checkPermission(): bool
    {
        return current_user_can("edit_pages") || current_user_can("edit_posts");
    }

    private function handleTemplate(string $action, array $params): array
    {
        $source = \Elementor\TemplateLibrary\Source_Local::CPT;

        switch ($action) {
            case "list":
                $templates = get_posts([
                    "post_type" => $source,
                    "posts_per_page" => min(
                        100,
                        (int) ($params["limit"] ?? 20),
                    ),
                    "offset" => (int) ($params["offset"] ?? 0),
                    "post_status" => "publish",
                ]);
                return [
                    "success" => true,
                    "items" => array_map(
                        fn($t) => [
                            "id" => $t->ID,
                            "title" => $t->post_title,
                            "type" => get_post_meta(
                                $t->ID,
                                \Elementor\TemplateLibrary\Source_Local::TYPE_META_KEY,
                                true,
                            ),
                            "date" => $t->post_date,
                        ],
                        $templates,
                    ),
                ];

            case "get":
                if (empty($params["id"])) {
                    return ["success" => false, "error" => "Erforderlich: id"];
                }
                $template = get_post((int) $params["id"]);
                if (!$template || $template->post_type !== $source) {
                    return [
                        "success" => false,
                        "error" => "Template nicht gefunden",
                    ];
                }
                $meta = get_post_meta($template->ID);
                return [
                    "success" => true,
                    "data" => [
                        "id" => $template->ID,
                        "title" => $template->post_title,
                        "content" => $template->post_content,
                        "type" => get_post_meta(
                            $template->ID,
                            \Elementor\TemplateLibrary\Source_Local::TYPE_META_KEY,
                            true,
                        ),
                        "meta" => $meta,
                    ],
                ];

            case "create":
                $postData = [
                    "post_type" => $source,
                    "post_title" => sanitize_text_field(
                        (string) ($params["title"] ?? "New Template"),
                    ),
                    "post_status" => "publish",
                    "post_content" => (string) ($params["content"] ?? ""),
                ];
                $id = wp_insert_post($postData, true);
                if (is_wp_error($id)) {
                    return [
                        "success" => false,
                        "error" => $id->get_error_message(),
                    ];
                }
                if (!empty($params["type"])) {
                    update_post_meta(
                        $id,
                        \Elementor\TemplateLibrary\Source_Local::TYPE_META_KEY,
                        (string) $params["type"],
                    );
                }
                return ["success" => true, "id" => $id];

            case "update":
                if (empty($params["id"])) {
                    return ["success" => false, "error" => "Erforderlich: id"];
                }
                $updateData = ["ID" => (int) $params["id"]];
                if (!empty($params["title"])) {
                    $updateData["post_title"] = sanitize_text_field(
                        (string) $params["title"],
                    );
                }
                if (isset($params["content"])) {
                    $updateData["post_content"] = (string) $params["content"];
                }
                $result = wp_update_post($updateData, true);
                if (is_wp_error($result)) {
                    return [
                        "success" => false,
                        "error" => $result->get_error_message(),
                    ];
                }
                return ["success" => true, "id" => $result];

            case "delete":
                if (empty($params["id"])) {
                    return ["success" => false, "error" => "Erforderlich: id"];
                }
                wp_delete_post((int) $params["id"], true);
                return ["success" => true, "deleted" => true];

            default:
                return [
                    "success" => false,
                    "error" => "Unbekannte Template-Aktion: {$action}",
                ];
        }
    }

    private function handlePage(string $action, array $params): array
    {
        if ($action !== "edit_with_elementor") {
            return [
                "success" => false,
                "error" => "Seiten-Aktion '{$action}' nicht unterstützt. Nutze 'edit_with_elementor'.",
            ];
        }

        $pageId = (int) ($params["page_id"] ?? 0);
        if ($pageId === 0) {
            return ["success" => false, "error" => "Erforderlich: page_id"];
        }

        $page = get_post($pageId);
        if (!$page instanceof \WP_Post) {
            return ["success" => false, "error" => "Seite nicht gefunden"];
        }

        // Mark as Elementor-edited
        update_post_meta($pageId, "_elementor_edit_mode", "builder");

        if (!empty($params["elements"])) {
            $elements = $params["elements"];
            if (is_string($elements)) {
                $elements = json_decode($elements, true);
            }
            if (is_array($elements)) {
                update_post_meta(
                    $pageId,
                    "_elementor_data",
                    wp_slash(json_encode($elements)),
                );
            }
        }

        if (!empty($params["content"])) {
            wp_update_post([
                "ID" => $pageId,
                "post_content" => (string) $params["content"],
            ]);
        }

        // Clear Elementor cache
        if (class_exists("\Elementor\Plugin")) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        return [
            "success" => true,
            "page_id" => $pageId,
            "edit_url" => admin_url("post.php?post={$pageId}&action=elementor"),
        ];
    }

    private function handleWidget(string $action, array $params): array
    {
        if ($action !== "get_registered") {
            return [
                "success" => false,
                "error" => "Widget-Aktion '{$action}' nicht unterstützt. Nutze 'get_registered'.",
            ];
        }

        if (!class_exists("\Elementor\Plugin")) {
            return ["success" => false, "error" => "Elementor nicht geladen"];
        }

        $widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
        $list = [];
        foreach ($widgets as $name => $widget) {
            $list[] = [
                "name" => $name,
                "title" => method_exists($widget, "get_title")
                    ? $widget->get_title()
                    : $name,
                "icon" => method_exists($widget, "get_icon")
                    ? $widget->get_icon()
                    : "",
                "categories" => method_exists($widget, "get_categories")
                    ? $widget->get_categories()
                    : [],
            ];
        }

        return [
            "success" => true,
            "widgets" => array_slice(
                $list,
                0,
                min(100, (int) ($params["limit"] ?? 50)),
            ),
            "total" => count($list),
        ];
    }

    private function handleSetting(string $action, array $params): array
    {
        $key = (string) ($params["key"] ?? "");
        if ($key === "") {
            return ["success" => false, "error" => "Erforderlich: key"];
        }

        switch ($action) {
            case "get":
                $value = get_option($key);
                return ["success" => true, "key" => $key, "value" => $value];

            case "update":
                if (!isset($params["value"])) {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: value",
                    ];
                }
                update_option($key, $params["value"]);
                return ["success" => true, "key" => $key, "updated" => true];

            default:
                return [
                    "success" => false,
                    "error" => "Unbekannte Setting-Aktion: {$action}",
                ];
        }
    }
}
