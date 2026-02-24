<?php

namespace Mohami\Agent\Agent;

class Identity {
    private string $identityPath;
    private ?string $soul = null;
    private ?string $rules = null;
    private ?string $knowledge = null;

    public function __construct() {
        $this->identityPath = MOHAMI_AGENT_PLUGIN_DIR . 'identity/';
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

        // Add dynamic context
        $parts[] = $this->getDynamicContext();

        return implode("\n\n---\n\n", $parts);
    }

    private function getDynamicContext(): string {
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
