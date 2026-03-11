<?php

namespace Levi\Agent\AI\Tools;

class WooCommerceManageTool implements ToolInterface {

    public function getName(): string {
        return 'manage_woocommerce';
    }

    public function getDescription(): string {
        return 'Write operations for WooCommerce: create/update/delete products (simple, variable, grouped), '
            . 'manage product attributes and variations, update order status, configure taxes, '
            . 'and create/update/delete coupons. Always use this tool instead of execute_wp_code for WooCommerce tasks. '
            . 'For variable products: first create_product (type=variable), then set_product_attributes, then create_variations.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => [
                    'create_product', 'update_product', 'delete_product',
                    'set_product_attributes', 'create_variations', 'update_variation', 'delete_variation',
                    'update_order_status', 'configure_tax',
                    'create_coupon', 'update_coupon', 'delete_coupon',
                ],
                'required' => true,
            ],
            'product_id' => [
                'type' => 'integer',
                'description' => 'Product ID (for update/delete_product, set_product_attributes, create_variations)',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Product name (for create_product)',
            ],
            'product_type' => [
                'type' => 'string',
                'description' => 'Product type (for create_product)',
                'enum' => ['simple', 'variable', 'grouped', 'external'],
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Full product description (for create/update_product)',
            ],
            'short_description' => [
                'type' => 'string',
                'description' => 'Short product description (for create/update_product)',
            ],
            'regular_price' => [
                'type' => 'string',
                'description' => 'Regular price (for create/update_product, create_variations, update_variation)',
            ],
            'sale_price' => [
                'type' => 'string',
                'description' => 'Sale price (empty string to remove)',
            ],
            'sku' => [
                'type' => 'string',
                'description' => 'SKU (for create/update_product)',
            ],
            'stock_quantity' => [
                'type' => 'integer',
                'description' => 'Stock quantity',
            ],
            'stock_status' => [
                'type' => 'string',
                'description' => 'Stock status',
                'enum' => ['instock', 'outofstock', 'onbackorder'],
            ],
            'product_status' => [
                'type' => 'string',
                'description' => 'Post status (for create/update_product)',
                'enum' => ['publish', 'draft', 'pending', 'private'],
            ],
            'category_ids' => [
                'type' => 'string',
                'description' => 'Comma-separated category term IDs (for create/update_product)',
            ],
            'attributes' => [
                'type' => 'string',
                'description' => 'JSON array of attributes for set_product_attributes. Each: {"name":"Color","options":["Red","Blue"],"visible":true,"variation":true}',
            ],
            'variations' => [
                'type' => 'string',
                'description' => 'JSON array of variations for create_variations. Each: {"attributes":{"pa_color":"red","pa_size":"m"},"regular_price":"29.99"}. If omitted, all combinations are generated with the given regular_price.',
            ],
            'variation_id' => [
                'type' => 'integer',
                'description' => 'Variation ID (for update_variation, delete_variation)',
            ],
            'order_id' => [
                'type' => 'integer',
                'description' => 'Order ID (for update_order_status)',
            ],
            'order_status' => [
                'type' => 'string',
                'description' => 'New order status (for update_order_status)',
                'enum' => ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'],
            ],
            'tax_enabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable tax calculation (for configure_tax)',
            ],
            'prices_include_tax' => [
                'type' => 'boolean',
                'description' => 'Whether prices include tax (for configure_tax)',
            ],
            'coupon_id' => [
                'type' => 'integer',
                'description' => 'Coupon ID (for update_coupon, delete_coupon)',
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
            'create_product' => $this->createProduct($params),
            'update_product' => $this->updateProduct($params),
            'delete_product' => $this->deleteProduct($params),
            'set_product_attributes' => $this->setProductAttributes($params),
            'create_variations' => $this->createVariations($params),
            'update_variation' => $this->updateVariation($params),
            'delete_variation' => $this->deleteVariation($params),
            'update_order_status' => $this->updateOrderStatus($params),
            'configure_tax' => $this->configureTax($params),
            'create_coupon' => $this->createCoupon($params),
            'update_coupon' => $this->updateCoupon($params),
            'delete_coupon' => $this->deleteCoupon($params),
            default => ['success' => false, 'error' => 'Invalid action. Available: create_product, update_product, delete_product, set_product_attributes, create_variations, update_variation, delete_variation, update_order_status, configure_tax, create_coupon, update_coupon, delete_coupon'],
        };
    }

    // ── Products ─────────────────────────────────────────────────────────

    private function createProduct(array $params): array {
        $name = sanitize_text_field((string) ($params['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required.'];
        }

        $type = (string) ($params['product_type'] ?? 'simple');
        $product = match ($type) {
            'variable' => new \WC_Product_Variable(),
            'grouped' => new \WC_Product_Grouped(),
            'external' => new \WC_Product_External(),
            default => new \WC_Product_Simple(),
        };

        $product->set_name($name);
        $product->set_status((string) ($params['product_status'] ?? 'publish'));

        if (isset($params['description'])) {
            $product->set_description(wp_kses_post((string) $params['description']));
        }
        if (isset($params['short_description'])) {
            $product->set_short_description(wp_kses_post((string) $params['short_description']));
        }
        if (isset($params['sku'])) {
            $product->set_sku(sanitize_text_field((string) $params['sku']));
        }
        if (isset($params['regular_price']) && $type !== 'variable') {
            $product->set_regular_price((string) $params['regular_price']);
        }
        if (isset($params['sale_price']) && $type !== 'variable') {
            $product->set_sale_price((string) $params['sale_price']);
        }
        if (isset($params['stock_quantity'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $params['stock_quantity']);
        }
        if (isset($params['stock_status'])) {
            $product->set_stock_status((string) $params['stock_status']);
        }
        if (isset($params['category_ids'])) {
            $ids = array_map('intval', array_filter(explode(',', (string) $params['category_ids'])));
            if (!empty($ids)) {
                $product->set_category_ids($ids);
            }
        }

        $product->save();

        return [
            'success' => true,
            'product_id' => $product->get_id(),
            'product_name' => $product->get_name(),
            'product_type' => $type,
            'status' => $product->get_status(),
            'permalink' => $product->get_permalink(),
            'message' => "Product '{$name}' created (type: {$type}).",
        ];
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

        if (isset($params['name'])) {
            $product->set_name(sanitize_text_field((string) $params['name']));
            $changes[] = 'name=' . $params['name'];
        }
        if (isset($params['description'])) {
            $product->set_description(wp_kses_post((string) $params['description']));
            $changes[] = 'description updated';
        }
        if (isset($params['short_description'])) {
            $product->set_short_description(wp_kses_post((string) $params['short_description']));
            $changes[] = 'short_description updated';
        }
        if (isset($params['sku'])) {
            $product->set_sku(sanitize_text_field((string) $params['sku']));
            $changes[] = 'sku=' . $params['sku'];
        }
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
        if (isset($params['category_ids'])) {
            $ids = array_map('intval', array_filter(explode(',', (string) $params['category_ids'])));
            $product->set_category_ids($ids);
            $changes[] = 'categories=' . implode(',', $ids);
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

    private function deleteProduct(array $params): array {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'product_id is required.'];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        $name = $product->get_name();
        $product->delete(true);

        return [
            'success' => true,
            'product_id' => $productId,
            'product_name' => $name,
            'message' => "Product '{$name}' deleted.",
        ];
    }

    // ── Attributes ───────────────────────────────────────────────────────

    private function setProductAttributes(array $params): array {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'product_id is required.'];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        $rawAttributes = json_decode((string) ($params['attributes'] ?? '[]'), true);
        if (!is_array($rawAttributes) || empty($rawAttributes)) {
            return ['success' => false, 'error' => 'attributes must be a JSON array of {name, options[], visible?, variation?}.'];
        }

        $wcAttributes = [];
        $createdTaxonomies = [];

        foreach ($rawAttributes as $i => $attr) {
            $attrName = trim((string) ($attr['name'] ?? ''));
            $options = (array) ($attr['options'] ?? []);
            if ($attrName === '' || empty($options)) {
                continue;
            }

            $taxonomy = $this->ensureGlobalAttribute($attrName);
            if ($taxonomy === null) {
                return ['success' => false, 'error' => "Failed to create attribute taxonomy for '{$attrName}'."];
            }
            $createdTaxonomies[] = $taxonomy;

            $termIds = [];
            foreach ($options as $optionValue) {
                $slug = sanitize_title((string) $optionValue);
                $term = get_term_by('slug', $slug, $taxonomy);
                if (!$term) {
                    $inserted = wp_insert_term((string) $optionValue, $taxonomy, ['slug' => $slug]);
                    if (is_wp_error($inserted)) {
                        if ($inserted->get_error_code() === 'term_exists') {
                            $termIds[] = (int) $inserted->get_error_data('term_exists');
                        } else {
                            return ['success' => false, 'error' => "Failed to create term '{$optionValue}' for {$taxonomy}: " . $inserted->get_error_message()];
                        }
                    } else {
                        $termIds[] = (int) $inserted['term_id'];
                    }
                } else {
                    $termIds[] = (int) $term->term_id;
                }
            }

            wp_set_object_terms($productId, $termIds, $taxonomy);

            $wcAttr = new \WC_Product_Attribute();
            $wcAttr->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $wcAttr->set_name($taxonomy);
            $wcAttr->set_options($termIds);
            $wcAttr->set_position($i);
            $wcAttr->set_visible((bool) ($attr['visible'] ?? true));
            $wcAttr->set_variation((bool) ($attr['variation'] ?? true));
            $wcAttributes[] = $wcAttr;
        }

        $product->set_attributes($wcAttributes);
        $product->save();

        return [
            'success' => true,
            'product_id' => $productId,
            'attributes_set' => count($wcAttributes),
            'taxonomies' => $createdTaxonomies,
            'message' => count($wcAttributes) . ' attribute(s) assigned to product.',
        ];
    }

    private function ensureGlobalAttribute(string $name): ?string {
        $slug = wc_sanitize_taxonomy_name($name);
        $taxonomy = 'pa_' . $slug;

        if (taxonomy_exists($taxonomy)) {
            return $taxonomy;
        }

        $existingId = wc_attribute_taxonomy_id_by_name($slug);
        if ($existingId) {
            register_taxonomy($taxonomy, 'product', [
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]);
            return $taxonomy;
        }

        $id = wc_create_attribute([
            'name' => $name,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ]);

        if (is_wp_error($id)) {
            return null;
        }

        register_taxonomy($taxonomy, 'product', [
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);

        return $taxonomy;
    }

    // ── Variations ───────────────────────────────────────────────────────

    private function createVariations(array $params): array {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'product_id is required.'];
        }

        $product = wc_get_product($productId);
        if (!$product || !$product->is_type('variable')) {
            return ['success' => false, 'error' => 'Product not found or not a variable product.'];
        }

        $rawVariations = null;
        if (!empty($params['variations'])) {
            $rawVariations = json_decode((string) $params['variations'], true);
        }

        $defaultPrice = (string) ($params['regular_price'] ?? '');
        $defaultStock = (string) ($params['stock_status'] ?? 'instock');
        $created = [];

        if (is_array($rawVariations) && !empty($rawVariations)) {
            foreach ($rawVariations as $varData) {
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($productId);
                $attrs = (array) ($varData['attributes'] ?? []);
                $variation->set_attributes($attrs);
                $variation->set_regular_price((string) ($varData['regular_price'] ?? $defaultPrice));
                if (isset($varData['sale_price'])) {
                    $variation->set_sale_price((string) $varData['sale_price']);
                }
                $variation->set_stock_status((string) ($varData['stock_status'] ?? $defaultStock));
                if (isset($varData['sku'])) {
                    $variation->set_sku(sanitize_text_field((string) $varData['sku']));
                }
                $variation->save();
                $created[] = $variation->get_id();
            }
        } else {
            $allCombinations = $this->generateAttributeCombinations($product);
            if (empty($allCombinations)) {
                return ['success' => false, 'error' => 'No variation attributes found on this product. Use set_product_attributes first.'];
            }
            foreach ($allCombinations as $combo) {
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($productId);
                $variation->set_attributes($combo);
                $variation->set_regular_price($defaultPrice);
                $variation->set_stock_status($defaultStock);
                $variation->save();
                $created[] = $variation->get_id();
            }
        }

        \WC_Product_Variable::sync($productId);

        return [
            'success' => true,
            'product_id' => $productId,
            'variations_created' => count($created),
            'variation_ids' => $created,
            'message' => count($created) . ' variation(s) created.',
        ];
    }

    private function generateAttributeCombinations(\WC_Product_Variable $product): array {
        $variationAttributes = [];
        foreach ($product->get_attributes() as $attr) {
            if (!$attr->get_variation()) {
                continue;
            }
            $taxonomy = $attr->get_name();
            $options = [];
            foreach ($attr->get_options() as $termIdOrSlug) {
                $term = get_term($termIdOrSlug, $taxonomy);
                $options[] = ($term && !is_wp_error($term)) ? $term->slug : (string) $termIdOrSlug;
            }
            if (!empty($options)) {
                $variationAttributes[$taxonomy] = $options;
            }
        }

        if (empty($variationAttributes)) {
            return [];
        }

        $keys = array_keys($variationAttributes);
        $values = array_values($variationAttributes);

        return $this->cartesianProduct($keys, $values);
    }

    private function cartesianProduct(array $keys, array $values): array {
        $result = [[]];
        foreach ($values as $i => $options) {
            $newResult = [];
            foreach ($result as $combo) {
                foreach ($options as $option) {
                    $newCombo = $combo;
                    $newCombo[$keys[$i]] = $option;
                    $newResult[] = $newCombo;
                }
            }
            $result = $newResult;
        }
        return $result;
    }

    private function updateVariation(array $params): array {
        $variationId = (int) ($params['variation_id'] ?? 0);
        if ($variationId <= 0) {
            return ['success' => false, 'error' => 'variation_id is required.'];
        }

        $variation = wc_get_product($variationId);
        if (!$variation || !$variation->is_type('variation')) {
            return ['success' => false, 'error' => 'Variation not found.'];
        }

        $changes = [];

        if (isset($params['regular_price'])) {
            $variation->set_regular_price((string) $params['regular_price']);
            $changes[] = 'regular_price=' . $params['regular_price'];
        }
        if (isset($params['sale_price'])) {
            $variation->set_sale_price((string) $params['sale_price']);
            $changes[] = 'sale_price=' . $params['sale_price'];
        }
        if (isset($params['stock_quantity'])) {
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity((int) $params['stock_quantity']);
            $changes[] = 'stock_quantity=' . $params['stock_quantity'];
        }
        if (isset($params['stock_status'])) {
            $variation->set_stock_status((string) $params['stock_status']);
            $changes[] = 'stock_status=' . $params['stock_status'];
        }
        if (isset($params['sku'])) {
            $variation->set_sku(sanitize_text_field((string) $params['sku']));
            $changes[] = 'sku=' . $params['sku'];
        }

        if (empty($changes)) {
            return ['success' => false, 'error' => 'No changes specified.'];
        }

        $variation->save();
        \WC_Product_Variable::sync($variation->get_parent_id());

        return [
            'success' => true,
            'variation_id' => $variationId,
            'parent_id' => $variation->get_parent_id(),
            'changes' => $changes,
            'message' => 'Variation updated.',
        ];
    }

    private function deleteVariation(array $params): array {
        $variationId = (int) ($params['variation_id'] ?? 0);
        if ($variationId <= 0) {
            return ['success' => false, 'error' => 'variation_id is required.'];
        }

        $variation = wc_get_product($variationId);
        if (!$variation || !$variation->is_type('variation')) {
            return ['success' => false, 'error' => 'Variation not found.'];
        }

        $parentId = $variation->get_parent_id();
        $variation->delete(true);
        \WC_Product_Variable::sync($parentId);

        return [
            'success' => true,
            'variation_id' => $variationId,
            'parent_id' => $parentId,
            'message' => 'Variation deleted.',
        ];
    }

    // ── Orders ───────────────────────────────────────────────────────────

    private function updateOrderStatus(array $params): array {
        $orderId = (int) ($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'order_id is required.'];
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        $newStatus = (string) ($params['order_status'] ?? '');
        if ($newStatus === '') {
            return ['success' => false, 'error' => 'order_status is required.'];
        }

        $oldStatus = $order->get_status();
        $order->update_status($newStatus, 'Status updated by Levi AI.');

        return [
            'success' => true,
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'message' => "Order #{$orderId} status changed from '{$oldStatus}' to '{$newStatus}'.",
        ];
    }

    // ── Tax ──────────────────────────────────────────────────────────────

    private function configureTax(array $params): array {
        $changes = [];

        if (isset($params['tax_enabled'])) {
            $val = (bool) $params['tax_enabled'] ? 'yes' : 'no';
            update_option('woocommerce_calc_taxes', $val);
            $changes[] = 'calc_taxes=' . $val;
        }
        if (isset($params['prices_include_tax'])) {
            $val = (bool) $params['prices_include_tax'] ? 'yes' : 'no';
            update_option('woocommerce_prices_include_tax', $val);
            $changes[] = 'prices_include_tax=' . $val;
        }

        if (empty($changes)) {
            return ['success' => false, 'error' => 'No tax settings specified. Provide tax_enabled and/or prices_include_tax.'];
        }

        return [
            'success' => true,
            'changes' => $changes,
            'message' => 'Tax settings updated.',
        ];
    }

    // ── Coupons ──────────────────────────────────────────────────────────

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
