<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;

class CreatePluginTool extends AbstractTool {

    use ValidatesSyntax;

    private const VALID_TYPES = ['plain', 'woocommerce', 'elementor', 'block', 'custom-post-type', 'shortcode'];
    private const VALID_FEATURES = ['admin-settings', 'frontend-css', 'frontend-js', 'rest-api'];

    public function getName(): string {
        return 'create_plugin';
    }

    public function getDescription(): string {
        return 'Create a new WordPress plugin scaffold with correct header, directory structure, and boilerplate. '
            . 'Use plugin_type to generate type-specific scaffolds: '
            . '"block" creates a Gutenberg block plugin with block.json, editor script, and server-side render template (no build step). '
            . '"custom-post-type" creates a CPT plugin with labels, rewrite rules, activation/deactivation hooks, and optional taxonomies. '
            . '"shortcode" creates a shortcode plugin with output buffering and optional frontend CSS. '
            . '"woocommerce" adds WC dependency check + settings section. "elementor" adds Elementor compatibility check. '
            . 'Use features to auto-generate admin settings, frontend assets, or REST API endpoints. '
            . 'Refuses if a plugin with the same slug already exists.';
    }

    public function getInputExamples(): array {
        return [
            ['slug' => 'my-simple-plugin', 'name' => 'My Simple Plugin', 'description' => 'A simple utility plugin'],
            ['slug' => 'wc-custom-shipping', 'name' => 'WC Custom Shipping', 'plugin_type' => 'woocommerce', 'features' => ['admin-settings']],
            ['slug' => 'elementor-fancy-widgets', 'name' => 'Fancy Widgets', 'plugin_type' => 'elementor', 'features' => ['frontend-css', 'frontend-js']],
            ['slug' => 'figur-produkte-block', 'name' => 'Figur Produkte Block', 'plugin_type' => 'block', 'description' => 'Shows WooCommerce products from "Figur" category', 'block_category' => 'woocommerce'],
            ['slug' => 'event-manager', 'name' => 'Event Manager', 'plugin_type' => 'custom-post-type', 'post_type_label_singular' => 'Event', 'post_type_label_plural' => 'Events', 'taxonomies' => [['slug' => 'event-category', 'label_singular' => 'Kategorie', 'label_plural' => 'Kategorien', 'hierarchical' => true]]],
            ['slug' => 'team-shortcode', 'name' => 'Team Shortcode', 'plugin_type' => 'shortcode', 'shortcode_tag' => 'team_members'],
        ];
    }

    public function getParameters(): array {
        return [
            'slug' => [
                'type' => 'string',
                'description' => 'Plugin slug, e.g. "my-custom-plugin"',
                'required' => true,
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Plugin display name',
                'required' => true,
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Short plugin description',
            ],
            'author' => [
                'type' => 'string',
                'description' => 'Plugin author',
            ],
            'version' => [
                'type' => 'string',
                'description' => 'Initial version',
                'default' => '0.1.0',
            ],
            'plugin_type' => [
                'type' => 'string',
                'description' => 'Plugin type: plain (default), woocommerce (adds WC dependency check + settings section), elementor (adds Elementor compatibility check)',
                'enum' => self::VALID_TYPES,
            ],
            'features' => [
                'type' => 'array',
                'description' => 'Optional features to scaffold: admin-settings, frontend-css, frontend-js, rest-api',
                'items' => ['type' => 'string'],
            ],
            'depends_on' => [
                'type' => 'array',
                'description' => 'Additional plugin dependencies (added to Requires Plugins header)',
                'items' => ['type' => 'string'],
            ],
            'activate' => [
                'type' => 'boolean',
                'description' => 'Activate plugin after creating files',
                'default' => false,
            ],
            'allow_wporg_slug_collision' => [
                'type' => 'boolean',
                'description' => 'Allow using a slug that already exists on wordpress.org',
                'default' => false,
            ],
            'block_title' => [
                'type' => 'string',
                'description' => 'Block display title in the editor inserter (only for plugin_type "block"). Defaults to plugin name.',
            ],
            'block_category' => [
                'type' => 'string',
                'description' => 'Block category in the editor inserter (only for plugin_type "block"). Common: text, media, design, widgets, theme, woocommerce. Default: widgets.',
            ],
            'post_type_slug' => [
                'type' => 'string',
                'description' => 'CPT slug for register_post_type (only for "custom-post-type"). Max 20 chars, no uppercase. Derived from plugin slug if omitted.',
            ],
            'post_type_label_singular' => [
                'type' => 'string',
                'description' => 'Singular label for the CPT (e.g. "Event"). Derived from plugin name if omitted.',
            ],
            'post_type_label_plural' => [
                'type' => 'string',
                'description' => 'Plural label for the CPT (e.g. "Events"). Derived from singular + "s" if omitted.',
            ],
            'post_type_supports' => [
                'type' => 'array',
                'description' => 'CPT supports array. Default: ["title", "editor", "thumbnail", "excerpt"]. Options: title, editor, thumbnail, excerpt, author, comments, custom-fields, revisions, page-attributes.',
                'items' => ['type' => 'string'],
            ],
            'taxonomies' => [
                'type' => 'array',
                'description' => 'Taxonomies to register with the CPT. Each item: {slug, label_singular, label_plural, hierarchical (true=categories, false=tags)}.',
                'items' => ['type' => 'object'],
            ],
            'shortcode_tag' => [
                'type' => 'string',
                'description' => 'Shortcode tag name (only for "shortcode"). Derived from plugin slug if omitted. Use underscores, e.g. "my_shortcode".',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('activate_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['slug'] ?? '');
        $name = sanitize_text_field($params['name'] ?? '');
        $description = sanitize_text_field($params['description'] ?? 'Generated by Levi Agent');
        $author = sanitize_text_field($params['author'] ?? wp_get_current_user()->display_name);
        $version = sanitize_text_field($params['version'] ?? '0.1.0');
        $pluginType = (string) ($params['plugin_type'] ?? 'plain');
        $features = (array) ($params['features'] ?? []);
        $dependsOn = (array) ($params['depends_on'] ?? []);
        $activateDefault = in_array($pluginType, ['block', 'custom-post-type'], true);
        $activate = (bool) ($params['activate'] ?? $activateDefault);
        $allowWpOrgSlugCollision = (bool) ($params['allow_wporg_slug_collision'] ?? false);

        if ($slug === '' || $name === '') {
            return ['success' => false, 'error' => 'Both slug and name are required.'];
        }

        if (!in_array($pluginType, self::VALID_TYPES, true)) {
            $pluginType = 'plain';
        }

        $features = array_intersect($features, self::VALID_FEATURES);

        if ($pluginType === 'woocommerce' && !in_array('woocommerce', $dependsOn, true)) {
            $dependsOn[] = 'woocommerce';
        }
        if ($pluginType === 'elementor' && !in_array('elementor', $dependsOn, true)) {
            $dependsOn[] = 'elementor';
        }

        if (!$allowWpOrgSlugCollision) {
            $collision = $this->checkWpOrgSlugCollision($slug);
            if ($collision !== null) {
                return $collision;
            }
        }

        $pluginDir = trailingslashit(WP_PLUGIN_DIR) . $slug;
        $mainFile = $pluginDir . '/' . $slug . '.php';
        $pluginBasename = $slug . '/' . $slug . '.php';

        $filesystem = $this->getFilesystem();
        if ($filesystem === null) {
            return ['success' => false, 'error' => 'WordPress filesystem is not available.'];
        }

        if ($filesystem->exists($mainFile)) {
            return [
                'success' => false,
                'error' => "A plugin with slug '$slug' already exists. Choose a different slug. "
                    . "To modify the existing plugin, use write_plugin_file or patch_plugin_file.",
                'existing_plugin' => $pluginBasename,
            ];
        }

        // --- Build directory structure ---
        $dirs = [$pluginDir];
        if ($pluginType === 'block') {
            $dirs[] = $pluginDir . '/src';
        }
        if ($pluginType === 'shortcode') {
            $dirs[] = $pluginDir . '/assets';
            $dirs[] = $pluginDir . '/assets/css';
        }
        if (in_array('frontend-css', $features) || in_array('frontend-js', $features)) {
            $dirs[] = $pluginDir . '/assets';
            if (in_array('frontend-css', $features)) {
                $dirs[] = $pluginDir . '/assets/css';
            }
            if (in_array('frontend-js', $features)) {
                $dirs[] = $pluginDir . '/assets/js';
            }
        }
        if (in_array('admin-settings', $features) || in_array('rest-api', $features)) {
            $dirs[] = $pluginDir . '/includes';
        }

        foreach ($dirs as $dir) {
            if (!$filesystem->is_dir($dir) && !$filesystem->mkdir($dir, FS_CHMOD_DIR, true)) {
                return ['success' => false, 'error' => "Could not create directory: $dir"];
            }
        }

        // --- Generate files ---
        $createdFiles = [];

        $header = $this->buildHeader($slug, $name, $description, $author, $version, $dependsOn);
        $body = $this->buildMainFileBody($slug, $name, $version, $pluginType, $features, $params);
        $mainContent = $header . $body;

        if (!$filesystem->put_contents($mainFile, $mainContent, FS_CHMOD_FILE)) {
            return ['success' => false, 'error' => 'Could not write main plugin file.'];
        }
        $createdFiles[] = $slug . '.php';

        $lint = $this->validatePhpSyntax($mainFile);
        if (($lint['valid'] ?? false) !== true) {
            $this->cleanupDir($filesystem, $pluginDir);
            return [
                'success' => false,
                'error' => 'Create reverted: PHP syntax error in main file. ' . ($lint['error'] ?? ''),
                'suggestion' => 'This is likely a scaffold generation bug. Try creating with fewer features or report the issue.',
            ];
        }

        if (in_array('frontend-css', $features)) {
            $cssContent = "/* {$name} – Frontend Styles */\n";
            $cssPath = $pluginDir . '/assets/css/' . $slug . '.css';
            $filesystem->put_contents($cssPath, $cssContent, FS_CHMOD_FILE);
            $createdFiles[] = 'assets/css/' . $slug . '.css';
        }

        if (in_array('frontend-js', $features)) {
            $jsContent = $this->buildFrontendJs($slug);
            $jsPath = $pluginDir . '/assets/js/' . $slug . '.js';
            $filesystem->put_contents($jsPath, $jsContent, FS_CHMOD_FILE);
            $createdFiles[] = 'assets/js/' . $slug . '.js';
        }

        if (in_array('admin-settings', $features)) {
            $settingsContent = $this->buildSettingsFile($slug, $name, $pluginType);
            $settingsPath = $pluginDir . '/includes/class-settings.php';
            $filesystem->put_contents($settingsPath, $settingsContent, FS_CHMOD_FILE);
            $createdFiles[] = 'includes/class-settings.php';

            $settingsLint = $this->validatePhpSyntax($settingsPath);
            if (($settingsLint['valid'] ?? false) !== true) {
                $this->cleanupDir($filesystem, $pluginDir);
                return [
                    'success' => false,
                    'error' => 'Create reverted: PHP syntax error in settings file. ' . ($settingsLint['error'] ?? ''),
                ];
            }
        }

        if (in_array('rest-api', $features)) {
            $restContent = $this->buildRestApiFile($slug, $name);
            $restPath = $pluginDir . '/includes/class-rest-api.php';
            $filesystem->put_contents($restPath, $restContent, FS_CHMOD_FILE);
            $createdFiles[] = 'includes/class-rest-api.php';

            $restLint = $this->validatePhpSyntax($restPath);
            if (($restLint['valid'] ?? false) !== true) {
                $this->cleanupDir($filesystem, $pluginDir);
                return [
                    'success' => false,
                    'error' => 'Create reverted: PHP syntax error in REST API file. ' . ($restLint['error'] ?? ''),
                ];
            }
        }

        if ($pluginType === 'block') {
            $blockTitle = sanitize_text_field($params['block_title'] ?? $name);
            $blockCategory = sanitize_text_field($params['block_category'] ?? 'widgets');
            $blockFiles = $this->buildBlockFiles($slug, $blockTitle, $description, $blockCategory);
            foreach ($blockFiles as $relativePath => $content) {
                $fullPath = $pluginDir . '/' . $relativePath;
                $filesystem->put_contents($fullPath, $content, FS_CHMOD_FILE);
                $createdFiles[] = $relativePath;
            }
            $renderPhpPath = $pluginDir . '/src/render.php';
            if ($filesystem->exists($renderPhpPath)) {
                $renderLint = $this->validatePhpSyntax($renderPhpPath);
                if (($renderLint['valid'] ?? false) !== true) {
                    $this->cleanupDir($filesystem, $pluginDir);
                    return [
                        'success' => false,
                        'error' => 'Create reverted: PHP syntax error in block render.php. ' . ($renderLint['error'] ?? ''),
                    ];
                }
            }
        }

        if ($pluginType === 'custom-post-type') {
            $uninstallContent = $this->buildUninstallFile($slug, $params);
            $uninstallPath = $pluginDir . '/uninstall.php';
            $filesystem->put_contents($uninstallPath, $uninstallContent, FS_CHMOD_FILE);
            $createdFiles[] = 'uninstall.php';

            $uninstallLint = $this->validatePhpSyntax($uninstallPath);
            if (($uninstallLint['valid'] ?? false) !== true) {
                $this->cleanupDir($filesystem, $pluginDir);
                return [
                    'success' => false,
                    'error' => 'Create reverted: PHP syntax error in uninstall.php. ' . ($uninstallLint['error'] ?? ''),
                ];
            }
        }

        if ($pluginType === 'shortcode') {
            $cssContent = "/* {$name} – Shortcode Styles */\n.shortcode-" . str_replace('-', '_', sanitize_key($params['shortcode_tag'] ?? str_replace('-', '_', $slug))) . " {\n    /* Add your styles here */\n}\n";
            $cssPath = $pluginDir . '/assets/css/' . $slug . '.css';
            $filesystem->put_contents($cssPath, $cssContent, FS_CHMOD_FILE);
            $createdFiles[] = 'assets/css/' . $slug . '.css';
        }

        $readme = "# {$name}\n\n{$description}\n";
        $filesystem->put_contents($pluginDir . '/README.md', $readme, FS_CHMOD_FILE);
        $createdFiles[] = 'README.md';

        wp_cache_delete('plugins', 'plugins');

        $activated = false;
        if ($activate) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $activationResult = activate_plugin($pluginBasename);
            if (is_wp_error($activationResult)) {
                return [
                    'success' => false,
                    'error' => 'Plugin created but activation failed: ' . $activationResult->get_error_message(),
                    'plugin_file' => $pluginBasename,
                    'created_files' => $createdFiles,
                ];
            }
            $activated = true;
        }

        $verify = [
            ['type' => 'file_exists', 'path' => $mainFile, 'expected' => true],
        ];
        if ($activated) {
            $verify[] = ['type' => 'plugin_active', 'plugin_file' => $pluginBasename, 'expected_active' => true];
        }

        $msgSuffix = match ($pluginType) {
            'block' => " Edit src/render.php for the block's HTML output. The block appears in the editor under its category.",
            'custom-post-type' => " The CPT is registered and rewrite rules are flushed on activation. Edit the main file to add meta boxes or template overrides.",
            'shortcode' => " Use the shortcode in any post or page. Edit the main file to customize the output.",
            default => '',
        };
        $result = [
            'success' => true,
            'slug' => $slug,
            'plugin_file' => $pluginBasename,
            'path' => $mainFile,
            'plugin_type' => $pluginType,
            'features' => $features,
            'created_files' => $createdFiles,
            'activated' => $activated,
            'message' => ($activated
                ? "Plugin scaffold created and activated ({$pluginType}, features: " . implode(', ', $features ?: ['none']) . ').'
                : "Plugin scaffold created ({$pluginType}, features: " . implode(', ', $features ?: ['none']) . ').') . $msgSuffix,
            '_verify' => $verify,
        ];

        if (!empty($lint['warning'])) {
            $result['warning'] = $lint['warning'];
        }

        return $result;
    }

    // ── Header builder ───────────────────────────────────────────────────

    private function buildHeader(
        string $slug,
        string $name,
        string $description,
        string $author,
        string $version,
        array $dependsOn
    ): string {
        $lines = [
            "<?php",
            "/**",
            " * Plugin Name: {$name}",
            " * Description: {$description}",
            " * Version: {$version}",
            " * Author: {$author}",
            " * Text Domain: {$slug}",
            " * Update URI: false",
        ];

        if (!empty($dependsOn)) {
            $lines[] = " * Requires Plugins: " . implode(', ', array_unique($dependsOn));
        }

        $lines[] = " */";
        $lines[] = "";

        return implode("\n", $lines) . "\n";
    }

    // ── Main file body builder ───────────────────────────────────────────

    private function buildMainFileBody(
        string $slug,
        string $name,
        string $version,
        string $pluginType,
        array $features,
        array $params = []
    ): string {
        $constPrefix = strtoupper(str_replace('-', '_', $slug));
        $lines = [];

        $lines[] = "if (!defined('ABSPATH')) {";
        $lines[] = "    exit;";
        $lines[] = "}";
        $lines[] = "";
        $lines[] = "define('{$constPrefix}_FILE', __FILE__);";
        $lines[] = "define('{$constPrefix}_VERSION', '{$version}');";
        $lines[] = "define('{$constPrefix}_DIR', plugin_dir_path(__FILE__));";
        $lines[] = "define('{$constPrefix}_URL', plugin_dir_url(__FILE__));";
        $lines[] = "";

        if ($pluginType === 'woocommerce') {
            $lines = array_merge($lines, $this->buildWcDependencyCheck());
        } elseif ($pluginType === 'elementor') {
            $lines = array_merge($lines, $this->buildElementorDependencyCheck());
        } elseif ($pluginType === 'block') {
            $lines[] = "add_action('init', function () {";
            $lines[] = "    register_block_type({$constPrefix}_DIR . 'src');";
            $lines[] = "});";
            $lines[] = "";
            return implode("\n", $lines);
        } elseif ($pluginType === 'custom-post-type') {
            return implode("\n", $lines) . $this->buildCptMainBody($slug, $name, $constPrefix, $params);
        } elseif ($pluginType === 'shortcode') {
            return implode("\n", $lines) . $this->buildShortcodeBody($slug, $name, $constPrefix, $params);
        }

        if (in_array('admin-settings', $features)) {
            $lines[] = "require_once {$constPrefix}_DIR . 'includes/class-settings.php';";
        }
        if (in_array('rest-api', $features)) {
            $lines[] = "require_once {$constPrefix}_DIR . 'includes/class-rest-api.php';";
        }
        if (in_array('admin-settings', $features) || in_array('rest-api', $features)) {
            $lines[] = "";
        }

        if (in_array('frontend-css', $features) || in_array('frontend-js', $features)) {
            $lines[] = "add_action('wp_enqueue_scripts', function () {";
            if (in_array('frontend-css', $features)) {
                $lines[] = "    wp_enqueue_style('{$slug}', {$constPrefix}_URL . 'assets/css/{$slug}.css', [], {$constPrefix}_VERSION);";
            }
            if (in_array('frontend-js', $features)) {
                $lines[] = "    wp_enqueue_script('{$slug}', {$constPrefix}_URL . 'assets/js/{$slug}.js', [], {$constPrefix}_VERSION, true);";
            }
            $lines[] = "});";
            $lines[] = "";
        }

        $lines[] = "add_action('init', function () {";
        $lines[] = "    // Plugin bootstrapped by Levi Agent.";
        $lines[] = "});";
        $lines[] = "";

        return implode("\n", $lines);
    }

    private function buildWcDependencyCheck(): array {
        return [
            "add_action('before_woocommerce_init', function () {",
            "    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {",
            "        \\Automattic\\WooCommerce\\Utilities\\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__);",
            "    }",
            "});",
            "",
            "add_action('plugins_loaded', function () {",
            "    if (!class_exists('WooCommerce')) {",
            "        add_action('admin_notices', function () {",
            "            echo '<div class=\"notice notice-error\"><p><strong>' . esc_html(get_file_data(__FILE__, ['Plugin Name'])[0]) . '</strong> requires WooCommerce.</p></div>';",
            "        });",
            "        return;",
            "    }",
            "});",
            "",
        ];
    }

    private function buildElementorDependencyCheck(): array {
        return [
            "add_action('plugins_loaded', function () {",
            "    if (!did_action('elementor/loaded')) {",
            "        add_action('admin_notices', function () {",
            "            echo '<div class=\"notice notice-error\"><p><strong>' . esc_html(get_file_data(__FILE__, ['Plugin Name'])[0]) . '</strong> requires Elementor.</p></div>';",
            "        });",
            "        return;",
            "    }",
            "});",
            "",
        ];
    }

    // ── Feature file builders ────────────────────────────────────────────

    private function buildFrontendJs(string $slug): string {
        $lines = [
            "'use strict';",
            "",
            "(function () {",
            "    document.addEventListener('DOMContentLoaded', function () {",
            "        // {$slug} frontend logic",
            "    });",
            "})();",
            "",
        ];
        return implode("\n", $lines);
    }

    private function buildSettingsFile(string $slug, string $name, string $pluginType): string {
        $constPrefix = strtoupper(str_replace('-', '_', $slug));
        $optionGroup = str_replace('-', '_', $slug);

        if ($pluginType === 'woocommerce') {
            return $this->buildWcSettingsFile($slug, $name, $constPrefix, $optionGroup);
        }

        $lines = [
            "<?php",
            "",
            "if (!defined('ABSPATH')) {",
            "    exit;",
            "}",
            "",
            "add_action('admin_menu', function () {",
            "    add_options_page(",
            "        '" . addslashes($name) . "',",
            "        '" . addslashes($name) . "',",
            "        'manage_options',",
            "        '{$slug}',",
            "        '{$optionGroup}_render_settings_page'",
            "    );",
            "});",
            "",
            "add_action('admin_init', function () {",
            "    register_setting('{$optionGroup}_settings', '{$optionGroup}_options');",
            "",
            "    add_settings_section(",
            "        '{$optionGroup}_main',",
            "        __('Settings', '{$slug}'),",
            "        '__return_null',",
            "        '{$slug}'",
            "    );",
            "",
            "    add_settings_field(",
            "        '{$optionGroup}_enabled',",
            "        __('Enabled', '{$slug}'),",
            "        function () {",
            "            \$options = get_option('{$optionGroup}_options', []);",
            "            \$checked = !empty(\$options['enabled']) ? 'checked' : '';",
            "            echo '<input type=\"checkbox\" name=\"{$optionGroup}_options[enabled]\" value=\"1\" ' . \$checked . ' />';",
            "        },",
            "        '{$slug}',",
            "        '{$optionGroup}_main'",
            "    );",
            "});",
            "",
            "function {$optionGroup}_render_settings_page() {",
            "    echo '<div class=\"wrap\">';",
            "    echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';",
            "    echo '<form method=\"post\" action=\"options.php\">';",
            "    settings_fields('{$optionGroup}_settings');",
            "    do_settings_sections('{$slug}');",
            "    submit_button();",
            "    echo '</form>';",
            "    echo '</div>';",
            "}",
            "",
        ];

        return implode("\n", $lines);
    }

    private function buildWcSettingsFile(string $slug, string $name, string $constPrefix, string $optionGroup): string {
        $sectionId = str_replace('-', '_', $slug);
        $lines = [
            "<?php",
            "",
            "if (!defined('ABSPATH')) {",
            "    exit;",
            "}",
            "",
            "add_filter('woocommerce_get_sections_products', function (\$sections) {",
            "    \$sections['{$sectionId}'] = __('" . addslashes($name) . "', '{$slug}');",
            "    return \$sections;",
            "});",
            "",
            "add_filter('woocommerce_get_settings_products', function (\$settings, \$current_section) {",
            "    if (\$current_section !== '{$sectionId}') {",
            "        return \$settings;",
            "    }",
            "",
            "    return [",
            "        [",
            "            'title' => __('" . addslashes($name) . " Settings', '{$slug}'),",
            "            'type'  => 'title',",
            "            'id'    => '{$sectionId}_options',",
            "        ],",
            "        [",
            "            'title'   => __('Enable', '{$slug}'),",
            "            'id'      => '{$sectionId}_enabled',",
            "            'type'    => 'checkbox',",
            "            'default' => 'yes',",
            "        ],",
            "        [",
            "            'type' => 'sectionend',",
            "            'id'   => '{$sectionId}_options',",
            "        ],",
            "    ];",
            "}, 10, 2);",
            "",
        ];

        return implode("\n", $lines);
    }

    private function buildRestApiFile(string $slug, string $name): string {
        $namespace = str_replace('-', '_', $slug);
        $lines = [
            "<?php",
            "",
            "if (!defined('ABSPATH')) {",
            "    exit;",
            "}",
            "",
            "add_action('rest_api_init', function () {",
            "    register_rest_route('{$slug}/v1', '/status', [",
            "        'methods'             => 'GET',",
            "        'callback'            => '{$namespace}_rest_status',",
            "        'permission_callback' => '__return_true',",
            "    ]);",
            "});",
            "",
            "function {$namespace}_rest_status() {",
            "    return new WP_REST_Response([",
            "        'plugin'  => '{$slug}',",
            "        'status'  => 'active',",
            "        'version' => defined('" . strtoupper($namespace) . "_VERSION') ? " . strtoupper($namespace) . "_VERSION : '0.0.0',",
            "    ], 200);",
            "}",
            "",
        ];

        return implode("\n", $lines);
    }

    // ── Block scaffold builder ────────────────────────────────────────────

    /**
     * @return array<string, string> Relative path => file content
     */
    private function buildBlockFiles(string $slug, string $blockTitle, string $description, string $blockCategory): array {
        $blockName = str_replace('-', '-', $slug);
        $namespace = explode('-', $slug, 2);
        $blockFullName = (count($namespace) >= 2)
            ? ($namespace[0] . '/' . implode('-', array_slice($namespace, 1)))
            : ($slug . '/' . $slug . '-block');

        $blockJson = json_encode([
            '$schema' => 'https://schemas.wp.org/trunk/block.json',
            'apiVersion' => 3,
            'name' => $blockFullName,
            'version' => '1.0.0',
            'title' => $blockTitle,
            'category' => $blockCategory,
            'icon' => 'block-default',
            'description' => $description,
            'supports' => [
                'html' => false,
                'align' => ['wide', 'full'],
            ],
            'textdomain' => $slug,
            'editorScript' => 'file:./index.js',
            'editorStyle' => 'file:./editor.css',
            'style' => 'file:./style.css',
            'render' => 'file:./render.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $editorJs = <<<JS
( function( blocks, element, serverSideRender ) {
    var el = element.createElement;

    blocks.registerBlockType( '{$blockFullName}', {
        edit: function( props ) {
            return el(
                serverSideRender,
                {
                    block: '{$blockFullName}',
                    attributes: props.attributes
                }
            );
        }
    } );
} )(
    window.wp.blocks,
    window.wp.element,
    window.wp.serverSideRender
);
JS;

        $assetPhp = <<<'PHP'
<?php
return [
    'dependencies' => ['wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-components', 'wp-block-editor', 'wp-data'],
    'version'      => '1.0.0',
];
PHP;

        $renderPhp = <<<PHP
<?php
/**
 * Server-side rendering for the {$blockFullName} block.
 *
 * Available variables:
 * \$attributes (array) - Block attributes
 * \$content    (string) - Inner block content
 * \$block      (WP_Block) - Block instance
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
    <p><?php echo esc_html( '{$blockTitle}' ); ?> – edit src/render.php to customize output.</p>
</div>
PHP;

        $styleCss = "/* {$blockTitle} – Frontend Styles */\n";
        $editorCss = "/* {$blockTitle} – Editor Styles */\n";

        return [
            'src/block.json'       => $blockJson,
            'src/index.js'         => $editorJs . "\n",
            'src/index.asset.php'  => $assetPhp . "\n",
            'src/render.php'       => $renderPhp . "\n",
            'src/style.css'        => $styleCss,
            'src/editor.css'       => $editorCss,
        ];
    }

    // ── Custom Post Type scaffold builder ─────────────────────────────────

    private function buildCptMainBody(string $slug, string $name, string $constPrefix, array $params): string {
        $cptSlug = sanitize_key($params['post_type_slug'] ?? str_replace('-', '_', $slug));
        $cptSlug = substr($cptSlug, 0, 20);
        $singular = sanitize_text_field($params['post_type_label_singular'] ?? $name);
        $plural = sanitize_text_field($params['post_type_label_plural'] ?? $singular . 's');
        $supports = (array) ($params['post_type_supports'] ?? ['title', 'editor', 'thumbnail', 'excerpt']);
        $taxonomies = (array) ($params['taxonomies'] ?? []);
        $funcPrefix = str_replace('-', '_', $slug);

        $supportsStr = "['" . implode("', '", $supports) . "']";

        $lines = [];
        $lines[] = "add_action('init', '{$funcPrefix}_register_post_type');";
        $lines[] = "";
        $lines[] = "function {$funcPrefix}_register_post_type() {";
        $lines[] = "    \$labels = [";
        $lines[] = "        'name'                  => __('{$plural}', '{$slug}'),";
        $lines[] = "        'singular_name'          => __('{$singular}', '{$slug}'),";
        $lines[] = "        'menu_name'              => __('{$plural}', '{$slug}'),";
        $lines[] = "        'name_admin_bar'         => __('{$singular}', '{$slug}'),";
        $lines[] = "        'add_new'                => __('Hinzufuegen', '{$slug}'),";
        $lines[] = "        'add_new_item'           => __('{$singular} hinzufuegen', '{$slug}'),";
        $lines[] = "        'new_item'               => __('Neue/r {$singular}', '{$slug}'),";
        $lines[] = "        'edit_item'              => __('{$singular} bearbeiten', '{$slug}'),";
        $lines[] = "        'view_item'              => __('{$singular} ansehen', '{$slug}'),";
        $lines[] = "        'all_items'              => __('Alle {$plural}', '{$slug}'),";
        $lines[] = "        'search_items'           => __('{$plural} suchen', '{$slug}'),";
        $lines[] = "        'not_found'              => __('Keine {$plural} gefunden.', '{$slug}'),";
        $lines[] = "        'not_found_in_trash'     => __('Keine {$plural} im Papierkorb.', '{$slug}'),";
        $lines[] = "        'featured_image'         => __('{$singular}-Bild', '{$slug}'),";
        $lines[] = "        'set_featured_image'     => __('{$singular}-Bild festlegen', '{$slug}'),";
        $lines[] = "        'remove_featured_image'  => __('{$singular}-Bild entfernen', '{$slug}'),";
        $lines[] = "    ];";
        $lines[] = "";
        $taxSlugs = [];
        foreach ($taxonomies as $tax) {
            if (empty($tax['slug'])) {
                continue;
            }
            $taxSlug = sanitize_key($tax['slug']);
            $taxSlugs[] = $taxSlug;
            $taxSingular = sanitize_text_field($tax['label_singular'] ?? ucfirst($taxSlug));
            $taxPlural = sanitize_text_field($tax['label_plural'] ?? $taxSingular . 's');
            $hierarchical = !empty($tax['hierarchical']) ? 'true' : 'false';

            $lines[] = "    register_taxonomy('{$taxSlug}', '{$cptSlug}', [";
            $lines[] = "        'labels' => [";
            $lines[] = "            'name'              => __('{$taxPlural}', '{$slug}'),";
            $lines[] = "            'singular_name'     => __('{$taxSingular}', '{$slug}'),";
            $lines[] = "            'search_items'      => __('{$taxPlural} suchen', '{$slug}'),";
            $lines[] = "            'all_items'         => __('Alle {$taxPlural}', '{$slug}'),";
            $lines[] = "            'edit_item'         => __('{$taxSingular} bearbeiten', '{$slug}'),";
            $lines[] = "            'update_item'       => __('{$taxSingular} aktualisieren', '{$slug}'),";
            $lines[] = "            'add_new_item'      => __('Neue/n {$taxSingular} hinzufuegen', '{$slug}'),";
            $lines[] = "            'new_item_name'     => __('Neuer {$taxSingular}-Name', '{$slug}'),";
            $lines[] = "            'menu_name'         => __('{$taxPlural}', '{$slug}'),";
            $lines[] = "        ],";
            $lines[] = "        'hierarchical'      => {$hierarchical},";
            $lines[] = "        'public'            => true,";
            $lines[] = "        'show_in_rest'      => true,";
            $lines[] = "        'show_admin_column' => true,";
            $lines[] = "        'rewrite'           => ['slug' => '{$taxSlug}'],";
            $lines[] = "    ]);";
            $lines[] = "";
        }

        $taxonomiesParam = !empty($taxSlugs) ? "        'taxonomies'         => ['" . implode("', '", $taxSlugs) . "']," : '';
        $lines[] = "    register_post_type('{$cptSlug}', [";
        $lines[] = "        'labels'             => \$labels,";
        $lines[] = "        'public'             => true,";
        $lines[] = "        'has_archive'        => true,";
        $lines[] = "        'show_in_rest'       => true,";
        $lines[] = "        'supports'           => {$supportsStr},";
        $lines[] = "        'menu_icon'          => 'dashicons-admin-post',";
        $lines[] = "        'rewrite'            => ['slug' => '{$cptSlug}', 'with_front' => false],";
        $lines[] = "        'capability_type'    => 'post',";
        $lines[] = "        'map_meta_cap'       => true,";
        if ($taxonomiesParam !== '') {
            $lines[] = $taxonomiesParam;
        }
        $lines[] = "    ]);";

        $lines[] = "}";
        $lines[] = "";
        $lines[] = "register_activation_hook({$constPrefix}_FILE, function () {";
        $lines[] = "    {$funcPrefix}_register_post_type();";
        $lines[] = "    flush_rewrite_rules();";
        $lines[] = "});";
        $lines[] = "";
        $lines[] = "register_deactivation_hook({$constPrefix}_FILE, function () {";
        $lines[] = "    flush_rewrite_rules();";
        $lines[] = "});";
        $lines[] = "";

        return implode("\n", $lines);
    }

    private function buildUninstallFile(string $slug, array $params): string {
        $cptSlug = sanitize_key($params['post_type_slug'] ?? str_replace('-', '_', $slug));
        $cptSlug = substr($cptSlug, 0, 20);

        $lines = [
            "<?php",
            "",
            "if (!defined('WP_UNINSTALL_PLUGIN')) {",
            "    exit;",
            "}",
            "",
            "\$posts = get_posts([",
            "    'post_type'      => '{$cptSlug}',",
            "    'posts_per_page' => -1,",
            "    'post_status'    => 'any',",
            "    'fields'         => 'ids',",
            "]);",
            "",
            "foreach (\$posts as \$post_id) {",
            "    wp_delete_post(\$post_id, true);",
            "}",
            "",
            "flush_rewrite_rules();",
            "",
        ];

        return implode("\n", $lines);
    }

    // ── Shortcode scaffold builder ──────────────────────────────────────

    private function buildShortcodeBody(string $slug, string $name, string $constPrefix, array $params): string {
        $tag = sanitize_key($params['shortcode_tag'] ?? str_replace('-', '_', $slug));
        $funcPrefix = str_replace('-', '_', $slug);

        $lines = [];
        $lines[] = "add_shortcode('{$tag}', '{$funcPrefix}_shortcode_render');";
        $lines[] = "";
        $lines[] = "/**";
        $lines[] = " * Shortcode: [{$tag}]";
        $lines[] = " *";
        $lines[] = " * Usage: [{$tag}] or [{$tag} attr=\"value\"]Inhalt[/{$tag}]";
        $lines[] = " */";
        $lines[] = "function {$funcPrefix}_shortcode_render(\$atts, \$content = null) {";
        $lines[] = "    \$atts = shortcode_atts([";
        $lines[] = "        'class' => '',";
        $lines[] = "    ], \$atts, '{$tag}');";
        $lines[] = "";
        $lines[] = "    \$css_class = 'shortcode-{$tag}';";
        $lines[] = "    if (!empty(\$atts['class'])) {";
        $lines[] = "        \$css_class .= ' ' . sanitize_html_class(\$atts['class']);";
        $lines[] = "    }";
        $lines[] = "";
        $lines[] = "    ob_start();";
        $lines[] = "    ?>";
        $lines[] = "    <div class=\"<?php echo esc_attr(\$css_class); ?>\">";
        $lines[] = "        <p><?php echo esc_html('{$name}'); ?> – edit the shortcode callback to customize output.</p>";
        $lines[] = "        <?php if (\$content) : ?>";
        $lines[] = "            <div class=\"{$tag}-content\"><?php echo wp_kses_post(do_shortcode(\$content)); ?></div>";
        $lines[] = "        <?php endif; ?>";
        $lines[] = "    </div>";
        $lines[] = "    <?php";
        $lines[] = "    return ob_get_clean();";
        $lines[] = "}";
        $lines[] = "";
        $lines[] = "add_action('wp_enqueue_scripts', function () {";
        $lines[] = "    global \$post;";
        $lines[] = "    if (is_a(\$post, 'WP_Post') && has_shortcode(\$post->post_content, '{$tag}')) {";
        $lines[] = "        wp_enqueue_style('{$slug}', {$constPrefix}_URL . 'assets/css/{$slug}.css', [], {$constPrefix}_VERSION);";
        $lines[] = "    }";
        $lines[] = "});";
        $lines[] = "";

        return implode("\n", $lines);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function checkWpOrgSlugCollision(string $slug): ?array {
        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        $api = plugins_api('plugin_information', [
            'slug' => $slug,
            'fields' => ['sections' => false],
        ]);
        if (!is_wp_error($api) && is_object($api) && !empty($api->name)) {
            return [
                'success' => false,
                'error' => "Slug '{$slug}' already exists on wordpress.org. Choose a unique slug (e.g. your-brand-{$slug}) or set allow_wporg_slug_collision=true.",
                'conflicting_slug' => $slug,
            ];
        }
        return null;
    }

    private function cleanupDir(\WP_Filesystem_Base $filesystem, string $dir): void {
        if ($filesystem->is_dir($dir)) {
            $filesystem->delete($dir, true);
        }
    }
}
