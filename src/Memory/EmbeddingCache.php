<?php

namespace Levi\Agent\Memory;

/**
 * Embedding Cache
 * 
 * Caches generated embeddings to avoid repeated API calls.
 * Significantly speeds up repeated or similar queries.
 * 
 * @package Levi\Agent\Memory
 */
class EmbeddingCache {
    private const CACHE_GROUP = 'levi_embeddings';
    private const CACHE_TTL = DAY_IN_SECONDS; // 24 hours
    private const MAX_TEXT_LENGTH = 500; // Hash only first 500 chars for cache key
    
    /**
     * Get cached embedding for text
     * 
     * @param string $text The text to get embedding for
     * @return array|null Cached embedding or null if not found
     */
    public function get(string $text): ?array {
        $cacheKey = $this->generateCacheKey($text);
        $cached = get_transient($cacheKey);
        
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        
        return null;
    }
    
    /**
     * Store embedding in cache
     * 
     * @param string $text The original text
     * @param array $embedding The embedding array
     * @return bool Success
     */
    public function set(string $text, array $embedding): bool {
        $cacheKey = $this->generateCacheKey($text);
        return set_transient($cacheKey, $embedding, self::CACHE_TTL);
    }
    
    /**
     * Generate cache key from text
     * Normalizes text for better cache hits (case insensitive, trimmed, stop words removed)
     * 
     * @param string $text
     * @return string
     */
    private function generateCacheKey(string $text): string {
        // Normalize: lowercase, trim, remove extra whitespace
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        // Remove common German/English stop words for better cache hits
        // "Wie gut kennst du Elementor" and "Kennst du Elementor" should match
        $stopWords = [
            'wie', 'gut', 'du', 'dich', 'mit', 'aus', 'was', 'ist', 'sind',
            'how', 'well', 'do', 'you', 'know', 'with', 'about', 'what', 'is', 'are',
            'the', 'a', 'an', 'der', 'die', 'das', 'ein', 'eine',
        ];
        
        $words = explode(' ', $normalized);
        $filtered = array_filter($words, function($word) use ($stopWords) {
            return !in_array($word, $stopWords, true) && mb_strlen($word) > 2;
        });
        
        $normalized = implode(' ', $filtered);
        
        // Use first 500 chars for hash to keep cache key reasonable
        $textForHash = mb_substr($normalized, 0, self::MAX_TEXT_LENGTH);
        
        return 'levi_emb_' . md5($textForHash);
    }
    
    /**
     * Clear all cached embeddings
     * Useful for debugging or when switching embedding models
     * 
     * @return void
     */
    public function clear(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_levi_emb_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_levi_emb_%'"
        );
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Stats about cached embeddings
     */
    public function getStats(): array {
        global $wpdb;
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_levi_emb_%'"
        );
        
        return [
            'cached_count' => (int) $count,
            'ttl_hours' => 24,
            'max_text_length' => self::MAX_TEXT_LENGTH,
        ];
    }
}
