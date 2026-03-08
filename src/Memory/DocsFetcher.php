<?php

namespace Levi\Agent\Memory;

/**
 * Fetches developer documentation from official sources and stores them locally.
 * Replaces the external Python scripts so docs stay current on any server.
 */
class DocsFetcher {
    private const DOCS_DIR_NAME = 'levi-agent/memories';
    private const OPTION_KEY = 'levi_docs_fetch_meta';
    private const REQUEST_TIMEOUT = 15;

    private const WC_LLMS_URL = 'https://developer.woocommerce.com/docs/llms-full.txt';
    private const WC_REST_API_URL = 'https://woocommerce.github.io/woocommerce-rest-api-docs/#introduction';
    private const WC_REST_API_MAX_CHARS = 150000;

    private const ELEMENTOR_LLMS_URL = 'https://elementor.com/llms.txt';
    private const ELEMENTOR_GITHUB_TREE_URL = 'https://api.github.com/repos/elementor/elementor-developers-docs/git/trees/master?recursive=1';
    private const ELEMENTOR_RAW_BASE = 'https://raw.githubusercontent.com/elementor/elementor-developers-docs/master';

    private const WP_DOC_URLS = [
        'https://developer.wordpress.org/block-editor/?output_format=md',
        'https://developer.wordpress.org/block-editor/getting-started/?output_format=md',
        'https://developer.wordpress.org/block-editor/getting-started/devenv/?output_format=md',
        'https://developer.wordpress.org/block-editor/getting-started/quick-start-guide/?output_format=md',
        'https://developer.wordpress.org/block-editor/getting-started/fundamentals/?output_format=md',
        'https://developer.wordpress.org/block-editor/getting-started/faq/?output_format=md',
        'https://developer.wordpress.org/block-editor/how-to-guides/block-api/?output_format=md',
        'https://developer.wordpress.org/block-editor/reference-guides/block-api/?output_format=md',
        'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/?output_format=md',
        'https://developer.wordpress.org/block-editor/explanations/architecture/key-concepts/?output_format=md',
        'https://developer.wordpress.org/block-editor/explanations/architecture/data-flow/?output_format=md',
        'https://developer.wordpress.org/themes/?output_format=md',
        'https://developer.wordpress.org/themes/getting-started/?output_format=md',
        'https://developer.wordpress.org/themes/getting-started/what-is-a-theme/?output_format=md',
        'https://developer.wordpress.org/themes/getting-started/tools-and-setup/?output_format=md',
        'https://developer.wordpress.org/themes/getting-started/quick-start-guide/?output_format=md',
        'https://developer.wordpress.org/themes/core-concepts/?output_format=md',
        'https://developer.wordpress.org/themes/basics/?output_format=md',
        'https://developer.wordpress.org/themes/advanced-topics/?output_format=md',
        'https://developer.wordpress.org/themes/getting-started/theme-security/?output_format=md',
        'https://developer.wordpress.org/rest-api/?output_format=md',
        'https://developer.wordpress.org/rest-api/key-concepts/?output_format=md',
        'https://developer.wordpress.org/rest-api/using-the-rest-api/?output_format=md',
        'https://developer.wordpress.org/rest-api/extending-the-rest-api/?output_format=md',
        'https://developer.wordpress.org/rest-api/reference/?output_format=md',
        'https://developer.wordpress.org/rest-api/authentication/?output_format=md',
        'https://developer.wordpress.org/apis/?output_format=md',
        'https://developer.wordpress.org/apis/hooks/?output_format=md',
        'https://developer.wordpress.org/apis/hooks/action-reference/?output_format=md',
        'https://developer.wordpress.org/apis/hooks/filter-reference/?output_format=md',
        'https://developer.wordpress.org/apis/options/?output_format=md',
        'https://developer.wordpress.org/apis/settings/?output_format=md',
        'https://developer.wordpress.org/advanced-administration/?output_format=md',
        'https://developer.wordpress.org/advanced-administration/before-install/?output_format=md',
        'https://developer.wordpress.org/advanced-administration/upgrade/?output_format=md',
        'https://developer.wordpress.org/advanced-administration/debug/?output_format=md',
        'https://developer.wordpress.org/coding-standards/?output_format=md',
        'https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/?output_format=md',
        'https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/?output_format=md',
        'https://developer.wordpress.org/cli/commands/?output_format=md',
        'https://developer.wordpress.org/cli/commands/core/?output_format=md',
        'https://developer.wordpress.org/cli/commands/core/download/?output_format=md',
        'https://developer.wordpress.org/cli/commands/db/?output_format=md',
    ];

    public static function getDocsDirectory(): string {
        $uploadDir = wp_upload_dir();
        return $uploadDir['basedir'] . '/' . self::DOCS_DIR_NAME;
    }

    /**
     * Fetch all documentation sources. Each source is independent - failures
     * in one source don't prevent others from completing.
     */
    public function fetchAll(): array {
        @set_time_limit(600);

        $docsDir = self::getDocsDirectory();
        if (!is_dir($docsDir)) {
            wp_mkdir_p($docsDir);
        }
        if (!is_dir($docsDir) || !is_writable($docsDir)) {
            $result = [
                'fetched_at' => current_time('mysql'),
                'status' => 'error',
                'error' => 'Cannot create or write to docs directory: ' . $docsDir,
            ];
            update_option(self::OPTION_KEY, $result, false);
            return $result;
        }

        $sources = [];
        $sources['woocommerce'] = $this->fetchWooCommerceDocs($docsDir);
        $sources['wordpress'] = $this->fetchWordPressDocs($docsDir);
        $sources['elementor'] = $this->fetchElementorDocs($docsDir);

        $hasErrors = false;
        foreach ($sources as $source) {
            if (!empty($source['error'])) {
                $hasErrors = true;
            }
        }

        $result = [
            'fetched_at' => current_time('mysql'),
            'status' => $hasErrors ? 'partial' : 'success',
            'sources' => $sources,
        ];
        update_option(self::OPTION_KEY, $result, false);
        return $result;
    }

    public static function getLastFetchMeta(): array {
        $meta = get_option(self::OPTION_KEY, []);
        return is_array($meta) ? $meta : [];
    }

    private function fetchWooCommerceDocs(string $docsDir): array {
        $outputFile = $docsDir . '/woocommerce-llm-developer.txt';

        $response = wp_remote_get(self::WC_LLMS_URL, [
            'timeout' => 60,
            'headers' => ['User-Agent' => 'levi-agent-docs-fetcher/1.0'],
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'llms-full.txt fetch failed: ' . $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        if (strlen($body) < 1000) {
            return ['error' => 'llms-full.txt too small (' . strlen($body) . ' chars)'];
        }

        $content = $body;

        $restSection = $this->fetchWcRestApiDocs();
        if ($restSection !== '') {
            $content .= $restSection;
        }

        $written = file_put_contents($outputFile, $content);
        if ($written === false) {
            return ['error' => 'Could not write to ' . $outputFile];
        }

        return ['chars' => strlen($content), 'file' => basename($outputFile)];
    }

    private function fetchWcRestApiDocs(): string {
        $response = wp_remote_get(self::WC_REST_API_URL, [
            'timeout' => 30,
            'headers' => ['User-Agent' => 'levi-agent-docs-fetcher/1.0'],
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $html = wp_remote_retrieve_body($response);
        $text = $this->stripHtml($html);

        if (strlen($text) > self::WC_REST_API_MAX_CHARS) {
            $text = substr($text, 0, self::WC_REST_API_MAX_CHARS)
                . "\n\n... [truncated at " . number_format(self::WC_REST_API_MAX_CHARS) . " chars, full docs: " . self::WC_REST_API_URL . "]";
        }

        return "\n\n---\n\n# WooCommerce REST API Reference\nSource: " . self::WC_REST_API_URL . "\n\n" . $text;
    }

    private function fetchWordPressDocs(string $docsDir): array {
        $outputFile = $docsDir . '/wordpress-lllm-developer.txt';
        $parts = [];
        $failed = 0;

        foreach (self::WP_DOC_URLS as $url) {
            $response = wp_remote_get($url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => ['User-Agent' => 'levi-agent-docs-fetcher/1.0'],
            ]);

            if (is_wp_error($response)) {
                $failed++;
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $clean = $this->cleanContent($body);

            if (strlen($clean) < 100) {
                continue;
            }

            $parts[] = "\n\n---\nurl: {$url}\n---\n\n{$clean}";
        }

        if (empty($parts)) {
            return ['error' => 'No WordPress docs fetched (' . $failed . ' failures)'];
        }

        $content = "# WordPress Developer Documentation – LLM Reference\n\n"
            . "Aggregated from developer.wordpress.org.\n"
            . "Excludes: Plugin Handbook.\n\n"
            . "Use this as reference for WordPress development: Block Editor, Themes, REST API, "
            . "Common APIs (Hooks/Filters), Advanced Administration, Coding Standards, WP-CLI.\n"
            . implode('', $parts);

        $written = file_put_contents($outputFile, $content);
        if ($written === false) {
            return ['error' => 'Could not write to ' . $outputFile];
        }

        return [
            'chars' => strlen($content),
            'file' => basename($outputFile),
            'pages' => count($parts),
            'failed' => $failed,
        ];
    }

    private function fetchElementorDocs(string $docsDir): array {
        $outputFile = $docsDir . '/elementor-llm-developer.txt';

        // 1. Fetch llms.txt
        $llmsContent = '';
        $response = wp_remote_get(self::ELEMENTOR_LLMS_URL, [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => ['User-Agent' => 'levi-agent-docs-fetcher/1.0'],
        ]);
        if (!is_wp_error($response)) {
            $llmsContent = $this->cleanContent(wp_remote_retrieve_body($response));
        }

        // 2. Fetch GitHub tree
        $response = wp_remote_get(self::ELEMENTOR_GITHUB_TREE_URL, [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => ['User-Agent' => 'levi-agent-docs-fetcher/1.0'],
        ]);

        if (is_wp_error($response)) {
            if ($llmsContent === '') {
                return ['error' => 'Elementor fetch failed: ' . $response->get_error_message()];
            }
            // At least save llms.txt
            $content = $this->buildElementorOutput($llmsContent, []);
            file_put_contents($outputFile, $content);
            return ['chars' => strlen($content), 'file' => basename($outputFile), 'pages' => 0, 'note' => 'GitHub tree failed, llms.txt only'];
        }

        $treeData = json_decode(wp_remote_retrieve_body($response), true);
        $docUrls = [];
        foreach (($treeData['tree'] ?? []) as $node) {
            $path = $node['path'] ?? '';
            if (str_ends_with($path, '.md') && str_starts_with($path, 'src/') && !str_contains($path, 'README')) {
                $docUrls[] = [
                    'url' => self::ELEMENTOR_RAW_BASE . '/' . $path,
                    'path' => substr($path, 4, -3),
                ];
            }
        }

        // 3. Fetch individual doc files
        $parts = [];
        $failed = 0;
        foreach ($docUrls as $doc) {
            $response = wp_remote_get($doc['url'], [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => ['User-Agent' => 'levi-agent-docs-fetcher/1.0'],
            ]);

            if (is_wp_error($response)) {
                $failed++;
                continue;
            }

            $clean = $this->cleanContent(wp_remote_retrieve_body($response));
            if (strlen($clean) < 30) {
                continue;
            }

            $parts[] = "\n\n---\ndoc: {$doc['path']}\n---\n\n{$clean}";
            usleep(100000); // 100ms delay between requests
        }

        $content = $this->buildElementorOutput($llmsContent, $parts);
        $written = file_put_contents($outputFile, $content);
        if ($written === false) {
            return ['error' => 'Could not write to ' . $outputFile];
        }

        return [
            'chars' => strlen($content),
            'file' => basename($outputFile),
            'pages' => count($parts),
            'failed' => $failed,
        ];
    }

    private function buildElementorOutput(string $llmsContent, array $parts): string {
        $header = "# Elementor Developer Documentation – LLM Reference\n\n"
            . "Aggregated from elementor.com/llms.txt and GitHub (elementor/elementor-developers-docs).\n"
            . "Use for Elementor addons, widgets, controls, hooks, forms, themes, CLI.\n";

        $llmsSection = '';
        if ($llmsContent !== '') {
            $llmsSection = "\n\n---\n# Elementor Product Overview (elementor.com/llms.txt)\n---\n\n{$llmsContent}\n";
        }

        return $header . $llmsSection . implode('', $parts);
    }

    private function stripHtml(string $html): string {
        $text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $text = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $text);
        $text = preg_replace('/<!--.*?-->/s', '', $text);
        $text = preg_replace_callback('/<(h[1-6])[^>]*>/i', function ($m) {
            return "\n\n" . str_repeat('#', (int)$m[1][1]) . ' ';
        }, $text);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<li[^>]*>/i', "\n- ", $text);
        $text = preg_replace('/<pre[^>]*>/i', "\n```\n", $text);
        $text = preg_replace('/<\/pre>/i', "\n```\n", $text);
        $text = preg_replace('/<[^>]+>/', '', $text);
        $text = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;'], [' ', '&', '<', '>'], $text);
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
        return trim($text);
    }

    private function cleanContent(string $text): string {
        $text = preg_replace('/\[ Back to top\]\([^)]+\)/', '', $text);
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
        return trim($text);
    }
}
