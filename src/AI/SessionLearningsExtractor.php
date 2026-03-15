<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;
use Levi\Agent\Memory\VectorStore;

class SessionLearningsExtractor {

    private const EXTRACT_PROMPT = <<<'PROMPT'
Analysiere diesen Chat-Verlauf zwischen einem WordPress-Admin und seinem KI-Assistenten "Levi".

Extrahiere konkrete, spezifische Learnings für zukünftige Sessions.

GUTE Learnings (KONKRET — so sollen sie aussehen):
- "User bevorzugt Kadence-Theme CSS-Variablen statt eigener Farbwerte"
- "Bei REST-API-Endpunkten: Admin-Klassen sind im Frontend-Kontext nicht verfügbar — get_option() direkt nutzen"
- "Option-Keys zwischen Admin-Settings und Frontend-Code müssen identisch sein — Mismatch war Fehlerursache"
- "User will bei Plugin-Erstellung immer erst einen kurzen Plan sehen"

SCHLECHTE Learnings (VAGE — diese NICHT extrahieren):
- "Features müssen funktionieren und testbar sein"
- "Code muss korrekt gerendert werden"
- "Fehler sofort beheben"
- "Vollständige Implementierungen liefern"

EXTRAHIEREN:
- User-Präferenzen (Design, Kommunikation, Workflows)
- Konkrete technische Fehler: Was war falsch → was ist richtig? (mit Detail)
- Site-spezifische Fakten (Naming-Konventionen, bevorzugte Patterns)
- Korrekturen des Users als Regel für die Zukunft

NICHT EXTRAHIEREN:
- Smalltalk, Höflichkeiten
- Einmalige erledigte Aktionen ("hat Post X erstellt")
- Aktueller Systemzustand (Plugins, Themes — kann sich ändern)
- Selbstverständlichkeiten die jeder Entwickler weiß
- Vage Meta-Aussagen ("sorgfältig arbeiten", "Fehler vermeiden", "vollständig implementieren")

Wenn der Chat keine verwertbaren Learnings enthält: antworte mit []

FORMAT: NUR ein JSON-Array mit Strings. Keine Erklärungen.
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

        $allSettings = $settings->getSettings();
        $model = $this->resolveModel($allSettings, $provider);
        $client = AIClientFactory::createWithModel($provider, $model);
        $response = $client->chat($messages);

        if (!is_wp_error($response)) {
            return $response;
        }

        error_log('Levi LearningsExtractor: API error: ' . $response->get_error_message());
        return null;
    }

    private function resolveModel(array $settings, string $provider): string {
        $providerKey = match ($provider) {
            'openrouter' => 'openrouter_model',
            'openai' => 'openai_model',
            'anthropic' => 'anthropic_model',
            default => '',
        };

        $model = trim((string) ($settings[$providerKey] ?? ''));
        if ($model !== '') {
            return $model;
        }

        return match ($provider) {
            'openrouter' => 'moonshotai/kimi-k2.5',
            'openai' => 'gpt-4.1-mini',
            'anthropic' => 'claude-sonnet-4-20250514',
            default => 'moonshotai/kimi-k2.5',
        };
    }
}
