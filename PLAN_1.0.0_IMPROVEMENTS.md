# Levi AI Agent 1.0.0 — Verbesserungsplan
## Vom guten Agenten zum Production-Grade WordPress-KI-System

**Version:** 1.0.0-draft  
**Ausgangsbasis:** 0.9.0 (feature/0.9.0)  
**Ziel:** Levi zum schnellsten, schlauesten und zuverlässigsten WordPress AI Agent auf dem Markt machen  
**Methodik:** Systematische Analyse nach Big-Player-Best-Practices (OpenAI, Anthropic, Google, Claude Code, Aider, Cursor)

---

## 🔬 Analyse: Was funktioniert gut in 0.9.0

| Bereich | Bewertung | Begründung |
|---------|-----------|------------|
| **Tool-Architektur** | ✅ Gut | 12 generische Tools, keine Deferred Loading Probleme |
| **Modulare Prompts** | ✅ Gut | Regex-Classification, ~72% Token-Einsparung |
| **State Machine** | ✅ Gut | ORPA-Loop, sichtbare Zustände |
| **Memory-System** | ⚠️ Mittel | SQLite-Vektoren funktionieren, aber skaliert schlecht |
| **UI/UX — Arbeits-Anzeige** | ❌ **Kritisch** | **Inkonsequent: mal Timeline, mal komische Mini-Texte, mal gar nichts sichtbar** |
| **UI/UX — Chat-Widget** | ⚠️ Mittel | Funktioniert, aber kein Fullscreen, kein Virtual Scrolling |
| **Observability** | ❌ Schwach | Keine Traces, keine Metriken, kein Eval-Framework |
| **Performance** | ⚠️ Mittel | Streaming OK, aber kein Caching, kein Pre-Fetching |
| **Safety** | ⚠️ Mittel | Capability-Checks OK, aber keine Guardrails, kein Circuit Breaker |
| **Code-Qualität** | ❌ Schwach | ChatController 2907 Zeilen, SettingsPage 3761 Zeilen — zu groß |

---

## 🎯 Die 10 wichtigsten Verbesserungen (priorisiert)

---

### 0. 🔥 Kritischer Hotfix: Konsistente Arbeits-Anzeige (Cursor-Style)

**Problem:** Die Darstellung von Levis Arbeitsstatus ist eine **Katastrophe der Inkonsistenz**. Der Nutzer sieht nie zuverlässig, ob Levi arbeitet, eingefroren ist, oder fertig ist. Es gibt **drei völlig unterschiedliche Darstellungsmodi**, die zufällig auftreten:

#### Inkonsistenz-Typ A: Die gute Timeline (selten)
> Manchmal erscheint eine saubere Timeline: "Completion-Check: Prüfe Datei-Vollständigkeit…" mit ✓-Checkmarks, Spinnern und Zeiten. Das sieht professionell aus — passiert aber nur bei bestimmten Tool-Phasen.

#### Inkonsistenz-Typ B: Die komischen Mini-Schnipsel (häufig)
> Statt einer Timeline erscheinen kleine, halb-transparente oder komisch formatierte Text-Blöcke wie:
> - "Der Patch schlägt fehl wegen Zeilenummern"
> - "Ich verschiebe den `require_once` auf d…"
> Diese sehen aus wie kaputte Chat-Bubbles ohne klare Struktur, Avatar oder Rahmen. Der Nutzer fragt sich: "Ist das eine Nachricht von Levi? Ein System-Log? Ein Fehler?"

#### Inkonsistenz-Typ C: Das schwarze Loch (sehr häufig)
> Levi arbeitet minutenlang an einem Plugin — aber im Chat sieht man **gar nichts**. Kein Spinner, kein Text, kein Hinweis. Der Nutzer weiß nicht, ob Levi noch lebt oder sich aufgehängt hat. Nur der Tab-Titel ändert sich (was niemand sieht, wenn der Tab im Hintergrund ist).

#### Warum passiert das?
1. **Status-Texte** (`type: "status"` / `type: "progress"`) werden nur als `typing.setLabel(text)` gesetzt — ein einzelnes `<small>`-Element im Typing-Indicator, das bei jedem Update überschrieben wird.
2. **Zwischen-Texte** vom LLM (z.B. "Ah, die Widget-Klasse fehlt noch! Ich erstelle sie sofort. 🔧") landen manchmal als `delta`-Events, manchmal als `status`-Events — je nachdem, wie das Modell antwortet.
3. **Tool-Timeline** wird nur bei `progress`-Events mit `phase: "start"` / `"done"` angezeigt. Wenn Levi aber erst "denkt" und dann ausführt, verschwindet der "denkt"-Status komplett.
4. **Kein persistenter Arbeits-Status**: Sobald Levi von "denken" zu "Tool ausführen" wechselt, wird der vorherige Status verworfen. Der Nutzer sieht nicht die Geschichte.

#### Referenz: Cursor, Claude Code, ChatGPT
| Produkt | Verhalten |
|---------|-----------|
| **Cursor** | Jeder Arbeitsschritt ist eine **eigene, persistente Chat-Bubble**. "I'll check the existing files…" bleibt stehen. Darunter erscheint "Running: read_file(…)". Wenn fertig: ✓. Der Nutzer sieht die komplette Geschichte. |
| **Claude Code** | Jeder Tool-Aufruf erscheint als **expandierbare Karte** in einer Bubble. "I'll help you with that. Let me start by reading the project structure." bleibt stehen. Tool-Aufrufe sind darunter als Liste mit ✓/✗. |
| **ChatGPT** | "Analyzing…" erscheint als **klare Status-Zeile** mit rotierendem Spinner. Wechselt zu "Browsing: site.com". Jeder Status-Wechsel ist sichtbar. |

#### Lösung: Einheitliches "Activity Stream"-Konzept

```
┌─────────────────────────────────────────────────────────┐
│ 🤖 Levi Assistant                               [×]     │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  👤 Erstelle ein Wetter-Widget für Elementor            │
│                                                         │
│  🤖 Ich starte direkt! 🚀                               │
│     ┌──────────────────────────────────────────────┐    │
│     │ ⏳ Prüfe vorhandene Plugins…                 │    │
│     │    └─ 🔍 read: wp_plugins                   │    │
│     └──────────────────────────────────────────────┘    │
│                                                         │
│  🤖 Gut, ich sehe es gibt bereits ein                   │
│     "elementor-custom-widgets" Plugin.                  │
│     Ich wähle einen eindeutigen Slug. ✨                │
│     ┌──────────────────────────────────────────────┐    │
│     │ ⏳ Erstelle Plugin-Scaffold…                 │    │
│     │    ├─ ✓ write: elementor-weather-widget.php │    │
│     │    ├─ ✓ write: assets/css/…                 │    │
│     │    └─ ⏳ write: includes/class-widget.php   │    │
│     └──────────────────────────────────────────────┘    │
│                                                         │
│  🤖 Super, das Scaffold steht! Jetzt baue ich           │
│     die Widget-Logik. 🏗️                                │
│     ┌──────────────────────────────────────────────┐    │
│     │ ⏳ Baue Widget-Klasse…                       │    │
│     │    └─ ⏳ edit: includes/class-widget.php    │    │
│     └──────────────────────────────────────────────┘    │
│                                                         │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│  [Header-Spinner] Levi arbeitet… | Abbruch [✕]          │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                         │
│  [Input-Bereich deaktiviert während Levi arbeitet]      │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

**Architektur:**
```
ActivityStream (neu, ersetzt TypingIndicator):
├── startActivity(text)           # Neue Bubble: "Ich starte direkt!"
├── updateActivity(text)          # Letzte Bubble aktualisieren
├── addToolStep(tool, status)     # Tool-Karte zur Bubble hinzufügen
├── completeActivity()            # Bubble abschließen, ✓ setzen
├── startNewActivity(text)        # Nächste Phase = neue Bubble
└── showThinking()                # Minimaler "Levi denkt…" Indicator

Jede Phase = eine eigene, persistente Bubble.
Die Tool-Timeline lebt IN der Bubble, nicht außerhalb.
Der Header zeigt IMMER einen Spinner + Status-Text.
```

**Backend-Änderungen:**
```php
// ChatController muss unterscheiden:
// - "thinking_text" → Text, den Levi "denkt" (wird als Bubble gespeichert)
// - "tool_status" → Tool-Aufruf-Status (wird als Karte in der Bubble angezeigt)
// - "final_answer" → Die eigentliche Antwort (neue Bubble)

// Aktuell: Alles geht als "status" oder "delta" — kein Unterschied.
// Neu: Explizite Event-Typen:
emitSSE("activity", ["phase" => "start", "text" => "Ich starte direkt!"]);
emitSSE("activity", ["phase" => "tool_start", "tool" => "read", "target" => "wp_plugins"]);
emitSSE("activity", ["phase" => "tool_done", "tool" => "read", "success" => true]);
emitSSE("activity", ["phase" => "complete"]);
emitSSE("assistant_text", ["text" => "Super, das Scaffold steht!"]);
```

**Frontend-Änderungen:**
```javascript
// ActivityStream-Klasse (ersetzt addTypingIndicator)
class ActivityStream {
    start(text) { /* Neue Bubble mit Spinner */ }
    update(text) { /* Letzte Bubble-Text ändern */ }
    addTool(tool, status) { /* Tool-Karte in Bubble */ }
    complete() { /* Spinner → ✓, Bubble bleibt stehen */ }
    next(text) { /* Neue Bubble, alte bleibt */ }
}

// Header-Status (immer sichtbar)
function setHeaderStatus(text, isWorking) {
    headerSpinner.classList.toggle('active', isWorking);
    headerStatusText.textContent = text;
}
```

**Akzeptanzkriterien:**
- Jeder Arbeitsschritt ist als **eigene, persistente Bubble** sichtbar
- Die Tool-Timeline erscheint **in der aktuellen Arbeits-Bubble**, nicht als separates Element
- Der Header zeigt **immer** einen Spinner + Status-Text, solange Levi arbeitet
- Es gibt **keine** halb-transparenten Mini-Schnipsel mehr
- Der Nutzer sieht **jederzeit** exakt, was Levi gerade macht
- Bei langen Aufgaben (> 10s) erscheint alle 5 Sekunden ein **Zwischen-Update** ("Noch am Arbeiten… 3/7 Dateien erstellt")

---

### 1. 🏗️ Architektur-Refactoring: Monolithen zerschneiden

**Problem:** `ChatController.php` (2.907 Zeilen) und `SettingsPage.php` (3.761 Zeilen) sind God-Classes. Das verhindert Testbarkeit, Wartbarkeit und parallele Entwicklung.

**Referenz:** Anthropic's Claude Code (modulare Subagents), OpenAI Agents SDK (klare Trennung Model/Tools/Instructions)

**Maßnahmen:**

```
ChatController (2907 Zeilen) →
├── RequestHandler          # HTTP-Layer, Routing, Auth
├── ChatSessionManager      # Session CRUD, History, Zusammenfassungen
├── MessagePipeline         # Build → Send → Parse → Stream
├── ToolLoopEngine          # V1/V2 Tool-Loop Orchestration
├── FallbackResolver        # Retry-Logik, Fallback-Kaskade
└── ResponseFormatter       # SSE-Formatting, State-Streaming

SettingsPage (3761 Zeilen) →
├── SettingsRegistry        # Config-Schema, Defaults, Validation
├── SettingsFormRenderer    # HTML-Generierung pro Tab
├── SettingsValidator       # Server-seitige Validierung
└── SettingsMigrator        # Upgrade-Pfade zwischen Versionen
```

**Akzeptanzkriterien:**
- Keine Klasse > 500 Zeilen
- Jede Klasse hat genau eine Verantwortung (SRP)
- 100% der neuen Klassen sind unit-testbar

---

### 2. 🧠 Memory-System 2.0: Von SQLite zu Hybrid-Memory

**Problem:** Die SQLite-Vektordatenbank skaliert schlecht bei >10.000 Einträgen. Cosine-Similarity in PHP ist langsam. Kein "Forgetting", keine Beziehungen zwischen Erinnerungen.

**Referenz:** Mem0 (Graph Memory + Vector + KV-Store), Anthropic (Context Compaction), LangGraph (Checkpointing)

**Maßnahmen:**

#### 2a. Hierarchisches Memory mit Scopes
```php
interface MemoryStore {
    public function remember(string $key, mixed $value, array $scopes = []): void;
    public function recall(string $query, array $scopes = [], int $limit = 5): array;
    public function forget(string $pattern, array $scopes = []): int;
    public function compact(float $targetCompression = 0.5): void;
}

// Scopes: user_id, session_id, agent_id, site_id
```

#### 2b. Graph Memory für Beziehungen
```php
class GraphMemory {
    // Neo4j-ähnlich, aber mit SQLite
    // "Plugin X" → [depends_on] → "WooCommerce"
    // "User Rin" → [prefers] → "Dark Mode"
    // "Theme Y" → [uses] → "Elementor"
    
    public function addRelation(string $from, string $relation, string $to, array $meta = []): void;
    public function query(string $startNode, int $depth = 2): array;
}
```

#### 2c. Temporal Decay (Forgetting)
```php
class EpisodicMemory {
    // Erinnerungen verlieren Relevanz über Zeit
    // Relevance = initial_relevance × e^(-decay_rate × age_days)
    // Bei Neuabruf: Relevance wird erhöht (Spaced Repetition)
    
    public function decay(): void;  // Täglicher Cron-Job
    public function reinforce(string $factId): void;  // Bei Abruf
}
```

#### 2d. Procedural Memory (Skills/Workflows)
```php
class ProceduralMemory {
    // Levi lernt Workflows aus wiederholten Aufgaben
    // "Plugin erstellen" → Schritt-für-Schritt Workflow
    // Wird gecacht und wiederverwendet
    
    public function learnWorkflow(string $taskType, array $steps): void;
    public function getWorkflow(string $taskType): ?array;
}
```

**Akzeptanzkriterien:**
- Memory-Retrieval < 50ms bei 10.000 Einträgen
- Graph Memory kann 3-Hop-Reasoning (A → B → C → D)
- Veraltete Erinnerungen werden automatisch archiviert
- Workflow-Learning reduziert Tool-Calls um 30% bei wiederholten Tasks

---

### 3. ⚡ Performance: Two-Stage Model Routing + Aggressive Caching

**Problem:** Jeder Request geht an das teure Frontier-Modell. Kein Caching für wiederholte Queries. Kein Pre-Fetching.

**Referenz:** OpenAI ("intelligence-per-dollar" Optimierung), Anthropic (Context Engineering), Google ADK (Pre-Fetching)

**Maßnahmen:**

#### 3a. Two-Stage Model Routing
```php
class ModelRouter {
    // Stage 1: Schnelles Modell für Intent-Klassifikation
    // GPT-4o-mini / Kimi 2.6 Flash — ~$0.001/Request
    public function classifyIntent(string $query): Intent;
    
    // Stage 2: Frontier-Modell nur bei Bedarf
    // GPT-4o / Kimi 2.6 — ~$0.01/Request
    public function route(Intent $intent): ModelConfig;
}
```

#### 3b. Query-Cache (Semantic Cache)
```php
class SemanticCache {
    // "Wie viele Posts habe ich?" → Cache-Hit bei ähnlicher Frage
    // "Zeig mir meine Posts" → 0.95 Similarity → Cache-Hit
    // TTL: 5 Minuten für Live-Daten, 1 Stunde für statische
    
    public function get(string $query): ?CachedResponse;
    public function set(string $query, array $response, int $ttl): void;
}
```

#### 3c. Pre-Fetching (Predictive Loading)
```php
class PredictiveLoader {
    // Wenn User "Plugin installieren" fragt:
    // → Lade Plugin-Verzeichnis im Hintergrund
    // → Lade installierte Plugins
    // → Antwort kommt sofort mit vorbereitetem Kontext
    
    public function predictAndPrefetch(string $query): void;
}
```

#### 3d. Prompt Caching für alle Provider
```php
class PromptCacheOptimizer {
    // Anthropic: cache_control blocks
    // OpenAI: automatic caching (min. 1024 Tokens)
    // OpenRouter: automatic via provider
    // Kimi: implizites Caching
    
    public function optimize(array $messages): array;
}
```

**Akzeptanzkriterien:**
- Einfache Queries: < 500ms End-to-End
- 70% der Requests gehen an günstiges Modell
- Cache-Hit-Rate > 40% für wiederholte Queries
- Token-Kosten sinken um 60%

---

### 4. 🔍 Observability: LangSmith-Style Tracing + Evals

**Problem:** Keine strukturierten Traces, keine Metriken, keine automatisierten Evals. Bugs werden nur durch User-Feedback entdeckt.

**Referenz:** LangSmith, OpenAI Traces, Anthropic's Eval-Framework, Prometheus/Grafana

**Maßnahmen:**

#### 4a. Trajectory Tracing
```php
class TrajectoryTracer {
    // Jede Anfrage wird als Trace gespeichert:
    // Input → System Prompt → LLM Call → Tool Calls → Output
    // Mit Timings, Token-Usage, State-Transitions
    
    public function startTrace(string $sessionId, string $query): Trace;
    public function logLLMCall(Trace $trace, array $payload, array $response, int $latencyMs): void;
    public function logToolCall(Trace $trace, string $tool, array $params, array $result, int $latencyMs): void;
    public function endTrace(Trace $trace, string $finalOutput, bool $success): void;
}
```

#### 4b. Metriken-Sammlung
```php
class MetricsCollector {
    // Pro Request:
    // - Latenz (p50, p95, p99)
    // - Token Usage (Prompt, Completion, Cached)
    // - Tool Success Rate
    // - Task Completion Rate
    // - Hallucination Rate (via Eval)
    // - Cost per Request
    
    public function record(RequestMetrics $metrics): void;
    public function getDashboard(): array;
}
```

#### 4c. Eval-Framework
```php
class EvalRunner {
    // Automatisierte Tests für jede Release:
    // - "Erstelle einen Post" → Prüfe: Post existiert?
    // - "Ändere Titel" → Prüfe: Titel geändert?
    // - "Installiere Plugin" → Prüfe: Plugin aktiv?
    
    public function runTestSuite(string $suiteName): EvalResult;
    public function benchmark(string $baselineVersion, string $candidateVersion): Comparison;
}
```

#### 4d. Real-Time Alerting
```php
class AlertManager {
    // Spike in Error Rate → Pause + Benachrichtigung
    // Spike in Token Usage → Anomalie-Detektion
    // Tool Success Rate < 80% → Alert
    
    public function checkThresholds(): void;
}
```

**Akzeptanzkriterien:**
- Jede Anfrage ist vollständig tracebar (Input → Output)
- Dashboard zeigt Latenz, Kosten, Success Rate in Echtzeit
- Eval-Suite läuft vor jedem Release automatisch
- Alerts bei Anomalien innerhalb von 60 Sekunden

---

### 5. 🛡️ Safety 2.0: Guardrails + Circuit Breaker + Structured Audit

**Problem:** Keine Input/Output-Guardrails. Kein Circuit Breaker bei wiederholten Fehlern. Audit-Log ist rudimentär.

**Referenz:** OpenAI Agents SDK (3 Ebenen Guardrails), Anthropic (Constitutional AI), Zylos Research (Self-Healing)

**Maßnahmen:**

#### 5a. Input Guardrails (parallel)
```php
class InputGuardrails {
    // Laufen parallel zur Anfrage:
    // 1. Prompt Injection Detection (Regex + Heuristik)
    // 2. PII Detection (bereits vorhanden, aber verbessern)
    // 3. Jailbreak Detection
    // 4. Rate Limit Check
    
    public function validate(string $input): GuardResult;
}
```

#### 5b. Output Guardrails
```php
class OutputGuardrails {
    // Nach LLM-Antwort:
    // 1. Hallucination Check (Fakten gegen WP-Datenbank prüfen)
    // 2. Policy Check (keine Anleitungen für Schadcode)
    // 3. Format Validation (JSON-Schema wenn erwartet)
    
    public function validate(string $output, string $context): GuardResult;
}
```

#### 5c. Circuit Breaker
```php
class CircuitBreaker {
    // Bei 5 Fehlern in 60 Sekunden:
    // → Tool temporär deaktivieren
    // → Agent informieren: "Tool X ist aktuell nicht verfügbar"
    // → Nach 5 Minuten Retry
    
    public function recordFailure(string $tool): void;
    public function isOpen(string $tool): bool;
}
```

#### 5d. Structured Audit Log
```php
class AuditLogger {
    // Unveränderliches Log:
    // - Timestamp, User, Session
    // - Jeder Tool Call mit Input/Output
    // - Jede Datei-Änderung mit Before/After-Hash
    // - LLM-Responses (für Compliance)
    
    public function logAction(AuditAction $action): void;
    public function getAuditTrail(string $sessionId): array;
}
```

**Akzeptanzkriterien:**
- Prompt Injection Detection > 95% Accuracy
- Circuit Breaker reagiert innerhalb von 5 Fehlern
- Audit-Log ist unveränderlich (Append-Only)
- Kein unautorisierter Zugriff auf Guardrail-Logs

---

### 6. 🔄 Tool-System 2.0: Parallele Ausführung + MCP + Subagents

**Problem:** Tools werden sequentiell ausgeführt. Keine echte Parallelisierung. Kein MCP-Standard. Keine Subagents.

**Referenz:** Anthropic MCP (10.000+ Server), OpenAI Agents SDK (Handoff), Claude Code (Subagents)

**Maßnahmen:**

#### 6a. Echte parallele Tool-Ausführung
```php
class ParallelToolExecutor {
    // Unabhängige Tool-Calls parallel ausführen:
    // read("plugin.php") + read("style.css") + get_posts()
    // → Alle gleichzeitig, nicht nacheinander
    
    public function execute(array $calls, callable $resolver): array;
}
```

#### 6b. MCP-Adapter (Model Context Protocol)
```php
class MCPAdapter {
    // Levi kann MCP-Server nutzen:
    // - Filesystem MCP (statt eigener read/write)
    // - GitHub MCP (Issues, PRs)
    // - Database MCP (SQL-Queries)
    // - WordPress MCP (eigener Server für andere Agents)
    
    public function discoverTools(string $mcpServerUrl): array;
    public function callTool(string $server, string $tool, array $params): array;
}
```

#### 6c. Subagents für komplexe Tasks
```php
class SubagentDispatcher {
    // "Erstelle ein komplettes Plugin" →
    // ├── Subagent: Scaffold erstellen
    // ├── Subagent: Admin-Seite bauen
    // ├── Subagent: Frontend-Assets erstellen
    // └── Subagent: Tests schreiben
    // Jeder Subagent hat isolierten Kontext → Hauptkontext bleibt schlank
    
    public function dispatch(string $task, array $context): SubagentResult;
}
```

#### 6d. Tool-Versionierung
```php
class VersionedToolRegistry {
    // Tools haben Versionen:
    // read@v1, read@v2
    // Bei Breaking Changes: Neue Version, alte bleibt erhalten
    
    public function register(string $name, int $version, ToolInterface $tool): void;
    public function get(string $name, ?int $version = null): ToolInterface;
}
```

**Akzeptanzkriterien:**
- Parallele Ausführung: 3x schneller für Multi-Read-Tasks
- MCP-Adapter kann 3 externe Server verbinden
- Subagents reduzieren Context-Window-Nutzung um 50%

---

### 7. 🧩 UI/UX 2.0: Fullscreen-Mode + React + Advanced Features

**Problem:** Chat-Widget ist 1800 Zeilen vanilla JS. Kein Fullscreen, kein Virtual Scrolling, keine Branching.

**Referenz:** ChatGPT, Claude, Cursor

**Maßnahmen:**

#### 7a. React-basiertes Fullscreen-UI
```
levi-ui/
├── src/
│   ├── components/
│   │   ├── ChatLayout.tsx      # Sidebar + Main
│   │   ├── MessageList.tsx     # Virtualized
│   │   ├── MessageBubble.tsx   # Markdown + Code
│   │   ├── ToolCallCard.tsx    # Tool-Visualization
│   │   ├── PlanViewer.tsx      # Plan-Anzeige + Freigabe
│   │   ├── InputArea.tsx       # Text + Upload + Voice
│   │   └── SettingsPanel.tsx   # Inline-Einstellungen
│   ├── hooks/
│   │   ├── useStreaming.ts     # SSE-Handling
│   │   ├── useSession.ts       # Session-Management
│   │   └── useMemory.ts        # Memory-Status
│   └── App.tsx
```

#### 7b. Virtual Scrolling
```tsx
// TanStack Virtual
// 10.000 Nachrichten → nur 20 im DOM
// Smooth scrolling, keine Performance-Probleme
```

#### 7c. Branching / Edit Mode
```
Message 1
├── Antwort A [regenerate]
│   └── Weiterführung...
├── Antwort B [regenerate]
│   └── Weiterführung...
└── Antwort C (aktuell)
```

#### 7d. Smart Suggestions
```
Während der Eingabe:
"Erstelle einen Post über..."
           ↓
[WordPress SEO]  [Gutenberg Blocks]  [Plugin Dev]
```

**Akzeptanzkriterien:**
- Fullscreen-Mode: 90% Viewport
- Virtual Scrolling: 60fps bei 1000 Nachrichten
- Branching: 3 Versionen pro Nachricht
- Smart Suggestions: < 200ms Latenz

---

### 8. 🧠 Reasoning 2.0: Chain-of-Thought + Reflection + Self-Healing

**Problem:** Kein explizites Chain-of-Thought. Keine Reflection. Kein Self-Healing bei Fehlern.

**Referenz:** OpenAI o-Series (Test-Time Computing), Claude Extended Thinking, ReAct-Paper

**Maßnahmen:**

#### 8a. Chain-of-Thought für komplexe Tasks
```php
class ReasoningEngine {
    // Bei komplexen Aufgaben:
    // 1. Frage LLM nach Schritt-für-Schritt-Begründung
    // 2. Führe Schritte aus
    // 3. Prüfe Konsistenz
    
    public function reason(string $task): ReasoningChain;
}
```

#### 8b. Reflection Pattern
```php
class ReflectionEngine {
    // Nach Tool-Ausführung:
    // "War das Ergebnis korrekt?"
    // "Gibt es Probleme?"
    // "Was könnte besser sein?"
    
    public function reflect(array $toolResults): Reflection;
}
```

#### 8c. Self-Healing
```php
class SelfHealingEngine {
    // Bei Fehlern:
    // 1. Analysiere Fehler
    // 2. Schlage Fix vor
    // 3. Führe Fix aus
    // 4. Verifiziere
    
    public function heal(array $error, array $context): HealingResult;
}
```

**Akzeptanzkriterien:**
- CoT reduziert Fehlerrate um 30%
- Reflection erkennt 80% der Probleme selbst
- Self-Healing löst 50% der Fehler ohne User-Intervention

---

### 9. 📊 A/B-Testing + Feature Flags

**Problem:** Kein systematisches A/B-Testing. Neue Features werden "blind" released.

**Referenz:** Google (Experiment-Platform), Netflix (Feature Flags)

**Maßnahmen:**

#### 9a. Feature Flag System
```php
class FeatureFlags {
    // Flags pro User/Session/Site:
    // "use_generic_tools" → 50% der User
    // "new_memory_system" → 10% der User (Canary)
    
    public function isEnabled(string $feature, string $userId): bool;
}
```

#### 9b. A/B-Test Framework
```php
class ABTestRunner {
    // Vergleiche zwei Varianten:
    // - Variant A: Altes Tool-System
    // - Variant B: Neues Tool-System
    // Metriken: Success Rate, Latenz, Token-Usage, User Satisfaction
    
    public function startTest(string $testName, array $variants): Test;
    public function getResults(string $testName): TestResult;
}
```

**Akzeptanzkriterien:**
- Jede Major-Feature hat A/B-Test
- Canary-Releases: 5% → 25% → 100%
- Automatische Rollback bei Regression

---

### 10. 🌐 Multi-Agent + A2A-Protokoll

**Problem:** Levi arbeitet allein. Keine Delegation, keine Zusammenarbeit mit anderen Agents.

**Referenz:** Google A2A (Agent-to-Agent), OpenAI Agents SDK (Handoff), AutoGen

**Maßnahmen:**

#### 10a. Agent Cards
```php
class AgentCard {
    // Levi beschreibt sich selbst:
    // - Name: "Levi WordPress Agent"
    // - Skills: ["WordPress", "WooCommerce", "Elementor"]
    // - Endpoint: https://site.com/wp-json/levi-agent/v1/a2a
    
    public function toArray(): array;
}
```

#### 10b. A2A-Kommunikation
```php
class A2AClient {
    // Levi kann andere Agents anrufen:
    // - "Frage den SEO-Agenten nach Keyword-Daten"
    // - "Lass den Design-Agenten ein Mockup erstellen"
    
    public function callAgent(string $endpoint, string $task): A2AResponse;
}
```

#### 10c. Skill-Marketplace
```php
class SkillRegistry {
    // Levi kann Skills dynamisch laden:
    // - "Shopify-Skill" für Shopify-Integration
    // - "SEO-Skill" für SEO-Optimierung
    // Skills sind MCP-Server oder JSON-Definitionen
    
    public function loadSkill(string $skillName): Skill;
    public function listSkills(): array;
}
```

**Akzeptanzkriterien:**
- Levi kann 3 externe Agents kontaktieren
- Skill-Marketplace hat 5 Skills
- A2A-Kommunikation < 2s Latenz

---

## 📋 Implementierungs-Roadmap

### Phase 1: Foundation (Woche 1–2) — "Solides Fundament"
**Ziel:** Architektur-Refactoring + Observability

- [ ] ChatController in 6 Klassen aufteilen
- [ ] SettingsPage in 4 Klassen aufteilen
- [ ] TrajectoryTracer implementieren
- [ ] MetricsCollector implementieren
- [ ] AuditLogger implementieren
- [ ] Circuit Breaker implementieren
- [ ] **Eval-Suite:** Architektur-Tests, Tracing-Tests

**Erfolgsmetriken:**
- Code-Coverage > 70%
- Keine God-Class > 500 Zeilen
- Jede Anfrage ist tracebar

---

### Phase 2: Memory 2.0 (Woche 3–4) — "Gedächtnis-Evolution"
**Ziel:** Hierarchisches Memory + Graph + Forgetting

- [ ] MemoryStore Interface + Implementierung
- [ ] GraphMemory mit SQLite
- [ ] Temporal Decay (Forgetting)
- [ ] Procedural Memory (Workflow-Learning)
- [ ] Memory-Komprimierung zwischen Sessions
- [ ] **Eval-Suite:** Memory-Retrieval-Tests, Forgetting-Tests

**Erfolgsmetriken:**
- Memory-Retrieval < 50ms
- Graph Memory: 3-Hop-Reasoning
- Workflow-Learning: 30% weniger Tool-Calls

---

### Phase 3: Performance (Woche 5–6) — "Speed-Dämon"
**Ziel:** Two-Stage Routing + Caching + Pre-Fetching

- [ ] ModelRouter implementieren
- [ ] SemanticCache implementieren
- [ ] PredictiveLoader implementieren
- [ ] PromptCacheOptimizer für alle Provider
- [ ] Parallele Tool-Ausführung
- [ ] **Eval-Suite:** Latenz-Benchmarks, Kosten-Benchmarks

**Erfolgsmetriken:**
- Einfache Queries < 500ms
- 70% günstige Modelle
- Cache-Hit-Rate > 40%
- Token-Kosten -60%

---

### Phase 4: Safety + Guardrails (Woche 7) — "Bunker-Modus"
**Ziel:** Production-Grade Safety

- [ ] InputGuardrails (Prompt Injection, Jailbreak)
- [ ] OutputGuardrails (Hallucination, Policy)
- [ ] Circuit Breaker für alle Tools
- [ ] Structured Audit Log
- [ ] **Eval-Suite:** Safety-Tests, Penetration-Tests

**Erfolgsmetriken:**
- Prompt Injection Detection > 95%
- Circuit Breaker: 5 Fehler → Open
- Audit-Log: Unveränderlich

---

### Phase 0: Konsistente Arbeits-Anzeige (Woche 0–1) — "Sofort-Hotfix"
**Ziel:** Levi's Arbeitsstatus ist jederzeit klar, konsistent und vertrauenswürdig sichtbar

- [ ] `ActivityStream`-Klasse im Frontend (ersetzt TypingIndicator)
- [ ] Neue SSE-Events: `activity_start`, `activity_update`, `activity_tool`, `activity_complete`
- [ ] Header-Spinner mit Status-Text (immer sichtbar während Arbeit)
- [ ] Tool-Timeline wird IN die aktuelle Arbeits-Bubble verschoben
- [ ] Status-Texte werden als **persistente Chat-Bubbles** gerendert, nicht als flüchtiges Label
- [ ] Keine halb-transparenten Mini-Schnipsel mehr
- [ ] Zwischen-Updates bei langen Aufgaben (> 10s)
- [ ] **Eval-Suite:** Manuelle Tests mit 10 typischen Workflows

**Erfolgsmetriken:**
- Nutzer weiß jederzeit, was Levi macht (Kein "schwarzes Loch")
- Keine inkonsistenten Darstellungsmodi mehr
- Tool-Timeline ist in 100% der Fälle sichtbar und gut formatiert

---

### Phase 5: UI/UX 2.0 (Woche 8–9) — "Wow-Effekt"
**Ziel:** React-basiertes Fullscreen-UI

- [ ] React-Projekt aufsetzen
- [ ] Fullscreen-Layout mit Sidebar
- [ ] Virtual Scrolling
- [ ] Tool-Visualization (baut auf ActivityStream aus Phase 0 auf)
- [ ] Plan-Viewer mit Freigabe
- [ ] Branching / Edit Mode
- [ ] Smart Suggestions
- [ ] **Eval-Suite:** UI-Tests, E2E-Tests

**Erfolgsmetriken:**
- 60fps bei 1000 Nachrichten
- Lighthouse Score > 90
- User Satisfaction > 4.5/5

---

### Phase 6: Reasoning + Self-Healing (Woche 10) — "Selbstdenkend"
**Ziel:** Bessere Reasoning + automatische Fehlerbehebung

- [ ] ReasoningEngine (CoT)
- [ ] ReflectionEngine
- [ ] SelfHealingEngine
- [ ] **Eval-Suite:** Reasoning-Tests, Self-Healing-Tests

**Erfolgsmetriken:**
- CoT: -30% Fehlerrate
- Reflection: 80% Selbsterkennung
- Self-Healing: 50% autonom gelöst

---

### Phase 7: Multi-Agent + A2A (Woche 11–12) — "Teamplayer"
**Ziel:** Agent-to-Agent Kommunikation

- [ ] AgentCard implementieren
- [ ] A2A-Client implementieren
- [ ] Skill-Marketplace
- [ ] MCP-Adapter
- [ ] **Eval-Suite:** A2A-Tests, Skill-Tests

**Erfolgsmetriken:**
- 3 externe Agents erreichbar
- 5 Skills verfügbar
- A2A-Latenz < 2s

---

## 📊 Erfolgsmetriken (1.0.0 vs. 0.9.0)

| Metrik | 0.9.0 (Baseline) | 1.0.0 (Ziel) | Messung |
|--------|-----------------|-------------|---------|
| **Avg. Latenz** | ~2.0s | < 800ms | Server-Timing + Frontend |
| **Token-Kosten/Request** | ~$0.008 | < $0.003 | API-Usage-Tracking |
| **Task Completion Rate** | ~75% | > 90% | User-Feedback + Evals |
| **Tool Success Rate** | ~80% | > 95% | Automatisch getrackt |
| **Code-Coverage** | ~30% | > 70% | PHPUnit + Jest |
| **God-Classes** | 2 | 0 | Static Analysis |
| **Cache-Hit-Rate** | 0% | > 40% | SemanticCache-Metrics |
| **User Satisfaction** | N/A | > 4.5/5 | In-Chat-Rating |
| **Prompt Injection Block** | 0% | > 95% | Safety-Evals |
| **Self-Healing Rate** | 0% | > 50% | Error-Tracking |

---

## 🎯 Zusammenfassung: Die 3 Säulen von Levi 1.0.0

### Säule 1: Geschwindigkeit (Speed)
- Two-Stage Model Routing
- Semantic Cache
- Parallele Tool-Ausführung
- Pre-Fetching

### Säule 2: Intelligenz (Smarts)
- Graph Memory + Reasoning
- Workflow-Learning
- Reflection + Self-Healing
- Subagents + A2A

### Säule 3: Zuverlässigkeit (Reliability)
- Guardrails auf 3 Ebenen
- Circuit Breaker
- Structured Audit Logs
- A/B-Testing + Canary

---

> **Kernprinzip:** Levi 1.0.0 wird nicht durch mehr Features besser — sondern durch bessere Architektur, bessere Observability und bessere Sicherheit. Die Features kommen von allein, wenn das Fundament stimmt.
