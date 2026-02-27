<?php

namespace Levi\Agent\AI\Tools;

class ManageTaxonomyTool implements ToolInterface {

    public function getName(): string {
        return 'manage_taxonomy';
    }

    public function getDescription(): string {
        return 'Query, create, or assign taxonomy terms (categories, tags, product categories, etc.). Actions: "get_terms" lists terms of a taxonomy, "get_post_terms" gets terms assigned to a specific post, "set_post_terms" assigns terms to a post, "create_term" creates a new term.';
    }

    public function getParameters(): array {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Action to perform: get_terms, get_post_terms, set_post_terms, create_term',
                'enum' => ['get_terms', 'get_post_terms', 'set_post_terms', 'create_term'],
                'required' => true,
            ],
            'taxonomy' => [
                'type' => 'string',
                'description' => 'Taxonomy slug (e.g. category, post_tag, product_cat, product_tag). Use discover_content_types to find available taxonomies.',
                'required' => true,
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID (required for get_post_terms and set_post_terms)',
            ],
            'terms' => [
                'type' => 'array',
                'description' => 'Term IDs or names to assign (for set_post_terms). Can be IDs (integers) or names (strings).',
                'items' => ['type' => 'string'],
            ],
            'append' => [
                'type' => 'boolean',
                'description' => 'For set_post_terms: true = add to existing terms, false = replace all terms. Default: false',
                'default' => false,
            ],
            'term_name' => [
                'type' => 'string',
                'description' => 'Name of the new term (for create_term)',
            ],
            'parent_term' => [
                'type' => 'integer',
                'description' => 'Parent term ID for hierarchical taxonomies (for create_term)',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search string to filter terms (for get_terms)',
            ],
            'hide_empty' => [
                'type' => 'boolean',
                'description' => 'Only return terms with posts assigned (for get_terms). Default: false',
                'default' => false,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function execute(array $params): array {
        $action = (string) ($params['action'] ?? '');
        $taxonomy = sanitize_key($params['taxonomy'] ?? '');

        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => "Taxonomy '$taxonomy' does not exist. Use discover_content_types to see available taxonomies."];
        }

        return match ($action) {
            'get_terms' => $this->getTerms($taxonomy, $params),
            'get_post_terms' => $this->getPostTerms($taxonomy, $params),
            'set_post_terms' => $this->setPostTerms($taxonomy, $params),
            'create_term' => $this->createTerm($taxonomy, $params),
            default => ['success' => false, 'error' => 'Invalid action.'],
        };
    }

    private function getTerms(string $taxonomy, array $params): array {
        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => (bool) ($params['hide_empty'] ?? false),
            'number' => 200,
            'orderby' => 'name',
            'order' => 'ASC',
        ];

        if (!empty($params['search'])) {
            $args['search'] = sanitize_text_field($params['search']);
        }

        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return ['success' => false, 'error' => $terms->get_error_message()];
        }

        $formatted = array_map(function ($term) {
            return [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description ?: null,
                'parent' => $term->parent,
                'count' => $term->count,
            ];
        }, $terms);

        return [
            'success' => true,
            'taxonomy' => $taxonomy,
            'terms' => array_values($formatted),
            'total' => count($formatted),
        ];
    }

    private function getPostTerms(string $taxonomy, array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required for get_post_terms.'];
        }

        $terms = wp_get_object_terms($postId, $taxonomy);
        if (is_wp_error($terms)) {
            return ['success' => false, 'error' => $terms->get_error_message()];
        }

        $formatted = array_map(function ($term) {
            return [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => $term->parent,
            ];
        }, $terms);

        return [
            'success' => true,
            'post_id' => $postId,
            'taxonomy' => $taxonomy,
            'terms' => array_values($formatted),
        ];
    }

    private function setPostTerms(string $taxonomy, array $params): array {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'post_id is required.'];
        }

        if (!current_user_can('edit_post', $postId)) {
            return ['success' => false, 'error' => 'Permission denied for this post.'];
        }

        $rawTerms = $params['terms'] ?? [];
        if (!is_array($rawTerms) || empty($rawTerms)) {
            return ['success' => false, 'error' => 'terms array is required.'];
        }

        $append = (bool) ($params['append'] ?? false);

        // Resolve term names to IDs, create if needed
        $termIds = [];
        foreach ($rawTerms as $term) {
            if (is_numeric($term)) {
                $termIds[] = (int) $term;
            } else {
                $existing = get_term_by('name', (string) $term, $taxonomy);
                if ($existing) {
                    $termIds[] = $existing->term_id;
                } else {
                    $new = wp_insert_term((string) $term, $taxonomy);
                    if (!is_wp_error($new)) {
                        $termIds[] = $new['term_id'];
                    }
                }
            }
        }

        $result = wp_set_object_terms($postId, $termIds, $taxonomy, $append);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        $currentTerms = wp_get_object_terms($postId, $taxonomy);
        $names = is_array($currentTerms) ? array_map(fn($t) => $t->name, $currentTerms) : [];

        return [
            'success' => true,
            'post_id' => $postId,
            'taxonomy' => $taxonomy,
            'assigned_terms' => $names,
            'message' => $append ? 'Terms added.' : 'Terms set.',
        ];
    }

    private function createTerm(string $taxonomy, array $params): array {
        $name = sanitize_text_field($params['term_name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'term_name is required.'];
        }

        if (!current_user_can('manage_categories')) {
            return ['success' => false, 'error' => 'Permission denied to create terms.'];
        }

        $args = [];
        if (!empty($params['parent_term'])) {
            $args['parent'] = (int) $params['parent_term'];
        }

        $result = wp_insert_term($name, $taxonomy, $args);
        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'term_exists') {
                $existingId = (int) $result->get_error_data();
                $existing = get_term($existingId, $taxonomy);
                return [
                    'success' => true,
                    'term_id' => $existingId,
                    'name' => $existing ? $existing->name : $name,
                    'message' => 'Term already exists.',
                    'already_existed' => true,
                ];
            }
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'term_id' => $result['term_id'],
            'name' => $name,
            'taxonomy' => $taxonomy,
            'message' => 'Term created.',
        ];
    }
}
