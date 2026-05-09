# Levi Agent — Architektur-Refactoring Status

## Ausgangslage
- Schritt 1 aus `PLAN_1.0.0_IMPROVEMENTS.md` wird umgesetzt: "Architektur-Refactoring: Monolithen zerschneiden"
- Ziel: ChatController (2.917 Zeilen) → 6 Klassen aufteilen, keine Klasse > 500 Zeilen

## Bereits erledigt

### ✅ RequestHandler — `src/API/RequestHandler.php` (207 Zeilen)
- HTTP-Layer, Routing, Auth, Rate-Limiting, SSE-Stream-Setup
- Thin Endpoints: getStatus, getHistory, getUserSessions, testConnection

### ✅ ChatSessionManager — `src/API/ChatSessionManager.php` (190 Zeilen)
- Session CRUD, History, Zusammenfassungen
- `prepareSessionContext()` kapselt den duplizierten Setup-Code aus processMessage() + processMessageStreaming()
- Alle DB-Operationen entkoppelt vom ChatController

### ✅ MessagePipeline — `src/API/MessagePipeline.php` (558 Zeilen)
- **Aus ChatController extrahiert:**
  - `chatWithTracking()` — blocking chat + usage accumulation
  - `streamChatWithTracking()` — streaming chat + usage accumulation
  - `streamContinuation()` — post-tool streaming with fallback
  - `accumulateUsage()` / `accumulateStreamUsage()` / `flushUsage()`
- **Aus `Concerns\BuildsContext` Trait migriert:**
  - `buildMessages()` / `buildMessagesLight()` — Kontextaufbau
  - `getMinimalSystemPrompt()`
- ChatController behält dünne Delegations-Methoden für Trait-Zugriff (`chatWithTracking`, `streamContinuation`, `flushUsage`)
- Traits nutzen `getUsageAccumulator()` statt direktem Property-Zugriff
- **Impact:** −245 Zeilen im ChatController (2.713 → 2.468)

### Aktuelle Zeilenanzahl
| Klasse | Zeilen |
|--------|--------|
| ChatController | 1.839 |
| RequestHandler | 207 |
| ChatSessionManager | 190 |
| MessagePipeline | 558 |

## Noch offen (in Reihenfolge)

### ✅ ToolLoopEngine — `src/API/ToolLoopEngine.php` (3.563 Zeilen)
- **Aus ChatController extrahiert:**
  - `validateToolCall()` / `isMutatingToolName()` / `hasSuccessfulMutation()` / `hasFailedMutation()` / `enforceMutationGate()`
  - `inferTaskIntent()` / `trackOwnedPluginFromToolResult()` / Plugin-Ownership-Helfer
  - `classifyMutationIntent()` / `classifyTaskCompleteness()`
- **Aus `Concerns\ExecutesToolLoop` komplett migriert:**
  - `handleToolCalls()` / `handleToolCallsStreaming()`
  - Alle privaten Helfer (`partitionToolCalls`, `detectToolLoop`, `executeToolWithAutopaging`, `logToolExecution`, etc.)
- **Aus `Concerns\ExecutesToolLoopV2` komplett migriert:**
  - `handleToolCallsV2()` / `handleToolCallsStreamingV2()`
  - Alle V2-Helfer (`getToolProgressLabelV2`, `recoverStreamedContentOrFallbackV2`, `buildSpecializedFallbackHint`)
- **ChatController-Anpassungen:**
  - `use Concerns\ExecutesToolLoop` und `use Concerns\ExecutesToolLoopV2` entfernt
  - `ToolLoopEngine` wird im Konstruktor initialisiert
  - Alle Aufrufe auf `$this->toolLoopEngine->…` umgeleitet
  - Dünne Delegationen für `getToolProgressLabel()`, `summarizeToolResult()`, `isWriteTool()` (werden von verbleibenden Traits/Controller aufgerufen)
  - Sichtbarkeit aller von der Engine aufgerufenen Methoden auf `public` angehoben (PHP-Regel: separate Klasse)
- **Impact:** −629 Zeilen. Tool-Loop-Logik vollständig extrahiert. Dead methods entfernt (`inferTaskIntent`, `enforceMutationGate`, Mutation-Gate-Helfer, Plugin-Ownership-Helfer).

### 5. FallbackResolver — `src/API/FallbackResolver.php` (geplant)
- **Methoden extrahieren:**
  - Retry-Logik in `processMessage()` und `processMessageStreaming()`
  - `isEmptyAiResponse()` / `getEmptyResponseFallback()` / `recoverStreamedContentOrFallback()`
  - `isNoEndpointsError()` / `isTimeoutError()` / `halveHistory()`
- **Impact:** ~−200 Zeilen

### 6. ResponseFormatter — `src/API/ResponseFormatter.php` (geplant)
- **Methoden extrahieren:**
  - `emitSSE()` — SSE-Formatting
  - `streamResultToResponse()` — Array-Normalisierung
  - `sanitizeAssistantMessageContent()` / `appendTruncationHint()` / `wasResponseTruncated()`
  - `applyResponseSafetyGates()` / `appendCreationHintIfNeeded()`
- **Impact:** ~−150 Zeilen

## Akzeptanzkriterien (laut Plan)
- Keine Klasse > 500 Zeilen
- Jede Klasse hat genau eine Verantwortung (SRP)
- Keine Breaking Changes am REST-API-Interface
- SSE-Stream bleibt funktionsfähig

## Wichtige Hinweise für die Fortsetzung
- Der ChatController ist weiterhin `WP_REST_Controller` und registriert alle Routen via `register_routes()`
- Die `Concerns/`-Traits enthalten noch sehr viel Logik (BuildsContext: 26K, ExecutesToolLoop: 77K, ExecutesToolLoopV2: 31K, PostProcessesToolResults: 73K)
- Die Traits sollten idealerweise in die neuen Klassen migriert werden, nicht dupliziert bleiben
- `conversationRepo` wird im ChatController nur noch initialisiert und an Dependencies weitergegeben
- **Bekannte TODOs:** Alte Trait-Dateien `ExecutesToolLoop.php` und `ExecutesToolLoopV2.php` sind noch vorhanden aber tot (keine `use`-Referenz mehr). Cleanup in separatem Commit.
- **Korrigiert nach Git-Mishap:** `git checkout` hat ChatController auf 2906 Zeilen zurückgesetzt; manuelle Wiederherstellung und Nacharbeitung der broken internal references.
- `MessagePipeline` ist mit 558 Zeilen knapp über dem 500-Zeilen-Limit; `streamResultToResponse()` wandert in Schritt 6 (ResponseFormatter), was ~20 Zeilen spart
