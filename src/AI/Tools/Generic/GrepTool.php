<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;
use Levi\Agent\AI\Tools\Concerns\HasFileOperations;

/**
 * Generic grep tool — search text in files or content.
 */
class GrepTool extends AbstractTool {
    use HasFileOperations;

    public function getName(): string { return 'grep'; }

    public function getDescription(): string {
        return "Sucht Text in Dateien oder Post-Inhalten. "
            . "Für Dateien: `pattern` + `directory` (rekursiv). "
            . "Für Posts: `pattern` + `post_type` + `field` (title/content/excerpt/both). "
            . "`regex=true` für reguläre Ausdrücke (PHP-PCRE). "
            . "`case_sensitive` (default true). "
            . "`limit` (default 20) maximale Ergebnisse.";
    }

    public function getParameters(): array {
        return [
            'pattern' => [
                'type' => 'string',
                'description' => 'Suchtext oder Regex-Pattern',
            ],
            'directory' => [
                'type' => 'string',
                'description' => 'Verzeichnis für Dateisuche (absolut oder relativ zu wp-content/)',
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Post-Type für Inhaltssuche (z.B. post, page, product)',
            ],
            'field' => [
                'type' => 'string',
                'enum' => ['title', 'content', 'excerpt', 'both'],
                'default' => 'both',
                'description' => 'Welches Feld bei Post-Suche durchsuchen',
            ],
            'regex' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Regulären Ausdruck verwenden',
            ],
            'case_sensitive' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Groß-/Kleinschreibung beachten',
            ],
            'limit' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
                'description' => 'Maximale Ergebnisse',
            ],
        ];
    }

    public function execute(array $params): array {
        $pattern = (string) ($params['pattern'] ?? '');
        if ($pattern === '') {
            return ['success' => false, 'error' => 'Erforderlich: pattern'];
        }

        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $regex = (bool) ($params['regex'] ?? false);
        $caseSensitive = (bool) ($params['case_sensitive'] ?? true);

        if (!empty($params['directory'])) {
            return $this->grepFiles($pattern, (string) $params['directory'], $regex, $caseSensitive, $limit);
        }

        if (!empty($params['post_type'])) {
            return $this->grepPosts($pattern, (string) $params['post_type'], (string) ($params['field'] ?? 'both'), $regex, $caseSensitive, $limit);
        }

        return ['success' => false, 'error' => 'Erforderlich: directory ODER post_type'];
    }

    public function checkPermission(): bool {
        return current_user_can('read');
    }

    private function grepFiles(string $pattern, string $directory, bool $regex, bool $caseSensitive, int $limit): array {
        $resolved = realpath($directory);
        if ($resolved === false) {
            $resolved = realpath(WP_CONTENT_DIR . '/' . $directory);
        }
        if ($resolved === false) {
            return ['success' => false, 'error' => "Verzeichnis nicht gefunden: {$directory}"];
        }

        // Sicherheitscheck
        $allowedRoots = [realpath(WP_CONTENT_DIR), realpath(ABSPATH)];
        $inAllowed = false;
        foreach ($allowedRoots as $root) {
            if ($root !== false && str_starts_with($resolved, $root)) {
                $inAllowed = true;
                break;
            }
        }
        if (!$inAllowed) {
            return ['success' => false, 'error' => 'Pfad außerhalb erlaubter Verzeichnisse'];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $results = [];
        $totalMatches = 0;

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            if ($file->getSize() > 1024 * 1024) continue; // Skip files > 1MB
            if (!in_array($file->getExtension(), ['php', 'js', 'css', 'json', 'xml', 'txt', 'md', 'html', 'twig'], true)) continue;

            $content = file_get_contents($file->getPathname());
            if ($content === false) continue;

            $lines = explode("\n", $content);
            $matches = [];

            foreach ($lines as $lineNum => $line) {
                $found = false;
                if ($regex) {
                    $flags = $caseSensitive ? '' : 'i';
                    $found = @preg_match("/{$pattern}/{$flags}", $line);
                    if ($found === false) {
                        return ['success' => false, 'error' => 'Ungültiger Regex: ' . $pattern];
                    }
                } else {
                    if ($caseSensitive) {
                        $found = str_contains($line, $pattern);
                    } else {
                        $found = str_contains(strtolower($line), strtolower($pattern));
                    }
                }

                if ($found) {
                    $matches[] = [
                        'line' => $lineNum + 1,
                        'content' => mb_substr($line, 0, 200),
                    ];
                    $totalMatches++;
                    if ($totalMatches >= $limit) {
                        break 2;
                    }
                }
            }

            if (!empty($matches)) {
                $results[] = [
                    'path' => $file->getPathname(),
                    'matches' => $matches,
                    'match_count' => count($matches),
                ];
            }
        }

        return [
            'success' => true,
            'results' => $results,
            'total_matches' => $totalMatches,
            'limit_reached' => $totalMatches >= $limit,
        ];
    }

    private function grepPosts(string $pattern, string $postType, string $field, bool $regex, bool $caseSensitive, int $limit): array {
        $fieldMap = [
            'title' => ['post_title'],
            'content' => ['post_content'],
            'excerpt' => ['post_excerpt'],
            'both' => ['post_title', 'post_content'],
        ];
        $searchFields = $fieldMap[$field] ?? $fieldMap['both'];

        $args = [
            'post_type' => $postType,
            'posts_per_page' => min($limit * 2, 100),
            'post_status' => 'any',
            'suppress_filters' => true,
        ];

        if (!$regex) {
            $args['s'] = $pattern;
        }

        $query = new \WP_Query($args);
        $results = [];
        $totalMatches = 0;

        foreach ($query->posts as $post) {
            $matches = [];
            foreach ($searchFields as $searchField) {
                $content = $post->$searchField ?? '';
                $found = false;

                if ($regex) {
                    $flags = $caseSensitive ? '' : 'i';
                    $found = @preg_match("/{$pattern}/{$flags}", $content);
                    if ($found === false) {
                        return ['success' => false, 'error' => 'Ungültiger Regex: ' . $pattern];
                    }
                } else {
                    if ($caseSensitive) {
                        $found = str_contains($content, $pattern);
                    } else {
                        $found = str_contains(strtolower($content), strtolower($pattern));
                    }
                }

                if ($found) {
                    $matches[] = [
                        'field' => $searchField,
                        'preview' => mb_substr(strip_tags($content), 0, 200),
                    ];
                }
            }

            if (!empty($matches)) {
                $results[] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => $post->post_type,
                    'status' => $post->post_status,
                    'matches' => $matches,
                ];
                $totalMatches++;
                if ($totalMatches >= $limit) {
                    break;
                }
            }
        }

        return [
            'success' => true,
            'results' => $results,
            'total_matches' => $totalMatches,
            'limit_reached' => $totalMatches >= $limit,
        ];
    }
}
