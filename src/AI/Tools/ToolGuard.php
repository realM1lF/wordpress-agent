<?php

namespace Levi\Agent\AI\Tools;

/**
 * Centralized guard layer for tool call interception.
 *
 * Evaluates every tool call (name + arguments) BEFORE execution and returns
 * one of two verdicts: allow or block.
 *
 * Destructive tools (delete, user management, etc.) are either allowed or
 * blocked based on the `allow_destructive` setting — no confirmation flow.
 */
class ToolGuard {

    public const ALLOW = 'allow';
    public const BLOCK = 'block';

    private const LEVI_PLUGIN_SLUG = 'levi-agent';

    private const DANGEROUS_OPTIONS = [
        'show_on_front', 'page_on_front', 'page_for_posts',
        'blogname', 'blogdescription',
        'permalink_structure',
        'default_role', 'users_can_register',
        'template', 'stylesheet',
        'WPLANG', 'timezone_string',
    ];

    /**
     * Tools that are destructive and require the `allow_destructive` setting.
     * execute_wp_code is NOT listed here — it's gated by the "full" tool profile.
     */
    private const DESTRUCTIVE_TOOLS = [
        'delete_post',
        'manage_user',
        'delete_theme_file',
    ];

    private const WC_DESTRUCTIVE_ACTIONS = [
        'delete_product',
        'delete_variation',
        'delete_coupon',
    ];

    private int $writeCallCount = 0;
    private int $maxWriteCalls;
    private bool $allowDestructive;

    public function __construct(int $maxWriteCalls = 15, bool $allowDestructive = false) {
        $this->maxWriteCalls = $maxWriteCalls;
        $this->allowDestructive = $allowDestructive;
    }

    /**
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

        if ($this->isDestructiveAction($toolName, $args) && !$this->allowDestructive) {
            return self::blocked(
                "Destruktive Aktionen sind in den Plugin-Einstellungen deaktiviert. "
                . "Der Nutzer muss 'Destruktive Aktionen erlauben' in den Levi-Einstellungen aktivieren."
            );
        }

        return self::allowed();
    }

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

    private static function blocked(string $reason): array {
        return ['verdict' => self::BLOCK, 'reason' => $reason];
    }

    // --- Self-protection ---

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
            if (!$this->allowDestructive) {
                return self::blocked(
                    "Globale Einstellung '{$option}' aendern ist blockiert. "
                    . "Destruktive Aktionen sind in den Plugin-Einstellungen deaktiviert."
                );
            }
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

    // --- Destructive action detection ---

    private function isDestructiveAction(string $toolName, array $args): bool {
        if ($toolName === 'manage_woocommerce') {
            $action = (string) ($args['action'] ?? '');
            return in_array($action, self::WC_DESTRUCTIVE_ACTIONS, true);
        }

        return in_array($toolName, self::DESTRUCTIVE_TOOLS, true);
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
