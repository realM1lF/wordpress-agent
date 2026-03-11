<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Memory\VectorStore;

class SessionLearningsExtractor {

    private const EXTRACT_PROMPT = <<<'PROMPT'
Analysiere diesen Chat-Verlauf zwischen einem WordPress-Admin und seinem KI-Assistenten "Levi".

Extrahiere 3-7 konkrete Learnings, die für ZUKÜNFTIGE Sessions nützlich sind.

WAS EXTRAHIEREN:
- User-Präferenzen und Regeln (z.B. "User will deutsche Kommentare im Code", "Events immer mit post_type=event erstellen")
- Getroffene Design-Entscheidungen (z.B. "Events-Plugin: Detailseiten ja, aber keine Archiv-Seite")
- Wiederkehrende Fehler-Muster und deren Fix als Regel (z.B. "Bei Plugin-Templates: dirname() doppelt nötig für korrekte Pfade")

WAS NICHT EXTRAHIEREN:
- Höflichkeitsfloskeln, Smalltalk
- Einmalige Aktionen (z.B. "Hat Plugin X installiert", "Seite Y wurde erstellt")
- Session-spezifische Statusmeldungen (z.B. "Problem wurde gelöst", "User hat X behoben") — in neuen Sessions veraltet und irreführend
- Aktueller Systemzustand (z.B. "WooCommerce ist aktiv", "Theme ist Twenty Twenty-Four", "DDEV-Umgebung") — kann sich jederzeit ändern
- Allgemeines WordPress/PHP-Wissen

WICHTIG: Nur ZEITLOSE Regeln und Präferenzen extrahieren, die IMMER gelten. Keine Momentaufnahmen, keine Zustände.

FORMAT:
Gib NUR ein JSON-Array mit Strings zurück, ein Learning pro Eintrag.
Beispiel: ["User bevorzugt minimales CSS ohne Frameworks", "Events immer mit post_type=event erstellen, nicht post"]

Kein Markdown, keine Erklärungen, NUR das JSON-Array.
PROMPT;

    private const MIN_MESSAGES_FOR_EXTRACTION = 6;

    /**
     * WP-Cron handler: process a pending session learning extraction.
     */
    public static function handleCron(string $sessionId): void {
        $key = 'levi_learnings_pending_' . $sessionId;
        $data = get_transient($key);
        if (!is_array($data) || empty($data['history'])) {
            delete_transient($key);
            return;
        }

        delete_transient($key);

        try {
            $extractor = new self();
            $stored = $extractor->extractAndStore($data['history'], (int) ($data['user_id'] ?? 0));
            if ($stored > 0) {
                error_log("Levi: Extracted {$stored} learnings from session {$sessionId} (async)");
            }
        } catch (\Throwable $e) {
            error_log('Levi LearningsExtractor cron error: ' . $e->getMessage());
        }
    }

    /**
     * Extract learnings from a session's conversation history.
     */
    public function extractAndStore(array $history, int $userId): int {
        if (count($history) < self::MIN_MESSAGES_FOR_EXTRACTION) {
            return 0;
        }

        $learnings = $this->extractLearnings($history);
        if (empty($learnings)) {
            return 0;
        }

        return $this->storeLearnings($learnings, $userId);
    }

    private function extractLearnings(array $history): array {
        $conversationText = $this->formatHistory($history);
        if ($conversationText === '') {
            return [];
        }

        $messages = [
            ['role' => 'system', 'content' => self::EXTRACT_PROMPT],
            ['role' => 'user', 'content' => $conversationText],
        ];

        try {
            $response = $this->chatWithCompactModel($messages);
            if ($response === null) {
                return [];
            }

            $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            if ($content === '') {
                return [];
            }

            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                error_log('Levi LearningsExtractor: Could not parse JSON: ' . substr($content, 0, 200));
                return [];
            }

            return array_filter($parsed, fn($item) => is_string($item) && mb_strlen(trim($item)) >= 10);
        } catch (\Throwable $e) {
            error_log('Levi LearningsExtractor exception: ' . $e->getMessage());
            return [];
        }
    }

    private function storeLearnings(array $learnings, int $userId): int {
        try {
            $vectorStore = new VectorStore();
            if (!$vectorStore->isAvailable()) {
                error_log('Levi LearningsExtractor: VectorStore not available');
                return 0;
            }
        } catch (\Throwable $e) {
            error_log('Levi LearningsExtractor: VectorStore init failed: ' . $e->getMessage());
            return 0;
        }

        $stored = 0;
        foreach ($learnings as $learning) {
            $learning = trim((string) $learning);
            if ($learning === '') {
                continue;
            }

            $embedding = $vectorStore->generateEmbedding($learning);
            if (is_wp_error($embedding)) {
                error_log('Levi LearningsExtractor: Embedding failed: ' . $embedding->get_error_message());
                continue;
            }

            $existing = $vectorStore->searchEpisodicMemories($embedding, $userId, 1, 0.92);
            if (!empty($existing)) {
                continue;
            }

            $ok = $vectorStore->storeEpisodicMemory($learning, $embedding, $userId, 'session_learning');
            if ($ok) {
                $stored++;
            }
        }

        $vectorStore->pruneOldEpisodicMemories(100);

        if ($stored > 0) {
            error_log("Levi LearningsExtractor: Stored {$stored} learnings for user {$userId}");
        }

        return $stored;
    }

    private function formatHistory(array $messages): string {
        $lines = [];
        foreach ($messages as $msg) {
            $role = (string) ($msg['role'] ?? 'unknown');
            $content = (string) ($msg['content'] ?? '');
            if ($content === '' || $role === 'summary') {
                continue;
            }

            $label = match ($role) {
                'user' => 'Nutzer',
                'assistant' => 'Levi',
                'system' => 'System',
                default => $role,
            };

            if (mb_strlen($content) > 2000) {
                $content = mb_substr($content, 0, 2000) . '... [gekürzt]';
            }

            $lines[] = "[{$label}]: {$content}";
        }
        return implode("\n\n", $lines);
    }

    private function chatWithCompactModel(array $messages): ?array {
        $settings = new SettingsPage();
        $provider = $settings->getProvider();

        if ($settings->getApiKeyForProvider($provider) === null) {
            return null;
        }

        $compactModel = $this->getCompactModel($settings, $provider);
        $client = AIClientFactory::createWithModel($provider, $compactModel);
        $response = $client->chat($messages);

        if (!is_wp_error($response)) {
            return $response;
        }

        error_log('Levi LearningsExtractor: API error: ' . $response->get_error_message());
        return null;
    }

    private function getCompactModel(SettingsPage $settings, string $provider): ?string {
        $allSettings = $settings->getSettings();
        $model = trim((string) ($allSettings['compact_model'] ?? $allSettings['summary_model'] ?? ''));
        if ($model !== '') {
            return $model;
        }

        $defaults = [
            'openrouter' => 'google/gemini-2.5-flash-lite',
            'openai' => 'gpt-4.1-mini',
            'anthropic' => 'claude-sonnet-4-20250514',
        ];

        return $defaults[$provider] ?? null;
    }
}
