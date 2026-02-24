# Wissen

## WordPress Kontext

Diese WordPress-Installation kann über folgende Wege angepasst werden:

### Website-Informationen
- Website-Name und Beschreibung sind konfigurierbar
- Permalink-Struktur (typischerweise /%postname%/)
- Standard-Post-Kategorie und -Format
- Zeitzone und Datumsformat-Einstellungen

### Inhaltstypen
- **Posts:** Blog-Artikel, Nachrichten, Updates
- **Seiten:** Statische Inhalte (Über uns, Kontakt, etc.)
- **Medien:** Bilder, Dokumente, Videos in der Mediathek
- **Eigene Post-Types:** Variieren je nach installierten Plugins

### Häufige Plugins (falls installiert)
- SEO-Plugins (Yoast, RankMath)
- Page-Builder (Elementor, Divi, Gutenberg-Patterns)
- Caching-Plugins (WP Rocket, W3 Total Cache)
- Formular-Plugins (Contact Form 7, Gravity Forms)
- E-Commerce (WooCommerce)

## Best Practices

### Inhaltserstellung
- Verwende Gutenberg-Blöcke für strukturierte Inhalte
- Füge Alt-Text zu Bildern hinzu
- Verwende Überschriften hierarchisch (H1 → H2 → H3)
- Halte Absätze kurz (2-4 Sätze)
- Füge interne Links hinzu, wenn relevant

### SEO-Grundlagen
- Eine H1 pro Seite/Post
- Meta-Beschreibung unter 160 Zeichen
- Verwende Fokus-Keyword im ersten Absatz
- Optimiere Bilder (komprimieren, beschreibende Dateinamen)
- Erstelle beschreibende Permalinks

### Performance
- Verwende passende Bildgrößen
- Lade keine unnötigen Skripte
- Minimiere Plugin-Nutzung
- Halte WordPress und Plugins aktuell

## Häufige Benutzer-Workflows

### Blogpost veröffentlichen
1. Entwurf mit Titel und Inhalt erstellen
2. Kategorien und Tags hinzufügen
3. Featured-Image setzen
4. Vorschau prüfen
5. Planen oder veröffentlichen

### Seite aktualisieren
1. Inhalt bearbeiten
2. Defekte Links aktualisieren
3. Mobile-Vorschau prüfen
4. Änderungen speichern
5. Cache leeren falls erforderlich

### Medien verwalten
1. In die Mediathek hochladen
2. Alt-Text und Beschreibung hinzufügen
3. Dateigröße optimieren
4. In Ordnern organisieren (falls Media-Organization-Plugin genutzt wird)

## Fehlerbehebungs-Wissen

### Häufige Probleme
- **Weiße Seite:** Meist PHP-Fehler, Fehlerprotokolle prüfen
- **Permalink 404:** Permalink-Einstellungen zurücksetzen
- **Langsamer Admin:** Wahrscheinlich Plugin-Konflikt oder unzureichendes Hosting
- **Bild-Upload fehlgeschlagen:** Dateiberechtigungen und Größenlimits prüfen
- **Update fehlgeschlagen:** Dateiberechtigungen prüfen (wp-content sollte beschreibbar sein)

### Gesundheits-Checks
- WordPress-Version aktuell?
- PHP-Version 8.0+?
- SSL-Zertifikat gültig?
- Datenbank-Tabellen optimiert?
- Backups funktionieren?

## Integrationspunkte

### Verfügbare Tools
Der Agent kann mit WordPress interagieren über:
- Post/Page CRUD-Operationen
- Mediathek-Zugriff
- Benutzerverwaltung (eingeschränkt)
- Einstellungen (ausgewählte, sichere)
- Taxonomie (Kategorien, Tags) Verwaltung
- Options-API (get/update)

### REST API Endpoints
Alle Agent-Operationen nutzen die WordPress REST API:
- `/wp/v2/posts` - Posts
- `/wp/v2/pages` - Seiten
- `/wp/v2/media` - Medien
- `/wp/v2/users` - Benutzer
- `/wp/v2/settings` - Einstellungen
