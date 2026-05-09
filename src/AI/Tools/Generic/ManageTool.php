<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Generic manage tool — manage WordPress entities: taxonomy, menu, cron, media, meta.
 */
class ManageTool extends AbstractTool
{
    public function getName(): string
    {
        return "manage";
    }

    public function getDescription(): string
    {
        return "Verwaltet WordPress-Entitäten: Taxonomien (Kategorien/Tags), Menüs, Cron-Jobs, Medien-Upload, Post-Meta. " .
            "`entity=taxonomy|menu|cron|media|meta`. " .
            "Taxonomie: `action=list|create|update|delete|assign`, `taxonomy` (category/post_tag), `name`, `slug`. " .
            "Menü: `action=create|update|delete|add_item|remove_item`, `menu_name`, `item` (URL+label). " .
            "Cron: `action=list|schedule|unschedule`, `hook`, `schedule` (hourly/daily/weekly), `args`. " .
            "Media: `action=upload`, `file_url` (Remote-URL zum Download) oder `file_data` (Base64). " .
            "Meta: `action=get|set|delete`, `post_id`, `key`, `value`.";
    }

    public function getParameters(): array
    {
        return [
            "entity" => [
                "type" => "string",
                "enum" => ["taxonomy", "menu", "cron", "media", "meta"],
                "description" => "Zu verwaltende Entität",
                "required" => true,
            ],
            "action" => [
                "type" => "string",
                "description" => "Aktion (je nach Entität unterschiedlich)",
                "required" => true,
            ],
            "taxonomy" => [
                "type" => "string",
                "description" => "Taxonomie-Slug (für entity=taxonomy)",
            ],
            "term_id" => [
                "type" => "integer",
                "description" => "Term-ID (für Taxonomie-Update/Delete)",
            ],
            "name" => [
                "type" => "string",
                "description" => "Name (für Taxonomie, Menü)",
            ],
            "slug" => [
                "type" => "string",
                "description" => "Slug (für Taxonomie, Menü)",
            ],
            "post_id" => [
                "type" => "integer",
                "description" => "Post-ID (für Taxonomie-Assign, Meta)",
            ],
            "post_ids" => [
                "type" => "array",
                "items" => ["type" => "integer"],
                "description" => "Mehrere Post-IDs (für Bulk-Assign)",
            ],
            "menu_name" => [
                "type" => "string",
                "description" => "Menü-Name (für entity=menu)",
            ],
            "menu_id" => [
                "type" => "integer",
                "description" => "Menü-Term-ID",
            ],
            "item" => [
                "type" => "object",
                "description" =>
                    "Menü-Item {title, url, type(custom/post_type), object_id}",
            ],
            "item_id" => [
                "type" => "integer",
                "description" => "Menü-Item-ID zum Entfernen",
            ],
            "hook" => [
                "type" => "string",
                "description" => "Cron-Hook-Name",
            ],
            "schedule" => [
                "type" => "string",
                "enum" => ["hourly", "twicedaily", "daily", "weekly"],
                "description" => "Cron-Intervall",
            ],
            "args" => [
                "type" => "array",
                "description" => "Cron-Argumente",
            ],
            "file_url" => [
                "type" => "string",
                "description" => "Remote-URL zum Upload (für media)",
            ],
            "file_data" => [
                "type" => "string",
                "description" => "Base64-kodierte Datei (für media)",
            ],
            "filename" => [
                "type" => "string",
                "description" => "Dateiname (für media upload)",
            ],
            "key" => [
                "type" => "string",
                "description" => "Meta-Key (für entity=meta)",
            ],
            "value" => [
                "type" => "string",
                "description" => "Meta-Wert",
            ],
        ];
    }

    public function execute(array $params): array
    {
        $entity = (string) ($params["entity"] ?? "");
        $action = (string) ($params["action"] ?? "");

        return match ($entity) {
            "taxonomy" => $this->handleTaxonomy($action, $params),
            "menu" => $this->handleMenu($action, $params),
            "cron" => $this->handleCron($action, $params),
            "media" => $this->handleMedia($action, $params),
            "meta" => $this->handleMeta($action, $params),
            default => [
                "success" => false,
                "error" => "Unbekannte Entität: {$entity}",
            ],
        };
    }

    public function checkPermission(): bool
    {
        return current_user_can("manage_categories") ||
            current_user_can("upload_files") ||
            current_user_can("edit_posts");
    }

    private function handleTaxonomy(string $action, array $params): array
    {
        $taxonomy = (string) ($params["taxonomy"] ?? "category");
        $allowed = ["category", "post_tag", "product_cat", "product_tag"];
        if (!in_array($taxonomy, $allowed, true)) {
            return [
                "success" => false,
                "error" => "Taxonomie '{$taxonomy}' nicht erlaubt",
            ];
        }

        switch ($action) {
            case "list":
                $terms = get_terms([
                    "taxonomy" => $taxonomy,
                    "hide_empty" => false,
                ]);
                if (is_wp_error($terms)) {
                    return [
                        "success" => false,
                        "error" => $terms->get_error_message(),
                    ];
                }
                return [
                    "success" => true,
                    "terms" => array_map(
                        fn($t) => [
                            "id" => $t->term_id,
                            "name" => $t->name,
                            "slug" => $t->slug,
                            "count" => $t->count,
                        ],
                        $terms,
                    ),
                ];

            case "create":
                $result = wp_insert_term(
                    (string) ($params["name"] ?? ""),
                    $taxonomy,
                    [
                        "slug" => $params["slug"] ?? null,
                    ],
                );
                if (is_wp_error($result)) {
                    return [
                        "success" => false,
                        "error" => $result->get_error_message(),
                    ];
                }
                return ["success" => true, "term_id" => $result["term_id"]];

            case "update":
                if (empty($params["term_id"])) {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: term_id",
                    ];
                }
                $result = wp_update_term((int) $params["term_id"], $taxonomy, [
                    "name" => $params["name"] ?? null,
                    "slug" => $params["slug"] ?? null,
                ]);
                if (is_wp_error($result)) {
                    return [
                        "success" => false,
                        "error" => $result->get_error_message(),
                    ];
                }
                return ["success" => true, "term_id" => $result["term_id"]];

            case "delete":
                if (empty($params["term_id"])) {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: term_id",
                    ];
                }
                $result = wp_delete_term((int) $params["term_id"], $taxonomy);
                if (is_wp_error($result)) {
                    return [
                        "success" => false,
                        "error" => $result->get_error_message(),
                    ];
                }
                return ["success" => true, "deleted" => (bool) $result];

            case "assign":
                $postIds =
                    $params["post_ids"] ??
                    (empty($params["post_id"])
                        ? []
                        : [(int) $params["post_id"]]);
                $termIds = is_array($params["term_id"] ?? null)
                    ? array_map("intval", $params["term_id"])
                    : [(int) ($params["term_id"] ?? 0)];
                if (empty($postIds)) {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: post_id oder post_ids",
                    ];
                }
                foreach ($postIds as $pid) {
                    wp_set_object_terms($pid, $termIds, $taxonomy, false);
                }
                return [
                    "success" => true,
                    "assigned_to" => $postIds,
                    "terms" => $termIds,
                ];

            default:
                return [
                    "success" => false,
                    "error" => "Unbekannte Aktion: {$action}",
                ];
        }
    }

    private function handleMenu(string $action, array $params): array
    {
        if (!current_user_can("edit_theme_options")) {
            return [
                "success" => false,
                "error" => "Keine Berechtigung für Menü-Verwaltung",
            ];
        }

        switch ($action) {
            case "create":
                $result = wp_create_nav_menu(
                    (string) ($params["name"] ?? "New Menu"),
                );
                if (is_wp_error($result)) {
                    return [
                        "success" => false,
                        "error" => $result->get_error_message(),
                    ];
                }
                return ["success" => true, "menu_id" => $result];

            case "list":
                $menus = wp_get_nav_menus();
                return [
                    "success" => true,
                    "menus" => array_map(
                        fn($m) => [
                            "id" => $m->term_id,
                            "name" => $m->name,
                            "slug" => $m->slug,
                            "count" => $m->count,
                        ],
                        $menus,
                    ),
                ];

            case "add_item":
                if (empty($params["menu_id"])) {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: menu_id",
                    ];
                }
                $item = $params["item"] ?? [];
                $itemData = [
                    "menu-item-title" => $item["title"] ?? "",
                    "menu-item-url" => $item["url"] ?? "",
                    "menu-item-type" => $item["type"] ?? "custom",
                    "menu-item-object-id" => $item["object_id"] ?? 0,
                    "menu-item-status" => "publish",
                ];
                $result = wp_update_nav_menu_item(
                    (int) $params["menu_id"],
                    0,
                    $itemData,
                );
                if (is_wp_error($result)) {
                    return [
                        "success" => false,
                        "error" => $result->get_error_message(),
                    ];
                }
                return ["success" => true, "item_id" => $result];

            case "remove_item":
                if (empty($params["item_id"])) {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: item_id",
                    ];
                }
                wp_delete_post((int) $params["item_id"], true);
                return ["success" => true, "removed" => true];

            default:
                return [
                    "success" => false,
                    "error" => "Unbekannte Menü-Aktion: {$action}",
                ];
        }
    }

    private function handleCron(string $action, array $params): array
    {
        if (!current_user_can("manage_options")) {
            return [
                "success" => false,
                "error" => "Keine Berechtigung für Cron-Verwaltung",
            ];
        }

        switch ($action) {
            case "list":
                $crons = _get_cron_array();
                $items = [];
                foreach ($crons as $timestamp => $hooks) {
                    foreach ($hooks as $hook => $events) {
                        foreach ($events as $event) {
                            $items[] = [
                                "hook" => $hook,
                                "schedule" => $event["schedule"] ?? "once",
                                "next_run" => date("Y-m-d H:i:s", $timestamp),
                                "args" => $event["args"] ?? [],
                            ];
                        }
                    }
                }
                return ["success" => true, "events" => $items];

            case "schedule":
                $hook = (string) ($params["hook"] ?? "");
                $schedule = (string) ($params["schedule"] ?? "daily");
                $args = $params["args"] ?? [];
                if ($hook === "") {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: hook",
                    ];
                }
                if (!wp_next_scheduled($hook, $args)) {
                    wp_schedule_event(time(), $schedule, $hook, $args);
                }
                return [
                    "success" => true,
                    "hook" => $hook,
                    "scheduled" => true,
                ];

            case "unschedule":
                $hook = (string) ($params["hook"] ?? "");
                $args = $params["args"] ?? [];
                if ($hook === "") {
                    return [
                        "success" => false,
                        "error" => "Erforderlich: hook",
                    ];
                }
                $timestamp = wp_next_scheduled($hook, $args);
                if ($timestamp) {
                    wp_unschedule_event($timestamp, $hook, $args);
                }
                return [
                    "success" => true,
                    "hook" => $hook,
                    "unscheduled" => (bool) $timestamp,
                ];

            default:
                return [
                    "success" => false,
                    "error" => "Unbekannte Cron-Aktion: {$action}",
                ];
        }
    }

    private function handleMedia(string $action, array $params): array
    {
        if (!current_user_can("upload_files")) {
            return [
                "success" => false,
                "error" => "Keine Berechtigung für Medien-Upload",
            ];
        }

        if ($action !== "upload") {
            return [
                "success" => false,
                "error" => "Medien-Aktion '{$action}' nicht unterstützt. Nutze 'upload'.",
            ];
        }

        if (!empty($params["file_url"])) {
            return $this->uploadFromUrl(
                (string) $params["file_url"],
                $params["filename"] ?? null,
            );
        }

        if (!empty($params["file_data"])) {
            return $this->uploadFromBase64(
                (string) $params["file_data"],
                (string) ($params["filename"] ?? "upload.bin"),
            );
        }

        return [
            "success" => false,
            "error" => "Erforderlich: file_url oder file_data",
        ];
    }

    private function uploadFromUrl(string $url, ?string $filename): array
    {
        if (!function_exists("media_sideload_image")) {
            require_once ABSPATH . "wp-admin/includes/media.php";
            require_once ABSPATH . "wp-admin/includes/file.php";
            require_once ABSPATH . "wp-admin/includes/image.php";
        }

        $tmpFile = download_url($url);
        if (is_wp_error($tmpFile)) {
            return [
                "success" => false,
                "error" => $tmpFile->get_error_message(),
            ];
        }

        $fileArr = [
            "name" =>
                $filename ??
                basename(parse_url($url, PHP_URL_PATH) ?: "download"),
            "tmp_name" => $tmpFile,
            "error" => 0,
            "size" => filesize($tmpFile),
        ];

        $id = media_handle_sideload($fileArr, 0);
        unlink($tmpFile);

        if (is_wp_error($id)) {
            return ["success" => false, "error" => $id->get_error_message()];
        }

        return [
            "success" => true,
            "id" => $id,
            "url" => wp_get_attachment_url($id),
        ];
    }

    private function uploadFromBase64(string $data, string $filename): array
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ["success" => false, "error" => "Ungültige Base64-Daten"];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), "levi_media_");
        file_put_contents($tmpFile, $decoded);

        $fileArr = [
            "name" => $filename,
            "tmp_name" => $tmpFile,
            "error" => 0,
            "size" => strlen($decoded),
        ];

        $id = media_handle_sideload($fileArr, 0);
        unlink($tmpFile);

        if (is_wp_error($id)) {
            return ["success" => false, "error" => $id->get_error_message()];
        }

        return [
            "success" => true,
            "id" => $id,
            "url" => wp_get_attachment_url($id),
        ];
    }

    private function handleMeta(string $action, array $params): array
    {
        $postId = (int) ($params["post_id"] ?? 0);
        $key = (string) ($params["key"] ?? "");

        if ($postId === 0) {
            return ["success" => false, "error" => "Erforderlich: post_id"];
        }

        switch ($action) {
            case "get":
                $value = get_post_meta($postId, $key, true);
                return [
                    "success" => true,
                    "post_id" => $postId,
                    "key" => $key,
                    "value" => $value,
                ];

            case "set":
                if ($key === "") {
                    return ["success" => false, "error" => "Erforderlich: key"];
                }
                update_post_meta($postId, $key, $params["value"] ?? "");
                return [
                    "success" => true,
                    "post_id" => $postId,
                    "key" => $key,
                    "action" => "set",
                ];

            case "delete":
                if ($key === "") {
                    return ["success" => false, "error" => "Erforderlich: key"];
                }
                delete_post_meta($postId, $key);
                return [
                    "success" => true,
                    "post_id" => $postId,
                    "key" => $key,
                    "action" => "delete",
                ];

            default:
                return [
                    "success" => false,
                    "error" => "Unbekannte Meta-Aktion: {$action}",
                ];
        }
    }
}
