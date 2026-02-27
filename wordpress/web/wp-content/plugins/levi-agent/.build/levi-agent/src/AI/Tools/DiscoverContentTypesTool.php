<?php

namespace Levi\Agent\AI\Tools;

class DiscoverContentTypesTool implements ToolInterface {

    public function getName(): string {
        return 'discover_content_types';
    }

    public function getDescription(): string {
        return 'Discover all registered WordPress post types and taxonomies on this site. Use this to find out what content types are available (e.g. products, orders, events) and their associated taxonomies (e.g. product categories, tags). Essential first step when working with any plugin data.';
    }

    public function getParameters(): array {
        return [
            'include_builtin' => [
                'type' => 'boolean',
                'description' => 'Include WordPress built-in types (post, page, attachment). Default: true',
                'default' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        $includeBuiltin = (bool) ($params['include_builtin'] ?? true);

        $postTypes = get_post_types(['public' => true], 'objects');
        $postTypeData = [];

        foreach ($postTypes as $slug => $typeObj) {
            if (!$includeBuiltin && in_array($slug, ['post', 'page', 'attachment'], true)) {
                continue;
            }

            $taxonomies = get_object_taxonomies($slug, 'objects');
            $taxList = [];
            foreach ($taxonomies as $taxSlug => $taxObj) {
                if (!$taxObj->public) {
                    continue;
                }
                $termCount = wp_count_terms(['taxonomy' => $taxSlug, 'hide_empty' => false]);
                $taxList[] = [
                    'slug' => $taxSlug,
                    'label' => $taxObj->label,
                    'hierarchical' => $taxObj->hierarchical,
                    'term_count' => is_wp_error($termCount) ? 0 : (int) $termCount,
                ];
            }

            $count = wp_count_posts($slug);
            $totalPublished = (int) ($count->publish ?? 0);
            $totalDraft = (int) ($count->draft ?? 0);
            $totalAll = 0;
            foreach ((array) $count as $status => $c) {
                $totalAll += (int) $c;
            }

            $postTypeData[] = [
                'slug' => $slug,
                'label' => $typeObj->label,
                'label_singular' => $typeObj->labels->singular_name ?? $typeObj->label,
                'description' => $typeObj->description ?: null,
                'hierarchical' => $typeObj->hierarchical,
                'has_archive' => (bool) $typeObj->has_archive,
                'supports' => get_all_post_type_supports($slug),
                'count' => [
                    'total' => $totalAll,
                    'published' => $totalPublished,
                    'draft' => $totalDraft,
                ],
                'taxonomies' => $taxList,
                'rest_base' => $typeObj->rest_base ?: $slug,
            ];
        }

        $nonPublicTypes = get_post_types(['public' => false, '_builtin' => false], 'objects');
        $internalTypes = [];
        foreach ($nonPublicTypes as $slug => $typeObj) {
            if (str_starts_with($slug, 'wp_') || str_starts_with($slug, 'oembed_')) {
                continue;
            }
            $internalTypes[] = [
                'slug' => $slug,
                'label' => $typeObj->label,
            ];
        }

        return [
            'success' => true,
            'post_types' => $postTypeData,
            'internal_types' => $internalTypes,
            'hint' => 'Use get_posts with post_type parameter to query any type. Use get_post_meta to read/write metadata. Use manage_taxonomy for categories/tags.',
        ];
    }
}
