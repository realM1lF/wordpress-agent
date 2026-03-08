<?php

namespace Levi\Agent\AI;

/**
 * Expands user queries into multiple search queries for better vector retrieval.
 * Bridges the gap between German conversational queries and English technical docs.
 *
 * Flow: detect domain → extract concepts → build focused English search queries.
 * The original query is always included; expanded queries are added on top.
 */
class QueryExpander {

    private const MAX_EXPANDED = 3;

    private const DOMAIN_TRIGGERS = [
        'woocommerce' => ['woocommerce', 'woo ', 'produkt', 'shop', 'warenkorb', 'bestellung', 'versand', 'zahlung', 'gutschein', 'coupon'],
        'elementor'   => ['elementor'],
        'wordpress'   => ['wordpress', 'theme', 'plugin', 'block', 'gutenberg', 'seite', 'beitrag', 'widget', 'shortcode', 'hook', 'filter', 'action', 'cron', 'rest api'],
    ];

    /**
     * German terms (or partial stems) → English technical equivalents.
     * Compound patterns are checked first, then single-word patterns.
     */
    private const COMPOUND_CONCEPTS = [
        'sales-badge'    => 'sale badge',
        'sale-badge'     => 'sale badge',
        'sale badge'     => 'sale badge',
        'product-box'    => 'product loop item card',
        'block-theme'    => 'block theme FSE full-site-editing',
        'block theme'    => 'block theme FSE full-site-editing',
        'rest api'       => 'REST API endpoint',
        'custom post'    => 'custom post type',
        'full site'      => 'full site editing FSE',
        'post type'      => 'post type',
        'single product' => 'single product page',
        'product page'   => 'single product page template',
    ];

    private const WORD_CONCEPTS = [
        // Sale & pricing
        'sale'       => 'sale',
        'angebot'    => 'sale on-sale',
        'reduziert'  => 'sale discount reduced',
        'badge'      => 'badge label',
        'rabatt'     => 'discount',
        'prozent'    => 'percentage',
        'spar'       => 'savings percentage discount',
        'preis'      => 'price',
        'regulär'    => 'regular price',
        // Product
        'produkt'    => 'product',
        'listing'    => 'product listing archive',
        'archiv'     => 'archive product catalog',
        'shop'       => 'shop page archive',
        'loop'       => 'product loop',
        // Cart / Checkout / Order
        'warenkorb'  => 'cart',
        'kasse'      => 'checkout',
        'checkout'   => 'checkout',
        'bestellung' => 'order',
        'versand'    => 'shipping',
        'zahlung'    => 'payment gateway',
        'gutschein'  => 'coupon',
        // WP concepts
        'plugin'     => 'plugin development',
        'theme'      => 'theme',
        'block'      => 'block',
        'template'   => 'template',
        'hook'       => 'hook action filter',
        'filter'     => 'filter hook',
        'einstellung'=> 'settings options API',
        'widget'     => 'widget',
        'menü'       => 'menu navigation',
        'shortcode'  => 'shortcode',
        'cron'       => 'cron wp_schedule_event',
        'taxonomy'   => 'taxonomy',
        'taxonomie'  => 'taxonomy',
        'meta'       => 'meta metadata',
        // Visual / CSS
        'farbe'      => 'color',
        'hintergrund'=> 'background',
        'textfarbe'  => 'text color',
        'css'        => 'CSS styling',
        'responsive' => 'responsive mobile',
        // Elementor
        'control'    => 'control',
        'dynamic'    => 'dynamic tag',
    ];

    /**
     * Domain-specific technical query patterns.
     * When certain concepts are detected together with a domain,
     * these generate highly targeted search queries.
     */
    private const DOMAIN_QUERY_PATTERNS = [
        'woocommerce' => [
            ['triggers' => ['sale', 'badge', 'discount', 'on-sale'], 'query' => 'WooCommerce sale badge filter hook product on-sale discount'],
            ['triggers' => ['product', 'block', 'loop', 'listing', 'archive', 'catalog'], 'query' => 'WooCommerce product collection block template extensibility'],
            ['triggers' => ['cart', 'checkout'], 'query' => 'WooCommerce cart checkout blocks extensibility filters'],
            ['triggers' => ['settings', 'options', 'color', 'background'], 'query' => 'WooCommerce settings API add custom settings fields color'],
            ['triggers' => ['payment', 'gateway'], 'query' => 'WooCommerce payment gateway development'],
            ['triggers' => ['shipping'], 'query' => 'WooCommerce shipping method zones'],
            ['triggers' => ['order'], 'query' => 'WooCommerce order HPOS custom order tables'],
            ['triggers' => ['coupon'], 'query' => 'WooCommerce coupon programmatically'],
            ['triggers' => ['price'], 'query' => 'WooCommerce price display formatting filter'],
            ['triggers' => ['product', 'template', 'page'], 'query' => 'WooCommerce single product template override'],
        ],
        'elementor' => [
            ['triggers' => ['widget'], 'query' => 'Elementor custom widget development register'],
            ['triggers' => ['control'], 'query' => 'Elementor controls add_control types'],
            ['triggers' => ['template'], 'query' => 'Elementor template library conditions'],
            ['triggers' => ['hook', 'action', 'filter'], 'query' => 'Elementor hooks actions filters developer'],
        ],
        'wordpress' => [
            ['triggers' => ['block', 'FSE', 'full-site-editing'], 'query' => 'WordPress block theme full site editing templates'],
            ['triggers' => ['plugin', 'development'], 'query' => 'WordPress plugin development hooks actions filters'],
            ['triggers' => ['REST', 'API', 'endpoint'], 'query' => 'WordPress REST API custom endpoint register'],
            ['triggers' => ['cron'], 'query' => 'WordPress cron wp_schedule_event custom intervals'],
            ['triggers' => ['taxonomy'], 'query' => 'WordPress custom taxonomy register'],
            ['triggers' => ['menu', 'navigation'], 'query' => 'WordPress navigation menu register walker'],
        ],
    ];

    /**
     * @return string[] Original query + up to MAX_EXPANDED additional English queries.
     */
    public function expand(string $query): array {
        $queries = [$query];
        $lower = mb_strtolower(trim($query));

        $domains = $this->detectDomains($lower);
        if (empty($domains)) {
            return $queries;
        }

        $concepts = $this->extractConcepts($lower);
        if (empty($concepts)) {
            return $queries;
        }

        $expanded = $this->buildSearchQueries($domains, $concepts);

        return array_values(array_unique(array_merge($queries, $expanded)));
    }

    /**
     * @return string[] Detected domain names (e.g. ['woocommerce', 'wordpress'])
     */
    private function detectDomains(string $lower): array {
        $domains = [];
        foreach (self::DOMAIN_TRIGGERS as $domain => $triggers) {
            foreach ($triggers as $trigger) {
                if (str_contains($lower, $trigger)) {
                    $domains[] = $domain;
                    break;
                }
            }
        }
        // WordPress is always implicit when any other domain is detected
        if (!empty($domains) && !in_array('wordpress', $domains, true)) {
            $domains[] = 'wordpress';
        }
        return $domains;
    }

    /**
     * @return string[] English concept strings extracted from German input
     */
    private function extractConcepts(string $lower): array {
        $concepts = [];

        foreach (self::COMPOUND_CONCEPTS as $pattern => $english) {
            if (str_contains($lower, $pattern)) {
                $concepts[] = $english;
            }
        }

        foreach (self::WORD_CONCEPTS as $german => $english) {
            if (str_contains($lower, $german)) {
                $concepts[] = $english;
            }
        }

        return array_values(array_unique($concepts));
    }

    /**
     * Build focused English search queries from domains + concepts.
     *
     * Strategy 1: Domain-specific pattern queries (most precise).
     * Strategy 2: Domain + combined concepts (broader fallback).
     */
    private function buildSearchQueries(array $domains, array $concepts): array {
        $queries = [];
        $conceptStr = implode(' ', $concepts);

        // Strategy 1: domain-specific patterns
        foreach ($domains as $domain) {
            foreach (self::DOMAIN_QUERY_PATTERNS[$domain] ?? [] as $pattern) {
                foreach ($pattern['triggers'] as $trigger) {
                    if (str_contains($conceptStr, $trigger)) {
                        $queries[] = $pattern['query'];
                        break;
                    }
                }
            }
        }

        // Strategy 2: combined domain + concepts (fallback if patterns didn't fire enough)
        if (count($queries) < 2) {
            $primaryDomain = $domains[0] ?? 'WordPress';
            $label = ucfirst($primaryDomain);
            $topConcepts = implode(' ', array_slice($concepts, 0, 4));
            $queries[] = $label . ' ' . $topConcepts;
        }

        // Deduplicate and cap
        return array_values(array_unique(array_slice($queries, 0, self::MAX_EXPANDED)));
    }
}
