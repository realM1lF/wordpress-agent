# Levi Monetarisierungs- und Update-Plan

## Teil 1: Lizenz-Stufen und Feature-Mapping

| Stufe | Features | Entspricht Registry-Profil | Zusätzliche Pro-Features (geplant) |
|-------|----------|----------------------------|-------------------------------------|
| **Free** | Nur Lesen | `minimal` | – |
| **Normal** | Lesen + Schreiben | `standard` | – |
| **Pro** | Lesen + Schreiben + Code + Analyse | `full` | Website-Analyse, SEO-Check, Performance-Scan |

**Aktueller Stand:** `levi_plan_tier` wird gesetzt, aber nicht für Feature-Gates genutzt. Die Registry nutzt `tool_profile` (minimal/standard/full), das sich gut auf die Lizenz-Stufen abbilden lässt.

---

## Teil 2: Strategie für die drei Varianten

### Empfehlung: Ein Codebase, Lizenz-basierte Feature-Gates

**Warum:** Ein Plugin, das je nach Lizenz unterschiedliche Features freischaltet. Keine drei parallelen Codebases, einfache Wartung.

**Ablauf:**

1. **Lizenz-Service** (deine Website):
   - Lizenzschlüssel ausstellen (Free/Normal/Pro)
   - API-Endpoint zur Validierung (z.B. `/api/levi/license/validate`)
   - Optional: Lizenzschlüssel an Update-Requests anhängen

2. **Plugin-Logik:**
   - `levi_plan_tier` aus gültiger Lizenz ableiten (nicht nur aus dem Setup-Wizard)
   - Mapping: `free` → `minimal`, `normal` → `standard`, `pro` → `full`
   - Registry bekommt den effektiven Plan und wählt das passende Profil

3. **Pro-Features (Website-Analyse):**
   - Neue Tools: z.B. `analyze_site_performance`, `seo_audit`, `lighthouse_check`
   - Nur bei `pro` registrieren

### Alternative: Drei separate Builds

- Drei ZIPs: `levi-agent-free`, `levi-agent`, `levi-agent-pro`
- Build-Script mit `LEVI_EDITION=free|normal|pro`
- Unterschiede: Plugin-Name, Slug, eingebaute Lizenz-Stufe, ggf. ausgeblendete Menüpunkte
- Nachteil: Mehr Build- und Testaufwand, drei Update-Pfade

---

## Teil 3: Schneller Build für alle Varianten

### Option A: Ein Plugin mit Lizenz (empfohlen)

- Ein Build wie bisher: `build-production-zip.sh`
- Eine ZIP: `levi-agent-{version}.zip`
- Lizenz bestimmt zur Laufzeit die Features

### Option B: Drei separate Builds

Erweiterung von `build-production-zip.sh`:

```bash
# Aufruf: ./scripts/build-production-zip.sh [edition]
# edition: free | normal | pro (default: normal)

LEVI_EDITION="${1:-normal}"
PLUGIN_SLUG="levi-agent-${LEVI_EDITION}"   # oder levi-agent für alle
# ... rsync wie bisher ...

# Nach rsync: Edition-spezifische Anpassungen
case "$LEVI_EDITION" in
  free)  echo "LEVI_EDITION_FORCED=free" > "$PKG_DIR/.levi-edition" ;;
  normal) echo "LEVI_EDITION_FORCED=normal" > "$PKG_DIR/.levi-edition" ;;
  pro)   echo "LEVI_EDITION_FORCED=pro" > "$PKG_DIR/.levi-edition" ;;
esac

# Optional: wp-levi-agent.php Header anpassen (Plugin Name, Description)
```

- `Makefile` oder `scripts/build-all-editions.sh`:

```bash
for edition in free normal pro; do
  ./scripts/build-production-zip.sh $edition
done
```

- Output: `dist/levi-agent-free-1.0.0.zip`, `dist/levi-agent-1.0.0.zip`, `dist/levi-agent-pro-1.0.0.zip`

---

## Teil 4: Plugin-Updates von deiner Website

### Architektur

```
[Deine Website]                    [Kunden-WordPress]
     │                                      │
     ├─ /updates/levi-agent/                 │
     │    get_metadata?slug=levi-agent       │
     │    → JSON (version, download_url)    │
     │                                      │
     │    download?slug=...&license=xxx     │
     │    → ZIP (nur wenn Lizenz gültig)    │
     └──────────────────────────────────────┘
                    ↑
              Plugin Update Checker (PUC)
              prüft alle 12h
```

### Komponenten

1. **Plugin Update Checker (PUC)**
   - Library: [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
   - Im Plugin einbinden, prüft deine Update-URL statt WordPress.org

2. **Update-Server**
   - [YahnisElsts/wp-update-server](https://github.com/YahnisElsts/wp-update-server): ZIPs in `packages/`, liefert Metadaten und Download-URL
   - Oder eigener Endpoint: JSON mit `version`, `download_url`, `sections` etc.

3. **Lizenz-Integration**
   - PUC: `$updateChecker->addQueryArgFilter()` → Lizenzschlüssel an Update-Request anhängen
   - Dein Server: Lizenz prüfen, nur bei gültiger Lizenz `download_url` zurückgeben

### Minimales JSON-Format (PUC-kompatibel)

```json
{
  "name": "Levi AI Agent",
  "version": "1.0.0",
  "download_url": "https://deine-website.de/downloads/levi-agent-1.0.0.zip?license=XXX",
  "sections": {
    "description": "KI-Mitarbeiter für WordPress",
    "changelog": "<h4>1.0.0</h4><ul><li>Neue Features...</li></ul>"
  },
  "requires": "6.0",
  "tested": "6.4"
}
```

### Release-Workflow

1. Version in `wp-levi-agent.php` erhöhen
2. `./scripts/build-production-zip.sh` ausführen
3. ZIP auf deine Website hochladen (z.B. `/downloads/levi-agent-1.0.0.zip`)
4. Update-Server/JSON anpassen: neue Version, neue `download_url`
5. Kunden erhalten Update über „Plugins → Aktualisieren“ oder „Jetzt aktualisieren“

---

## Teil 5: Implementierungs-Roadmap

### Phase 1: Lizenz-System (Basis)

1. `levi_plan_tier` überall nutzen:
   - ChatController, Registry, SettingsPage
   - Quelle: Lizenz-API oder Fallback auf gespeicherten Plan
2. Mapping: `free` → `minimal`, `normal` → `standard`, `pro` → `full`
3. Lizenz-API auf deiner Website (oder später):
   - Endpoint: `POST /api/levi/license/validate`
   - Request: `license_key`, `site_url`
   - Response: `{ "valid": true, "tier": "pro" }`

### Phase 2: Update-Mechanismus

1. PUC per Composer einbinden:
   `composer require yahnis-elsts/plugin-update-checker`
2. Im Haupt-Plugin-File initialisieren:

```php
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$leviUpdateChecker = PucFactory::buildUpdateChecker(
    'https://deine-website.de/updates/levi-agent/info.json',
    __FILE__,
    'levi-agent'
);
$leviUpdateChecker->addQueryArgFilter(function($args) {
    $args['license'] = get_option('levi_license_key', '');
    return $args;
});
```

3. Update-Server auf deiner Website einrichten (wp-update-server oder eigener Endpoint)
4. ZIPs in `packages/` ablegen oder Download-URL dynamisch aus Lizenz ableiten

### Phase 3: Pro-Features (Website-Analyse)

1. Neue Tools: z.B. `AnalyzeSitePerformanceTool`, `SeoAuditTool`
2. Nur bei `pro` in der Registry registrieren
3. Optional: Integration von PageSpeed Insights, Lighthouse o.ä.

### Phase 4: Build-Automatisierung (falls mehrere Editionen)

1. `build-production-zip.sh` um Edition-Parameter erweitern
2. CI/CD (GitHub Actions): Bei Tag/Release alle Editionen bauen und hochladen

---

## Teil 6: Checkliste für Updates

| Schritt | Aktion |
|---------|--------|
| 1 | Version in `wp-levi-agent.php` erhöhen |
| 2 | `composer install --no-dev` (falls nötig) |
| 3 | `./scripts/build-production-zip.sh` ausführen |
| 4 | ZIP auf Server kopieren (z.B. `packages/levi-agent.zip`) |
| 5 | `info.json` aktualisieren (Version, download_url, Changelog) |
| 6 | Optional: GitHub Release mit ZIP als Asset erstellen |

---

## Zusammenfassung

| Thema | Empfehlung |
|-------|------------|
| **Varianten** | Ein Plugin mit Lizenz-basierten Feature-Gates |
| **Build** | Ein Build-Prozess, ggf. später um Edition-Parameter erweitern |
| **Updates** | PUC + eigener Update-Server (wp-update-server oder eigener Endpoint) |
| **Lizenz** | Lizenz-API auf deiner Website, Lizenz an Update-Requests anhängen |

Die bestehende `tool_profile`-Logik in `Registry.php` passt gut zu den Stufen Free/Normal/Pro. Der Hauptaufwand liegt in Lizenz-Validierung und Update-Server; die Feature-Gates sind mit wenigen Änderungen umsetzbar.
