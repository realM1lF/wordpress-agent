<?php

namespace Levi\Agent\Agent;

class Identity {
    private string $identityPath;
    private ?string $soul = null;
    private ?string $rules = null;
    private ?string $knowledge = null;

    /** @var array<string, string> Cached rule module contents keyed by module name */
    private array $ruleModules = [];

    public function __construct() {
        $this->identityPath = LEVI_AGENT_PLUGIN_DIR . 'identity/';
        $this->load();
    }

    private function load(): void {
        $this->soul = $this->loadFile('soul.md');
        $this->rules = $this->loadFile('rules.md');
        $this->knowledge = $this->loadFile('knowledge.md');
    }

    private function loadFile(string $filename): ?string {
        $path = $this->identityPath . $filename;
        if (!file_exists($path)) {
            return null;
        }
        return file_get_contents($path);
    }

    /**
     * Load a single rule module from identity/rules/<name>.md.
     * Results are cached per instance to avoid repeated disk reads.
     */
    private function loadRuleModule(string $name): ?string {
        if (isset($this->ruleModules[$name])) {
            return $this->ruleModules[$name] ?: null;
        }

        $path = $this->identityPath . 'rules/' . $name . '.md';
        if (!file_exists($path)) {
            $this->ruleModules[$name] = '';
            return null;
        }

        $content = file_get_contents($path);
        $this->ruleModules[$name] = $content ?: '';
        return $content ?: null;
    }

    /**
     * Load specific rule modules and combine them.
     *
     * @param string[] $modules Module names (e.g. ['core', 'tools', 'coding'])
     */
    public function getRulesForModules(array $modules): string {
        $parts = [];
        foreach ($modules as $module) {
            $content = $this->loadRuleModule($module);
            if ($content !== null) {
                $parts[] = $content;
            }
        }

        if (empty($parts)) {
            return $this->rules ?? '';
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Check whether modular rules exist on disk.
     */
    public function hasModularRules(): bool {
        return file_exists($this->identityPath . 'rules/core.md');
    }

    public function getSoul(): ?string {
        return $this->soul;
    }

    public function getRules(): ?string {
        return $this->rules;
    }

    public function getKnowledge(): ?string {
        return $this->knowledge;
    }

    public function getSystemPrompt(): string {
        $parts = [];

        if ($this->soul) {
            $parts[] = $this->soul;
        }

        if ($this->rules) {
            $parts[] = $this->rules;
        }

        if ($this->knowledge) {
            $parts[] = $this->knowledge;
        }

        $parts[] = self::getDynamicContext();

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Static identity text (soul + rules + knowledge), without dynamic context.
     * When modular rules are available and specific modules are requested,
     * only those modules are included instead of the full rules.md.
     *
     * @param string[]|null $ruleModules If set, load these rule modules instead of full rules.md
     */
    public function getFullContent(?array $ruleModules = null): string {
        $parts = [];

        if ($this->soul) {
            $parts[] = $this->soul;
        }

        if ($ruleModules !== null && $this->hasModularRules()) {
            $modularRules = $this->getRulesForModules($ruleModules);
            if ($modularRules !== '') {
                $parts[] = $modularRules;
            }
        } elseif ($this->rules) {
            $parts[] = $this->rules;
        }

        if ($this->knowledge) {
            $parts[] = $this->knowledge;
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * SHA-256 hash over the three identity files for cache invalidation.
     */
    public function getContentHash(): string {
        return hash('sha256', ($this->soul ?? '') . ($this->rules ?? '') . ($this->knowledge ?? ''));
    }

    /**
     * Dynamic context (user, site, time). Static because it uses only WP API functions,
     * no instance properties -- avoids unnecessary file I/O when called standalone.
     */
    public static function getDynamicContext(): string {
        $user = wp_get_current_user();
        $siteName = get_bloginfo('name');
        $siteUrl = get_site_url();
        
        return "# Current Context\n\n" .
            "- **User:** " . ($user->display_name ?? 'Guest') . "\n" .
            "- **Role:** " . ($user->roles[0] ?? 'unknown') . "\n" .
            "- **Site:** " . $siteName . " (" . $siteUrl . ")\n" .
            "- **Time:** " . current_time('mysql') . "\n" .
            "- **WordPress Version:** " . get_bloginfo('version');
    }

    public function reload(): void {
        $this->load();
    }
}
