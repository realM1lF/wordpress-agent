<?php

namespace Levi\Agent\AI\Tools;

class WooCommerceManageTool implements ToolInterface {

    public function getName(): string {
        return 'manage_woocommerce';
    }

    public function getDescription(): string {
        return 'Write operations for WooCommerce: update product prices/stock, create/update coupons. Uses WooCommerce CRUD methods for data integrity. Destructive actions require confirmation.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => ['update_product', 'create_coupon', 'update_coupon', 'delete_coupon'],
                'required' => true,
            ],
            'product_id' => [
                'type' => 'integer',
                'description' => 'Product ID (for update_product)',
            ],
            'coupon_id' => [
                'type' => 'integer',
                'description' => 'Coupon ID (for update_coupon, delete_coupon)',
            ],
            'regular_price' => [
                'type' => 'string',
                'description' => 'New regular price (for update_product)',
            ],
            'sale_price' => [
                'type' => 'string',
                'description' => 'New sale price (for update_product, empty string to remove)',
            ],
            'stock_quantity' => [
                'type' => 'integer',
                'description' => 'New stock quantity (for update_product)',
            ],
            'stock_status' => [
                'type' => 'string',
                'description' => 'Stock status (for update_product)',
                'enum' => ['instock', 'outofstock', 'onbackorder'],
            ],
            'product_status' => [
                'type' => 'string',
                'description' => 'Post status (for update_product)',
                'enum' => ['publish', 'draft', 'pending', 'private'],
            ],
            'coupon_code' => [
                'type' => 'string',
                'description' => 'Coupon code (for create_coupon)',
            ],
            'discount_type' => [
                'type' => 'string',
                'description' => 'Discount type (for create_coupon/update_coupon)',
                'enum' => ['percent', 'fixed_cart', 'fixed_product'],
            ],
            'amount' => [
                'type' => 'string',
                'description' => 'Discount amount (for create_coupon/update_coupon)',
            ],
            'free_shipping' => [
                'type' => 'boolean',
                'description' => 'Enable free shipping for coupon',
            ],
            'minimum_amount' => [
                'type' => 'string',
                'description' => 'Minimum cart amount for coupon',
            ],
            'usage_limit' => [
                'type' => 'integer',
                'description' => 'Max usage count for coupon',
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

        return match ($action) {
            'update_product' => $this->updateProduct($params),
            'create_coupon' => $this->createCoupon($params),
            'update_coupon' => $this->updateCoupon($params),
            'delete_coupon' => $this->deleteCoupon($params),
            default => ['success' => false, 'error' => 'Invalid action.'],
        };
    }

    private function updateProduct(array $params): array {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'product_id is required.'];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        $changes = [];

        if (isset($params['regular_price'])) {
            $product->set_regular_price((string) $params['regular_price']);
            $changes[] = 'regular_price=' . $params['regular_price'];
        }
        if (isset($params['sale_price'])) {
            $product->set_sale_price((string) $params['sale_price']);
            $changes[] = 'sale_price=' . $params['sale_price'];
        }
        if (isset($params['stock_quantity'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $params['stock_quantity']);
            $changes[] = 'stock_quantity=' . $params['stock_quantity'];
        }
        if (isset($params['stock_status'])) {
            $product->set_stock_status((string) $params['stock_status']);
            $changes[] = 'stock_status=' . $params['stock_status'];
        }
        if (isset($params['product_status'])) {
            $product->set_status((string) $params['product_status']);
            $changes[] = 'status=' . $params['product_status'];
        }

        if (empty($changes)) {
            return ['success' => false, 'error' => 'No changes specified. Provide at least one field to update.'];
        }

        $product->save();

        return [
            'success' => true,
            'product_id' => $productId,
            'product_name' => $product->get_name(),
            'changes' => $changes,
            'message' => 'Product updated successfully.',
        ];
    }

    private function createCoupon(array $params): array {
        $code = sanitize_text_field((string) ($params['coupon_code'] ?? ''));
        if ($code === '') {
            return ['success' => false, 'error' => 'coupon_code is required.'];
        }

        $existing = wc_get_coupon_id_by_code($code);
        if ($existing) {
            return ['success' => false, 'error' => 'A coupon with this code already exists (ID ' . $existing . ').'];
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code($code);

        $this->applyCouponParams($coupon, $params);
        $coupon->save();

        return [
            'success' => true,
            'coupon_id' => $coupon->get_id(),
            'code' => $coupon->get_code(),
            'message' => 'Coupon created successfully.',
        ];
    }

    private function updateCoupon(array $params): array {
        $couponId = (int) ($params['coupon_id'] ?? 0);
        if ($couponId <= 0) {
            return ['success' => false, 'error' => 'coupon_id is required.'];
        }

        $coupon = new \WC_Coupon($couponId);
        if (!$coupon->get_id()) {
            return ['success' => false, 'error' => 'Coupon not found.'];
        }

        $this->applyCouponParams($coupon, $params);
        $coupon->save();

        return [
            'success' => true,
            'coupon_id' => $coupon->get_id(),
            'code' => $coupon->get_code(),
            'message' => 'Coupon updated successfully.',
        ];
    }

    private function deleteCoupon(array $params): array {
        $couponId = (int) ($params['coupon_id'] ?? 0);
        if ($couponId <= 0) {
            return ['success' => false, 'error' => 'coupon_id is required.'];
        }

        $coupon = new \WC_Coupon($couponId);
        if (!$coupon->get_id()) {
            return ['success' => false, 'error' => 'Coupon not found.'];
        }

        $code = $coupon->get_code();
        $coupon->delete(true);

        return [
            'success' => true,
            'coupon_id' => $couponId,
            'code' => $code,
            'message' => 'Coupon deleted.',
        ];
    }

    private function applyCouponParams(\WC_Coupon $coupon, array $params): void {
        if (isset($params['discount_type'])) {
            $coupon->set_discount_type((string) $params['discount_type']);
        }
        if (isset($params['amount'])) {
            $coupon->set_amount((float) $params['amount']);
        }
        if (isset($params['free_shipping'])) {
            $coupon->set_free_shipping((bool) $params['free_shipping']);
        }
        if (isset($params['minimum_amount'])) {
            $coupon->set_minimum_amount((float) $params['minimum_amount']);
        }
        if (isset($params['usage_limit'])) {
            $coupon->set_usage_limit((int) $params['usage_limit']);
        }
    }
}
