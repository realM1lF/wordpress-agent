<?php

namespace Levi\Agent\AI\Tools;

/**
 * Centralized guard layer for tool call interception.
 *
 * Evaluates every tool call (name + arguments) BEFORE execution and returns
 * one of three verdicts: allow, escalate (needs user confirmation), or block.
 *
 * Inspired by OpenClaw's before_tool_call hook pattern.
 */
class ToolGuard {

    public const ALLOW    = 'allow';
    public const ESCALATE = 'escalate';
    public const BLOCK    = 'block';

    private const LEVI_PLUGIN_SLUG = 'levi-agent';

    /**
     * Global options that affect the entire site and must not be changed
     * without explicit user request.
     */
    private const DANGEROUS_OPTIONS = [
        'show_on_front', 'page_on_front', 'page_for_posts',
        'blogname', 'blogdescription',
        'permalink_structure',
        'default_role', 'users_can_register',
        'template', 'stylesheet',
        'WPLANG', 'timezone_string',
    ];

    /**
     * Tools that always require user confirmation (name-level).
     */
    private const ESCALATE_TOOLS = [
        'delete_post',
        'switch_theme',
        'update_any_option',
        'manage_user',
        'install_plugin',
        'delete_plugin_file',
        'delete_theme_file',
        'execute_wp_code',
        'manage_elementor',
        'manage_menu',
        'manage_cron',
        'create_plugin',
    ];

    /**
     * WooCommerce actions that require confirmation.
     */
    private const WC_ESCALATE_ACTIONS = [
        'delete_product',
        'delete_variation',
        'delete_coupon',
        'update_order_status',
        'configure_tax',
    ];

    private int $writeCallCount = 0;
    private int $maxWriteCalls;

    public function __construct(int $maxWriteCalls = 15) {
        $this->maxWriteCalls = $maxWriteCalls;
    }

    /**
     * Evaluate a tool call and return the verdict.
     *
     * @return array{verdict: string, reason?: string}
     */
    public function evaluate(string $toolName, array $args): array {
        if ($toolName === '') {
            return self::blocked('Leerer Tool-Name.');
        }

        $selfProtect = $this->checkSelfProtection($toolName, $args);
        if ($selfProtect !== null) {
            return $selfProtect;
        }

        $argGuard = $this->checkArgumentRules($toolName, $args);
        if ($argGuard !== null) {
            return $argGuard;
        }

        if ($this->isWriteTool($toolName)) {
            $this->writeCallCount++;
            if ($this->writeCallCount > $this->maxWriteCalls) {
                return self::blocked(
                    "Write-Tool-Budget erschoepft ({$this->maxWriteCalls} Schreiboperationen pro Request). "
                    . "Weitere Aenderungen muessen in einem neuen Request erfolgen."
                );
            }
        }

        if ($this->isNameLevelEscalation($toolName, $args)) {
            return self::escalated($this->describeEscalation($toolName, $args));
        }

        return self::allowed();
    }

    /**
     * Track a write tool call that was executed (for budget counting from external code).
     */
    public function trackWrite(): void {
        $this->writeCallCount++;
    }

    public function getWriteCallCount(): int {
        return $this->writeCallCount;
    }

    // --- Verdict helpers ---

    private static function allowed(): array {
        return ['verdict' => self::ALLOW];
    }

    private static function escalated(string $reason): array {
        return ['verdict' => self::ESCALATE, 'reason' => $reason];
    }

    private static function blocked(string $reason): array {
        return ['verdict' => self::BLOCK, 'reason' => $reason];
    }

    // --- Self-protection: Never modify the Levi plugin itself ---

    private function checkSelfProtection(string $toolName, array $args): ?array {
        $pluginMutators = ['write_plugin_file', 'patch_plugin_file', 'delete_plugin_file'];
        if (!in_array($toolName, $pluginMutators, true)) {
            return null;
        }

        $slug = $this->extractPluginSlug($args);
        if ($slug === self::LEVI_PLUGIN_SLUG || $slug === 'wp-levi-agent') {
            return self::blocked(
                "Levi-Plugin-Selbstschutz: Bearbeitung des eigenen Plugin-Codes ist verboten."
            );
        }

        return null;
    }

    // --- Argument-level rules ---

    private function checkArgumentRules(string $toolName, array $args): ?array {
        if ($toolName === 'update_option') {
            return $this->guardUpdateOption($args);
        }

        if ($toolName === 'execute_wp_code') {
            return $this->guardExecuteWpCode($args);
        }

        return null;
    }

    private function guardUpdateOption(array $args): ?array {
        $option = (string) ($args['option'] ?? '');
        if (in_array($option, self::DANGEROUS_OPTIONS, true)) {
            return self::escalated(
                "Globale Einstellung '{$option}' aendern erfordert explizite Bestaetigung."
            );
        }
        return null;
    }

    private function guardExecuteWpCode(array $args): ?array {
        $code = (string) ($args['code'] ?? '');
        foreach (self::DANGEROUS_OPTIONS as $opt) {
            if (str_contains($code, "update_option('{$opt}'")
                || str_contains($code, "update_option(\"{$opt}\"")) {
                return self::blocked(
                    "Code-Ausfuehrung blockiert: Versuch, globale Einstellung '{$opt}' "
                    . "via execute_wp_code zu aendern. Nutze update_option direkt."
                );
            }
        }
        return null;
    }

    // --- Name-level escalation (existing destructive tool logic) ---

    private function isNameLevelEscalation(string $toolName, array $args): bool {
        if ($toolName === 'manage_woocommerce') {
            $action = (string) ($args['action'] ?? '');
            return in_array($action, self::WC_ESCALATE_ACTIONS, true);
        }

        return in_array($toolName, self::ESCALATE_TOOLS, true);
    }

    private function describeEscalation(string $toolName, array $args): string {
        if ($toolName === 'manage_woocommerce') {
            $action = (string) ($args['action'] ?? '');
            return "WooCommerce-Aktion '{$action}' erfordert Bestaetigung.";
        }
        return "Tool '{$toolName}' erfordert Bestaetigung.";
    }

    // --- Write tool detection ---

    private function isWriteTool(string $toolName): bool {
        static $writeTools = [
            'write_plugin_file', 'patch_plugin_file', 'write_theme_file',
            'create_plugin', 'create_theme', 'execute_wp_code',
            'elementor_build', 'create_post', 'update_post', 'create_page',
            'delete_post', 'update_option', 'update_any_option',
            'upload_media', 'manage_post_meta', 'manage_taxonomy',
            'install_plugin', 'switch_theme', 'delete_plugin_file',
            'delete_theme_file', 'manage_user', 'manage_menu',
            'manage_woocommerce', 'manage_elementor', 'manage_cron',
        ];
        return in_array($toolName, $writeTools, true);
    }

    // --- Helpers ---

    private function extractPluginSlug(array $args): string {
        $slug = (string) ($args['plugin_slug'] ?? $args['slug'] ?? '');
        return sanitize_title($slug);
    }
}
