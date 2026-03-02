<?php

namespace Levi\Agent\AI;

/**
 * Filter for detecting prompt injection attempts in user messages.
 * Uses conservative regex patterns to avoid false positives on legitimate requests.
 *
 * @see https://owasp.org/www-project-top-10-for-large-language-model-applications/
 */
class PromptInjectionFilter {

    /**
     * Patterns that strongly indicate prompt injection attempts.
     * Case-insensitive, conservative to minimize false positives.
     *
     * @var string[]
     */
    private static array $patterns = [
        '/ignore\s+(all\s+)?(previous|prior)\s+instructions/i',
        '/disregard\s+(all\s+)?(previous|prior)\s+instructions/i',
        '/forget\s+(all\s+)?(your|the)\s+instructions/i',
        '/override\s+your\s+instructions/i',
        '/you\s+are\s+now\s+in\s+(developer|DAN|jailbreak)\s+mode/i',
    ];

    /**
     * Check if the message contains suspicious prompt injection patterns.
     *
     * @param string $message User message to check.
     * @return bool True if suspicious patterns detected.
     */
    public static function hasSuspiciousPatterns(string $message): bool {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        foreach (self::$patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check and optionally block. Returns error message if blocked, null otherwise.
     *
     * @param string $message User message to check.
     * @return string|null Error message to return to user if blocked, null if OK.
     */
    public static function check(string $message): ?string {
        if (! self::hasSuspiciousPatterns($message)) {
            return null;
        }

        error_log('Levi: Prompt injection attempt detected and blocked. Message length: ' . strlen($message));

        return 'Bitte formuliere deine Anfrage anders.';
    }
}
