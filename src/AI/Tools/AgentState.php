<?php

namespace Levi\Agent\AI\Tools;

/**
 * ORPA State Machine States
 *
 * IDLE → OBSERVING → REASONING → (PLANNING | EXECUTING) → VERIFYING → (DONE | ERROR)
 */
enum AgentState: string
{
    case IDLE = "idle";
    case OBSERVING = "observing";
    case REASONING = "reasoning";
    case PLANNING = "planning";
    case EXECUTING = "executing";
    case VERIFYING = "verifying";
    case DONE = "done";
    case ERROR = "error";

    /**
     * Valid state transitions.
     */
    public function canTransitionTo(self $next): bool
    {
        $valid = match ($this) {
            self::IDLE => [self::OBSERVING],
            self::OBSERVING => [
                self::REASONING,
                self::PLANNING,
                self::DONE,
                self::ERROR,
            ],
            self::REASONING => [
                self::PLANNING,
                self::EXECUTING,
                self::DONE,
                self::ERROR,
            ],
            self::PLANNING => [self::EXECUTING, self::IDLE, self::ERROR],
            self::EXECUTING => [self::VERIFYING, self::REASONING, self::ERROR],
            self::VERIFYING => [self::DONE, self::EXECUTING, self::ERROR],
            self::DONE => [self::IDLE],
            self::ERROR => [self::IDLE],
        };
        return in_array($next, $valid, true);
    }

    /**
     * Human-readable German label for UI/streaming.
     */
    public function label(): string
    {
        return match ($this) {
            self::IDLE => "Bereit",
            self::OBSERVING => "Analysiere Anfrage...",
            self::REASONING => "Levi denkt nach...",
            self::PLANNING => "Erstelle Plan...",
            self::EXECUTING => "Führe aus...",
            self::VERIFYING => "Verifiziere Ergebnisse...",
            self::DONE => "Fertig",
            self::ERROR => "Fehler",
        };
    }

    /**
     * Whether this state should be shown to the user via SSE.
     */
    public function isVisible(): bool
    {
        return match ($this) {
            self::IDLE => false,
            self::OBSERVING => true,
            self::REASONING => true,
            self::PLANNING => true,
            self::EXECUTING => true,
            self::VERIFYING => true,
            self::DONE => true,
            self::ERROR => true,
        };
    }
}
