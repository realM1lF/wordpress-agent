<?php

namespace Levi\Agent\AI;

use Levi\Agent\Admin\SettingsPage;

class SessionSummarizer {

    private const SUMMARY_SYSTEM_PROMPT = <<<'PROMPT'
Du bist ein präziser Zusammenfasser für Chat-Verläufe zwischen einem WordPress-Admin und seinem KI-Assistenten "Levi".

Erstelle eine strukturierte Zusammenfassung der folgenden Chat-Nachrichten. Die Zusammenfassung wird genutzt, damit Levi in späteren Nachrichten den Kontext der Session nicht verliert.

REGELN:
1. Behalte JEDE konkrete Anforderung/Wunsch des Nutzers bei (mit Details wie IDs, Namen, Werte)
2. Behalte JEDE getroffene Entscheidung bei (z.B. "Option A gewählt", "Farbe soll grün sein", "Produkt-ID 21")
3. Dokumentiere JEDE durchgeführte Aktion und ihr Ergebnis (welches Tool, Erfolg/Fehler)
4. Dokumentiere offene Probleme oder ungelöste Punkte
5. Halte die chronologische Reihenfolge ein (was kam zuerst, was danach)
6. Komprimiere NUR: Höflichkeitsfloskeln, Wiederholungen, technische Details die im Ergebnis bereits enthalten sind
7. Wenn eine vorherige Zusammenfassung existiert, integriere sie nahtlos mit den neuen Nachrichten

FORMAT:
## Session-Zusammenfassung (Nachrichten 1–{N})

**Ziel des Nutzers:** [Was der Nutzer in dieser Session erreichen wollte]

**Getroffene Entscheidungen:**
- [Entscheidung 1 mit Details]
- [Entscheidung 2 mit Details]

**Durchgeführte Aktionen:**
1. [Aktion 1] → [Ergebnis]
2. [Aktion 2] → [Ergebnis]

**Aktueller Stand:** [Was ist erledigt, was ist offen]

**Wichtige Details:** [Konkrete Werte, IDs, Einstellungen die nicht verloren gehen dürfen]
PROMPT;

    private const COMPACT_MODEL_DEFAULTS = [
        'openrouter' => 'google/gemini-2.5-flash-preview',
        'openai' => 'gpt-4.1-mini',
        'anthropic' => 'claude-sonnet-4-20250514',
    ];

    /**
     * Summarize older messages that would otherwise be dropped from context.
     *
     * @param array $messagesToSummarize Messages that will be dropped (chronological order)
     * @param string|null $existingSummary Previous summary to integrate (if any)
     * @param int $totalMessageCount Total messages in the session
     * @param int $summarizedUpToIndex Index of the last message being summarized
     * @return string|null The summary text, or null on failure
     */
    public function summarize(
        array $messagesToSummarize,
        ?string $existingSummary,
        int $totalMessageCount,
        int $summarizedUpToIndex
    ): ?string {
        if (empty($messagesToSummarize)) {
            return null;
        }

        $conversationText = $this->formatMessagesForSummary($messagesToSummarize);
        if ($conversationText === '') {
            return null;
        }

        $userPrompt = '';
        if ($existingSummary !== null && $existingSummary !== '') {
            $userPrompt .= "## Vorherige Zusammenfassung\n\n" . $existingSummary . "\n\n---\n\n";
        }
        $userPrompt .= "## Neue Nachrichten zum Zusammenfassen\n\n" . $conversationText;
        $userPrompt .= "\n\n---\n\nErstelle jetzt die Zusammenfassung für Nachrichten 1–{$summarizedUpToIndex} von {$totalMessageCount}.";

        $messages = [
            ['role' => 'system', 'content' => self::SUMMARY_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        try {
            $response = $this->chatWithFallback($messages);
            if ($response === null) {
                return null;
            }

            $summary = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            if ($summary === '') {
                error_log('Levi SessionSummarizer: Empty summary returned');
                return null;
            }

            return $summary;
        } catch (\Throwable $e) {
            error_log('Levi SessionSummarizer exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Try compact model first, fall back to primary model on failure.
     */
    private function chatWithFallback(array $messages): ?array {
        $settings = new SettingsPage();
        $provider = $settings->getProvider();

        if ($settings->getApiKeyForProvider($provider) === null) {
            error_log('Levi SessionSummarizer: No API key for provider ' . $provider);
            return null;
        }

        $compactModel = $this->getCompactModel($settings, $provider);
        $compactClient = AIClientFactory::createWithModel($provider, $compactModel);
        $response = $compactClient->chat($messages);

        if (!is_wp_error($response)) {
            return $response;
        }

        $compactError = $response->get_error_message();
        error_log('Levi SessionSummarizer: compact model failed (' . ($compactModel ?? 'default') . '): ' . $compactError);

        // Fallback to primary model (only if compact model was different)
        $primaryModel = $settings->getModelForProvider($provider);
        if ($compactModel !== null && $compactModel !== $primaryModel) {
            error_log('Levi SessionSummarizer: falling back to primary model (' . ($primaryModel ?? 'default') . ')');
            $primaryClient = AIClientFactory::createWithModel($provider, $primaryModel);
            $fallbackResponse = $primaryClient->chat($messages);

            if (!is_wp_error($fallbackResponse)) {
                return $fallbackResponse;
            }

            error_log('Levi SessionSummarizer: primary fallback also failed: ' . $fallbackResponse->get_error_message());
        }

        return null;
    }

    private function formatMessagesForSummary(array $messages): string {
        $lines = [];
        foreach ($messages as $i => $msg) {
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

            $lines[] = "[{$label}]: {$content}";
        }
        return implode("\n\n", $lines);
    }

    /**
     * Resolve the model to use for compaction/summarization.
     * Priority: explicit setting > provider default > null (use provider's default model).
     */
    private function getCompactModel(SettingsPage $settings, string $provider): ?string {
        $allSettings = $settings->getSettings();

        // Check new key first, then legacy key for backwards compat
        $model = trim((string) ($allSettings['compact_model'] ?? $allSettings['summary_model'] ?? ''));
        if ($model !== '') {
            return $model;
        }

        return self::COMPACT_MODEL_DEFAULTS[$provider] ?? null;
    }
}
