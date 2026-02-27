<?php

namespace Levi\Agent\AI\Tools;

class ManageMenuTool implements ToolInterface {

    public function getName(): string {
        return 'manage_menu';
    }

    public function getDescription(): string {
        return 'Read and manage WordPress navigation menus and widget areas. List menus, get menu items, add/remove items, list sidebars and their widgets.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => ['list_menus', 'get_menu_items', 'add_menu_item', 'remove_menu_item', 'list_sidebars', 'get_sidebar_widgets'],
                'required' => true,
            ],
            'menu_id' => [
                'type' => 'integer',
                'description' => 'Menu ID or term_id (for get_menu_items, add_menu_item, remove_menu_item)',
            ],
            'item_id' => [
                'type' => 'integer',
                'description' => 'Menu item ID (for remove_menu_item)',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Title for new menu item',
            ],
            'url' => [
                'type' => 'string',
                'description' => 'URL for new custom-link menu item',
            ],
            'object_type' => [
                'type' => 'string',
                'description' => 'Object type for menu item (page, post, category, custom)',
                'enum' => ['page', 'post', 'category', 'custom'],
            ],
            'object_id' => [
                'type' => 'integer',
                'description' => 'Object ID for page/post/category menu items',
            ],
            'parent_item_id' => [
                'type' => 'integer',
                'description' => 'Parent menu item ID (for sub-items)',
            ],
            'sidebar_id' => [
                'type' => 'string',
                'description' => 'Sidebar ID (for get_sidebar_widgets)',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_theme_options');
    }

    public function execute(array $params): array {
        $action = (string) ($params['action'] ?? '');

        return match ($action) {
            'list_menus' => $this->listMenus(),
            'get_menu_items' => $this->getMenuItems($params),
            'add_menu_item' => $this->addMenuItem($params),
            'remove_menu_item' => $this->removeMenuItem($params),
            'list_sidebars' => $this->listSidebars(),
            'get_sidebar_widgets' => $this->getSidebarWidgets($params),
            default => ['success' => false, 'error' => 'Invalid action.'],
        };
    }

    private function listMenus(): array {
        $menus = wp_get_nav_menus();
        $locations = get_nav_menu_locations();
        $registeredLocations = get_registered_nav_menus();

        $result = [];
        foreach ($menus as $menu) {
            $assignedLocations = [];
            foreach ($locations as $locSlug => $menuId) {
                if ($menuId == $menu->term_id) {
                    $assignedLocations[] = [
                        'slug' => $locSlug,
                        'name' => $registeredLocations[$locSlug] ?? $locSlug,
                    ];
                }
            }

            $result[] = [
                'id' => $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'count' => $menu->count,
                'assigned_locations' => $assignedLocations,
            ];
        }

        return [
            'success' => true,
            'menus' => $result,
            'available_locations' => $registeredLocations,
        ];
    }

    private function getMenuItems(array $params): array {
        $menuId = (int) ($params['menu_id'] ?? 0);
        if ($menuId <= 0) {
            return ['success' => false, 'error' => 'menu_id is required.'];
        }

        $items = wp_get_nav_menu_items($menuId);
        if ($items === false) {
            return ['success' => false, 'error' => 'Menu not found.'];
        }

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'id' => $item->ID,
                'title' => $item->title,
                'url' => $item->url,
                'type' => $item->type,
                'object' => $item->object,
                'object_id' => (int) $item->object_id,
                'parent' => (int) $item->menu_item_parent,
                'position' => $item->menu_order,
                'classes' => array_filter($item->classes),
            ];
        }

        return [
            'success' => true,
            'menu_id' => $menuId,
            'items' => $result,
        ];
    }

    private function addMenuItem(array $params): array {
        $menuId = (int) ($params['menu_id'] ?? 0);
        if ($menuId <= 0) {
            return ['success' => false, 'error' => 'menu_id is required.'];
        }

        $objectType = (string) ($params['object_type'] ?? 'custom');
        $title = sanitize_text_field((string) ($params['title'] ?? ''));

        $menuItemData = [
            'menu-item-status' => 'publish',
            'menu-item-parent-id' => (int) ($params['parent_item_id'] ?? 0),
        ];

        if ($objectType === 'custom') {
            $url = esc_url_raw((string) ($params['url'] ?? ''));
            if ($url === '' || $title === '') {
                return ['success' => false, 'error' => 'title and url are required for custom links.'];
            }
            $menuItemData['menu-item-type'] = 'custom';
            $menuItemData['menu-item-title'] = $title;
            $menuItemData['menu-item-url'] = $url;
        } elseif ($objectType === 'page' || $objectType === 'post') {
            $objectId = (int) ($params['object_id'] ?? 0);
            if ($objectId <= 0) {
                return ['success' => false, 'error' => 'object_id is required for page/post items.'];
            }
            $menuItemData['menu-item-type'] = 'post_type';
            $menuItemData['menu-item-object'] = $objectType;
            $menuItemData['menu-item-object-id'] = $objectId;
            if ($title !== '') {
                $menuItemData['menu-item-title'] = $title;
            }
        } elseif ($objectType === 'category') {
            $objectId = (int) ($params['object_id'] ?? 0);
            if ($objectId <= 0) {
                return ['success' => false, 'error' => 'object_id is required for category items.'];
            }
            $menuItemData['menu-item-type'] = 'taxonomy';
            $menuItemData['menu-item-object'] = 'category';
            $menuItemData['menu-item-object-id'] = $objectId;
            if ($title !== '') {
                $menuItemData['menu-item-title'] = $title;
            }
        }

        $result = wp_update_nav_menu_item($menuId, 0, $menuItemData);

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'item_id' => $result,
            'message' => 'Menu item added.',
        ];
    }

    private function removeMenuItem(array $params): array {
        $itemId = (int) ($params['item_id'] ?? 0);
        if ($itemId <= 0) {
            return ['success' => false, 'error' => 'item_id is required.'];
        }

        $deleted = wp_delete_post($itemId, true);
        if (!$deleted) {
            return ['success' => false, 'error' => 'Could not delete menu item.'];
        }

        return [
            'success' => true,
            'item_id' => $itemId,
            'message' => 'Menu item removed.',
        ];
    }

    private function listSidebars(): array {
        global $wp_registered_sidebars;

        $sidebars = [];
        foreach ($wp_registered_sidebars as $id => $sidebar) {
            $sidebars[] = [
                'id' => $id,
                'name' => $sidebar['name'],
                'description' => $sidebar['description'] ?? '',
            ];
        }

        return [
            'success' => true,
            'sidebars' => $sidebars,
        ];
    }

    private function getSidebarWidgets(array $params): array {
        $sidebarId = sanitize_key((string) ($params['sidebar_id'] ?? ''));
        if ($sidebarId === '') {
            return ['success' => false, 'error' => 'sidebar_id is required.'];
        }

        $sidebarsWidgets = wp_get_sidebars_widgets();
        if (!isset($sidebarsWidgets[$sidebarId])) {
            return ['success' => false, 'error' => 'Sidebar not found.'];
        }

        $widgets = [];
        foreach ($sidebarsWidgets[$sidebarId] as $widgetId) {
            $widgets[] = ['widget_id' => $widgetId];
        }

        return [
            'success' => true,
            'sidebar_id' => $sidebarId,
            'widgets' => $widgets,
        ];
    }
}
