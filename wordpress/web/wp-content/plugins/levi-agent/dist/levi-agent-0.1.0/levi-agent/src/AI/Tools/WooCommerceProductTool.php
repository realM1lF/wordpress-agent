<?php

namespace Levi\Agent\AI\Tools;

class WooCommerceProductTool implements ToolInterface {

    public function getName(): string {
        return 'get_woocommerce_data';
    }

    public function getDescription(): string {
        return 'Read WooCommerce product data: product details, variations, categories, stock, prices. Works with simple, variable, grouped products. Use action "get_product" for a single product, "get_variations" for variable product variations, "search_products" to find products, "get_categories" for product categories.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform',
                'enum' => ['get_product', 'get_variations', 'search_products', 'get_categories'],
                'required' => true,
            ],
            'product_id' => [
                'type' => 'integer',
                'description' => 'Product ID (required for get_product and get_variations)',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search term for search_products',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Category slug to filter products in search_products',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Max results for search_products (default 20, max 100)',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        if (!function_exists('wc_get_product')) {
            return ['success' => false, 'error' => 'WooCommerce is not active on this site.'];
        }

        $action = (string) ($params['action'] ?? '');

        return match ($action) {
            'get_product' => $this->getProduct($params),
            'get_variations' => $this->getVariations($params),
            'search_products' => $this->searchProducts($params),
            'get_categories' => $this->getCategories(),
            default => ['success' => false, 'error' => 'Invalid action. Use: get_product, get_variations, search_products, get_categories'],
        };
    }

    private function getProduct(array $params): array {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'product_id is required.'];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        return [
            'success' => true,
            'product' => $this->formatProduct($product),
        ];
    }

    private function getVariations(array $params): array {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'product_id is required.'];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        if (!$product->is_type('variable')) {
            return [
                'success' => true,
                'product_id' => $productId,
                'product_type' => $product->get_type(),
                'variations' => [],
                'message' => 'This is not a variable product, so it has no variations.',
            ];
        }

        $variations = [];
        foreach ($product->get_available_variations() as $v) {
            $variation = wc_get_product($v['variation_id']);
            if (!$variation) {
                continue;
            }
            $variations[] = [
                'variation_id' => $v['variation_id'],
                'attributes' => $v['attributes'],
                'price' => $variation->get_price(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
                'in_stock' => $variation->is_in_stock(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'sku' => $variation->get_sku(),
                'is_purchasable' => $variation->is_purchasable(),
            ];
        }

        return [
            'success' => true,
            'product_id' => $productId,
            'product_name' => $product->get_name(),
            'product_type' => 'variable',
            'total_variations' => count($variations),
            'variations' => $variations,
        ];
    }

    private function searchProducts(array $params): array {
        $search = (string) ($params['search'] ?? '');
        $category = (string) ($params['category'] ?? '');
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));

        $args = [
            'limit' => $limit,
            'status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }
        if ($category !== '') {
            $args['category'] = [$category];
        }

        $products = wc_get_products($args);
        $results = [];

        foreach ($products as $product) {
            $results[] = $this->formatProduct($product);
        }

        return [
            'success' => true,
            'total' => count($results),
            'search' => $search ?: null,
            'category' => $category ?: null,
            'products' => $results,
        ];
    }

    private function getCategories(): array {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        if (is_wp_error($terms)) {
            return ['success' => false, 'error' => $terms->get_error_message()];
        }

        $categories = [];
        foreach ($terms as $term) {
            $categories[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'count' => $term->count,
                'parent_id' => $term->parent,
            ];
        }

        return [
            'success' => true,
            'total' => count($categories),
            'categories' => $categories,
        ];
    }

    private function formatProduct($product): array {
        $data = [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'slug' => $product->get_slug(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'in_stock' => $product->is_in_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'is_purchasable' => $product->is_purchasable(),
            'is_virtual' => $product->is_virtual(),
            'permalink' => $product->get_permalink(),
            'categories' => [],
        ];

        $cats = wc_get_product_terms($product->get_id(), 'product_cat', ['fields' => 'all']);
        if (!is_wp_error($cats)) {
            foreach ($cats as $cat) {
                $data['categories'][] = ['id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug];
            }
        }

        if ($product->is_type('variable')) {
            $data['variation_count'] = count($product->get_children());
            $data['attributes'] = [];
            foreach ($product->get_attributes() as $attr) {
                $data['attributes'][] = [
                    'name' => $attr->get_name(),
                    'options' => $attr->get_options(),
                    'visible' => $attr->get_visible(),
                    'variation' => $attr->get_variation(),
                ];
            }
        }

        $imageId = $product->get_image_id();
        if ($imageId) {
            $data['image'] = wp_get_attachment_url($imageId);
        }

        return $data;
    }
}
