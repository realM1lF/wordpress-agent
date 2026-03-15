<?php

namespace Levi\Agent\API\Concerns;

trait PostProcessesToolResults {

    /**
     * After write tools, auto-inject a read_error_log check so the AI sees any PHP errors
     * it may have caused. Returns additional messages to append to the conversation.
     */
    private function injectPostWriteValidation(array $toolCalls, array $toolResults): array {
        $hadWrite = false;
        foreach ($toolCalls as $tc) {
            $name = $tc['function']['name'] ?? '';
            if ($this->isWriteTool($name)) {
                $matchingResult = null;
                foreach ($toolResults as $tr) {
                    if ($tr['tool'] === $name && ($tr['result']['success'] ?? false)) {
                        $matchingResult = $tr;
                        break;
                    }
                }
                if ($matchingResult) {
                    $hadWrite = true;
                    break;
                }
            }
        }

        if (!$hadWrite) {
            return [];
        }

        $errorLogResult = $this->toolRegistry->execute('read_error_log', [
            'lines' => 30,
            'filter' => 'Fatal|Warning|Deprecated|Parse error',
        ]);

        $validationMessages = [];

        $fakeToolCallId = 'auto_validation_' . bin2hex(random_bytes(8));

        $validationMessages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => $fakeToolCallId,
                'type' => 'function',
                'function' => [
                    'name' => 'read_error_log',
                    'arguments' => json_encode(['lines' => 30, 'filter' => 'Fatal']),
                ],
            ]],
        ];

        $hasErrors = !empty($errorLogResult['lines']);
        $content = $this->compactToolResultForModel($errorLogResult);

        if ($hasErrors) {
            $content .= "\n\n[SYSTEM] ACHTUNG: Nach deiner letzten Code-Aenderung wurden PHP-Fehler im Error-Log gefunden. "
                . "Analysiere die Fehler und behebe sie sofort, bevor du dem Kunden antwortest.";
        }

        $validationMessages[] = [
            'role' => 'tool',
            'tool_call_id' => $fakeToolCallId,
            'content' => $content,
        ];

        return $validationMessages;
    }

    /**
     * After CSS/JS file writes, nudge the LLM to verify frontend output.
     * If http_fetch is available: auto-fetch the shop page and inject the HTML.
     * If not: inject a system message reminding Levi to ask the user to check.
     */
    private function injectPostCSSWriteNudge(array $toolCalls, array $toolResults): array {
        $cssWritten = false;
        foreach ($toolResults as $tr) {
            if (!($tr['result']['success'] ?? false)) {
                continue;
            }
            $tool = $tr['tool'] ?? '';
            if (!in_array($tool, ['write_plugin_file', 'patch_plugin_file', 'write_theme_file'], true)) {
                continue;
            }
            $path = $tr['result']['path'] ?? $tr['result']['relative_path'] ?? '';
            if ($path === '') {
                $args = [];
                foreach ($toolCalls as $tc) {
                    if (($tc['function']['name'] ?? '') === $tool) {
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        break;
                    }
                }
                $path = $args['relative_path'] ?? '';
            }
            if (preg_match('/\.(css|js|scss|less)$/i', $path)) {
                $cssWritten = true;
                break;
            }
        }

        if (!$cssWritten) {
            return [];
        }

        $httpFetchTool = $this->toolRegistry->get('http_fetch');

        if ($httpFetchTool && $httpFetchTool->checkPermission()) {
            $shopUrl = '/shop/';
            if (function_exists('wc_get_page_id')) {
                $shopPageId = wc_get_page_id('shop');
                if ($shopPageId > 0) {
                    $shopUrl = get_permalink($shopPageId) ?: '/shop/';
                }
            }

            $fetchResult = $httpFetchTool->execute([
                'url' => $shopUrl,
                'extract' => 'body',
            ]);

            $fakeToolCallId = 'auto_css_verify_' . bin2hex(random_bytes(8));

            return [
                [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $fakeToolCallId,
                        'type' => 'function',
                        'function' => [
                            'name' => 'http_fetch',
                            'arguments' => json_encode(['url' => $shopUrl, 'extract' => 'body']),
                        ],
                    ]],
                ],
                [
                    'role' => 'tool',
                    'tool_call_id' => $fakeToolCallId,
                    'content' => $this->compactToolResultForModel($fetchResult)
                        . "\n\n[SYSTEM] Du hast gerade eine CSS/JS-Datei geschrieben. Oben siehst du den HTML-Quelltext der Shop-Seite. "
                        . "Pruefe die tatsaechliche DOM-Struktur: Stimmen deine CSS-Selektoren mit den echten Klassen ueberein? "
                        . "Hat das Parent-Element von absolut positionierten Elementen ein position:relative? "
                        . "Falls nicht, korrigiere dein CSS sofort.",
                ],
            ];
        }

        return [
            [
                'role' => 'system',
                'content' => '[SYSTEM] Du hast eine CSS/JS-Datei geschrieben, aber http_fetch ist nicht verfuegbar. '
                    . 'Weise den Kunden darauf hin, dass er die Aenderung im Frontend pruefen soll. '
                    . 'Nenne ihm die relevante Seite (z.B. /shop/) und worauf er achten soll (Badge-Positionierung, Farben, etc.).',
            ],
        ];
    }

    /**
     * After writing a PHP file for a plugin, activate it and fetch a frontend page
     * to detect runtime errors (ArgumentCountError, Fatal, etc.) that php -l cannot catch.
     * If errors are found, deactivate the plugin and inject the error for the LLM to fix.
     */
    private function injectPostPluginSmokeTest(array $toolCalls, array $toolResults): array {
        $pluginSlug = null;

        foreach ($toolResults as $tr) {
            if (!($tr['result']['success'] ?? false)) {
                continue;
            }
            $tool = $tr['tool'] ?? '';
            if (!in_array($tool, ['write_plugin_file', 'patch_plugin_file'], true)) {
                continue;
            }
            $path = $tr['result']['relative_path'] ?? '';
            if ($path === '') {
                foreach ($toolCalls as $tc) {
                    if (($tc['function']['name'] ?? '') === $tool) {
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        $path = $args['relative_path'] ?? '';
                        break;
                    }
                }
            }
            if (preg_match('/\.php$/i', $path)) {
                $pluginSlug = $tr['result']['plugin_slug']
                    ?? $tr['result']['slug']
                    ?? null;
                if ($pluginSlug === null) {
                    foreach ($toolCalls as $tc) {
                        if (($tc['function']['name'] ?? '') === $tool) {
                            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                            $pluginSlug = $args['plugin_slug'] ?? null;
                            break;
                        }
                    }
                }
                if ($pluginSlug !== null) {
                    break;
                }
            }
        }

        if ($pluginSlug === null) {
            return [];
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        wp_cache_delete('plugins', 'plugins');

        $pluginBasename = null;
        $allPlugins = get_plugins();
        foreach ($allPlugins as $file => $data) {
            if (str_starts_with($file, $pluginSlug . '/')) {
                $pluginBasename = $file;
                break;
            }
        }

        if ($pluginBasename === null) {
            return [];
        }

        $wasAlreadyActive = is_plugin_active($pluginBasename);

        if (!$wasAlreadyActive) {
            $activation = activate_plugin($pluginBasename);
            if (is_wp_error($activation)) {
                $fakeId = 'smoke_activate_' . bin2hex(random_bytes(8));
                return [
                    [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => $fakeId,
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_plugins',
                                'arguments' => json_encode(['status' => 'all']),
                            ],
                        ]],
                    ],
                    [
                        'role' => 'tool',
                        'tool_call_id' => $fakeId,
                        'content' => "[SMOKE-TEST] Plugin '$pluginSlug' konnte nicht aktiviert werden: "
                            . $activation->get_error_message()
                            . "\n\n[SYSTEM] PFLICHT: Das Plugin hat einen Aktivierungsfehler. "
                            . "Analysiere den Fehler und behebe ihn sofort mit patch_plugin_file oder write_plugin_file.",
                    ],
                ];
            }
        }

        // Scan plugin files once for CPTs and shortcodes
        $cptSlugs = [];
        $shortcodeSlugs = [];
        $pluginDir = trailingslashit(WP_PLUGIN_DIR) . $pluginSlug;
        if (is_dir($pluginDir)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($pluginDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $content = @file_get_contents($file->getPathname());
                    if ($content === false) {
                        continue;
                    }
                    if (preg_match_all("/register_post_type\s*\(\s*['\"]([a-z0-9_]+)['\"]/i", $content, $m)) {
                        $cptSlugs = array_merge($cptSlugs, $m[1]);
                    }
                    if (preg_match_all("/add_shortcode\s*\(\s*['\"]([a-z0-9_-]+)['\"]/i", $content, $m)) {
                        $shortcodeSlugs = array_merge($shortcodeSlugs, $m[1]);
                    }
                }
            } catch (\Throwable $e) {
                // ignore filesystem errors
            }
            $cptSlugs = array_unique($cptSlugs);
            $shortcodeSlugs = array_unique($shortcodeSlugs);
        }

        // CPT: flush rewrite rules + collect archive and single post URLs
        $pluginTestUrls = [];
        if (!empty($cptSlugs)) {
            flush_rewrite_rules();
            foreach ($cptSlugs as $cptSlug) {
                $archiveUrl = get_post_type_archive_link($cptSlug);
                if ($archiveUrl) {
                    $pluginTestUrls[] = $archiveUrl;
                }
                $singlePosts = get_posts([
                    'post_type' => $cptSlug,
                    'posts_per_page' => 1,
                    'post_status' => 'publish',
                    'fields' => 'ids',
                ]);
                if (!empty($singlePosts)) {
                    $singleUrl = get_permalink($singlePosts[0]);
                    if ($singleUrl) {
                        $pluginTestUrls[] = $singleUrl;
                    }
                }
            }
        }

        // Shortcode: find published pages containing the shortcode
        if (!empty($shortcodeSlugs)) {
            global $wpdb;
            foreach ($shortcodeSlugs as $sc) {
                $pageId = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 1",
                    '%[' . $wpdb->esc_like($sc) . '%'
                ));
                if ($pageId) {
                    $scUrl = get_permalink((int) $pageId);
                    if ($scUrl) {
                        $pluginTestUrls[] = $scUrl;
                    }
                }
            }
        }

        $testUrls = [home_url('/')];
        if (function_exists('wc_get_page_id')) {
            $shopPageId = wc_get_page_id('shop');
            if ($shopPageId > 0) {
                $testUrls = [get_permalink($shopPageId) ?: home_url('/')];
            }

            $writtenPhpParts = $this->collectWrittenPhpContent($toolCalls, $toolResults);
            $writtenCode = implode("\n", $writtenPhpParts);
            if ($writtenCode !== '') {
                $hasCartRef = (bool) preg_match('/woocommerce_(before_cart|after_cart|cart_contents|after_cart_table|before_cart_table|cart_coupon|cart_actions|cart_totals)/', $writtenCode);
                $hasCheckoutRef = (bool) preg_match('/woocommerce_(before_checkout|after_checkout|checkout_before|checkout_after|review_order)/', $writtenCode);

                if ($hasCartRef) {
                    $cartPageId = wc_get_page_id('cart');
                    if ($cartPageId > 0) {
                        $cartUrl = get_permalink($cartPageId);
                        if ($cartUrl) {
                            $testUrls[] = $cartUrl;
                        }
                    }
                }
                if ($hasCheckoutRef) {
                    $checkoutPageId = wc_get_page_id('checkout');
                    if ($checkoutPageId > 0) {
                        $checkoutUrl = get_permalink($checkoutPageId);
                        if ($checkoutUrl) {
                            $testUrls[] = $checkoutUrl;
                        }
                    }
                }
            }
        }
        if (!empty($pluginTestUrls)) {
            $testUrls = array_merge($testUrls, $pluginTestUrls);
        }
        $testUrls = array_unique($testUrls);

        $testUrl = $testUrls[0];
        $fetchResponse = wp_remote_get($testUrl, [
            'timeout' => 15,
            'sslverify' => false,
            'user-agent' => 'Levi-SmokeTest/1.0',
        ]);

        $statusCode = 0;
        $body = '';
        $hasFatalError = false;
        $errorDetail = '';

        if (is_wp_error($fetchResponse)) {
            $hasFatalError = true;
            $errorDetail = 'HTTP-Fehler: ' . $fetchResponse->get_error_message();
        } else {
            $statusCode = wp_remote_retrieve_response_code($fetchResponse);
            $body = wp_remote_retrieve_body($fetchResponse);

            if ($statusCode >= 500) {
                $hasFatalError = true;
                $errorDetail = "HTTP $statusCode";
            }

            if (preg_match('/Fatal\s+error:.*?in\s+.*?on\s+line\s+\d+/is', $body, $m)) {
                $hasFatalError = true;
                $errorDetail = trim($m[0]);
            } elseif (preg_match('/(?:Uncaught\s+(?:Error|Exception|TypeError|ArgumentCountError)[^<]*)/is', $body, $m)) {
                $hasFatalError = true;
                $errorDetail = trim($m[0]);
            } elseif (stripos($body, 'critical error on your website') !== false || stripos($body, 'kritischer Fehler') !== false) {
                $hasFatalError = true;
                $errorDetail = 'WordPress Critical Error Screen detected';
            }
        }

        $errorLogResult = $this->toolRegistry->execute('read_error_log', [
            'lines' => 30,
            'filter' => 'Fatal|Warning|Deprecated|ArgumentCountError|TypeError|Uncaught',
        ]);
        $recentLogErrors = '';
        if (!empty($errorLogResult['lines'])) {
            $pluginPattern = preg_quote($pluginSlug, '/');
            foreach ($errorLogResult['lines'] as $line) {
                if (preg_match("/$pluginPattern/i", $line)) {
                    $recentLogErrors .= $line . "\n";
                }
            }
        }

        if (!$hasFatalError && $recentLogErrors !== '') {
            $hasFatalError = true;
            $errorDetail = "Error-Log Eintraege fuer $pluginSlug gefunden";
        }

        if ($hasFatalError) {
            if (!$wasAlreadyActive) {
                deactivate_plugins($pluginBasename);
            }

            $fakeId = 'smoke_test_' . bin2hex(random_bytes(8));
            $errorContent = "[SMOKE-TEST FEHLGESCHLAGEN] Plugin '$pluginSlug' verursacht einen Runtime-Fehler!\n\n"
                . "Getestete URL: $testUrl\n"
                . "HTTP-Status: $statusCode\n"
                . "Fehler: $errorDetail\n";

            if ($recentLogErrors !== '') {
                $errorContent .= "\nError-Log:\n$recentLogErrors\n";
            }

            $errorContent .= "\n[SYSTEM] PFLICHT: Das Plugin wurde wegen eines Laufzeitfehlers wieder deaktiviert. "
                . "Der Fehler tritt auf wenn eine Seite geladen wird, die Plugin-Code ausfuehrt. "
                . "Typische Ursachen: undefinierte Variablen (Variable vor Verwendung definieren!), "
                . "falsche Argument-Anzahl bei add_filter/add_action (pruefe accepted_args!), "
                . "fehlende Klassen/Funktionen, falsche Hook-Signaturen. "
                . "Lies den Fehler genau, korrigiere den Code mit patch_plugin_file, und nenne dem Nutzer NICHT 'fertig' bis der Fehler behoben ist.";

            return [
                [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $fakeId,
                        'type' => 'function',
                        'function' => [
                            'name' => 'http_fetch',
                            'arguments' => json_encode(['url' => $testUrl, 'extract' => 'body']),
                        ],
                    ]],
                ],
                [
                    'role' => 'tool',
                    'tool_call_id' => $fakeId,
                    'content' => $errorContent,
                ],
            ];
        }

        $fakeId = 'smoke_ok_' . bin2hex(random_bytes(8));
        $okContent = "[SMOKE-TEST BESTANDEN] Plugin '$pluginSlug' wurde aktiviert und die Seite $testUrl "
            . "laesst sich fehlerfrei laden (HTTP $statusCode).\n";

        if (!$wasAlreadyActive) {
            $okContent .= "Das Plugin ist jetzt aktiv.\n";
        }

        if ($body !== '') {
            $wcBlockInfo = $this->detectWcBlocksInBody($body);
            if ($wcBlockInfo !== '') {
                $okContent .= "\n" . $wcBlockInfo;
            }
        }

        $additionalUrls = array_slice($testUrls, 1);
        foreach ($additionalUrls as $extraUrl) {
            $extraResponse = wp_remote_get($extraUrl, [
                'timeout' => 10,
                'sslverify' => false,
                'user-agent' => 'Levi-SmokeTest/1.0',
            ]);
            if (!is_wp_error($extraResponse)) {
                $extraStatus = wp_remote_retrieve_response_code($extraResponse);
                $extraBody = wp_remote_retrieve_body($extraResponse);
                $extraHasError = false;

                if ($extraStatus >= 500) {
                    $extraHasError = true;
                }
                if (preg_match('/Fatal\s+error:|Uncaught\s+(?:Error|Exception)|critical error/i', $extraBody)) {
                    $extraHasError = true;
                }

                if ($extraHasError) {
                    $okContent .= "\n\n[WARNUNG] Zusaetzlicher Smoke-Test auf $extraUrl ergab HTTP $extraStatus "
                        . "oder einen PHP-Fehler. Das Plugin verursacht moeglicherweise Probleme auf dieser Seite.";
                } else {
                    $okContent .= "\nZusaetzlich getestet: $extraUrl (HTTP $extraStatus, OK)";
                    $extraWcInfo = $this->detectWcBlocksInBody($extraBody);
                    if ($extraWcInfo !== '') {
                        $okContent .= "\n" . $extraWcInfo;
                    }
                }
            }
        }

        $env = \Levi\Agent\Memory\StateSnapshotService::getCachedEnvironment();
        if (!empty($env['woocommerce_coming_soon'])) {
            $okContent .= "\n\n[WARNUNG] WooCommerce Coming-Soon-Modus ist aktiv. "
                . "Der Smoke-Test laeuft ohne Session und sieht moeglicherweise die Platzhalter-Seite "
                . "statt der echten Plugin-Ausgabe. Teste die Seite manuell als eingeloggter Nutzer "
                . "oder deaktiviere Coming-Soon voruebergehend, um zuverlaessige Ergebnisse zu erhalten.";
        }

        return [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $fakeId,
                    'type' => 'function',
                    'function' => [
                        'name' => 'http_fetch',
                        'arguments' => json_encode(['url' => $testUrl, 'extract' => 'body']),
                    ],
                ]],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => $fakeId,
                'content' => $okContent,
            ],
        ];
    }

    /**
     * After create_plugin, inject a system nudge forcing the LLM to write actual code.
     * create_plugin only generates a scaffold stub — without this nudge the LLM often
     * claims "done" without ever calling write_plugin_file.
     */
    private function injectPostCreatePluginNudge(array $toolCalls, array $toolResults): array {
        $createdSlug = null;
        $pluginType = 'plain';
        foreach ($toolCalls as $tc) {
            if (($tc['function']['name'] ?? '') !== 'create_plugin') {
                continue;
            }
            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
            foreach ($toolResults as $tr) {
                if ($tr['tool'] === 'create_plugin' && ($tr['result']['success'] ?? false)) {
                    $createdSlug = $tr['result']['slug'] ?? null;
                    $pluginType = $tr['result']['plugin_type'] ?? ($args['plugin_type'] ?? 'plain');
                    break 2;
                }
            }
        }

        if ($createdSlug === null) {
            return [];
        }

        $nudge = "[SYSTEM – PFLICHT] Das Plugin '$createdSlug' wurde als LEERES SCAFFOLD erstellt. "
            . "Die Hauptdatei enthaelt nur einen Platzhalter ohne Funktionalitaet. "
            . "Du MUSST jetzt mit write_plugin_file den vollstaendigen, funktionalen Code in die Hauptdatei schreiben. "
            . "Fuer kleine Aenderungen an bestehenden Dateien nutze patch_plugin_file statt write_plugin_file. "
            . "Antworte dem Nutzer NICHT mit 'fertig' oder 'erstellt' bevor du write_plugin_file oder patch_plugin_file aufgerufen hast. "
            . "Falls das Plugin mehrere Dateien braucht (CSS, JS, Admin-Seite), schreibe ALLE benoetigten Dateien. "
            . "WICHTIG: Nach dem Schreiben der Hauptdatei wird das Plugin automatisch aktiviert und getestet (Smoke-Test). "
            . "Rufe NICHT separat install_plugin mit action=activate auf — das passiert automatisch.";

        $envHints = $this->buildEnvironmentHintsForPluginCreation($pluginType);
        if ($envHints !== '') {
            $nudge .= "\n\n" . $envHints;
        }

        return [[
            'role' => 'system',
            'content' => $nudge,
        ]];
    }

    /**
     * Build environment-aware hints for plugin creation based on cached snapshot.
     * Covers: theme type, WC page modes, Elementor, PHP version.
     */
    private function buildEnvironmentHintsForPluginCreation(string $pluginType): string {
        $env = \Levi\Agent\Memory\StateSnapshotService::getCachedEnvironment();
        $lastData = get_option('levi_agent_state_snapshot_last', []);
        $snapshot = is_array($lastData['snapshot'] ?? null) ? $lastData['snapshot'] : [];
        $hints = [];

        $isBlockTheme = !empty($snapshot['active_theme']['is_block_theme']);
        $themeName = (string) ($snapshot['active_theme']['name'] ?? '');

        if ($isBlockTheme) {
            $hints[] = "[THEME] Das aktive Theme '$themeName' ist ein Block-Theme (FSE). "
                . "Classic-Template-Funktionen (get_header, get_footer, get_sidebar, dynamic_sidebar) sind NICHT verfuegbar. "
                . "Fuer Frontend-Output: Block-Patterns, render_block Filter oder wp_enqueue_scripts nutzen.";
        }

        $wcPages = $env['woocommerce_pages'] ?? [];
        $blockPages = [];
        foreach ($wcPages as $page => $type) {
            if ($type === 'block') {
                $blockPages[] = ucfirst($page);
            }
        }
        if (!empty($blockPages)) {
            $hints[] = "[WC-ENVIRONMENT] " . implode(', ', $blockPages)
                . " nutzt WooCommerce Blocks (NICHT Shortcodes). "
                . "Klassische PHP-Hooks wie woocommerce_before_cart, woocommerce_after_cart_table etc. feuern dort NICHT. "
                . "Fuer Frontend-Aenderungen an Block-basierten Seiten: Custom Block, die WC Block Extensibility API oder JavaScript/DOM-Manipulation verwenden.";
        }

        if (!empty($env['elementor_active'])) {
            $hints[] = "[ELEMENTOR] Elementor ist aktiv. Seiten koennen mit Elementor gebaut sein. "
                . "Pruefe vor the_content-Filtern ob die Zielseite Elementor nutzt.";
        }

        $phpVersion = (string) ($snapshot['php_version'] ?? PHP_VERSION);
        $phpMajorMinor = implode('.', array_slice(explode('.', $phpVersion), 0, 2));
        if (version_compare($phpMajorMinor, '8.0', '<')) {
            $hints[] = "[PHP] PHP $phpVersion – Kein match-Expression, named arguments oder union types verwenden.";
        } elseif (version_compare($phpMajorMinor, '8.1', '<')) {
            $hints[] = "[PHP] PHP $phpVersion – Keine Enums oder Fibers verwenden.";
        }

        return implode("\n", $hints);
    }

    /**
     * Find the main PHP file of a plugin (the one with the "Plugin Name:" header).
     * Returns the absolute path or null if not found.
     */
    private function findMainPluginFile(string $pluginSlug): ?string {
        $pluginRoot = trailingslashit(WP_PLUGIN_DIR) . $pluginSlug;
        if (!is_dir($pluginRoot)) {
            return null;
        }

        $candidate = $pluginRoot . '/' . $pluginSlug . '.php';
        if (is_file($candidate)) {
            return $candidate;
        }

        $entries = scandir($pluginRoot);
        if ($entries === false) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $pluginRoot . '/' . $entry;
            if (!is_file($path) || strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $header = file_get_contents($path, false, null, 0, 8192);
            if ($header !== false && preg_match('/Plugin\s+Name\s*:/i', $header)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Collect PHP content that was written or patched in this tool loop.
     * For write_plugin_file: uses the content arg directly.
     * For patch_plugin_file: reads the patched file from disk (patch is already applied).
     *
     * @return string[] PHP file contents
     */
    private function collectWrittenPhpContent(array $toolCalls, array $toolResults): array {
        $writtenPhpContent = [];

        foreach ($toolResults as $tr) {
            if (!($tr['result']['success'] ?? false)) {
                continue;
            }
            $tool = $tr['tool'] ?? '';
            if (!in_array($tool, ['write_plugin_file', 'patch_plugin_file'], true)) {
                continue;
            }

            $pluginSlug = $tr['result']['plugin_slug'] ?? null;
            $relPath = $tr['result']['relative_path'] ?? '';
            if ($relPath === '') {
                foreach ($toolCalls as $tc) {
                    if (($tc['function']['name'] ?? '') === $tool) {
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        $relPath = $args['relative_path'] ?? '';
                        break;
                    }
                }
            }
            if (!preg_match('/\.php$/i', $relPath)) {
                continue;
            }

            if ($tool === 'write_plugin_file') {
                foreach ($toolCalls as $tc) {
                    if (($tc['function']['name'] ?? '') === 'write_plugin_file') {
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        if (!empty($args['content'])) {
                            $writtenPhpContent[] = $args['content'];
                        }
                        break;
                    }
                }
            } elseif ($tool === 'patch_plugin_file' && $pluginSlug !== null && $relPath !== '') {
                $filePath = trailingslashit(WP_PLUGIN_DIR) . $pluginSlug . '/' . $relPath;
                $content = @file_get_contents($filePath);
                if ($content !== false) {
                    $writtenPhpContent[] = $content;
                }
            }
        }

        return $writtenPhpContent;
    }

    /**
     * After writing PHP to a plugin, scan the code for patterns that conflict
     * with the current WordPress environment (block theme, WC blocks, Elementor).
     */
    private function injectPostWriteEnvironmentWarnings(array $toolCalls, array $toolResults): array {
        $writtenPhpContent = $this->collectWrittenPhpContent($toolCalls, $toolResults);

        if (empty($writtenPhpContent)) {
            return [];
        }

        $env = \Levi\Agent\Memory\StateSnapshotService::getCachedEnvironment();
        $warnings = [];
        $allCode = implode("\n", $writtenPhpContent);

        $wcPages = $env['woocommerce_pages'] ?? [];
        $cartIsBlock = ($wcPages['cart'] ?? '') === 'block';
        $checkoutIsBlock = ($wcPages['checkout'] ?? '') === 'block';

        if ($cartIsBlock || $checkoutIsBlock) {
            $cartHookPattern = '/woocommerce_(before_cart|after_cart|cart_contents|before_cart_table|after_cart_table|cart_coupon|cart_actions|before_cart_totals|cart_totals_before_order_total)/';
            $checkoutHookPattern = '/woocommerce_(before_checkout_form|after_checkout_form|checkout_before_customer_details|checkout_after_customer_details|review_order_before_payment)/';

            $foundCartHooks = [];
            $foundCheckoutHooks = [];

            if ($cartIsBlock && preg_match_all($cartHookPattern, $allCode, $m)) {
                $foundCartHooks = array_unique($m[0]);
            }
            if ($checkoutIsBlock && preg_match_all($checkoutHookPattern, $allCode, $m)) {
                $foundCheckoutHooks = array_unique($m[0]);
            }

            if (!empty($foundCartHooks)) {
                $warnings[] = "[ENVIRONMENT-KONFLIKT] Dein Code verwendet klassische Cart-Hooks ("
                    . implode(', ', $foundCartHooks) . "), aber der Warenkorb nutzt WooCommerce BLOCKS. "
                    . "Diese Hooks feuern NICHT auf Block-basierten Seiten! "
                    . "Alternativen: Einen Custom WooCommerce Block schreiben, JavaScript/DOM-Manipulation nutzen, "
                    . "oder den woocommerce/cart Block-Filter-API verwenden.";
            }

            if (!empty($foundCheckoutHooks)) {
                $warnings[] = "[ENVIRONMENT-KONFLIKT] Dein Code verwendet klassische Checkout-Hooks ("
                    . implode(', ', $foundCheckoutHooks) . "), aber der Checkout nutzt WooCommerce BLOCKS. "
                    . "Diese Hooks feuern NICHT auf Block-basierten Seiten! "
                    . "Alternativen: Einen Custom WooCommerce Block schreiben oder die WooCommerce Checkout Block Extensibility API nutzen.";
            }
        }

        $isBlockTheme = false;
        $lastData = get_option('levi_agent_state_snapshot_last', []);
        $snapshot = is_array($lastData['snapshot'] ?? null) ? $lastData['snapshot'] : [];
        if (!empty($snapshot['active_theme']['is_block_theme'])) {
            $isBlockTheme = true;
        }

        if ($isBlockTheme) {
            $classicThemePatterns = [
                'get_header()' => 'get_header()',
                'get_footer()' => 'get_footer()',
                'get_sidebar()' => 'get_sidebar()',
                'dynamic_sidebar(' => 'dynamic_sidebar()',
                'the_custom_logo()' => 'the_custom_logo()',
            ];
            $foundClassicPatterns = [];
            foreach ($classicThemePatterns as $pattern => $label) {
                if (str_contains($allCode, $pattern)) {
                    $foundClassicPatterns[] = $label;
                }
            }

            if (!empty($foundClassicPatterns)) {
                $warnings[] = "[ENVIRONMENT-HINWEIS] Dein Code verwendet Classic-Theme-Funktionen ("
                    . implode(', ', $foundClassicPatterns) . "), aber das aktive Theme ist ein Block-Theme (FSE). "
                    . "Block-Themes verwenden HTML-Templates in /templates/ und /parts/ statt PHP-Template-Dateien. "
                    . "Fuer Frontend-Aenderungen: Block-Patterns, Template-Parts oder den render_block Filter nutzen.";
            }
        }

        if (!empty($env['elementor_active'])) {
            if (preg_match('/the_content\s*\(/', $allCode) && str_contains($allCode, 'add_filter')) {
                $warnings[] = "[ENVIRONMENT-HINWEIS] Elementor ist aktiv. the_content Filter koennen mit "
                    . "Elementor-gerenderten Seiten kollidieren. Pruefe ob die Zielseite(n) mit Elementor gebaut sind "
                    . "(elementor_data Post-Meta) und nutze ggf. Elementor Hooks statt the_content.";
            }
        }

        // the_content recursion: filter callback calling the_content()
        if (preg_match('/add_filter\s*\(\s*[\'"]the_content[\'"]/', $allCode)
            && preg_match('/\bthe_content\s*\(/', $allCode)
        ) {
            $warnings[] = "[CODE-FEHLER] Dein Code registriert einen the_content-Filter UND ruft the_content() "
                . "im selben Code auf. Das verursacht eine Endlosschleife (infinite recursion) und einen HTTP 500 Fehler! "
                . "Innerhalb eines the_content-Filter-Callbacks IMMER den \$content-Parameter verwenden, "
                . "NIE the_content() aufrufen.";
        }

        // Meta-key consistency: detect mismatched keys between get_post_meta and update_post_meta
        $this->checkMetaKeyConsistency($allCode, $toolResults, $warnings);

        if (empty($warnings)) {
            return [];
        }

        return [[
            'role' => 'system',
            'content' => implode("\n\n", $warnings)
                . "\n\n[SYSTEM] PFLICHT: Pruefe deinen Code gegen die obigen Warnungen. "
                . "Falls ein Konflikt besteht, korrigiere den Code SOFORT mit patch_plugin_file. "
                . "Antworte dem Nutzer NICHT mit 'fertig' bis die Konflikte behoben sind.",
        ]];
    }

    /**
     * Check for meta-key mismatches between get_post_meta and update_post_meta calls
     * across ALL PHP files in the plugin directory, not just the main file.
     */
    private function checkMetaKeyConsistency(string $writtenCode, array $toolResults, array &$warnings): void {
        $pluginSlug = null;
        foreach ($toolResults as $tr) {
            if (($tr['result']['success'] ?? false)
                && in_array($tr['tool'] ?? '', ['write_plugin_file', 'patch_plugin_file'], true)
            ) {
                $pluginSlug = $tr['result']['plugin_slug'] ?? null;
                if ($pluginSlug !== null) {
                    break;
                }
            }
        }

        $fullCode = $writtenCode;
        if ($pluginSlug !== null) {
            $pluginDir = trailingslashit(WP_PLUGIN_DIR) . $pluginSlug;
            if (is_dir($pluginDir)) {
                $allPhpCode = '';
                $fileCount = 0;
                $totalBytes = 0;
                $maxFiles = 50;
                $maxBytes = 2 * 1024 * 1024; // 2 MB
                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($pluginDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $file) {
                        if ($file->getExtension() !== 'php') {
                            continue;
                        }
                        if ($fileCount >= $maxFiles || $totalBytes >= $maxBytes) {
                            break;
                        }
                        $content = @file_get_contents($file->getPathname());
                        if ($content === false) {
                            continue;
                        }
                        $allPhpCode .= $content . "\n";
                        $fileCount++;
                        $totalBytes += strlen($content);
                    }
                } catch (\Throwable $e) {
                    // fall through to use $writtenCode
                }
                if ($allPhpCode !== '') {
                    $fullCode = $allPhpCode;
                }
            }
        }

        $getKeys = [];
        $updateKeys = [];
        if (preg_match_all("/get_post_meta\s*\([^,]+,\s*['\"]([^'\"]+)['\"]/", $fullCode, $m)) {
            $getKeys = array_unique($m[1]);
        }
        if (preg_match_all("/update_post_meta\s*\([^,]+,\s*['\"]([^'\"]+)['\"]/", $fullCode, $m)) {
            $updateKeys = array_unique($m[1]);
        }

        if (empty($getKeys) || empty($updateKeys)) {
            return;
        }

        $onlyInGet = array_diff($getKeys, $updateKeys);
        $onlyInUpdate = array_diff($updateKeys, $getKeys);

        if (empty($onlyInGet) || empty($onlyInUpdate)) {
            return;
        }

        foreach ($onlyInGet as $getKey) {
            foreach ($onlyInUpdate as $updateKey) {
                $shorter = strlen($getKey) < strlen($updateKey) ? $getKey : $updateKey;
                $longer = strlen($getKey) >= strlen($updateKey) ? $getKey : $updateKey;
                if (str_contains($longer, $shorter) || similar_text($getKey, $updateKey) > (strlen($longer) * 0.6)) {
                    $warnings[] = "[META-KEY MISMATCH] get_post_meta liest '{$getKey}', aber update_post_meta schreibt '{$updateKey}'. "
                        . "Das fuehrt zu leeren Daten! Pruefe ob die Keys identisch sein muessen und korrigiere den Mismatch.";
                    return;
                }
            }
        }
    }

    private function injectPostWriteIntegrationCheck(array $toolCalls, array $toolResults): array {
        $writtenSubFiles = [];

        foreach ($toolResults as $tr) {
            if (!($tr['result']['success'] ?? false)) {
                continue;
            }
            $tool = $tr['tool'] ?? '';
            if (!in_array($tool, ['write_plugin_file', 'patch_plugin_file'], true)) {
                continue;
            }

            $slug = $tr['result']['plugin_slug'] ?? null;
            $relPath = $tr['result']['relative_path'] ?? '';
            if ($slug === null || $relPath === '') {
                foreach ($toolCalls as $tc) {
                    if (($tc['function']['name'] ?? '') === $tool) {
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        $slug = $slug ?? ($args['plugin_slug'] ?? null);
                        $relPath = $relPath ?: ($args['relative_path'] ?? '');
                        break;
                    }
                }
            }
            if ($slug === null || $relPath === '') {
                continue;
            }

            if (str_contains($relPath, '/') || str_contains($relPath, '\\')) {
                $writtenSubFiles[] = ['slug' => $slug, 'path' => $relPath];
            }
        }

        if (empty($writtenSubFiles)) {
            return [];
        }

        $warnings = [];
        $checkedSlugs = [];

        foreach ($writtenSubFiles as $sub) {
            $slug = $sub['slug'];
            $relPath = $sub['path'];

            $cacheKey = $slug . '::' . $relPath;
            if (isset($checkedSlugs[$cacheKey])) {
                continue;
            }
            $checkedSlugs[$cacheKey] = true;

            $mainFile = $this->findMainPluginFile($slug);
            if ($mainFile === null) {
                continue;
            }

            $mainRelPath = basename($mainFile);
            if ($relPath === $mainRelPath) {
                continue;
            }

            $mainContent = @file_get_contents($mainFile);
            if ($mainContent === false) {
                continue;
            }

            $basename = basename($relPath);
            $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $found = false;

            if ($ext === 'php') {
                $escaped = preg_quote($basename, '/');
                $escapedPath = preg_quote($relPath, '/');
                if (preg_match('/(?:require|include)(?:_once)?\s*[\(]?\s*[\'"].*?' . $escaped . '/i', $mainContent)
                    || preg_match('/(?:require|include)(?:_once)?\s*[\(]?\s*.*?' . $escapedPath . '/i', $mainContent)) {
                    $found = true;
                }

                if (!$found) {
                    $allPhpFiles = glob(dirname($mainFile) . '/**/*.php');
                    if ($allPhpFiles) {
                        foreach ($allPhpFiles as $phpFile) {
                            if ($phpFile === $mainFile) {
                                continue;
                            }
                            $otherContent = @file_get_contents($phpFile);
                            if ($otherContent !== false && preg_match('/(?:require|include)(?:_once)?\s*[\(]?\s*[\'"].*?' . $escaped . '/i', $otherContent)) {
                                $found = true;
                                break;
                            }
                        }
                    }
                }
            } elseif ($ext === 'css' || $ext === 'js') {
                $escaped = preg_quote($basename, '/');
                $enqueuePattern = '/wp_(?:enqueue|register)_(?:style|script)\s*\(.*?' . $escaped . '/is';
                if (preg_match($enqueuePattern, $mainContent)) {
                    $found = true;
                }

                if (!$found) {
                    $allPhpFiles = glob(dirname($mainFile) . '/{,*/,*/*/}*.php', GLOB_BRACE);
                    if ($allPhpFiles) {
                        foreach ($allPhpFiles as $phpFile) {
                            if ($phpFile === $mainFile) {
                                continue;
                            }
                            $otherContent = @file_get_contents($phpFile);
                            if ($otherContent !== false && preg_match($enqueuePattern, $otherContent)) {
                                $found = true;
                                break;
                            }
                        }
                    }
                }
            } else {
                continue;
            }

            if (!$found) {
                $typeHint = ($ext === 'php')
                    ? 'require_once oder include_once'
                    : (($ext === 'css') ? 'wp_enqueue_style' : 'wp_enqueue_script');
                $warnings[] = "'{$relPath}' in Plugin '{$slug}' wird von keiner PHP-Datei eingebunden "
                    . "(kein {$typeHint} gefunden). Die Datei wird nie geladen!";
            }
        }

        if (empty($warnings)) {
            return [];
        }

        return [[
            'role' => 'system',
            'content' => "[SYSTEM – INTEGRATION CHECK]\n"
                . implode("\n", $warnings)
                . "\n\nDu MUSST die Hauptdatei oder eine passende PHP-Datei des Plugins aktualisieren, "
                . "damit die oben genannten Dateien eingebunden werden. "
                . "Bei PHP: require_once. Bei CSS: wp_enqueue_style. Bei JS: wp_enqueue_script. "
                . "Antworte dem Nutzer NICHT mit 'fertig' bevor die Integration sichergestellt ist.",
        ]];
    }

    /**
     * When read_plugin_file or read_theme_file fails with "File does not exist",
     * automatically run list_plugin_files/list_theme_files and inject the file tree
     * so the LLM can pick the correct path without wasting another round trip.
     */
    private function injectAutoListOnMissingFile(array $toolCalls, array $toolResults): array {
        $injected = [];

        foreach ($toolResults as $i => $tr) {
            $tool = $tr['tool'] ?? '';
            $success = $tr['result']['success'] ?? true;
            $error = $tr['result']['error'] ?? '';

            if ($success || stripos($error, 'does not exist') === false) {
                continue;
            }

            $isPluginRead = $tool === 'read_plugin_file';
            $isThemeRead = $tool === 'read_theme_file';
            if (!$isPluginRead && !$isThemeRead) {
                continue;
            }

            $slug = null;
            foreach ($toolCalls as $tc) {
                if (($tc['function']['name'] ?? '') === $tool) {
                    $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                    $slug = $args['plugin_slug'] ?? $args['theme_slug'] ?? null;
                    break;
                }
            }
            if ($slug === null) {
                continue;
            }

            $listTool = $isPluginRead ? 'list_plugin_files' : 'list_theme_files';
            $slugParam = $isPluginRead ? 'plugin_slug' : 'theme_slug';

            $listResult = $this->toolRegistry->execute($listTool, [$slugParam => $slug, 'max_depth' => 3]);

            if (empty($listResult) || !empty($listResult['error'])) {
                continue;
            }

            $fakeId = 'auto_list_' . bin2hex(random_bytes(8));
            $fileTree = is_array($listResult) ? wp_json_encode($listResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $listResult;

            $injected[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $fakeId,
                    'type' => 'function',
                    'function' => [
                        'name' => $listTool,
                        'arguments' => wp_json_encode([$slugParam => $slug]),
                    ],
                ]],
            ];
            $injected[] = [
                'role' => 'tool',
                'tool_call_id' => $fakeId,
                'content' => "[AUTO-RECOVERY] Die Datei existiert nicht. Hier ist die tatsaechliche Dateistruktur von '$slug':\n\n"
                    . $fileTree
                    . "\n\nWaehle den richtigen Pfad aus dieser Liste und versuche es erneut.",
            ];

            break;
        }

        return $injected;
    }

    /**
     * Scan tool results for code_tag_warning entries. If any were found (i.e. tags
     * slipped past the auto-strip), inject a hard system message forcing the AI to
     * patch them immediately.
     */
    private function injectCodeTagWarnings(array $toolResults): array {
        $affectedFiles = [];

        foreach ($toolResults as $tr) {
            if (empty($tr['result']['code_tag_warning'])) {
                continue;
            }

            $tool = $tr['tool'] ?? '';
            $slug = $tr['result']['plugin_slug'] ?? $tr['result']['theme_slug'] ?? '?';
            $relPath = $tr['result']['relative_path'] ?? '?';
            $patchTool = str_contains($tool, 'theme') ? 'write_theme_file' : 'patch_plugin_file';

            $affectedFiles[] = "{$slug}/{$relPath} (verwende {$patchTool})";
        }

        if (empty($affectedFiles)) {
            return [];
        }

        return [[
            'role' => 'system',
            'content' => "[SYSTEM – CODE-TAG WARNUNG]\n"
                . "Die folgenden Dateien enthalten noch <code>- oder <pre>-Tags im HTML-Output:\n"
                . implode("\n", array_map(fn($f) => "  - $f", $affectedFiles))
                . "\n\nDiese Tags MUESSEN SOFORT entfernt werden! Sie verhindern, dass CSS-Styles greifen "
                . "und zeigen Frontend-Inhalte als haesslichen Monospace-Text an.\n"
                . "PATCHE jede betroffene Datei JETZT und entferne alle <code>, </code>, <pre>, </pre> Tags "
                . "aus dem HTML-Output. HTML-Elemente wie <div>, <h3>, <span> sind Render-Output, KEIN 'Code zum Anzeigen'.\n"
                . "Antworte dem Nutzer NICHT mit 'fertig' bevor alle Tags entfernt sind.",
        ]];
    }

    /**
     * Detect when the AI called get_pages but the user asked about "Beiträge" (posts),
     * or called get_posts but the user asked about "Seiten" (pages).
     * Injects a system correction message so the AI calls the correct tool.
     */
    private function injectToolMismatchCorrection(?string $userMessage, array $toolResults): array {
        if ($userMessage === null || $userMessage === '' || empty($toolResults)) {
            return [];
        }

        $msgLower = mb_strtolower($userMessage);

        $postKeywords = ['beitrag', 'beiträge', 'beitraege', 'blogbeitrag', 'blogartikel', 'blog-beitrag', 'blog-artikel', 'artikel'];
        $pageKeywords = ['seite', 'seiten', 'unterseite', 'unterseiten', 'startseite', 'homepage'];

        $userMeansPost = false;
        $userMeansPage = false;

        foreach ($postKeywords as $kw) {
            if (mb_strpos($msgLower, $kw) !== false) {
                $userMeansPost = true;
                break;
            }
        }

        foreach ($pageKeywords as $kw) {
            if (mb_strpos($msgLower, $kw) !== false) {
                $userMeansPage = true;
                break;
            }
        }

        if ($userMeansPost && $userMeansPage) {
            return [];
        }

        $calledTools = array_unique(array_map(fn($tr) => $tr['tool'] ?? '', $toolResults));

        if ($userMeansPost && in_array('get_pages', $calledTools, true) && !in_array('get_posts', $calledTools, true)) {
            return [[
                'role' => 'system',
                'content' => "[SYSTEM – FALSCHES TOOL VERWENDET]\n"
                    . "Der Nutzer hat nach BEITRÄGEN (Posts) gefragt, aber du hast get_pages (Seiten) aufgerufen. "
                    . "Das sind unterschiedliche WordPress-Inhaltstypen!\n"
                    . "- Beiträge/Posts/Blog = get_posts (post_type='post')\n"
                    . "- Seiten/Pages = get_pages (post_type='page')\n\n"
                    . "Rufe JETZT get_posts auf, um die korrekten Beiträge zu laden. "
                    . "Verwende NICHT die Daten aus dem get_pages-Ergebnis — das sind Seiten, keine Beiträge. "
                    . "Präsentiere dem Nutzer NUR die Daten aus get_posts.",
            ]];
        }

        if ($userMeansPage && in_array('get_posts', $calledTools, true) && !in_array('get_pages', $calledTools, true)) {
            $postType = '';
            foreach ($toolResults as $tr) {
                if (($tr['tool'] ?? '') === 'get_posts') {
                    $postType = $tr['result']['queried_post_type'] ?? 'post';
                    break;
                }
            }
            if ($postType === 'page') {
                return [];
            }

            return [[
                'role' => 'system',
                'content' => "[SYSTEM – FALSCHES TOOL VERWENDET]\n"
                    . "Der Nutzer hat nach SEITEN (Pages) gefragt, aber du hast get_posts (Beiträge) aufgerufen. "
                    . "Das sind unterschiedliche WordPress-Inhaltstypen!\n"
                    . "- Seiten/Pages = get_pages (post_type='page')\n"
                    . "- Beiträge/Posts/Blog = get_posts (post_type='post')\n\n"
                    . "Rufe JETZT get_pages auf, um die korrekten Seiten zu laden. "
                    . "Verwende NICHT die Daten aus dem get_posts-Ergebnis — das sind Beiträge, keine Seiten. "
                    . "Präsentiere dem Nutzer NUR die Daten aus get_pages.",
            ]];
        }

        return [];
    }

    /**
     * Completion gate: before the AI says "done", verify that all sub-files written
     * during this session are properly integrated into their plugin's main file,
     * and that the main file doesn't reference files that don't exist on disk.
     *
     * Returns null if everything looks OK, or a description of the issues found.
     */
    private function checkWriteCompleteness(array $toolResults): ?string {
        $pluginWrites = [];

        foreach ($toolResults as $tr) {
            if (!($tr['result']['success'] ?? false)) {
                continue;
            }
            $tool = $tr['tool'] ?? '';
            if (!in_array($tool, ['write_plugin_file', 'patch_plugin_file'], true)) {
                continue;
            }
            $slug = $tr['result']['plugin_slug'] ?? null;
            $relPath = $tr['result']['relative_path'] ?? '';
            if ($slug === null || $relPath === '') {
                continue;
            }
            $pluginWrites[$slug][] = $relPath;
        }

        if (empty($pluginWrites)) {
            return null;
        }

        $issues = [];

        foreach ($pluginWrites as $slug => $writtenPaths) {
            $mainFile = $this->findMainPluginFile($slug);
            if ($mainFile === null) {
                continue;
            }

            $mainContent = @file_get_contents($mainFile);
            if ($mainContent === false) {
                continue;
            }

            $mainRelPath = basename($mainFile);
            $pluginRoot = trailingslashit(WP_PLUGIN_DIR) . $slug;

            foreach ($writtenPaths as $relPath) {
                if ($relPath === $mainRelPath) {
                    continue;
                }
                if (!str_contains($relPath, '/') && !str_contains($relPath, '\\')) {
                    continue;
                }

                $basename = basename($relPath);
                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                $found = false;

                if ($ext === 'php') {
                    $escaped = preg_quote($basename, '/');
                    $allPhpFiles = glob($pluginRoot . '/{,*/,*/*/}*.php', GLOB_BRACE) ?: [];
                    foreach ($allPhpFiles as $phpFile) {
                        $c = @file_get_contents($phpFile);
                        if ($c !== false && preg_match('/(?:require|include)(?:_once)?\s*[\(]?\s*[\'"].*?' . $escaped . '/i', $c)) {
                            $found = true;
                            break;
                        }
                    }
                } elseif (in_array($ext, ['css', 'js'], true)) {
                    $escaped = preg_quote($basename, '/');
                    $pattern = '/wp_(?:enqueue|register)_(?:style|script)\s*\(.*?' . $escaped . '/is';
                    $allPhpFiles = glob($pluginRoot . '/{,*/,*/*/}*.php', GLOB_BRACE) ?: [];
                    foreach ($allPhpFiles as $phpFile) {
                        $c = @file_get_contents($phpFile);
                        if ($c !== false && preg_match($pattern, $c)) {
                            $found = true;
                            break;
                        }
                    }
                } else {
                    continue;
                }

                if (!$found) {
                    $issues[] = "Plugin '{$slug}': Datei '{$relPath}' wurde geschrieben, "
                        . "ist aber in keiner PHP-Datei des Plugins eingebunden.";
                }
            }

            if (preg_match_all('/(?:require|include)(?:_once)?\s*[\(]?\s*(?:__DIR__|dirname\s*\(\s*__FILE__\s*\))\s*\.\s*[\'"]([^"\']+)[\'"]/i', $mainContent, $matches)) {
                foreach ($matches[1] as $referenced) {
                    $referenced = ltrim($referenced, '/\\');
                    $fullPath = $pluginRoot . '/' . $referenced;
                    if (!is_file($fullPath)) {
                        $issues[] = "Plugin '{$slug}': Die Hauptdatei referenziert '{$referenced}', "
                            . "aber diese Datei existiert nicht. Das verursacht einen Fatal Error!";
                    }
                }
            }
        }

        return empty($issues) ? null : implode("\n", $issues);
    }

    /**
     * After write/patch tools, scan for symbols defined in the changed file and grep
     * all other files in the same plugin/theme for references. Warns about reverse dependencies.
     */
    private function injectPostWriteReverseDependencyWarnings(array $toolCalls, array $toolResults): array {
        $writeTools = [
            'write_plugin_file', 'patch_plugin_file', 'write_theme_file', 'patch_theme_file',
        ];

        $changedFiles = [];
        foreach ($toolResults as $tr) {
            if (!($tr['result']['success'] ?? false)) {
                continue;
            }
            $tool = $tr['tool'] ?? '';
            if (!in_array($tool, $writeTools, true)) {
                continue;
            }

            $slug = $tr['result']['plugin_slug'] ?? $tr['result']['theme_slug'] ?? null;
            $relPath = $tr['result']['relative_path'] ?? '';
            $isTheme = isset($tr['result']['theme_slug']);

            if ($slug === null || $relPath === '') {
                foreach ($toolCalls as $tc) {
                    if (($tc['function']['name'] ?? '') === $tool) {
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        if ($slug === null) {
                            $slug = $args['plugin_slug'] ?? $args['theme_slug'] ?? null;
                            $isTheme = isset($args['theme_slug']);
                        }
                        if ($relPath === '') {
                            $relPath = $args['relative_path'] ?? '';
                        }
                        break;
                    }
                }
            }

            if ($slug === null || $relPath === '' || !preg_match('/\.php$/i', $relPath)) {
                continue;
            }

            $rootDir = $isTheme
                ? (function_exists('get_theme_root') ? get_theme_root() . '/' . $slug : '')
                : trailingslashit(WP_PLUGIN_DIR) . $slug;

            if ($rootDir === '' || !is_dir($rootDir)) {
                continue;
            }

            $symbols = $this->extractChangedSymbols($tool, $toolCalls, $rootDir, $relPath);
            if (!empty($symbols)) {
                $changedFiles[] = [
                    'root' => realpath($rootDir) ?: $rootDir,
                    'rel_path' => $relPath,
                    'symbols' => $symbols,
                    'slug' => $slug,
                ];
            }
        }

        if (empty($changedFiles)) {
            return [];
        }

        $allWarnings = [];
        foreach ($changedFiles as $cf) {
            $depHits = $this->scanReverseDependencies($cf['root'], $cf['rel_path'], $cf['symbols']);
            if (empty($depHits)) {
                continue;
            }

            $lines = [];
            foreach ($depHits as $symbol => $refs) {
                $refParts = [];
                foreach (array_slice($refs, 0, 5) as $ref) {
                    $refParts[] = "{$ref['file']} (Zeile {$ref['line']})";
                }
                $lines[] = "- `{$symbol}` wird verwendet in: " . implode(', ', $refParts);
                if (count($refs) > 5) {
                    $lines[count($lines) - 1] .= ' (+' . (count($refs) - 5) . ' weitere)';
                }
            }

            $allWarnings[] = "[DEPENDENCY-WARNUNG] {$cf['rel_path']} enthaelt Symbole, die in anderen Dateien referenziert werden:\n"
                . implode("\n", $lines)
                . "\nPruefe ob diese Stellen mit deiner Aenderung kompatibel sind.";
        }

        if (empty($allWarnings)) {
            return [];
        }

        return [[
            'role' => 'system',
            'content' => implode("\n\n", $allWarnings),
        ]];
    }

    /**
     * After write/patch tools, run reference integrity + WordPress pattern checks
     * on the written PHP files. Injects [CODE-WARNUNG] messages for any issues found.
     */
    private function injectPostWriteReferenceCheck(array $toolCalls, array $toolResults): array {
        $writeTools = [
            'write_plugin_file', 'patch_plugin_file', 'write_theme_file', 'patch_theme_file',
        ];

        $checkedFiles = [];
        $knownFunctionsCache = [];
        $allWarnings = [];

        foreach ($toolResults as $tr) {
            if (!($tr['result']['success'] ?? false)) {
                continue;
            }
            $tool = $tr['tool'] ?? '';
            if (!in_array($tool, $writeTools, true)) {
                continue;
            }

            $slug = $tr['result']['plugin_slug'] ?? $tr['result']['theme_slug'] ?? null;
            $relPath = $tr['result']['relative_path'] ?? '';
            $isTheme = isset($tr['result']['theme_slug']);

            if ($slug === null || $relPath === '') {
                foreach ($toolCalls as $tc) {
                    if (($tc['function']['name'] ?? '') === $tool) {
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        if ($slug === null) {
                            $slug = $args['plugin_slug'] ?? $args['theme_slug'] ?? null;
                            $isTheme = isset($args['theme_slug']);
                        }
                        if ($relPath === '') {
                            $relPath = $args['relative_path'] ?? '';
                        }
                        break;
                    }
                }
            }

            if ($slug === null || $relPath === '' || !preg_match('/\.php$/i', $relPath)) {
                continue;
            }

            $fileKey = $slug . '/' . $relPath;
            if (isset($checkedFiles[$fileKey])) {
                continue;
            }
            $checkedFiles[$fileKey] = true;

            $rootDir = $isTheme
                ? (function_exists('get_theme_root') ? get_theme_root() . '/' . $slug : '')
                : trailingslashit(WP_PLUGIN_DIR) . $slug;

            if ($rootDir === '' || !is_dir($rootDir)) {
                continue;
            }

            $filePath = $rootDir . '/' . $relPath;
            if (!is_file($filePath)) {
                continue;
            }

            $fileWarnings = [];

            $content = @file_get_contents($filePath);

            if (method_exists($this, 'checkReferenceIntegrity') && $content !== false) {
                if (!isset($knownFunctionsCache[$rootDir]) && method_exists($this, 'buildKnownFunctionsForPlugin')) {
                    $knownFunctionsCache[$rootDir] = $this->buildKnownFunctionsForPlugin($rootDir);
                }
                $refCheck = $this->checkReferenceIntegrity($filePath, $rootDir, $knownFunctionsCache[$rootDir] ?? null);
                if (!empty($refCheck['undefined_calls'])) {
                    $preview = implode(', ', array_slice($refCheck['undefined_calls'], 0, 5));
                    $fileWarnings[] = "Moeglicherweise undefinierte Funktionen: {$preview}";
                }
            }

            if (method_exists($this, 'checkWordPressPatterns') && $content !== false) {
                $wpCheck = $this->checkWordPressPatterns($content, $relPath);
                foreach ($wpCheck['warnings'] ?? [] as $w) {
                    $fileWarnings[] = $w;
                }
            }

            if (!empty($fileWarnings)) {
                $allWarnings[] = "[CODE-WARNUNG] Moegliche Probleme in {$relPath}:\n- "
                    . implode("\n- ", $fileWarnings)
                    . "\nPruefe ob diese Aufrufe korrekt sind.";
            }
        }

        if (empty($allWarnings)) {
            return [];
        }

        return [[
            'role' => 'system',
            'content' => implode("\n\n", $allWarnings),
        ]];
    }

    private function extractChangedSymbols(string $tool, array $toolCalls, string $rootDir, string $relPath): array {
        $isPatch = str_contains($tool, 'patch_');
        $symbols = [];

        if ($isPatch) {
            foreach ($toolCalls as $tc) {
                if (($tc['function']['name'] ?? '') !== $tool) {
                    continue;
                }
                $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $replacements = $args['replacements'] ?? [];
                foreach ($replacements as $r) {
                    $search = (string) ($r['search'] ?? '');
                    $replace = (string) ($r['replace'] ?? '');
                    $combined = $search . "\n" . $replace;
                    $this->extractSymbolsFromCode($combined, $symbols);
                }
                break;
            }
        } else {
            $filePath = $rootDir . '/' . $relPath;
            $content = @file_get_contents($filePath, false, null, 0, 200 * 1024);
            if ($content !== false) {
                $this->extractSymbolsFromCode($content, $symbols);
            }
        }

        return array_slice(array_unique($symbols), 0, 10);
    }

    private function extractSymbolsFromCode(string $code, array &$symbols): void {
        if (preg_match_all('/\bfunction\s+(\w+)\s*\(/m', $code, $m)) {
            foreach ($m[1] as $fn) {
                if (strlen($fn) >= 4) {
                    $symbols[] = $fn;
                }
            }
        }
        if (preg_match_all('/\bclass\s+(\w+)/m', $code, $m)) {
            foreach ($m[1] as $cls) {
                $symbols[] = $cls;
            }
        }
        if (preg_match_all('/\badd_(?:action|filter)\(\s*[\'"]([^\'"]+)[\'"]/m', $code, $m)) {
            foreach ($m[1] as $hook) {
                if (strlen($hook) >= 4 && !str_starts_with($hook, 'wp_') && !str_starts_with($hook, 'admin_') && !str_starts_with($hook, 'init')) {
                    $symbols[] = $hook;
                }
            }
        }
        if (preg_match_all('/\bdefine\(\s*[\'"](\w+)[\'"]/m', $code, $m)) {
            foreach ($m[1] as $const) {
                if (!in_array($const, ['ABSPATH', 'WPINC', 'WP_DEBUG'], true)) {
                    $symbols[] = $const;
                }
            }
        }
    }

    /**
     * @return array<string, array<array{file: string, line: int}>>
     */
    private function scanReverseDependencies(string $rootDir, string $changedRelPath, array $symbols): array {
        if (empty($symbols)) {
            return [];
        }

        $skipExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'mp3', 'zip', 'gz', 'tar', 'map'];
        $hits = [];
        $filesScanned = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($filesScanned >= 50) {
                    break;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $skipExtensions, true) || !in_array($ext, ['php', 'js', 'css'], true)) {
                    continue;
                }
                if ($file->getSize() > 500 * 1024) {
                    continue;
                }
                if (str_contains($file->getFilename(), '.min.')) {
                    continue;
                }

                $filePath = $file->getPathname();
                $relFile = ltrim(substr($filePath, strlen($rootDir)), '/');

                if ($relFile === $changedRelPath) {
                    continue;
                }

                $content = @file_get_contents($filePath);
                if ($content === false || str_contains($content, "\x00")) {
                    continue;
                }
                $filesScanned++;

                $lines = explode("\n", $content);
                foreach ($symbols as $symbol) {
                    foreach ($lines as $lineIdx => $lineContent) {
                        if (str_contains($lineContent, $symbol)) {
                            if (!isset($hits[$symbol])) {
                                $hits[$symbol] = [];
                            }
                            $hits[$symbol][] = [
                                'file' => $relFile,
                                'line' => $lineIdx + 1,
                            ];
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently handle filesystem errors
        }

        return $hits;
    }

    /**
     * Check HTML body for WooCommerce Block rendering patterns.
     * Returns a warning string if block-based cart/checkout is detected.
     */
    private function detectWcBlocksInBody(string $body): string {
        $blockPages = [];

        if (str_contains($body, 'wp-block-woocommerce-cart') || str_contains($body, 'wc-block-cart')) {
            $blockPages[] = 'Cart';
        }
        if (str_contains($body, 'wp-block-woocommerce-checkout') || str_contains($body, 'wc-block-checkout')) {
            $blockPages[] = 'Checkout';
        }

        if (empty($blockPages)) {
            return '';
        }

        return '[WC-ENVIRONMENT] Diese Seite nutzt WooCommerce Blocks (' . implode(', ', $blockPages) . '). '
            . 'Klassische PHP-Hooks (woocommerce_before_cart, woocommerce_after_cart_table etc.) feuern hier NICHT. '
            . 'Fuer Frontend-Aenderungen: Custom Block oder JavaScript/DOM-Manipulation verwenden.';
    }
}
