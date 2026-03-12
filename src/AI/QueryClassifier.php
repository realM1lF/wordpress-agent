<?php

namespace Levi\Agent\AI;

/**
 * Query Classifier
 * 
 * Classifies user queries to determine if deep memory retrieval is needed.
 * Simple questions don't need expensive vector search.
 * 
 * @package Levi\Agent\AI
 */
class QueryClassifier {
    
    public const TYPE_GREETING = 'greeting';
    public const TYPE_SIMPLE = 'simple';
    public const TYPE_KNOWLEDGE = 'knowledge';
    public const TYPE_DATA = 'data';
    public const TYPE_ACTION = 'action';
    public const TYPE_COMPLEX = 'complex';
    
    /**
     * Check if query needs deep memory retrieval
     * 
     * Simple questions (capabilities, knowledge checks) don't need
     * expensive vector search. Complex/action queries do.
     * 
     * @param string $query User query
     * @return bool True if deep retrieval needed
     */
    public function needsDeepRetrieval(string $query): bool {
        $type = $this->classify($query);
        return !in_array($type, [self::TYPE_SIMPLE, self::TYPE_GREETING], true);
    }
    
    /**
     * Classify query type into one of 6 categories.
     */
    public function classify(string $query): string {
        $lower = mb_strtolower(trim($query));

        if ($lower === '') {
            return self::TYPE_SIMPLE;
        }

        if ($this->isGreeting($lower)) {
            return self::TYPE_GREETING;
        }

        if ($this->isActionQuery($lower)) {
            return self::TYPE_ACTION;
        }

        if ($this->isSimpleKnowledgeQuery($lower)) {
            if (!$this->needsDataRetrieval($lower)) {
                return self::TYPE_SIMPLE;
            }
        }

        if ($this->needsDataRetrieval($lower)) {
            return self::TYPE_DATA;
        }

        if ($this->isComplexQuery($lower)) {
            if ($this->needsSetupContext($lower)) {
                return self::TYPE_COMPLEX;
            }
            return self::TYPE_KNOWLEDGE;
        }

        if ($this->mentionsWordPressObjects($lower)) {
            return self::TYPE_KNOWLEDGE;
        }

        return self::TYPE_COMPLEX;
    }

    /**
     * Return a retrieval strategy array that tells the caller which data layers to activate.
     * Called once per request; result is passed through to getContextMemories().
     */
    public function getRetrievalStrategy(string $query): array {
        $type = $this->classify($query);

        return match ($type) {
            self::TYPE_GREETING => ['identity' => true, 'reference' => false, 'snapshot' => false, 'full_tools' => false],
            self::TYPE_SIMPLE   => ['identity' => true, 'reference' => false, 'snapshot' => false, 'full_tools' => false],
            self::TYPE_KNOWLEDGE => ['identity' => true, 'reference' => true,  'snapshot' => true,  'full_tools' => false],
            self::TYPE_DATA     => ['identity' => true, 'reference' => false, 'snapshot' => false, 'full_tools' => true],
            self::TYPE_ACTION   => ['identity' => true, 'reference' => true,  'snapshot' => false, 'full_tools' => true],
            self::TYPE_COMPLEX  => ['identity' => true, 'reference' => true,  'snapshot' => true,  'full_tools' => true],
            default             => ['identity' => true, 'reference' => true,  'snapshot' => true,  'full_tools' => true],
        };
    }
    
    private function isGreeting(string $lower): bool {
        $greetingPatterns = [
            '/^(hi|hallo|hey|moin|servus|guten (morgen|tag|abend)|na\b|yo\b|hello|good (morning|evening|afternoon))/u',
            '/^(wie geht|wie gehts|was geht|alles klar|na wie)/u',
        ];
        foreach ($greetingPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                if (mb_strlen($lower) < 60) {
                    return true;
                }
            }
        }
        return false;
    }

    private function needsSetupContext(string $lower): bool {
        $setupPatterns = [
            '/\b(setup|einrichtung|konfiguration|empfehl|empfiehl|welches plugin|bestes plugin|welches theme|passend|geeignet)\b/u',
            '/\b(optimier|verbesser|diagnos|analyse|problem|fehler|langsam|schneller|performance|seo)\b/u',
            '/\b(warum|why|wieso|weshalb)\b/u',
        ];
        foreach ($setupPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if query is an action query (creates/updates/deletes)
     */
    private function isActionQuery(string $lower): bool {
        $actionPatterns = [
            '/\b(erstell|anleg|schreib|änder|bearbeit|update|install|aktivier|deaktivier|lösch|entfern|switch|veröffentl|publish|setz|füg|mach|mache)\b/u',
            '/\b(erstelle|ändere|lösche|installiere|aktiviere|deaktiviere|publiziere)\b/u',
            '/\b(bau|baue|bauen|programmier|implementier|entwickl|code|coden)\b/u',
            '/\b(create|update|delete|install|activate|deactivate|publish|change|modify|add|remove|build|implement|develop)\b/u',
            '/\b(fix|fixe|fixen|reparier|repariere|korrigier|korrigiere|beheb|behebe|patch|patche|wiederherstell)\b/u',
        ];

        $hasActionVerb = false;
        foreach ($actionPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                $hasActionVerb = true;
                break;
            }
        }

        if (!$hasActionVerb) {
            return false;
        }

        if (preg_match('/\b(plugin|seite|post|beitrag|datei|theme|benutzer|user|option|einstellung|page|menu|widget|shortcode|produkt|product|block|formular|form|newsletter|badge|modul|feature|funktion|grid|css|style|layout|design|fehler|bug|error)\b/u', $lower)) {
            return true;
        }

        // Short queries with action verbs + demonstrative/context words are implicit actions
        // e.g. "fix das", "kannst du das fixen", "mach das weg", "lösch den bitte"
        if (preg_match('/\b(das|es|die|den|dem|dieser|dieses|diese|ihn|ihr|ihm|gleich|bitte|mal|schnell|direkt|nochmal|sofort)\b/u', $lower)) {
            return true;
        }

        return false;
    }
    
    /**
     * Check if query is a simple knowledge question
     * 
     * @param string $lower Lowercase query
     * @return bool
     */
    private function isSimpleKnowledgeQuery(string $lower): bool {
        // Capability questions - ALWAYS simple, even with WordPress objects
        // "Wie gut kennst du dich mit Elementor aus?" = simple!
        // BUT: "kannst du das fixen?" is an ACTION, not a capability question
        $capabilityPatterns = [
            '/\b(wie gut kennst du|was kannst du|was weißt du|kennst du dich)\b/u',
            '/\b(how well do you know|what can you|do you know)\b/u',
        ];

        // "kannst du" / "can you" is only a capability question when NOT followed by an action verb
        $actionVerbCheck = '/\b(erstell|anleg|schreib|änder|bearbeit|update|install|aktivier|deaktivier|lösch|entfern|mach|bau|programmier|implementier|fix|fixen|fixe|reparier|korrigier|beheb|patch|code|coden|develop|build|create|delete|change|modify|add|remove)\b/u';

        if (preg_match('/\b(kannst du|can you)\b/u', $lower)) {
            if (!preg_match($actionVerbCheck, $lower)) {
                return true;
            }
            return false;
        }

        foreach ($capabilityPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }
        
        // Other simple patterns
        $simplePatterns = [
            // General knowledge
            '/\b(was ist|was sind|erklär mir|wie funktioniert|wie geht|wie macht man)\b/u',
            '/\b(what is|what are|explain|how does|how to)\b/u',
            
            // Help requests
            '/\b(hilf|hilfe|helfen|help|assist)\b/u',
            
            // Opinion/Assessment
            '/\b(meinst du|was hältst du|wie findest du)\b/u',
            '/\b(do you think|what do you think|how do you find)\b/u',
        ];
        
        foreach ($simplePatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                // These should not be about specific WP objects to be "simple"
                if (!$this->mentionsWordPressObjects($lower)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if query asks for specific data that requires a tool
     * Examples: "Welche Seiten habe ich?", "Zeige mir alle Posts", "Welche Plugins sind aktiv?"
     * 
     * @param string $lower Lowercase query
     * @return bool
     */
    private function needsDataRetrieval(string $lower): bool {
        // Check for "Was ist [installiert/da/drin]?" patterns - these are data queries!
        if (preg_match('/\bwas ist\b.*\b(installiert|da|drin|vorhanden)\b/u', $lower)) {
            return true;
        }
        
        // EXPLICIT data retrieval patterns - must indicate user wants to SEE/LIST their data
        $explicitDataPatterns = [
            // German: asking to see/list/enumerate user's own data
            '/\b(welche|liste|zeig|zeige|zeig mir|zeig mir alle|gib mir|gibt es|auflisten)\b.*\b(habe|hast|haben|sind|gibt|installiert|angelegt|erstellt)\b/u',
            '/\b(hab ich|habe ich|haben wir)\b.*\b(angelegt|erstellt|installiert|drin|da)\b/u',
            // English: asking to see/list/enumerate user's own data
            '/\b(which|show|show me|list|give me|what)\b.*\b(do i have|do we have|are installed|do you see)\b/u',
            '/\b(do i have|do we have)\b.*\b(created|installed|pages|posts|plugins)\b/u',
        ];
        
        foreach ($explicitDataPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                // If asking about WP objects (pages, posts, plugins, etc.), it needs a tool
                if ($this->mentionsWordPressObjects($lower) || $this->mentionsContentTypes($lower)) {
                    return true;
                }
            }
        }
        
        // Check for standalone "gibt es" / "are there" with content types
        if (preg_match('/\b(gibt es|are there|existieren|vorhanden)\b/u', $lower)) {
            if ($this->mentionsContentTypes($lower)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if query mentions content types that need tools
     */
    private function mentionsContentTypes(string $lower): bool {
        $contentTypes = [
            'seiten', 'pages', 'beiträge', 'posts', 'plugins', 'themes', 'medien', 'media',
            'benutzer', 'users', 'kategorien', 'categories', 'tags', 'schlagwörter',
            'menus', 'menüs', 'widgets', 'shortcodes', 'produkte', 'products',
            'bestellungen', 'orders', 'coupons', 'gutscheine', 'forms', 'formulare',
        ];
        
        foreach ($contentTypes as $type) {
            if (str_contains($lower, $type)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if query is complex and needs context
     * BUT: Exclude simple knowledge questions even if they contain complex keywords
     * 
     * @param string $lower Lowercase query
     * @return bool
     */
    private function isComplexQuery(string $lower): bool {
        // First check: Is this actually a simple knowledge question?
        // "Was ist Elementor?" contains "ist" but is simple!
        if ($this->isPureKnowledgeQuestion($lower)) {
            return false;
        }
        
        $complexPatterns = [
            '/\b(optimier|verbesser|erweiter|anpassen|konfigurier|debug|fehler|problem|issue|fixen|reparier|korrigier|beheb)\b/u',
            '/\b(optimize|improve|extend|configure|customize|debug|error|fix|solve|repair|patch)\b/u',
            '/\b(warum|warum nicht|why|why not|wie kann ich|how can i)\b/u',
            '/\b(vergleich|unterschied|difference|compare|vs|versus)\b/u',
            '/\b(best practice|empfohlen|recommended|optimal|richtig|falsch)\b/u',
        ];
        
        foreach ($complexPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }
        
        // Long queries (>100 chars) are likely complex
        if (mb_strlen($lower) > 100) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if query is a pure knowledge question (not complex)
     * Examples: "Was ist X?", "Erklär mir Y", "Wie funktioniert Z?"
     */
    private function isPureKnowledgeQuestion(string $lower): bool {
        // Pure knowledge patterns - these override complex detection
        $knowledgePatterns = [
            '/^was ist\b/u',
            '/^was sind\b/u',
            '/^erklär\b/u',
            '/^erkläre\b/u',
            '/^wie funktioniert\b/u',
            '/^wie geht\b/u',
            '/^wie macht man\b/u',
            '/^what is\b/u',
            '/^what are\b/u',
            '/^explain\b/u',
            '/^how does\b/u',
        ];
        
        foreach ($knowledgePatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if query mentions specific WordPress objects
     * 
     * @param string $lower Lowercase query
     * @return bool
     */
    private function mentionsWordPressObjects(string $lower): bool {
        $objects = [
            'plugin', 'plugins', 'seite', 'seiten', 'page', 'pages',
            'post', 'posts', 'beitrag', 'beiträge', 'theme', 'themes',
            'template', 'datei', 'file', 'benutzer', 'user', 'menu',
            'woocommerce', 'elementor', 'gutenberg', 'acf', 'yoast',
            'einstellung', 'setting', 'option', 'widget', 'shortcode',
            'hook', 'filter', 'action', 'database', 'db', 'tabelle',
            'permalink', 'slug', 'category', 'kategorie', 'tag'
        ];
        
        foreach ($objects as $object) {
            if (str_contains($lower, $object)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Return which rule modules should be loaded for this query.
     *
     * Mapping:
     *   GREETING / SIMPLE → core only
     *   KNOWLEDGE / DATA  → core + tools
     *   ACTION / COMPLEX  → core + tools + coding + planning
     *
     * Domain-specific modules (woocommerce, elementor, cron) are appended
     * when the query contains relevant keywords.
     *
     * @return string[] Module names (without .md) matching files in identity/rules/
     */
    public function getRequiredRuleModules(string $query): array {
        $type = $this->classify($query);
        $lower = mb_strtolower(trim($query));

        $modules = match ($type) {
            self::TYPE_GREETING, self::TYPE_SIMPLE => ['core'],
            self::TYPE_KNOWLEDGE, self::TYPE_DATA   => ['core', 'tools'],
            self::TYPE_ACTION, self::TYPE_COMPLEX   => ['core', 'tools', 'coding', 'planning'],
            default                                  => ['core', 'tools', 'coding', 'planning'],
        };

        if (preg_match('/\b(woocommerce|produkt|produkte|product|products|shop|bestell|coupon|gutschein|warenkorb|cart)\b/u', $lower)) {
            $modules[] = 'woocommerce';
        }

        if (preg_match('/\b(elementor|widget|section|container|template-kit)\b/u', $lower)) {
            $modules[] = 'elementor';
        }

        if (preg_match('/\b(cron|zeitplan|schedule|automatisch|wiederkehrend|recurring|task)\b/u', $lower)) {
            $modules[] = 'cron';
        }

        return array_unique($modules);
    }

    /**
     * Get human-readable classification details (for debugging).
     */
    public function getClassificationDetails(string $query): array {
        $type = $this->classify($query);
        $strategy = $this->getRetrievalStrategy($query);
        $lower = mb_strtolower(trim($query));

        $reasons = [
            self::TYPE_GREETING  => 'Greeting - identity only',
            self::TYPE_SIMPLE    => 'Simple knowledge question - no tools or deep retrieval needed',
            self::TYPE_KNOWLEDGE => 'Knowledge/consultation - needs reference + snapshot context',
            self::TYPE_DATA      => 'Data retrieval - needs tools for live data',
            self::TYPE_ACTION    => 'Action request - needs tools to execute',
            self::TYPE_COMPLEX   => 'Complex question - needs full context + tools',
        ];

        return [
            'type' => $type,
            'reason' => $reasons[$type] ?? 'Unknown',
            'strategy' => $strategy,
            'needs_deep_retrieval' => $this->needsDeepRetrieval($query),
            'mentions_wp_objects' => $this->mentionsWordPressObjects($lower),
            'needs_data_retrieval' => $this->needsDataRetrieval($lower),
            'query_length' => mb_strlen($query),
        ];
    }
}
