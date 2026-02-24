# Mohami WordPress AI Agent

KI-Mitarbeiter für WordPress - inspiriert vom Mohami KI-Agent System.

## Features

- 💬 **Chat Interface** - Schwebender Chat im WordPress Admin
- 🤖 **KI-Integration** - Verbindung zu OpenAI/Claude
- 🧠 **Memory System** - 4-Schichten-Gedächtnis für Kontext
- 🛠️ **WordPress Tools** - Posts, Seiten, Einstellungen verwalten
- 🔒 **Sicherheit** - WordPress Capability-Checks & Nonce-Verification

## Installation

### 1. Voraussetzungen

- WordPress 6.0+
- PHP 8.1+

### 2. Installation

```bash
cd wp-content/plugins/
git clone git@github.com:realM1lF/wordpress-agent.git mohami-agent
```

Oder als ZIP:
1. Repository als ZIP herunterladen
2. In WordPress unter Plugins → Installieren → ZIP hochladen

### 3. Aktivieren

1. WordPress Admin → Plugins
2. "Mohami AI Agent" aktivieren
3. Datenbank-Tabellen werden automatisch erstellt

## Entwicklung

### Setup

```bash
cd mohami-agent
composer install
```

### Code Style

```bash
composer run phpcs    # Prüfen
composer run phpcbf   # Fixen
```

### Dateistruktur

```
mohami-agent/
├── wp-mohami-agent.php      # Haupt-Plugin-Datei
├── src/
│   ├── Core/Plugin.php      # Hauptklasse
│   ├── Admin/               # Admin-Interface
│   ├── API/                 # REST API
│   ├── Database/            # Datenbank
│   ├── Memory/              # Gedächtnis-System
│   └── AI/                  # KI-Integration
├── assets/
│   ├── css/                 # Styles
│   └── js/                  # JavaScript
└── templates/               # PHP Templates
```

## Roadmap

- [x] Plugin Boilerplate
- [x] Chat Widget UI
- [x] REST API Grundstruktur
- [ ] LLM Integration (OpenAI/Claude)
- [ ] Memory System (4 Schichten)
- [ ] WordPress Tools (Posts, Pages, Settings)
- [ ] Tool Execution UI
- [ ] Einstellungs-Seite

## Lizenz

GPL v2

## Autor

realM1lF
