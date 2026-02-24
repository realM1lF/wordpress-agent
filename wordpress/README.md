# WordPress Dev Environment for Levi

DDEV-basierte WordPress-Entwicklungsumgebung zum Testen des Levi Agent Plugins.

## Voraussetzungen

- [Docker](https://www.docker.com/products/docker-desktop)
- [DDEV](https://ddev.com/get-started/)

## Quick Start

```bash
cd wordpress/
ddev start
```

**Zugriff:**
- Website: `https://levi-wordpress.ddev.site`
- Admin: `https://levi-wordpress.ddev.site/wp-admin/`
  - Username: `admin`
  - Password: `admin`

## Wichtige Befehle

```bash
# Umgebung starten
ddev start

# Umgebung stoppen
ddev stop

# WordPress CLI
ddev wp --info

# Datenbank exportieren
ddev export-db

# Datenbank importieren
ddev import-db --src=dump.sql

# Logs ansehen
ddev logs

# PHPMyAdmin öffnen
ddev launch -p

# Mailhog öffnen (für E-Mail-Testing)
ddev launch -m
```

## Plugin-Entwicklung

Das Levi-Plugin wird über ein Symlink in WordPress eingebunden:

```
wordpress/web/wp-content/plugins/levi-agent -> ../../../..
```

Nach dem Start ist das Plugin unter Plugins → Installierte Plugins zu finden und kann aktiviert werden.

### Source-of-Truth Workflow

Entwickle den Plugin-Code ausschließlich im Projektroot:

`wordpress-agent/`

Danach synchronisierst du den Stand in die Testinstanz mit:

```bash
./scripts/sync-plugin-to-wordpress.sh
```

So musst du keine Änderungen doppelt in `wordpress/...` pflegen.

## Konfiguration

### API-Key
Nach Aktivierung unter:
- Einstellungen → Levi AI
- API-Key eintragen (oder `.env` im Parent-Ordner nutzen)

### Memory Reload
- "Reload Memories" klicken um `.md` Files zu indexieren

## Dateistruktur

```
wordpress/
├── .ddev/
│   └── config.yaml          # DDEV Konfiguration
├── web/                     # Document Root
│   ├── wp-content/
│   │   ├── plugins/
│   │   │   └── levi-agent -> ../../../..  # Symlink zum Plugin
│   │   └── uploads/
│   └── ...
└── README.md
```

## Troubleshooting

### Port bereits belegt
```bash
ddev poweroff
ddev start
```

### Datenbank zurücksetzen
```bash
ddev delete -yO
ddev start
```

### Caches leeren
```bash
ddev wp cache flush --allow-root
```
