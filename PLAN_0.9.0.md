# Levi AI Agent 0.9.0 — Umsetzungsplan
## Vom komplexen Tool-Stack zum "Anthropic-Level" WordPress-Agenten

**Version:** 0.1.0-draft  
**Branch:** `feature/0.9.0`  
**Ziel:** Levi zum besten WordPress AI Agent Plugin auf dem Markt machen  
**Kernprinzip:** *"Trust the LLM more, guard it less"*

---

## Executive Summary

Levi 0.8.0 ist technisch beeindruckend, aber **über-engeniert**. Wir haben 44 spezialisierte Tools, 15+ Post-Execution Guards, Deferred Tool Loading und eine Query Classification — und trotzdem ist die Zuverlässigkeit nicht da, wo sie sein sollte.

Die besten Agenten der Branche (Claude Code, Aider, Cursor) funktionieren anders:
- **Wenige generische Tools** statt vieler spezialisierter
- **Tool-Wissen im Prompt** statt in Tool-Schemas
- **Plan-Execute-Verify** statt "Hoffen-dass-es-klappt"
- **Vertrauen statt Überwachung**

Dieser Plan beschreibt die Migration von Levis aktuellem Architektur-Stack zu einem schlankeren, zuverlässigeren System — **ohne das akkumulierte Tool-Wissen zu verlieren**.

---

## 1. Problemanalyse: Was bei 0.8.0 nicht funktioniert

### 1.1 Das "Deferred Loading" Henne-Ei-Problem

```
Nutzer: "Erstelle einen WooCommerce-Gutschein"
LLM sieht: 20 Core-Tools (kein manage_woocommerce!)
LLM denkt: "Ich habe kein Tool für Gutscheine"
LLM tut: Gibt auf oder nutzt execute_wp_code (unsicher)
```

Das LLM kann `search_tools` nicht aufrufen, um ein Tool zu finden, von dem es nicht weiß, dass es fehlt. Deferred Loading funktioniert nur, wenn das Modell **bewusst** eine Lücke erkennt — das passiert selten.

### 1.2 Das "Too Many Guards" Problem

Nach jedem Tool-Call werden 10+ System-Nachrichten injiziert:
- Post-Write Validation
- Post-Patch Verification  
- Post-Create-Plugin Nudge
- Post-CSS-Write Nudge
- Smoke Test
- Environment Warnings
- Integration Check
- Reverse Dependency Warnings
- Reference Check
- Code Tag Warnings
- Tool Mismatch Correction
- Working Set Summary
- Completion Gate
- Mutation Gate

**Das sind 5.000–15.000 Tokens pro Iteration.** Nach 3–4 Tool-Calls ist das Kontextfenster voll. Das Modell "vergisst" die ursprüngliche Aufgabe.

### 1.3 Das "Always Load Everything" Problem

```php
// BuildsContext.php
private const ALL_RULE_MODULES = [
    'core', 'tools', 'coding', 'planning',
    'frontend', 'elementor', 'woocommerce', 'cron'
];
```

Selbst für "Hallo, wie geht's?" werden alle 8 Module (ca. 15.000+ Tokens) geladen. Der SIMPLE fast-path spart Tool-Calls, aber keine Prompt-Tokens.

### 1.4 Das "44 spezialisierte Tools" Problem

Jedes Tool hat ein JSON-Schema, das Tokens frisst. Bei 44 Tools sind das 8.000–12.000 Tokens nur für Tool-Definitions. Das LLM muss bei jedem Call aus 44 Optionen wählen — die Fehlerrate steigt mit der Anzahl.

> *Research finding: Tool choice accuracy drops significantly beyond 15–20 tools. At 44 tools, the model often "guesses" the wrong one or calls parameters incorrectly.*

### 1.5 Das "Extra API Call for Classification" Problem

Vor jedem Request wird ein zusätzlicher LLM-Call für die Query Classification gemacht (SIMPLE/CRUD/COMPLEX). Das kostet:
- Zeit (+200–500ms Latenz)
- Tokens (+500–1.000)
- Zuverlässigkeit (wenn die Klassifizierung falsch ist, fehlen Memories oder Tools)

---

## 2. Ziel-Architektur: Der "Anthropic-Level" Agent

### 2.1 Grundprinzipien

| Prinzip | Beschreibung |
|---------|-------------|
| **Generische Tools** | ~12 universelle Tools statt 44 spezialisierter |
| **Wissen im Prompt** | Tool-Spezifika in Identity/.md-Files, nicht in JSON-Schemas |
| **Modulare Prompts** | Nur relevante Regeln laden (nicht alle 8 Module) |
| **Plan-First** | Bei komplexen Aufgaben: Plan zeigen → Freigabe → Ausführen |
| **Weniger Guards** | 3 klare System-Anweisungen statt 15+ Injections |
| **State Machine** | Formaler ORPA-Loop (Observe-Reason-Plan-Act) |

### 2.2 Die neue Tool-Architektur (12 Tools statt 44)

Inspiriert von Claude Code (Bash, Read, Edit, Write, Grep, Glob) und Aider:

| # | Tool | Beschreibung | Ersetzt |
|---|------|-------------|---------|
| 1 | `read` | Datei(en) lesen (WP-Content, Plugins, Themes, Logs) | get_posts, get_pages, get_users, get_plugins, get_media, read_plugin_file, read_theme_file, read_error_log, get_options |
| 2 | `write` | Datei erstellen/überschreiben | create_post, create_page, write_plugin_file, write_theme_file, create_plugin, create_theme |
| 3 | `edit` | Search-and-replace in Dateien | patch_plugin_file, patch_theme_file, update_post, update_option |
| 4 | `list` | Verzeichnis/Struktur auflisten | list_plugin_files, list_theme_files, get_posts, get_pages, get_plugins |
| 5 | `grep` | Text/Regex-Suche in Dateien | grep_plugin_files, grep_theme_files |
| 6 | `execute` | PHP-Code in Sandbox ausführen | execute_wp_code |
| 7 | `install` | Plugin/Theme installieren/aktivieren | install_plugin, switch_theme |
| 8 | `manage_wp` | WordPress CRUD-Operationen (Posts, Pages, Users, Options, Taxonomies, Menus) | create_post, update_post, delete_post, manage_user, manage_taxonomy, manage_menu, update_any_option |
| 9 | `manage_wc` | WooCommerce-Operationen | manage_woocommerce, get_woocommerce_data, get_woocommerce_shop |
| 10 | `manage_elementor` | Elementor-Operationen | elementor_build, manage_elementor, get_elementor_data |
| 11 | `fetch` | HTTP-Requests (Frontend-Test, CSS-Analyse) | http_fetch |
| 12 | `search_tools` | Tool-Referenz durchsuchen (nur für Transition) | search_tools |

**Wichtig:** Die 12 Tools sind **generisch** in ihrer Signatur, aber das `identity/rules/tools.md` beschreibt detailliert, wie sie für WordPress-Szenarien genutzt werden.

### 2.3 Tool-Wissen: Von JSON-Schema zu Prompt-Knowledge

**Vorher (0.8.0):**
```json
{
  "name": "create_plugin",
  "description": "Creates a new plugin scaffold... plugin_type: woocommerce|elementor|block...",
  "parameters": { ...20 Parameter... }
}
```

**Nachher (0.9.0):**
```json
{
  "name": "write",
  "description": "Create or overwrite a file. For plugin scaffolding, see knowledge/rules/coding.md",
  "parameters": {
    "path": "string — absolute path",
    "content": "string — file content"
  }
}
```

**Das Wissen wandert in `identity/knowledge.md`:**
```markdown
## Plugin Scaffolding
When creating a new WordPress plugin, use `write` to create these files:
1. `{slug}/{slug}.php` — Main file with plugin header
2. `{slug}/includes/` — Business logic
3. ...

For WooCommerce plugins: Include HPOS compatibility header.
For Elementor plugins: Include dependency check.
For Block plugins: Create `block.json`, `src/index.js`, `src/render.php`.
```

**Vorteil:** Das LLM kann sein Training + das Knowledge-File nutzen, um korrekte Plugin-Strukturen zu generieren. Es muss nicht ein spezialisiertes Tool finden — es weiß, was zu tun ist.

---

## 3. Identity-System Refactoring

### 3.1 Wirklich modulare Prompts

**Vorher:**
```php
private const ALL_RULE_MODULES = [
    'core', 'tools', 'coding', 'planning',
    'frontend', 'elementor', 'woocommerce', 'cron'
];
```

**Nachher:**
```php
private function getRuleModulesForQuery(string $query): array {
    $type = $this->classifyQueryType($query); // Regex-based, NO LLM call
    
    $modules = match($type) {
        'SIMPLE' => ['core'],
        'READ'   => ['core', 'tools'],
        'WRITE'  => ['core', 'tools', 'coding'],
        'DEV'    => ['core', 'tools', 'coding', 'planning'],
        default  => ['core', 'tools', 'coding'],
    };
    
    // Lazy append: only if keywords match
    if (str_contains($query, 'WooCommerce')) $modules[] = 'woocommerce';
    if (str_contains($query, 'Elementor')) $modules[] = 'elementor';
    if (str_contains($query, 'Cron')) $modules[] = 'cron';
    if (str_contains($query, 'Frontend') || str_contains($query, 'CSS')) $modules[] = 'frontend';
    
    return array_unique($modules);
}
```

**Wichtig:** Die Klassifizierung ist **Regex-basiert** (kein LLM-Call). Sie kostet ~0ms und ist deterministisch.

### 3.2 Neue Identity-Struktur

```
identity/
├── soul.md                    # Persönlichkeit (immer laden)
├── knowledge.md               # Domänenwissen (immer laden)
├── rules/
│   ├── core.md               # Safety, Kommunikation, Identität (immer laden)
│   ├── tools.md              # Tool-Referenz (nur bei Tool-Nutzung)
│   ├── coding.md             # Coding-Standards (nur bei Dev-Tasks)
│   ├── planning.md           # Planungsregeln (nur bei komplexen Tasks)
│   ├── frontend.md           # Frontend-Qualität (nur bei CSS/JS)
│   ├── woocommerce.md        # WC-Spezifika (nur bei WC-Keywords)
│   ├── elementor.md          # Elementor-Spezifika (nur bei Elementor-Keywords)
│   └── cron.md               # Cron-Spezifika (nur bei Cron-Keywords)
```

### 3.3 Prompt-Caching-Optimierung

**Stable Part** (identisch pro Runde, cacheable):
- `soul.md`
- `knowledge.md`
- Geladene Rule-Module

**Dynamic Part** (pro Request):
- Dynamic Context (User, Site, WP-Version)
- Memory-Kontext (nur bei COMPLEX)
- State Snapshot (nur bei Bedarf)

Dies ermöglicht Provider-seitiges Prompt Caching (Anthropic `cache_control`, OpenRouter automatic caching).

---

## 4. State Machine: Der ORPA-Loop

### 4.1 Warum eine State Machine?

Levi 0.8.0 hat einen freien Tool-Loop:
```
User → LLM → Tool → LLM → Tool → LLM → Done
```

Das Problem: Es gibt keine explizite Planungsphase. Das Modell "driftet" oft — es verliert das Ziel aus den Augen.

**Claude Code und professionelle Agents nutzen einen formalen Loop:**
```
IDLE → OBSERVE → REASON → PLAN → EXECUTE → VERIFY → (loop or DONE)
```

### 4.2 Levis ORPA-Implementierung

```php
enum AgentState: string {
    case IDLE = 'idle';
    case OBSERVING = 'observing';
    case REASONING = 'reasoning';
    case PLANNING = 'planning';
    case EXECUTING = 'executing';
    case VERIFYING = 'verifying';
    case DONE = 'done';
    case ERROR = 'error';
}
```

**State Transitions (deterministisch):**

| Von State | Nach State | Trigger |
|-----------|-----------|---------|
| IDLE | OBSERVING | User-Nachricht empfangen |
| OBSERVING | REASONING | Kontext geladen |
| REASONING | PLANNING | Aufgabe ist komplex (>2 Tools oder Plugin-Dev) |
| REASONING | EXECUTING | Aufgabe ist einfach (1–2 Tools) |
| PLANNING | EXECUTING | User hat Plan freigegeben |
| PLANNING | IDLE | User hat Plan abgelehnt |
| EXECUTING | VERIFYING | Alle Tools ausgeführt |
| EXECUTING | REASONING | Tool-Fehler → Neubewertung |
| VERIFYING | DONE | Verifikation bestanden |
| VERIFYING | EXECUTING | Verifikation fehlgeschlagen → Fix |
| ANY | ERROR | Unbehebbarer Fehler |

### 4.3 Die PLANNING-Phase (neu)

Bei komplexen Aufgaben (Plugin-Erstellung, Multi-File-Edits) zeigt Levi einen Plan:

```
Nutzer: "Baue ein Plugin für Event-Buchungen"

Levi (PLANNING):
"Ich plane das Plugin 'levi-events':
1. Scaffold erstellen (levi-events.php, includes/, assets/)
2. Custom Post Type 'event' registrieren
3. Admin-Metaboxen für Datum/Ort
4. Frontend-Template mit Container Queries
5. Aktivierung + Rewrite-Flush

Soll ich sofort loslegen?"
```

**Technisch:**
- Plan wird als `plan.md` im Session-Context gespeichert
- Jeder EXECUTING-Schritt prüft gegen den Plan
- Wenn das Modell vom Plan abweicht → System-Nachricht: "Bleibe beim Plan: Schritt 3 von 5"

### 4.4 Die VERIFYING-Phase (vereinfacht)

Statt 15+ Post-Execution-Guards:

```php
private function verifyExecution(array $toolResults, array $plan): array {
    $issues = [];
    
    // 1. Plan-Completeness: Hat der Plan alle Schritte?
    foreach ($plan['steps'] as $step) {
        if (!$this->wasStepExecuted($step, $toolResults)) {
            $issues[] = "Schritt fehlt: {$step['description']}";
        }
    }
    
    // 2. Write-Verification: Wurden geschriebene Dateien verifiziert?
    foreach ($toolResults as $result) {
        if ($result['tool'] === 'write' && !($result['verified'] ?? false)) {
            $issues[] = "Datei nicht verifiziert: {$result['path']}";
        }
    }
    
    // 3. Mutation-Gate: Hat das Modell "erledigt" gesagt, ohne zu schreiben?
    if ($this->claimedMutationWithoutAction($toolResults)) {
        $issues[] = "Mutation behauptet, aber keine Write-Action gefunden";
    }
    
    return $issues;
}
```

**Nur 3 Checks statt 15+.** Das reicht für 95% der Fälle.

---

## 5. Tool-Migration: Von 44 zu 12

### 5.1 Migrations-Matrix

| Altes Tool | Neues Tool | Parameter-Mapping |
|-----------|-----------|-------------------|
| `get_posts` | `read` | `read({"path": "wp_posts", "query": {"post_type": "post", "status": "publish"}})` |
| `get_pages` | `read` | `read({"path": "wp_posts", "query": {"post_type": "page"}})` |
| `create_post` | `manage_wp` | `manage_wp({"action": "create", "type": "post", ...})` |
| `update_post` | `manage_wp` | `manage_wp({"action": "update", "id": 123, ...})` |
| `delete_post` | `manage_wp` | `manage_wp({"action": "delete", "id": 123})` |
| `read_plugin_file` | `read` | `read({"path": "/wp-content/plugins/{slug}/{file}"})` |
| `write_plugin_file` | `write` | `write({"path": "/wp-content/plugins/{slug}/{file}", "content": "..."})` |
| `patch_plugin_file` | `edit` | `edit({"path": "/wp-content/plugins/{slug}/{file}", "replacements": [...]})` |
| `manage_woocommerce` | `manage_wc` | Gleiche API, nur Name geändert |
| `get_woocommerce_data` | `read` | `read({"path": "wc_products", ...})` |
| `execute_wp_code` | `execute` | Gleiche API, nur Name geändert |
| `http_fetch` | `fetch` | Gleiche API, nur Name geändert |

### 5.2 Unified `read` Tool

```php
class ReadTool implements ToolInterface {
    public function getName(): string { return 'read'; }
    
    public function getParameters(): array {
        return [
            'source' => [
                'type' => 'string',
                'description' => 'What to read: wp_posts, wp_pages, wp_users, wp_media, wp_plugins, wp_options, wp_error_log, plugin_file, theme_file, wc_products, wc_orders, elementor_data, or a file path',
            ],
            'query' => [
                'type' => 'object',
                'description' => 'Optional filters (post_type, status, limit, offset, etc.)',
            ],
            'path' => [
                'type' => 'string', 
                'description' => 'File path (for plugin_file, theme_file sources)',
            ],
            'lines' => [
                'type' => 'object',
                'description' => 'Line range {start, end} for partial file reads',
            ],
        ];
    }
}
```

### 5.3 Unified `write` Tool

```php
class WriteTool implements ToolInterface {
    public function getName(): string { return 'write'; }
    
    public function getParameters(): array {
        return [
            'path' => [
                'type' => 'string',
                'description' => 'Absolute file path',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'File content to write',
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Allow overwriting existing files (default: false)',
            ],
        ];
    }
    
    public function execute(array $params): array {
        // 1. Safety checks (path traversal, allowed dirs)
        // 2. Backup existing file
        // 3. Write
        // 4. Syntax check (PHP/JS/CSS)
        // 5. Return with verification data
        
        return [
            'success' => true,
            'path' => $params['path'],
            'line_count' => $lineCount,
            'preview' => $preview,
            'verification' => [
                'file_exists' => true,
                'syntax_valid' => true,
            ],
        ];
    }
}
```

### 5.4 Unified `edit` Tool

```php
class EditTool implements ToolInterface {
    public function getName(): string { return 'edit'; }
    
    public function getParameters(): array {
        return [
            'path' => ['type' => 'string'],
            'replacements' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string'],
                        'replace' => ['type' => 'string'],
                    ],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => false],
        ];
    }
}
```

### 5.5 Behaltenswerte spezialisierte Tools

Nicht alle Tools werden generisch. Diese bleiben spezialisiert, weil ihre Domänenlogik zu komplex für generische Tools ist:

- `manage_wc` — WooCommerce CRUD API (Produkte, Bestellungen, Gutscheine)
- `manage_elementor` — Elementor-Datenstrukturen (_elementor_data)
- `execute` — PHP-Sandbox (Sicherheitskritisch)
- `manage_wp` — WordPress CRUD (Posts, Pages, Users, Taxonomies, Menus)

**Aber:** Ihre Schemas werden vereinfacht. Statt 20 Parameter sind es 5–8.

---

## 6. Memory-System Optimierungen

### 6.1 Context Budget Management

**Vorher:**
- System Prompt: ~15.000 Tokens
- History: ~5.000 Tokens
- Tool Results: ~3.000 Tokens
- **Gesamt: ~23.000 Tokens** (von 128k)

**Nachher:**
- System Prompt: ~3.000 Tokens (nur relevante Module)
- History: ~2.000 Tokens (komprimiert)
- Tool Results: ~1.500 Tokens (komprimiert)
- **Gesamt: ~6.500 Tokens** (von 128k)

**~72% Reduktion.** Das gibt dem Modell Raum zum Denken.

### 6.2 Message Compaction

```php
private function compactMessages(array $messages, int $maxTokens = 4000): array {
    // 1. Keep system messages intact
    $systemMessages = array_filter($messages, fn($m) => $m['role'] === 'system');
    
    // 2. Compress old tool results (>2 iterations ago)
    $compressed = [];
    foreach ($messages as $msg) {
        if ($msg['role'] === 'tool' && $msg['_iteration'] < $currentIteration - 2) {
            $msg['content'] = $this->summarizeToolResult($msg['content']);
        }
        $compressed[] = $msg;
    }
    
    // 3. If still too long, halve history
    if ($this->estimateTokens($compressed) > $maxTokens) {
        $compressed = $this->halveHistory($compressed);
    }
    
    return array_merge($systemMessages, $compressed);
}
```

### 6.3 Memory-Retrieval: On-Demand statt Always-On

**Vorher:** Vector Search + BM25 + Reranking bei jedem COMPLEX-Request.

**Nachher:**
- **SIMPLE/READ:** Kein Memory-Lookup (saves ~500ms + tokens)
- **WRITE/DEV:** Nur episodic Memory (Nutzer-Präferenzen)
- **COMPLEX:** Full pipeline (Vector + BM25 + Reranking)

---

## 7. Implementierungs-Phasen

### Phase 1: Foundation (Woche 1–2)
**Ziel:** Neue Tool-Architektur + State Machine

- [ ] `AbstractTool` erweitern für generische Tools
- [ ] `ReadTool`, `WriteTool`, `EditTool`, `ListTool`, `GrepTool` implementieren
- [ ] `AgentState` Enum + State Machine implementieren
- [ ] Neue `ToolOrchestrator` Klasse (ersetzt `ExecutesToolLoop`)
- [ ] Vereinfachte Verifikation (3 Checks statt 15+)
- [ ] Unit Tests für alle neuen Tools

**Stolperstein:** Die alten Tool-Implementierungen enthalten viel Business-Logik (z.B. `create_plugin` mit Scaffold-Generierung). Diese Logik muss in die Tool-Implementation migriert werden, nicht verloren gehen.

**Mitigation:** Jedes alte Tool wird "wrappen" — `WriteTool::execute()` ruft intern die alte Logik auf, bis die Migration komplett ist.

### Phase 2: Identity Refactoring (Woche 2–3)
**Ziel:** Modulare Prompts + Wissenstransfer

- [ ] `QueryClassifier` auf Regex-basiert umschreiben (kein LLM-Call)
- [ ] `Identity::getRulesForModules()` als primären Loader nutzen
- [ ] Tool-Wissen aus alten Tool-Schemas in `identity/rules/tools.md` migrieren
- [ ] Tool-Wissen aus alten Implementierungen in `identity/knowledge.md` migrieren
- [ ] `BuildsContext` auf modulares Laden umstellen
- [ ] Prompt-Caching-Optimierung (`cache_control` für Anthropic)

**Stolperstein:** Die `identity/rules/*.md` Files sind aktuell für das *alte* Tool-System geschrieben. Sie müssen für die neuen generischen Tools umgeschrieben werden.

**Mitigation:** Schrittweise Migration. Alte und neue Rules parallel pflegen, bis Phase 3 abgeschlossen ist.

### Phase 3: Integration & Testing (Woche 3–4)
**Ziel:** Alles zusammenfügen + stabilisieren

- [ ] `ChatController` auf State Machine umstellen
- [ ] PLANNING-Phase UI (Chat-Widget zeigt "Levi plant..." mit Freigabe-Button)
- [ ] Fallback von neuer auf alte Tool-Registry bei Fehlern
- [ ] End-to-End Tests für typische Workflows:
  - Plugin erstellen
  - Beitrag erstellen + bearbeiten
  - WooCommerce-Produkt erstellen
  - Theme-Datei patchen
- [ ] Performance-Benchmarking (Token-Nutzung, Latenz)

**Stolperstein:** Die neue Architektur könnte bei Edge-Cases schlechter abschneiden als die alte.

**Mitigation:** A/B-Testing-Fähigkeit bauen — prozentuale Ausrollung der neuen Architektur.

### Phase 4: Polish & Release (Woche 4–5)
**Ziel:** Production-Ready 0.9.0

- [ ] Alte Tool-Implementierungen entfernen (nach erfolgreicher Migration)
- [ ] CHANGELOG.md aktualisieren
- [ ] Dokumentation aktualisieren
- [ ] Beta-Tester-Gruppe (5–10 Nutzer)
- [ ] Bugfixes aus Beta-Feedback
- [ ] Release `0.9.0`

---

## 8. Risiken & Mitigationen

| Risiko | Wahrscheinlichkeit | Impact | Mitigation |
|--------|-------------------|--------|-----------|
| **LLM wählt falsches generisches Tool** | Mittel | Hoch | Bessere Tool-Descriptions + Beispiele in Prompt. A/B-Test mit altem System. |
| **Generische Tools verlieren WordPress-Spezifika** | Mittel | Hoch | Tool-Wissen wird in Identity/.md-Files ausgelagert, nicht gelöscht. |
| **Planung-Phase ist zu langsam** | Niedrig | Mittel | Nur bei komplexen Tasks aktivieren. SIMPLE/READ überspringen. |
| **State Machine zu rigide** | Niedrig | Mittel | Übergänge sind Empfehlungen, keine harten Blockaden. LLM kann immer "override" mit System-Nachricht. |
| **Nutzer verstehen PLANNING-UI nicht** | Mittel | Mittel | Klare Visualisierung: "Levi hat einen Plan erstellt. Soll er loslegen?" |
| **Context-Reduktion reicht nicht** | Niedrig | Hoch | Monitoring: Token-Nutzung pro Request tracken. Bei >50% Reduktion → Erfolg. |
| **Migration bricht bestehende Sessions** | Hoch | Mittel | Sessions sind State-less (nur DB-History). Keine Migration nötig. |

---

## 9. Erfolgsmetriken

| Metrik | 0.8.0 (Baseline) | 0.9.0 (Ziel) | Messung |
|--------|-----------------|-------------|---------|
| Tool-Choice Accuracy | ~75% | >90% | Manuelle Review von 50 Requests |
| Avg. Tokens per Request | ~18.000 | <8.000 | API-Usage-Tracking |
| Avg. Latenz (inkl. Classification) | ~3.5s | <2.0s | Server-Timing-Header |
| Task Completion Rate | ~65% | >85% | Nutzer-Feedback (Thumbs Up/Down) |
| Plugin-Dev Success Rate | ~40% | >70% | Manuelle Review von 20 Plugin-Tasks |
| Hallucination Rate | ~15% | <5% | Manuelle Review |
| User Satisfaction | N/A | >4.0/5.0 | In-Chat-Rating |

---

## 10. Offene Fragen & Entscheidungen

### 10.1 Soll `search_tools` entfallen?

**Option A:** `search_tools` entfernen (alle 12 Tools immer sichtbar)
- Pro: Kein Discovery-Problem
- Con: 12 Tool-Schemas frisst ~2.500 Tokens

**Option B:** `search_tools` behalten, aber als Fallback
- Pro: Token-Einsparung bei einfachen Tasks
- Con: Komplexität bleibt

**Empfehlung:** Option A. 12 Tools sind weniger als Levis aktuelle 20 Core-Tools. Die Token-Kosten sind geringer als bei 0.8.0.

### 10.2 Sollen alte Tool-Namen als Aliases behalten werden?

**Option A:** Backward-compatible Aliases
- `create_post` → intern `manage_wp(action: create, type: post)`
- Pro: Keine Breaking Changes für Nutzer
- Con: Zwei APIs zu pflegen

**Option B:** Hard Cut
- Pro: Saubere Architektur
- Con: Nutzer müssen umlernen

**Empfehlung:** Option A für 0.9.0, Option B für 1.0.0.

### 10.3 Soll die Query Classification komplett entfallen?

**Option A:** Classification entfernen, State Machine entscheidet
- Der State Machine Loop erkennt selbst, ob Planung nötig ist

**Option B:** Regex-basierte Classification behalten (schnell, deterministisch)
- Pro: Schnell, zuverlässig, steuert Prompt-Module
- Con: Noch ein Konzept zu pflegen

**Empfehlung:** Option B. Die Regex-Classification ist so schnell, dass sie keinen Nachteil hat. Sie ermöglicht modulare Prompts.

---

## 11. Zusammenfassung

Levi 0.9.0 wird nicht durch **mehr Features** besser. Es wird durch **weniger Komplexität** besser.

**Die 3 wichtigsten Änderungen:**

1. **44 → 12 Tools:** Generische Tools wie Claude Code. Tool-Wissen wandert in `.md`-Files.
2. **Modulare Prompts:** Nur relevante Rule-Module laden. 15.000 → 3.000 Tokens System-Prompt.
3. **State Machine:** Plan-Execute-Verify statt freiem Loop. Weniger Guards, mehr Vertrauen.

**Das akkumulierte Wissen (WooCommerce-Fallstricke, Elementor-Patterns, Frontend-Qualität, Cron-Regeln) bleibt erhalten** — es wandert von JSON-Schemas und Tool-Implementierungen in die Identity-Dateien.

**Das Ziel:** Levi soll sich anfühlen wie Claude Code für WordPress. Direkt, zuverlässig, schnell.

---

## Anhang A: Vergleichstabelle — Alt vs. Neu

| Aspekt | 0.8.0 | 0.9.0 |
|--------|-------|-------|
| **Tools** | 44 spezialisierte | 12 generische |
| **Tool Loading** | Deferred (Core + search_tools) | Alle immer sichtbar |
| **System Prompt** | ~15.000 Tokens (alle Module) | ~3.000 Tokens (relevante Module) |
| **Control Flow** | Freier Loop | ORPA State Machine |
| **Planung** | Implizit (manchmal) | Explizit (bei komplexen Tasks) |
| **Guards** | 15+ Post-Execution Injections | 3 Checks (Plan, Write, Mutation) |
| **Classification** | LLM-basiert (+API Call) | Regex-basiert (+0ms) |
| **Tool-Wissen** | In JSON-Schemas | In Identity/.md-Files |
| **Message Compaction** | Ja (nach Iteration 3) | Ja (nach Iteration 2 + aggressive) |
| **Memory Retrieval** | Immer bei COMPLEX | On-Demand (nur wenn nötig) |
| **Error Recovery** | Graduated Fallbacks | State-basiertes Retry |

## Anhang B: Architektur-Diagramm (textuell)

```
┌─────────────────────────────────────────────────────────────┐
│                      NUTZER-EINGABE                          │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  QUERY CLASSIFIER (Regex, 0ms)                               │
│  → Typ: SIMPLE | READ | WRITE | DEV                          │
│  → Module: [core] | [core,tools] | [core,tools,coding] ...   │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  PROMPT BUILDER                                              │
│  → soul.md + knowledge.md + relevante rules/*.md             │
│  → Dynamic Context (User, Site, WP-Version)                  │
│  → Memory (nur bei DEV/COMPLEX)                              │
│  → State Snapshot (nur bei Bedarf)                           │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  ORPA STATE MACHINE                                          │
│                                                              │
│  ┌─────────┐    ┌──────────┐    ┌─────────┐    ┌─────────┐  │
│  │ OBSERVE │───→│  REASON  │───→│  PLAN   │───→│ EXECUTE │  │
│  └─────────┘    └──────────┘    └────┬────┘    └────┬────┘  │
│       ▲                              │              │       │
│       │                              │              ▼       │
│       │                         ┌────┴────┐    ┌─────────┐  │
│       └─────────────────────────│  DONE   │←───│ VERIFY  │  │
│                                 └─────────┘    └─────────┘  │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  TOOL EXECUTOR (12 Tools)                                    │
│  read | write | edit | list | grep | execute                 │
│  install | manage_wp | manage_wc | manage_elementor          │
│  fetch                                                                  │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  VERIFICATION (3 Checks)                                     │
│  1. Plan-Completeness                                        │
│  2. Write-Verification                                       │
│  3. Mutation-Gate                                            │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  ANTWORT + SSE STREAMING                                     │
└─────────────────────────────────────────────────────────────┘
```
