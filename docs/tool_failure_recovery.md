# Tool Failure Recovery System (Option B)

## Ziel
Wenn Tools fehlschlagen, soll Levi explizit nachfragen was zu tun ist.

## Implementierung

### 1. Neue Klasse: ToolFailureHandler
```php
class ToolFailureHandler {
    public function hasFailedTools(array $toolResults): bool;
    public function getFailureSummary(array $toolResults): array;
    public function buildRecoveryPrompt(array $failures): string;
}
```

### 2. ChatController Anpassung
Nach Tool-Execution:
```php
if ($this->hasFailedTools($toolResults)) {
    // Stoppe Auto-Execution
    // Sende Recovery-Optionen an User
    $options = $this->buildRecoveryOptions($failures);
    $this->emitSSE('choices', $options);
    return; // Warte auf User-Antwort
}
```

### 3. Frontend Anpassung
Zeige Buttons:
- [Ohne Bilder veröffentlichen]
- [Nochmal versuchen]  
- [Andere Bilder suchen]
- [Abbrechen]

### 4. Recovery-Context speichern
```php
// In Session speichern
$_SESSION['levi_recovery'] = [
    'failed_tools' => [...],
    'partial_results' => [...],
    'original_request' => ...
];
```

## Schnellere Alternative (für jetzt)

Einfach den Prompt ändern:

**Statt:**
> "Ich kann fehlgeschlagene Schritte erneut ausführen"

**Besser:**
> "Bilder konnten nicht hochgeladen werden. Der Post wurde mit externen Bildlinks erstellt. Du kannst die Bilder später manuell hinzufügen, oder sag mir: 'Versuche die Bilder nochmal'"

## Entscheidung

**A) Schneller Fix (Prompt-Änderung)** - 5 Minuten
**B) Vollständiges Recovery-System** - 30-45 Minuten  
**C) Beides** - Prompt jetzt, Recovery später

Welche Variante?
