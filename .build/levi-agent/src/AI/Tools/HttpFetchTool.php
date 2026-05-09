<?php

namespace Levi\Agent\AI\Tools;

class HttpFetchTool implements ToolInterface {

    private const MAX_RESPONSE_BYTES = 51200;
    private const TIMEOUT_SECONDS = 15;

    public function getName(): string {
        return 'http_fetch';
    }

    public function getDescription(): string {
        return 'Fetch a URL via HTTP GET from the same WordPress site. Useful to test frontend output, check if a page renders correctly, inspect REST API responses, or verify shortcode output. Use extract="styles" to get CSS custom properties, stylesheets and body classes for design-consistent styling. Only same-site requests are allowed.';
    }

    public function getParameters(): array {
        return [
            'url' => [
                'type' => 'string',
                'description' => 'Full URL or path on this site (e.g. "/shop/" or "/wp-json/wc/v3/products")',
                'required' => true,
            ],
            'method' => [
                'type' => 'string',
                'description' => 'HTTP method (default GET)',
                'enum' => ['GET', 'HEAD'],
            ],
            'extract' => [
                'type' => 'string',
                'description' => 'What to extract: "body" (default, cleaned HTML), "full" (full HTML), "title" (page title), "headers_only", "styles" (CSS custom properties, stylesheets, body classes — use before writing CSS to match existing design)',
                'enum' => ['full', 'body', 'title', 'headers_only', 'styles'],
            ],
        ];
    }

    public function getInputExamples(): array {
        return [
            ['url' => '/sample-page/'],
            ['url' => '/cart/', 'extract' => 'body'],
            ['url' => '/shop/', 'extract' => 'styles'],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $url = trim((string) ($params['url'] ?? ''));
        $method = strtoupper(trim((string) ($params['method'] ?? 'GET')));
        $extract = (string) ($params['extract'] ?? 'body');

        if ($url === '') {
            return ['success' => false, 'error' => 'URL is required.'];
        }

        if (!str_starts_with($url, 'http')) {
            $url = rtrim(home_url(), '/') . '/' . ltrim($url, '/');
        }

        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
        $requestHost = wp_parse_url($url, PHP_URL_HOST);

        if ($siteHost !== $requestHost) {
            return [
                'success' => false,
                'error' => "Only same-site requests allowed. Site: $siteHost, requested: $requestHost",
            ];
        }

        $args = [
            'timeout' => self::TIMEOUT_SECONDS,
            'sslverify' => false,
            'user-agent' => 'Levi-Agent/1.0',
        ];

        if ($method === 'HEAD') {
            $response = wp_remote_head($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $contentType = $headers['content-type'] ?? 'unknown';

        $result = [
            'success' => true,
            'status_code' => $statusCode,
            'content_type' => $contentType,
            'url' => $url,
        ];

        if ($method === 'HEAD' || $extract === 'headers_only') {
            $result['headers'] = $this->formatHeaders($headers);
            return $result;
        }

        $body = wp_remote_retrieve_body($response);

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if ($decoded !== null) {
                $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if (strlen($json) > self::MAX_RESPONSE_BYTES) {
                    $json = mb_substr($json, 0, self::MAX_RESPONSE_BYTES) . "\n... [truncated]";
                }
                $result['json'] = $decoded;
                return $result;
            }
        }

        if ($extract === 'title') {
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m);
            $result['title'] = $m[1] ?? null;
            $result['body_length'] = strlen($body);
            return $result;
        }

        if ($extract === 'styles') {
            return array_merge($result, $this->extractStyles($body));
        }

        if ($extract === 'body') {
            $body = $this->extractBody($body);
        }

        $body = $this->cleanHtml($body);

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $body = mb_substr($body, 0, self::MAX_RESPONSE_BYTES) . "\n... [truncated at " . self::MAX_RESPONSE_BYTES . " bytes]";
        }

        $result['body'] = $body;
        $result['body_length'] = strlen($body);

        $wcHints = $this->detectWcRenderingMode($body);
        if (!empty($wcHints)) {
            $result = array_merge($result, $wcHints);
        }

        if ($statusCode === 404) {
            $result['suggestion'] = 'Page may not exist. Use get_pages or get_posts to find available pages.';
        }

        return $result;
    }

    private function extractBody(string $html): string {
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
            return $m[1];
        }
        return $html;
    }

    private function cleanHtml(string $html): string {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = strip_tags($html, '<h1><h2><h3><h4><p><a><ul><ol><li><table><tr><td><th><div><span><form><input><select><option><img>');
        $html = preg_replace('/\s+/', ' ', $html);
        return trim($html);
    }

    private function extractStyles(string $html): array {
        $bodyClasses = [];
        if (preg_match('/<body[^>]*class=["\']([^"\']*)["\'][^>]*>/is', $html, $m)) {
            $bodyClasses = array_values(array_filter(preg_split('/\s+/', trim($m[1]))));
        }

        $stylesheets = [];
        if (preg_match_all('/<link[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/is', $html, $matches)) {
            $siteUrl = rtrim(home_url(), '/');
            foreach ($matches[1] as $href) {
                $path = $href;
                if (str_starts_with($path, $siteUrl)) {
                    $path = substr($path, strlen($siteUrl));
                }
                $path = preg_replace('/\?.*$/', '', $path);
                $stylesheets[] = $path;
            }
        }

        $cssVars = [];
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $styleBlocks)) {
            foreach ($styleBlocks[1] as $css) {
                if (preg_match_all('/(--[\w-]+)\s*:\s*([^;}{]+);/m', $css, $varMatches, PREG_SET_ORDER)) {
                    foreach ($varMatches as $match) {
                        $name = trim($match[1]);
                        $value = trim($match[2]);
                        if ($value !== '') {
                            $cssVars[$name] = $value;
                        }
                    }
                }
            }
        }

        $grouped = ['colors' => [], 'fonts' => [], 'spacing' => [], 'other' => []];
        foreach ($cssVars as $name => $value) {
            if (preg_match('/color|background|border-color|shadow/i', $name)) {
                $grouped['colors'][$name] = $value;
            } elseif (preg_match('/font|typography|letter-spacing|line-height/i', $name)) {
                $grouped['fonts'][$name] = $value;
            } elseif (preg_match('/spacing|padding|margin|gap|block-gap/i', $name)) {
                $grouped['spacing'][$name] = $value;
            } else {
                $grouped['other'][$name] = $value;
            }
        }
        $grouped = array_filter($grouped);

        return [
            'body_classes' => $bodyClasses,
            'stylesheets' => $stylesheets,
            'css_custom_properties' => $grouped,
            'total_custom_properties' => count($cssVars),
        ];
    }

    private function formatHeaders($headers): array {
        $result = [];
        if ($headers instanceof \Requests_Utility_CaseInsensitiveDictionary || is_iterable($headers)) {
            foreach ($headers as $key => $value) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Detect whether a WooCommerce page uses Block-based or Shortcode-based rendering.
     * Returns hints for the AI so it can choose the right approach (Custom Block vs PHP hooks).
     */
    private function detectWcRenderingMode(string $body): array {
        $hasBlockCart = str_contains($body, 'wp-block-woocommerce-cart')
            || str_contains($body, 'wc-block-cart');
        $hasBlockCheckout = str_contains($body, 'wp-block-woocommerce-checkout')
            || str_contains($body, 'wc-block-checkout');
        $hasClassicCart = str_contains($body, 'woocommerce-cart-form')
            || str_contains($body, '[woocommerce_cart]');
        $hasClassicCheckout = str_contains($body, 'woocommerce-checkout')
            && !$hasBlockCheckout;

        if (!$hasBlockCart && !$hasBlockCheckout && !$hasClassicCart && !$hasClassicCheckout) {
            return [];
        }

        $result = [];
        $blockPages = [];
        $classicPages = [];

        if ($hasBlockCart) {
            $blockPages[] = 'Cart';
        }
        if ($hasBlockCheckout) {
            $blockPages[] = 'Checkout';
        }
        if ($hasClassicCart) {
            $classicPages[] = 'Cart';
        }
        if ($hasClassicCheckout) {
            $classicPages[] = 'Checkout';
        }

        if (!empty($blockPages)) {
            $result['wc_rendering'] = 'block';
            $result['wc_block_pages'] = $blockPages;
            $result['wc_note'] = 'This page uses WooCommerce Blocks (' . implode(', ', $blockPages) . '). '
                . 'Classic PHP hooks (woocommerce_before_cart, woocommerce_after_cart_table, etc.) will NOT fire here. '
                . 'Use a Custom Block or JavaScript/DOM manipulation instead.';
        } elseif (!empty($classicPages)) {
            $result['wc_rendering'] = 'shortcode';
            $result['wc_classic_pages'] = $classicPages;
        }

        return $result;
    }
}
