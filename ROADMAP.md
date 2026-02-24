# WordPress AI Agent - ROADMAP

> Ein KI-Mitarbeiter für WordPress, inspiriert von Mohami aber nativ integriert.

## 🎯 Vision

Ein WordPress-Plugin, das einen KI-Agenten direkt in den Admin-Bereich integriert. Der Agent kann:
- WordPress-Daten live abfragen (Posts, Seiten, Einstellungen)
- Persönliches Wissen aus Markdown-Dateien nutzen
- Im Chat helfen und Aufgaben ausführen
- Sich an Präferenzen erinnern (aber nicht an komplette Chat-Verläufe)

## 🏗️ Architektur-Prinzipien

### Gedächtnis-Hierarchie

```
┌─────────────────────────────────────────────────────────────┐
│                    MEMORY SYSTEM                            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  TIER 1: Session (Flüchtig)                                 │
│  ├── Redis oder SQLite TEMP                                 │
│  ├── Aktive Konversation                                    │
│  └── Wird nach Session-Ende gelöscht                        │
│                                                             │
│  TIER 2: Episodic (SQLite + Vektoren)                       │
│  ├── Gelernte Präferenzen                                   │
│  ├── "Rin schreibt Dienstags"                               │
│  └── Klein, kompakt, performant                             │
│                                                             │
│  TIER 3: Reference (Markdown Files)                         │
│  ├── identity/soul.md        → Persönlichkeit               │
│  ├── identity/rules.md       → Verhaltensregeln             │
│  ├── identity/knowledge.md   → Basiswissen                  │
│  └── memories/*.md           → Erweiterbares Wissen         │
│                                                             │
│  TIER 4: Live (WordPress API)                               │
│  ├── Aktuelle Posts/Pages                                   │
│  ├── Aktuelle Einstellungen                                 │
│  └── Plugins/Themes                                         │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Kern-Entscheidung: Kein ChromaDB!

**Warum SQLite statt Chroma?**
- Shared-Hosting-kompatibel
- Die WordPress-Doku kommt nicht ins Gedächtnis
- Agent fragt WordPress LIVE ab für aktuelle Daten
- Nur .md-Files + gelernte Präferenzen in SQLite (kleine Datenmenge)
- Redis für Sessions (optional, sonst SQLite TEMP)

## 📋 Phasen-Plan

### Phase 1: Foundation ✅
**Ziel:** Plugin läuft, API-Key konfigurierbar, erste KI-Antwort

- [x] Plugin-Boilerplate (PSR-4, Composer)
- [x] Settings-Page (API-Key, Model, Rate-Limiting)
- [x] OpenRouter Integration
- [x] Chat-UI (Floating Widget)
- [x] REST API Endpoints
- [x] Session-Management (localStorage + DB)

**Dateien:**
- `wp-levi-agent.php`
- `src/Core/Plugin.php`
- `src/Admin/SettingsPage.php`
- `src/AI/OpenRouterClient.php`
- `src/API/ChatController.php`
- `assets/css/chat-widget.css`
- `assets/js/chat-widget.js`

---

### Phase 2: Identity System ✅
**Ziel:** Agent hat Persönlichkeit durch .md-Files

- [x] `identity/soul.md` erstellen
- [x] `identity/rules.md` erstellen
- [x] `identity/knowledge.md` erstellen
- [x] `src/Agent/Identity.php` - Lädt und kombiniert .md-Files
- [x] System-Prompt bauen aus Identity
- [x] Dynamischer Context (User, Site, Zeit)

**Wichtig:**
- Identity wird bei jedem Request neu geladen (kein Caching nötig)
- "Reload-Button" für später: Lädt .md-Files neu in SQLite-Vektoren

---

### Phase 3: Vector Memory (SQLite)
**Ziel:** Semantische Suche in .md-Files + episodisches Gedächtnis

- [ ] `src/Memory/VectorStore.php` - SQLite mit Vektor-Support
- [ ] Embedding-Generierung (OpenAI API)
- [ ] `memories/` Ordner überwachen
- [ ] .md-Files → Chunks → Embeddings → SQLite
- [ ] Semantische Suche implementieren
- [ ] "Reload Memories" Button in Settings

**Technik:**
- SQLite mit `sqlite-vec` Extension ODER
- Pure PHP Cosine-Similarity (einfacher, kein Extension nötig)

**Datenbank-Schema:**
```sql
CREATE TABLE memory_vectors (
    id INTEGER PRIMARY KEY,
    source_file VARCHAR(255),      -- z.B. "identity/soul.md"
    content TEXT,
    embedding BLOB,                -- JSON-Array von Zahlen
    memory_type VARCHAR(50),       -- "identity", "reference", "episodic"
    created_at DATETIME
);
```

---

### Phase 4: Tool System (Lesen)
**Ziel:** Agent kann WordPress-Daten live abfragen

- [ ] `src/AI/Tools/ToolInterface.php`
- [ ] `src/AI/Tools/Registry.php`
- [ ] Erste Tools implementieren:
  - `GetPostsTool` - Posts auflisten
  - `GetPostTool` - Einzelnen Post lesen
  - `GetPagesTool` - Seiten auflisten
  - `GetOptionsTool` - Einstellungen lesen
  - `GetUsersTool` - User auflisten
  - `GetPluginsTool` - Installierte Plugins

**Wichtig:**
- Tools definieren Schema für KI (JSON Schema)
- KI entscheidet welches Tool zu nutzen
- Agent führt Tool aus, gibt Ergebnis zurück an KI
- KI formuliert Antwort für User

**Workflow:**
```
User: "Wie viele Posts habe ich?"
KI: "Ich schaue nach..." → Function Call: get_posts
Agent: Führt wp_count_posts() aus
KI: "Du hast 42 Posts, davon 38 veröffentlicht."
```

---

### Phase 5: Tool System (Schreiben)
**Ziel:** Agent kann WordPress ändern (mit Safety)

- [ ] Schreibende Tools:
  - `CreatePostTool` (immer als Draft!)
  - `UpdatePostTool`
  - `CreatePageTool`
  - `UpdateOptionTool` (Whitelist!)
  - `UploadMediaTool`

- [ ] Safety-Layer:
  - Capability-Checks
  - User-Bestätigung für destruktive Aktionen
  - Action-Logging
  - "Dry-Run" Mode (zeigt was passieren würde)

- [ ] UI für Bestätigungen:
  - "Soll ich das wirklich tun?" Dialog
  - Preview vor dem Speichern

---

### Phase 6: Episodic Memory
**Ziel:** Agent lernt Präferenzen (nicht Chat-Verlauf!)

- [ ] `src/Memory/EpisodicMemory.php`
- [ ] Automatisches Lernen aus Konversationen:
  - "Rin mag kurze Sätze" → Speichern
  - "Veröffentliche immer Dienstags" → Speichern
  - "Ich nutze Elementor" → Speichern

- [ ] Retrieval:
  - Vor jeder Anfrage: Suche relevante Episoden
  - In Context einbauen

**Wichtig:**
- Nur EXPLIZIT gelernte Fakten (nicht: "vor 5 Minuten hast du X gesagt")
- Zusammenfassung alter Chats extrahiert Lernpunkte
- Max. 100-200 Episoden (SQLite skaliert das locker)

---

### Phase 7: UI/UX Polish
**Ziel:** Professionelles Look & Feel

- [ ] Markdown-Rendering im Chat
- [ ] Code-Highlighting
- [ ] Tool-Ausführung sichtbar machen (wie Mohami)
- [ ] Typing-Indicator während KI denkt
- [ ] Dark Mode Support
- [ ] Keyboard Shortcuts (Cmd+Enter senden, Esc schließen)
- [ ] Mobile-Responsive

---

### Phase 8: Testing & Release
**Ziel:** Produktionsreif

- [ ] Unit Tests (PHPUnit)
- [ ] Integration Tests (REST API)
- [ ] Security Audit
- [ ] Performance-Testing (große .md-Files)
- [ ] WordPress.org Plugin-Directory vorbereiten
- [ ] Dokumentation

---

## 🔄 Memory Reload Workflow

Wann werden .md-Files neu geladen?

### Option A: Manuelles Reload (empfohlen für V1)
- Button in Settings: "Memories neu laden"
- Liest alle .md-Files neu ein
- Erstellt neue Embeddings
- Dauert 5-30 Sekunden (je nach Dateigröße)

### Option B: File-Watcher (V2)
- Prüft bei jedem Request: Hat sich .md geändert?
- Automatisches Reload
- Aufwendiger, nicht für V1 nötig

---

## 📁 Finale Ordnerstruktur

```
levi-agent/
├── wp-levi-agent.php
├── composer.json
├── README.md
├── ROADMAP.md
│
├── identity/                    ← KONFIGURATION (.md)
│   ├── soul.md
│   ├── rules.md
│   └── knowledge.md
│
├── memories/                    ← ERWEITERBARES WISSEN (.md)
│   ├── wordpress-basics.md
│   ├── project-context.md
│   └── ... (beliebig viele)
│
├── data/                        ← DATEN (nicht im Git)
│   └── vector-memory.sqlite     ← Embeddings + episodic
│
├── src/
│   ├── Core/
│   │   └── Plugin.php
│   │
│   ├── Admin/
│   │   ├── SettingsPage.php
│   │   └── ChatWidget.php
│   │
│   ├── API/
│   │   └── ChatController.php
│   │
│   ├── AI/
│   │   ├── OpenRouterClient.php
│   │   ├── Tools/
│   │   │   ├── ToolInterface.php
│   │   │   ├── Registry.php
│   │   │   ├── GetPostsTool.php
│   │   │   ├── CreatePostTool.php
│   │   │   └── ...
│   │   └── Streaming.php
│   │
│   ├── Agent/
│   │   ├── Identity.php         ← Lädt .md Files
│   │   └── Personality.php
│   │
│   ├── Memory/
│   │   ├── VectorStore.php      ← SQLite + Embeddings
│   │   ├── EpisodicMemory.php   ← Gelernte Fakten
│   │   └── SessionMemory.php    ← Aktive Chats
│   │
│   └── Database/
│       ├── Tables.php
│       └── ConversationRepository.php
│
├── assets/
│   ├── css/chat-widget.css
│   └── js/chat-widget.js
│
├── templates/
│   └── admin/
│       └── chat-widget.php
│
└── tests/
```

---

## 🎯 Key Design Decisions

| Entscheidung | Begründung |
|--------------|------------|
| **SQLite statt Chroma** | Shared-Hosting-kompatibel |
| **Kein Chat-Langzeitgedächtnis** | Sessions sind temporär, nur gelernte Fakten bleiben |
| **.md-Files für Wissen** | Versionierbar mit Git, einfach editierbar |
| **Live-Abfragen für WP-Daten** | Aktuell, kein Sync nötig |
| **Manuelles Memory-Reload** | Einfacher, explizite Kontrolle |
| **Tool-System** | KI entscheidet, Agent führt aus |

---

## 🚀 Nächste Schritte

1. **Phase 3 starten:** Vector Memory (SQLite)
2. Dann: Tool System (Lesen)
3. Dann: Tool System (Schreiben)
4. Dann: Episodic Memory

**Soll ich mit Phase 3 beginnen?**
