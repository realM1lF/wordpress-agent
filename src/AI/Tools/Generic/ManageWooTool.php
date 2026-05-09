<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;

/**
 * Generic WooCommerce management tool — products, orders, coupons, settings.
 */
class ManageWooTool extends AbstractTool {

    public function getName(): string { return 'manage_woo'; }

    public function getDescription(): string {
        return "Verwaltet WooCommerce-Produkte, Bestellungen, Gutscheine und Einstellungen. "
            . "`entity=product|order|coupon|setting`. "
            . "Produkt: `action=list|get|create|update|delete`, `name`, `price`, `stock`, `type` (simple/variable). "
            . "Bestellung: `action=list|get|update_status`, `status` (pending/processing/completed). "
            . "Gutschein: `action=list|get|create|delete`, `code`, `amount`, `type` (fixed_percent/percent). "
            . "Einstellung: `action=get|update`, `key` (WooCommerce-Option).";
    }

    public function getParameters(): array {
        return [
            'entity' => [
                'type' => 'string',
                'enum' => ['product', 'order', 'coupon', 'setting'],
                'description' => 'WooCommerce-Entität',
            ],
            'action' => [
                'type' => 'string',
                'description' => 'Aktion',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'ID (für get, update, delete)',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Name (für Produkt)',
            ],
            'type' => [
                'type' => 'string',
                'description' => 'Typ (für Produkt: simple/variable/grouped; Coupon: fixed_cart/percent)',
            ],
            'price' => [
                'type' => 'number',
                'description' => 'Preis (für Produkt)',
            ],
            'regular_price' => [
                'type' => 'number',
                'description' => 'Regulärer Preis (für Produkt)',
            ],
            'sale_price' => [
                'type' => 'number',
                'description' => 'Angebotspreis (für Produkt)',
            ],
            'stock' => [
                'type' => 'integer',
                'description' => 'Lagerbestand (für Produkt)',
            ],
            'sku' => [
                'type' => 'string',
                'description' => 'SKU (für Produkt)',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Status (für Bestellung: pending/processing/on-hold/completed/cancelled/refunded)',
            ],
            'code' => [
                'type' => 'string',
                'description' => 'Gutschein-Code',
            ],
            'amount' => [
                'type' => 'number',
                'description' => 'Gutschein-Betrag',
            ],
            'key' => [
                'type' => 'string',
                'description' => 'WooCommerce-Einstellungs-Key (z.B. woocommerce_currency)',
            ],
            'value' => [
                'type' => 'string',
                'description' => 'Wert (für Einstellung)',
            ],
            'meta' => [
                'type' => 'object',
                'description' => 'Meta-Daten als Key-Value',
            ],
            'limit' => [
                'type' => 'integer',
                'default' => 20,
                'description' => 'Maximale Ergebnisse (für list)',
            ],
            'offset' => [
                'type' => 'integer',
                'default' => 0,
                'description' => 'Offset (für list)',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Suchbegriff (für list)',
            ],
        ];
    }

    public function execute(array $params): array {
        if (!class_exists('WooCommerce')) {
            return ['success' => false, 'error' => 'WooCommerce ist nicht aktiv'];
        }

        $entity = (string) ($params['entity'] ?? '');
        $action = (string) ($params['action'] ?? '');

        return match ($entity) {
            'product' => $this->handleProduct($action, $params),
            'order' => $this->handleOrder($action, $params),
            'coupon' => $this->handleCoupon($action, $params),
            'setting' => $this->handleSetting($action, $params),
            default => ['success' => false, 'error' => "Unbekannte Entität: {$entity}"],
        };
    }

    public function checkPermission(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('edit_products');
    }

    private function handleProduct(string $action, array $params): array {
        if (!function_exists('wc_get_product')) {
            return ['success' => false, 'error' => 'WooCommerce-Funktionen nicht verfügbar'];
        }

        switch ($action) {
            case 'list':
                $args = [
                    'limit' => min(100, (int) ($params['limit'] ?? 20)),
                    'offset' => (int) ($params['offset'] ?? 0),
                    'status' => ['publish', 'draft', 'pending'],
                    'return' => 'objects',
                ];
                if (!empty($params['search'])) {
                    $args['s'] = (string) $params['search'];
                }
                $products = wc_get_products($args);
                return ['success' => true, 'items' => array_map(fn($p) => $this->productToArray($p), $products)];

            case 'get':
                if (empty($params['id'])) {
                    return ['success' => false, 'error' => 'Erforderlich: id'];
                }
                $product = wc_get_product((int) $params['id']);
                if (!$product) {
                    return ['success' => false, 'error' => 'Produkt nicht gefunden'];
                }
                return ['success' => true, 'data' => $this->productToArray($product)];

            case 'create':
                $product = new \WC_Product();
                $this->applyProductData($product, $params);
                $id = $product->save();
                return ['success' => true, 'id' => $id, 'url' => get_permalink($id)];

            case 'update':
                if (empty($params['id'])) {
                    return ['success' => false, 'error' => 'Erforderlich: id'];
                }
                $product = wc_get_product((int) $params['id']);
                if (!$product) {
                    return ['success' => false, 'error' => 'Produkt nicht gefunden'];
                }
                $this->applyProductData($product, $params);
                $id = $product->save();
                return ['success' => true, 'id' => $id];

            case 'delete':
                if (empty($params['id'])) {
                    return ['success' => false, 'error' => 'Erforderlich: id'];
                }
                $product = wc_get_product((int) $params['id']);
                if (!$product) {
                    return ['success' => false, 'error' => 'Produkt nicht gefunden'];
                }
                $product->delete(true);
                return ['success' => true, 'deleted' => true];

            default:
                return ['success' => false, 'error' => "Unbekannte Produkt-Aktion: {$action}"];
        }
    }

    private function productToArray(\WC_Product $product): array {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'permalink' => $product->get_permalink(),
        ];
    }

    private function applyProductData(\WC_Product $product, array $params): void {
        if (!empty($params['name'])) {
            $product->set_name((string) $params['name']);
        }
        if (!empty($params['type'])) {
            // Type cannot be changed after creation, only on new products
        }
        if (isset($params['regular_price'])) {
            $product->set_regular_price((string) $params['regular_price']);
        }
        if (isset($params['sale_price'])) {
            $product->set_sale_price((string) $params['sale_price']);
        }
        if (isset($params['price'])) {
            $product->set_price((string) $params['price']);
        }
        if (isset($params['stock'])) {
            $product->set_stock_quantity((int) $params['stock']);
        }
        if (!empty($params['sku'])) {
            $product->set_sku((string) $params['sku']);
        }
        if (!empty($params['status'])) {
            $product->set_status((string) $params['status']);
        }
    }

    private function handleOrder(string $action, array $params): array {
        switch ($action) {
            case 'list':
                $args = [
                    'limit' => min(100, (int) ($params['limit'] ?? 20)),
                    'offset' => (int) ($params['offset'] ?? 0),
                    'return' => 'objects',
                ];
                if (!empty($params['status'])) {
                    $args['status'] = [(string) $params['status']];
                }
                $orders = wc_get_orders($args);
                return ['success' => true, 'items' => array_map(fn($o) => [
                    'id' => $o->get_id(),
                    'status' => $o->get_status(),
                    'total' => $o->get_total(),
                    'date' => $o->get_date_created()->date('Y-m-d H:i:s'),
                    'customer' => $o->get_billing_email(),
                ], $orders)];

            case 'get':
                if (empty($params['id'])) {
                    return ['success' => false, 'error' => 'Erforderlich: id'];
                }
                $order = wc_get_order((int) $params['id']);
                if (!$order) {
                    return ['success' => false, 'error' => 'Bestellung nicht gefunden'];
                }
                return ['success' => true, 'data' => [
                    'id' => $order->get_id(),
                    'status' => $order->get_status(),
                    'total' => $order->get_total(),
                    'items' => array_map(fn($item) => [
                        'name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total' => $item->get_total(),
                    ], $order->get_items()),
                    'billing' => [
                        'first_name' => $order->get_billing_first_name(),
                        'last_name' => $order->get_billing_last_name(),
                        'email' => $order->get_billing_email(),
                    ],
                ]];

            case 'update_status':
                if (empty($params['id']) || empty($params['status'])) {
                    return ['success' => false, 'error' => 'Erforderlich: id und status'];
                }
                $order = wc_get_order((int) $params['id']);
                if (!$order) {
                    return ['success' => false, 'error' => 'Bestellung nicht gefunden'];
                }
                $order->update_status((string) $params['status']);
                return ['success' => true, 'id' => $order->get_id(), 'status' => $order->get_status()];

            default:
                return ['success' => false, 'error' => "Unbekannte Bestellungs-Aktion: {$action}"];
        }
    }

    private function handleCoupon(string $action, array $params): array {
        switch ($action) {
            case 'list':
                $coupons = get_posts([
                    'post_type' => 'shop_coupon',
                    'posts_per_page' => min(100, (int) ($params['limit'] ?? 20)),
                    'offset' => (int) ($params['offset'] ?? 0),
                    'post_status' => 'publish',
                ]);
                return ['success' => true, 'items' => array_map(fn($c) => [
                    'id' => $c->ID,
                    'code' => $c->post_title,
                    'amount' => get_post_meta($c->ID, 'coupon_amount', true),
                    'type' => get_post_meta($c->ID, 'discount_type', true),
                ], $coupons)];

            case 'get':
                if (empty($params['id'])) {
                    return ['success' => false, 'error' => 'Erforderlich: id'];
                }
                $coupon = new \WC_Coupon((int) $params['id']);
                return ['success' => true, 'data' => [
                    'id' => $coupon->get_id(),
                    'code' => $coupon->get_code(),
                    'amount' => $coupon->get_amount(),
                    'type' => $coupon->get_discount_type(),
                ]];

            case 'create':
                $coupon = new \WC_Coupon();
                if (!empty($params['code'])) {
                    $coupon->set_code((string) $params['code']);
                }
                if (isset($params['amount'])) {
                    $coupon->set_amount((float) $params['amount']);
                }
                if (!empty($params['type'])) {
                    $coupon->set_discount_type((string) $params['type']);
                }
                $id = $coupon->save();
                return ['success' => true, 'id' => $id, 'code' => $coupon->get_code()];

            case 'delete':
                if (empty($params['id'])) {
                    return ['success' => false, 'error' => 'Erforderlich: id'];
                }
                wp_delete_post((int) $params['id'], true);
                return ['success' => true, 'deleted' => true];

            default:
                return ['success' => false, 'error' => "Unbekannte Coupon-Aktion: {$action}"];
        }
    }

    private function handleSetting(string $action, array $params): array {
        $key = (string) ($params['key'] ?? '');
        if ($key === '') {
            return ['success' => false, 'error' => 'Erforderlich: key'];
        }

        switch ($action) {
            case 'get':
                $value = get_option($key);
                return ['success' => true, 'key' => $key, 'value' => $value];

            case 'update':
                if (!isset($params['value'])) {
                    return ['success' => false, 'error' => 'Erforderlich: value'];
                }
                update_option($key, $params['value']);
                return ['success' => true, 'key' => $key, 'updated' => true];

            default:
                return ['success' => false, 'error' => "Unbekannte Setting-Aktion: {$action}"];
        }
    }
}
