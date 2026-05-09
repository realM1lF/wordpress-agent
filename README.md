# Levi AI Agent - Technische Dokumentation

> Ein KI-gestützter WordPress-Mitarbeiter, der direkt im Admin-Panel arbeitet. Für Agenturen, Freelancer und Website-Betreiber, die WordPress-Aufgaben beschleunigen wollen.

---

## 🚀 Produktübersicht

### Was ist Levi?

**Levi** ist ein KI-gestützter Mitarbeiter für WordPress – inspiriert vom Mohami KI-Agent System, aber nativ in WordPress integriert. Statt eines externen Dashboards erscheint Levi als schwebendes Chat-Widget direkt im WordPress Admin-Panel.

**Das Besondere:** Levi versteht natürliche Sprache und kann komplexe WordPress-Aufgaben eigenständig ausführen – von Content-Erstellung über Plugin-Installation bis hin zur Code-Entwicklung.

### Für wen ist Levi gedacht?

| Zielgruppe | Anwendungsfälle |
|------------|-----------------|
| **WordPress-Agenturen** | Schnelle Umsetzung von Kundenwünschen, Bulk-Operationen |
| **Freelancer** | Effizientere WordPress-Wartung, mehr Kunden pro Tag |
| **Website-Betreiber** | Content-Management ohne technisches Know-how |
| **WooCommerce-Shops** | Produktdaten pflegen, Gutscheine erstellen |
| **Entwickler** | Plugin/Theme-Entwicklung direkt im Chat |

---

## ✨ Hauptfunktionen

### 1. Natürlichsprachliche Steuerung

Statt durch Menüs zu navigieren, beschreibst du Levi einfach, was du willst:

> *"Erstelle einen neuen Blogpost über WordPress-Sicherheit mit 5 Tipps"*

> *"Installiere das Yoast SEO Plugin und aktiviere es"*

> *"Ändere den Titel der Startseite auf 'Willkommen bei uns'"*

### 2. Integrierte Tools

Levi hat direkten Zugriff auf deine WordPress-Installation über zwei Tool-Architekturen:

**Standard-Modus (44 spezialisierte Tools)**
Für maximale Präzision bei komplexen WordPress-Aufgaben:
- Content Management: Posts, Pages, Media, Taxonomien
- Plugin & Theme Verwaltung: Installation, Datei-Edits, Scaffolds
- WooCommerce Integration: Produkte, Gutscheine, Bestellungen
- System-Administration: Benutzer, Menüs, Cron-Jobs, Error-Logs
- Power-Tools: PHP-Sandbox, HTTP-Requests, REST-API-Discovery

**Beta: Generische Tools (12 universelle Tools) — 0.9.0**
Inspiriert von Claude Code und Aider — höhere Zuverlässigkeit bei geringerem Token-Verbrauch:
- `read`, `write`, `edit`, `list`, `grep` — Datei- und Content-Operationen
- `execute` — PHP/WP-Code ausführen
- `install` — Plugins/Themes verwalten
- `manage` — WordPress CRUD (Posts, Users, Taxonomien, Menüs)
- `manage_woo` — WooCommerce-Operationen
- `manage_elementor` — Elementor-Verwaltung
- `fetch` — HTTP-Requests
- `health_check` — Systemdiagnose

Aktiviere generische Tools in den Einstellungen unter „Erweitert → Generische Tools verwenden".

### 3. Multi-Layer Memory-System

Levi "erinnert" sich über vier Ebenen:

| Ebene | Was wird gespeichert? | Beispiel |
|-------|----------------------|----------|
| **Identity** | Persönlichkeit, Regeln | Levi ist freundlich, kommuniziert per "Du" |
| **Reference** | Technische Dokumentation | WordPress Codex, WooCommerce Docs |
| **Episodic** | Gelernte Präferenzen | "Rin bevorzugt kurze Sätze" |
| **Live** | Aktueller WordPress-Zustand | Aktive Plugins, Theme, Einstellungen |

### 4. Mehrere KI-Provider

Wähle deinen bevorzugten KI-Anbieter:

| Provider | Modelle | Besonderheit |
|----------|---------|--------------|
| **OpenRouter** (Standard) | Kimi K2.5, Claude, GPT-4 | Retry-Logik, viele Modelle |
| **OpenAI** | GPT-4, GPT-3.5 | Native Tool-Unterstützung |
| **Anthropic** | Claude 3 Opus/Sonnet | Lange Kontexte |

### 5. Drei Sicherheitsstufen (Tool-Profile)

| Profil | Für wen? | Was ist möglich? |
|--------|----------|------------------|
| **Minimal** | Anfänger | Nur Lesen und Diagnose |
| **Standard** | Empfohlen | Lesen + Schreiben (Content, Einstellungen) |
| **Full** | Entwickler | Alle Tools inkl. Code-Ausführung |

---

## 🏗️ Technische Architektur

### Systemanforderungen

| Komponente | Minimum | Empfohlen |
|------------|---------|-----------|
| **PHP** | 8.1 | 8.2+ |
| **WordPress** | 6.0 | 6.4+ |
| **MySQL/MariaDB** | 5.7 | 8.0+ |
| **Speicher** | 64MB | 128MB+ |

### Projektstruktur

```
wp-content/plugins/levi-agent/
│
├── wp-levi-agent.php           # Haupt-Plugin-Datei
├── composer.json               # PHP-Dependencies (PSR-4)
│
├── identity/                   # Agent-Identität
│   ├── soul.md                 # Persönlichkeit
│   ├── rules.md                # Verhaltensregeln
│   └── knowledge.md            # Domänenwissen
│
├── memories/                   # Referenz-Wissen
│   ├── wordpress-lllm-developer.txt
│   ├── woocommerce-lllm-developer.txt
│   └── elementor-llm-developer.txt
│
├── src/
│   ├── Core/Plugin.php         # Haupt-Plugin-Klasse
│   ├── Admin/                  # Admin-Interface
│   │   ├── ChatWidget.php
│   │   ├── SettingsPage.php
│   │   └── SetupWizardPage.php
│   ├── API/ChatController.php  # REST API Endpoints
│   ├── AI/                     # KI-Integration
│   │   ├── AIClientFactory.php
│   │   ├── AnthropicClient.php
│   │   ├── OpenAIClient.php
│   │   ├── OpenRouterClient.php
│   │   └── Tools/              # 40+ Tool-Implementierungen
│   │       └── Registry.php
│   ├── Database/               # Datenbank-Layer
│   ├── Memory/                 # Gedächtnis-System
│   └── Agent/Identity.php      # Agent-Identität
│
├── assets/
│   ├── css/                    # Stylesheets (Dark Mode)
│   └── js/                     # Chat-Widget JavaScript
│
└── templates/                  # PHP-Templates
```

### Datenbank-Tabellen

| Tabelle | Zweck |
|---------|-------|
| `wp_levi_conversations` | Chat-Verläufe |
| `wp_levi_vectors` | Vektor-Embeddings (semantische Suche) |
| `wp_levi_vector_files` | Geladene Memory-Dateien (Hash-Tracking) |
| `wp_levi_episodic_memories` | Gelernte Benutzerpräferenzen |
| `wp_levi_state_snapshots` | Tägliche WordPress-Zustandssicherungen |

### REST API Endpoints

| Methode | Endpoint | Beschreibung |
|---------|----------|--------------|
| POST | `/wp-json/levi-agent/v1/chat` | Nachricht senden (Classic) |
| POST | `/wp-json/levi-agent/v1/chat/stream` | Nachricht senden (Streaming) |
| GET | `/wp-json/levi-agent/v1/chat/{session}/history` | Verlauf laden |
| POST | `/wp-json/levi-agent/v1/chat/upload` | Dateien hochladen |
| DELETE | `/wp-json/levi-agent/v1/chat/{session}` | Session löschen |

### Kommunikations-Architektur

```
┌─────────────────────────────────────────────────────────────┐
│  User Input (natürliche Sprache)                            │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  SYSTEM PROMPT                                              │
│  ├── Identity (soul.md, rules.md, knowledge.md)             │
│  ├── Reference Memories (WordPress-Doku, WooCommerce-Doku)  │
│  ├── State Snapshot (aktive Plugins, Theme, WP-Version)     │
│  └── User Context (Name, Rolle, aktuelle Seite)             │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  AI Provider (OpenRouter / OpenAI / Anthropic)              │
│  └── LLM entscheidet: Welches Tool wird benötigt?           │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Tool Execution                                             │
│  └── Direkte WordPress-API-Nutzung                          │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Response → User                                            │
└─────────────────────────────────────────────────────────────┘
```

---

## ✅ Was Levi kann - Detaillierte Übersicht

### Content Management

| Aufgabe | Beispiel-Befehl |
|---------|-----------------|
| Blogpost erstellen | *"Erstelle einen Post über WordPress-Sicherheit"* |
| Post aktualisieren | *"Ändere den Titel von Post #123 auf 'Neuer Titel'"* |
| Seite erstellen | *"Erstelle eine neue Seite 'Über uns'"* |
| Featured Image setzen | *"Setze das Featured Image für die Startseite"* |
| Kategorien verwalten | *"Erstelle eine neue Kategorie 'Tutorials'"* |

### Plugin-Verwaltung

| Aufgabe | Beispiel-Befehl |
|---------|-----------------|
| Plugin installieren | *"Installiere WooCommerce"* |
| Plugins auflisten | *"Welche Plugins sind aktiviert?"* |
| Plugin-Datei lesen | *"Zeige mir die main.php von WooCommerce"* |
| Plugin entwickeln | *"Erstelle ein Plugin 'Mein Plugin' mit einer Admin-Seite"* |

### Theme-Entwicklung

| Aufgabe | Beispiel-Befehl |
|---------|-----------------|
| Theme wechseln | *"Aktiviere das Twenty Twenty-Four Theme"* |
| Theme-Datei bearbeiten | *"Füge zur functions.php eine Custom-Post-Type Registrierung hinzu"* |
| Template erstellen | *"Erstelle ein Template für eine Landingpage"* |

### WooCommerce

| Aufgabe | Beispiel-Befehl |
|---------|-----------------|
| Produktdaten anzeigen | *"Zeige mir Produkt #456"* |
| Preis ändern | *"Setze den Preis von 'T-Shirt' auf 29,99€"* |
| Gutschein erstellen | *"Erstelle einen Gutschein SUMMER2024 mit 20% Rabatt"* |
| Bestellungen anzeigen | *"Zeige mir die letzten 10 Bestellungen"* |

### System-Administration

| Aufgabe | Beispiel-Befehl |
|---------|-----------------|
| Benutzer erstellen | *"Erstelle einen neuen Benutzer 'max' mit Rolle Editor"* |
| Einstellungen ändern | *"Ändere den Seitentitel auf 'Meine Firma'"* |
| Menü bearbeiten | *"Füge zum Hauptmenü einen Link 'Kontakt' hinzu"* |
| Error-Log lesen | *"Zeige mir die letzten PHP-Fehler"* |

### Entwickler-Funktionen (Full Profile)

| Aufgabe | Beispiel-Befehl |
|---------|-----------------|
| PHP-Code ausführen | *"Führe aus: echo get_bloginfo('version');"* |
| REST-API testen | *"Rufe die REST-API für Posts ab"* |
| Plugin-Code debuggen | *"Prüfe plugin.php auf Syntax-Fehler"* |

---

## ❌ Was Levi NICHT kann - Limitationen

### Sicherheitsbedingte Limitationen

| Limitation | Begründung |
|------------|------------|
| **Keine externen HTTP-Requests** | `HttpFetchTool` erlaubt nur Same-Site Requests |
| **Keine Shell-Ausführung** | `exec()`, `shell_exec()`, `system()` sind blockiert |
| **Keine Plugin-Deinstallation** | Plugins können installiert werden, aber nicht vollständig gelöscht |
| **Keine User-Löschung** | Benutzer können erstellt, aber nicht gelöscht werden |
| **Keine WordPress-Core-Updates** | Keine automatischen WP-Version-Updates |

### Funktionale Limitationen

| Limitation | Details |
|------------|---------|
| **Keine neuen WooCommerce-Produkte** | Nur bestehende Produkte können aktualisiert werden |
| **Keine Bestellstatus-Änderungen** | Bestellungen sind nur lesbar |
| **Keine direkten SQL-Queries** | Datenbank nur über WordPress-API |
| **Keine Multisite-Operationen** | Keine site-übergreifenden Aktionen |
| **Keine API-Key-Änderungen** | AI-Provider-Keys nicht über Chat änderbar |

### Technische Grenzen

| Limitation | Grenzwert |
|------------|-----------|
| **Max. Dateigröße (Upload)** | 5MB pro Datei |
| **Max. Tool-Iterationen** | 12 Runden pro Anfrage |
| **Rate Limit** | 50 Anfragen/Stunde (konfigurierbar) |
| **PHP-Code Timeout** | 30 Sekunden |
| **Max. Output (Code-Ausführung)** | 50KB |

---

## 🎨 Benutzeroberfläche

### Chat-Widget

Das Chat-Widget erscheint als schwebendes Element im WordPress Admin:

**Position:** Fixed, unten rechts (`bottom: 30px; right: 30px`)
**Design:** Dark Mode mit blauen Akzenten (#2563eb)
**Z-Index:** 999999 (immer oben)

```
┌─────────────────────────────────────────────┐
│ 🤖 Levi Assistant [ALPHA]    [↗] [🗑] [×]  │  ← Header
├─────────────────────────────────────────────┤
│                                             │
│  Hallo Rin! 👋                              │
│  Ich bin dein WordPress KI-Assistent...     │  ← Nachrichten
│                                             │
│          [User-Nachricht]                   │
│                                             │
│  [Assistant-Antwort mit Markdown]           │
│                                             │
│  [⏳ Levi schreibt...]                      │  ← Tipp-Indikator
│  ████████░░░░░░░░░░                         │
│                                             │
├─────────────────────────────────────────────┤
│ [📎]  ┌──────────────────┐ [➤]            │  ← Eingabe
│       │  Nachricht...    │                 │
│       └──────────────────┘                 │
└─────────────────────────────────────────────┘
```

**Features:**
- Markdown-Rendering mit Syntax-Highlighting
- Datei-Upload (Text, Bilder, Code)
- Vollbild-Modus
- Session-Management
- Editieren gesendeter Nachrichten

### Einstellungsseite

Fünf Tabs mit umfassenden Konfigurationsmöglichkeiten:

1. **General** - Übersicht, Quick Start, Memory-Status
2. **AI Provider** - API-Keys, Modell-Auswahl
3. **Memory** - Vector-Memory-Einstellungen
4. **Safety** - Limits, Tool-Profile, Datenschutz
5. **Advanced** - Datenbank-Wartung, System-Info

### Setup-Assistent

Vier-Schritt-Einrichtung für neue Nutzer:

1. **Willkommen** - Produktvorstellung
2. **API-Key** - OpenRouter-Key eingeben
3. **Tuning** - Tool-Profil, Gründlichkeit, Sicherheitsmodus wählen
4. **Abschluss** - Initial-Snapshot erstellen

---

## 🧠 Memory-System im Detail

### Vier Ebenen des Gedächtnisses

```
┌─────────────────────────────────────────────────────────────┐
│  TIER 1: IDENTITY                                           │
│  Dateien: soul.md, rules.md, knowledge.md                   │
│  ─────────────────────────────────────────────             │
│  • Wer ist Levi (Persönlichkeit)                            │
│  • Wie kommuniziert er (Du/Sie, Stil)                       │
│  • Welche Regeln befolgt er (Safety-First)                  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  TIER 2: REFERENCE                                          │
│  Dateien: memories/*.txt                                    │
│  ─────────────────────────────────────────────             │
│  • WordPress-Entwickler-Doku                                │
│  • WooCommerce-Doku                                         │
│  • Elementor-Doku                                           │
│  (Wird in Vektoren gespeichert für semantische Suche)       │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  TIER 3: EPISODIC                                           │
│  Datenbank: wp_levi_episodic_memories                       │
│  ─────────────────────────────────────────────             │
│  • Gelernte Präferenzen ("Rin mag kurze Sätze")             │
│  • Wichtige Entscheidungen                                  │
│  • Projektspezifische Details                               │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  TIER 4: LIVE (State Snapshots)                             │
│  Datenbank: wp_levi_state_snapshots                         │
│  ─────────────────────────────────────────────             │
│  • Aktive Plugins & Versionen                               │
│  • Aktives Theme                                            │
│  • WordPress-Version                                        │
│  • Wichtige Einstellungen                                   │
│  (Wird täglich um 00:07 Uhr aktualisiert)                   │
└─────────────────────────────────────────────────────────────┘
```

### Technische Spezifikation

| Aspekt | Details |
|--------|---------|
| **Datenbank** | SQLite3 (`vector-memory.sqlite`) |
| **Embedding-Modell** | text-embedding-3-small |
| **Embedding-Dimensionen** | 1536 |
| **Chunk-Größe** | 500 Wörter |
| **Chunk-Überlappung** | 50 Wörter |
| **Ähnlichkeitsmetrik** | Cosine Similarity |
| **Min. Ähnlichkeit (Identity/Reference)** | 0.7 |
| **Min. Ähnlichkeit (Episodic)** | 0.75 |

---

## 🔐 Sicherheitsfeatures

### WordPress-Standard-Sicherheit

- **Capability-Checks** - Jedes Tool prüft WordPress-Berechtigungen
- **Nonce-Verifizierung** - Alle AJAX/REST-Calls mit WordPress Nonces
- **Rate Limiting** - Konfigurierbare Limits pro Benutzer/Stunde

### Datenschutz

- **PII-Redaktion** - Automatische Maskierung von E-Mails, Telefonnummern, IBANs
- **Kein Langzeit-Chat-Gedächtnis** - Sessions sind temporär
- **Lokale Datenverarbeitung** - SQLite-Datenbank bleibt auf dem Server

### Code-Sicherheit

- **PHP-Lint-Check** - Automatische Syntax-Prüfung bei Datei-Operationen
- **Rollback bei Fehlern** - Automatisches Zurücksetzen bei Syntax-Fehlern
- **Path-Traversal-Schutz** - Kein Zugriff außerhalb vorgesehener Verzeichnisse
- **Sandboxed Execution** - Code läuft im WordPress-Kontext ohne Shell-Zugriff

### Bestätigungspflichtige Aktionen

Folgende Aktionen erfordern explizite Bestätigung:
- Löschen von Posts/Seiten
- Löschen von Benutzern
- Theme-Wechsel
- Kritische Einstellungen (Permalinks)
- Passwort-Änderungen

---

## 💪 Stärken & Unique Selling Points

### 1. Native WordPress-Integration

Im Gegensatz zu externen KI-Tools arbeitet Levi direkt im WordPress Admin:
- Kein Kontextwechsel nötig
- Live-Abfrage von WordPress-Daten
- Kein Synchronisieren von Inhalten

### 2. SQLite-basiertes Memory

- **Keine externe Datenbank** nötig (ChromaDB, Redis)
- Funktioniert auf Shared Hosting
- Ein Plugin, alles inklusive

### 3. Markdown-basierte Identität

- Persönlichkeit in `.md`-Dateien definiert
- Versionierbar mit Git
- Einfach anpassbar ohne Code-Änderungen

### 4. Umfangreiches Tool-System

Mit 40+ Tools deckt Levi nahezu alle WordPress-Aufgaben ab:
- Content-Management
- Plugin/Theme-Verwaltung
- WooCommerce
- System-Administration
- Entwickler-Tools

### 5. Flexible KI-Integration

- Unterstützung für 3 Provider (OpenRouter, OpenAI, Anthropic)
- Bring Your Own Key (BYOK)
- Freemium-Option über OpenRouter Free Tier

### 6. WordPress-konformes Freemium

- Free-Version bleibt dauerhaft nutzbar
- Pro-Features erst nach Zahlung freigeschaltet
- Konform mit WordPress.org Richtlinien

---

## 📅 Roadmap & Zukunft

### Aktueller Status

- ✅ Plugin-Grundstruktur
- ✅ Chat-Interface
- ✅ KI-Integration (3 Provider)
- ✅ Vector Memory System
- ✅ 40+ Tools (Lesen & Schreiben)
- ✅ Setup-Assistent

### Geplant (Phase 5-8)

| Phase | Feature | Status |
|-------|---------|--------|
| Phase 5 | Schreibende Tools mit Safety-Layer | 🔄 Aktiv |
| Phase 6 | Episodic Memory (Lernen von Präferenzen) | ⏳ Geplant |
| Phase 7 | UI/UX Polish (Fullscreen, Markdown, Mobile) | ⏳ Geplant |
| Phase 8 | Testing & WordPress.org Release | ⏳ Geplant |

### Langfristige Vision

- **Voice Mode** - Spracheingabe/-ausgabe
- **Image Generation** - DALL-E 3 / Stable Diffusion Integration
- **Collaborative Chat** - Mehrere WP-User im selben Chat
- **Scheduled Tasks** - Automatisierung via WP-Cron
- **Code Execution Sandbox** - Containerisierte PHP-Ausführung

---

## 🛠️ Installation & Einrichtung

### Schnellinstallation

```bash
# 1. In WordPress plugins-Verzeichnis
cd wp-content/plugins/

# 2. Repository klonen
git clone <repository-url> levi-agent

# 3. Dependencies installieren
cd levi-agent
composer install

# 4. In WordPress aktivieren
# Plugins → Levi AI Agent → Aktivieren
```

### Alternativ: ZIP-Upload

1. Repository als ZIP herunterladen
2. WordPress Admin → Plugins → Installieren → ZIP hochladen
3. Plugin aktivieren
4. Setup-Assistent folgen

### Ersteinrichtung

1. **Setup-Assistent starten** (wird automatisch angezeigt)
2. **API-Key eingeben** - OpenRouter API-Key von [openrouter.ai/keys](https://openrouter.ai/keys)
3. **Tool-Profil wählen** - Minimal / Standard / Full
4. **Gründlichkeit einstellen** - Schnell / Ausgewogen / Sehr gründlich
5. **Levi starten** - Initial-Snapshot wird erstellt

---

## 💰 Preise & Pläne

### Levi Free (€0)

- Read-first Modus
- Standard-Modell (Llama 3.1 70B Free)
- Begrenzte Rate-Limits
- Alle Lesen-Tools

### Pro Starter (€9-19/Monat)

- Alle Standard-Tools
- Erweiterte Modelle
- Höhere Rate-Limits
- Prioritäts-Support

### Pro Plus (€29-49/Monat)

- Premium-Modelle (Kimi 2.5, Claude Opus)
- Alle Tools inkl. Full-Profile
- Höchste Rate-Limits
- White-Label-Option

---

## 📞 Support & Ressourcen

- **Dokumentation**: Diese Datei
- **Issues**: GitHub Issues
- **Feature Requests**: GitHub Discussions

---

**Levi AI Agent** - Dein KI-Mitarbeiter für WordPress
*Version 0.1.0 | Made with ❤️ and 🤖*
