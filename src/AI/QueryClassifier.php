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
    
    /**
     * Query types
     */
    public const TYPE_SIMPLE = 'simple';
    public const TYPE_COMPLEX = 'complex';
    public const TYPE_ACTION = 'action';
    
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
        return $type !== self::TYPE_SIMPLE;
    }
    
    /**
     * Classify query type
     * 
     * @param string $query
     * @return string One of TYPE_ constants
     */
    public function classify(string $query): string {
        $lower = mb_strtolower(trim($query));
        
        // Empty query
        if ($lower === '') {
            return self::TYPE_SIMPLE;
        }
        
        // Action queries - always need tools and deep retrieval
        if ($this->isActionQuery($lower)) {
            return self::TYPE_ACTION;
        }
        
        // Simple knowledge questions (check FIRST before data retrieval)
        // "Was ist Elementor?" = simple knowledge, not data retrieval
        if ($this->isSimpleKnowledgeQuery($lower)) {
            // But check if it's also a data query - "Was ist installiert?" needs tool
            if (!$this->needsDataRetrieval($lower)) {
                return self::TYPE_SIMPLE;
            }
        }
        
        // Data retrieval queries - need tools but may not need memory
        // Examples: "Welche Seiten habe ich?", "Zeige mir alle Plugins"
        if ($this->needsDataRetrieval($lower)) {
            return self::TYPE_COMPLEX;
        }
        
        // Complex questions that benefit from context
        if ($this->isComplexQuery($lower)) {
            return self::TYPE_COMPLEX;
        }
        
        // Default: treat as complex to be safe (needs tool = not simple)
        return self::TYPE_COMPLEX;
    }
    
    /**
     * Check if query is an action query (creates/updates/deletes)
     * 
     * @param string $lower Lowercase query
     * @return bool
     */
    private function isActionQuery(string $lower): bool {
        $actionPatterns = [
            '/\b(erstell|anleg|schreib|änder|bearbeit|update|install|aktivier|deaktivier|lösch|entfern|switch|veröffentl|publish|setz|füg|mach|mache)\b/u',
            '/\b(erstelle|ändere|lösche|installiere|aktiviere|deaktiviere|publiziere)\b/u',
            '/\b(create|update|delete|install|activate|deactivate|publish|change|modify|add|remove)\b/u',
        ];
        
        foreach ($actionPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                // Check if it's about WordPress objects
                if (preg_match('/\b(plugin|seite|post|beitrag|datei|theme|benutzer|user|option|einstellung|page|menu|widget|shortcode)\b/u', $lower)) {
                    return true;
                }
            }
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
        $capabilityPatterns = [
            '/\b(wie gut kennst du|was kannst du|was weißt du|kennst du dich|kannst du)\b/u',
            '/\b(how well do you know|what can you|do you know|can you)\b/u',
        ];
        
        foreach ($capabilityPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true; // Always simple - no WP object check
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
            '/\b(optimier|verbesser|erweiter|anpassen|konfigurier|debug|fehler|problem|issue)\b/u',
            '/\b(optimize|improve|extend|configure|customize|debug|error|fix|solve)\b/u',
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
     * Get human-readable classification reason
     * Useful for debugging
     * 
     * @param string $query
     * @return array
     */
    public function getClassificationDetails(string $query): array {
        $type = $this->classify($query);
        $lower = mb_strtolower(trim($query));
        
        // Determine specific reason
        $reason = 'Unknown';
        if ($type === self::TYPE_SIMPLE) {
            $reason = 'Simple knowledge question - no tools or deep retrieval needed';
        } elseif ($type === self::TYPE_ACTION) {
            $reason = 'Action request (create/update/delete) - needs tools and full context';
        } elseif ($type === self::TYPE_COMPLEX) {
            if ($this->needsDataRetrieval($lower)) {
                $reason = 'Data retrieval question - needs tools to fetch current state';
            } else {
                $reason = 'Complex question - needs context from memories';
            }
        }
        
        return [
            'type' => $type,
            'reason' => $reason,
            'needs_deep_retrieval' => $this->needsDeepRetrieval($query),
            'mentions_wp_objects' => $this->mentionsWordPressObjects($lower),
            'needs_data_retrieval' => $this->needsDataRetrieval($lower),
            'query_length' => mb_strlen($query),
        ];
    }
}
