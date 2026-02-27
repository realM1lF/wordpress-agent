<?php

namespace Levi\Agent\AI\Tools;

class WooCommerceShopTool implements ToolInterface {

    public function getName(): string {
        return 'get_woocommerce_shop';
    }

    public function getDescription(): string {
        return 'Read WooCommerce shop configuration: shipping zones/methods, coupons, recent orders, and general shop settings. Use this to understand the shop setup before making changes.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'What to read',
                'enum' => ['get_shipping', 'get_coupons', 'get_orders', 'get_settings'],
                'required' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Max results for orders/coupons (default 10, max 50)',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public function execute(array $params): array {
        if (!class_exists('WooCommerce')) {
            return ['success' => false, 'error' => 'WooCommerce is not active on this site.'];
        }

        $action = (string) ($params['action'] ?? '');
        $limit = min(50, max(1, (int) ($params['limit'] ?? 10)));

        return match ($action) {
            'get_shipping' => $this->getShipping(),
            'get_coupons' => $this->getCoupons($limit),
            'get_orders' => $this->getOrders($limit),
            'get_settings' => $this->getSettings(),
            default => ['success' => false, 'error' => 'Invalid action. Use: get_shipping, get_coupons, get_orders, get_settings'],
        };
    }

    private function getShipping(): array {
        if (!class_exists('WC_Shipping_Zones')) {
            return ['success' => false, 'error' => 'WC_Shipping_Zones not available.'];
        }

        $zones = \WC_Shipping_Zones::get_zones();
        $restOfWorld = \WC_Shipping_Zones::get_zone(0);

        $result = [];

        foreach ($zones as $zone) {
            $zoneObj = new \WC_Shipping_Zone($zone['id']);
            $result[] = $this->formatZone($zoneObj);
        }

        if ($restOfWorld) {
            $result[] = $this->formatZone($restOfWorld);
        }

        return [
            'success' => true,
            'total_zones' => count($result),
            'zones' => $result,
        ];
    }

    private function formatZone(\WC_Shipping_Zone $zone): array {
        $methods = [];
        foreach ($zone->get_shipping_methods() as $method) {
            $methodData = [
                'id' => $method->id,
                'instance_id' => $method->get_instance_id(),
                'title' => $method->get_title(),
                'enabled' => $method->is_enabled(),
            ];

            if ($method->id === 'free_shipping') {
                $methodData['min_amount'] = $method->get_option('min_amount', '');
                $methodData['requires'] = $method->get_option('requires', '');
            }
            if ($method->id === 'flat_rate') {
                $methodData['cost'] = $method->get_option('cost', '');
            }

            $methods[] = $methodData;
        }

        return [
            'id' => $zone->get_id(),
            'name' => $zone->get_zone_name(),
            'locations' => array_map(function ($loc) {
                return ['type' => $loc->type, 'code' => $loc->code];
            }, $zone->get_zone_locations()),
            'methods' => $methods,
        ];
    }

    private function getCoupons(int $limit): array {
        $args = [
            'post_type' => 'shop_coupon',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $query = new \WP_Query($args);
        $coupons = [];

        foreach ($query->posts as $post) {
            $coupon = new \WC_Coupon($post->ID);
            $coupons[] = [
                'id' => $coupon->get_id(),
                'code' => $coupon->get_code(),
                'discount_type' => $coupon->get_discount_type(),
                'amount' => $coupon->get_amount(),
                'usage_count' => $coupon->get_usage_count(),
                'usage_limit' => $coupon->get_usage_limit(),
                'expiry_date' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
                'minimum_amount' => $coupon->get_minimum_amount(),
                'maximum_amount' => $coupon->get_maximum_amount(),
                'free_shipping' => $coupon->get_free_shipping(),
            ];
        }

        return [
            'success' => true,
            'total' => count($coupons),
            'coupons' => $coupons,
        ];
    }

    private function getOrders(int $limit): array {
        $orders = wc_get_orders([
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $result = [];
        foreach ($orders as $order) {
            $items = [];
            foreach ($order->get_items() as $item) {
                $items[] = [
                    'product_id' => $item->get_product_id(),
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total(),
                ];
            }

            $result[] = [
                'id' => $order->get_id(),
                'status' => $order->get_status(),
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : null,
                'items_count' => count($items),
                'items' => $items,
                'shipping_method' => $order->get_shipping_method(),
                'payment_method' => $order->get_payment_method_title(),
            ];
        }

        return [
            'success' => true,
            'total' => count($result),
            'orders' => $result,
        ];
    }

    private function getSettings(): array {
        $settings = [
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'price_thousand_sep' => wc_get_price_thousand_separator(),
            'price_decimal_sep' => wc_get_price_decimal_separator(),
            'price_num_decimals' => wc_get_price_decimals(),
            'tax_enabled' => wc_tax_enabled(),
            'prices_include_tax' => wc_prices_include_tax(),
            'shop_page_id' => wc_get_page_id('shop'),
            'cart_page_id' => wc_get_page_id('cart'),
            'checkout_page_id' => wc_get_page_id('checkout'),
            'myaccount_page_id' => wc_get_page_id('myaccount'),
            'weight_unit' => get_option('woocommerce_weight_unit'),
            'dimension_unit' => get_option('woocommerce_dimension_unit'),
            'store_address' => get_option('woocommerce_store_address'),
            'store_city' => get_option('woocommerce_store_city'),
            'store_postcode' => get_option('woocommerce_store_postcode'),
            'default_country' => get_option('woocommerce_default_country'),
            'calc_taxes' => get_option('woocommerce_calc_taxes'),
            'enable_coupons' => get_option('woocommerce_enable_coupons'),
        ];

        return [
            'success' => true,
            'settings' => $settings,
        ];
    }
}
