<?php

namespace Levi\Agent\AI\Tools;

class PostMetaTool implements ToolInterface {

    public function getName(): string {
        return 'manage_post_meta';
    }

    public function getDescription(): string {
        return 'Read, write, or delete metadata for any post/page/product/custom post type. Post meta stores extended data like WooCommerce prices (_regular_price, _sale_price), stock (_stock, _stock_status), SKU (_sku), ACF fields, etc. Use action "get" to read, "set" to write, "delete" to remove.';
    }

    public function getParameters(): array {
        return [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post/product/page ID',
                'required' => true,
            ],
            'action' => [
                'type' => 'string',
                'description' => 'Action: get (read meta), set (write/update meta), delete (remove meta)',
                'enum' => ['get', 'set', 'delete'],
                'required' => true,
            ],
            'meta_key' => [
                'type' => 'string',
                'description' => 'Specific meta key to read/write/delete. Leave empty with action "get" to retrieve all meta.',
            ],
            'meta_value' => [
                'type' => 'string',
                'description' => 'Value to set (required for action "set"). Will be auto-detected as string, number, or JSON.',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        $action = (string) ($params['action'] ?? '');
        $metaKey = (string) ($params['meta_key'] ?? '');
        $metaValue = $params['meta_value'] ?? null;

        if ($postId <= 0) {
            return ['success' => false, 'error' => 'Valid post_id is required.'];
        }

        $post = get_post($postId);
        if (!$post) {
            return ['success' => false, 'error' => 'Post not found.'];
        }

        if (!current_user_can('edit_post', $postId)) {
            return ['success' => false, 'error' => 'Permission denied for this post.'];
        }

        return match ($action) {
            'get' => $this->getMeta($postId, $metaKey),
            'set' => $this->setMeta($postId, $metaKey, $metaValue),
            'delete' => $this->deleteMeta($postId, $metaKey),
            default => ['success' => false, 'error' => 'Invalid action. Use: get, set, delete'],
        };
    }

    private function getMeta(int $postId, string $metaKey): array {
        if ($metaKey !== '') {
            $value = get_post_meta($postId, $metaKey, true);
            return [
                'success' => true,
                'post_id' => $postId,
                'meta_key' => $metaKey,
                'meta_value' => $value,
            ];
        }

        $allMeta = get_post_meta($postId);
        $filtered = [];
        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, '_edit_') || $key === '_encloseme' || $key === '_pingme') {
                continue;
            }
            $filtered[$key] = count($values) === 1 ? $values[0] : $values;
        }

        return [
            'success' => true,
            'post_id' => $postId,
            'post_type' => get_post_type($postId),
            'meta' => $filtered,
            'meta_count' => count($filtered),
        ];
    }

    private function setMeta(int $postId, string $metaKey, $metaValue): array {
        if ($metaKey === '') {
            return ['success' => false, 'error' => 'meta_key is required for set action.'];
        }

        if ($metaValue === null) {
            return ['success' => false, 'error' => 'meta_value is required for set action.'];
        }

        $protectedKeys = ['_wp_attached_file', '_wp_attachment_metadata'];
        if (in_array($metaKey, $protectedKeys, true)) {
            return ['success' => false, 'error' => 'This meta key is protected and cannot be modified.'];
        }

        $decoded = json_decode((string) $metaValue, true);
        $valueToStore = (json_last_error() === JSON_ERROR_NONE && $decoded !== null) ? $decoded : (string) $metaValue;

        $result = update_post_meta($postId, $metaKey, $valueToStore);

        // For WooCommerce: sync product data cache if relevant keys change
        $wcSyncKeys = ['_regular_price', '_sale_price', '_price', '_stock', '_stock_status', '_sku'];
        if (in_array($metaKey, $wcSyncKeys, true) && function_exists('wc_get_product')) {
            $product = wc_get_product($postId);
            if ($product) {
                // Trigger WC data sync
                if (in_array($metaKey, ['_regular_price', '_sale_price'], true)) {
                    $regular = get_post_meta($postId, '_regular_price', true);
                    $sale = get_post_meta($postId, '_sale_price', true);
                    $price = ($sale !== '' && $sale !== false) ? $sale : $regular;
                    update_post_meta($postId, '_price', $price);
                }
                clean_post_cache($postId);
            }
        }

        return [
            'success' => $result !== false,
            'post_id' => $postId,
            'meta_key' => $metaKey,
            'meta_value' => $valueToStore,
            'message' => $result !== false ? 'Meta updated.' : 'Meta unchanged (same value or error).',
        ];
    }

    private function deleteMeta(int $postId, string $metaKey): array {
        if ($metaKey === '') {
            return ['success' => false, 'error' => 'meta_key is required for delete action.'];
        }

        $existed = metadata_exists('post', $postId, $metaKey);
        $result = delete_post_meta($postId, $metaKey);

        return [
            'success' => $result || !$existed,
            'post_id' => $postId,
            'meta_key' => $metaKey,
            'message' => $existed ? 'Meta deleted.' : 'Meta key did not exist.',
        ];
    }
}
