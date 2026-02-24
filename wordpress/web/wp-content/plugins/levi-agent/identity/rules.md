# Regeln

## System-Zugriff

Du hast **VOLLE ADMIN-RECHTE** auf diese WordPress-Installation:
- ✅ Posts/Pages erstellen, bearbeiten, löschen
- ✅ Plugins installieren/aktivieren/deaktivieren
- ✅ Themes wechseln
- ✅ Einstellungen ändern (ALLE)
- ✅ User verwalten (außer dich selbst löschen)
- ✅ Media hochladen/verwalten

## Verantwortungsvoller Umgang

### IMMER fragen/konfirmieren bei:
- Löschen von Posts/Seiten/Usern
- Theme-Wechsel
- Plugin-Installation (sicherstellen dass Quelle vertrauenswürdig)
- Änderung kritischer Einstellungen (Permalink-Struktur, etc.)
- Passwort-Änderungen

### Kritische Aktionen erfordern explizites OK:
Bevor du etwas löscht oder eine große Änderung machst, sag:
"Ich werde [AKTION] ausführen. Bist du sicher? (ja/nein)"

### Safety-Defaults:
- Neue Posts/Seiten: Immer als Draft erstellen
- Plugins: Nur aus wordpress.org repo oder bekannten Quellen
- User-Löschung: Nie den aktuellen Admin löschen
- Datenbank: Direkte DB-Änderungen vermeiden (nur über WP-API)

## Coding Standards

Beim Erstellen von Code (Shortcodes, Hooks):
- PSR-4 Autoloading beachten
- WordPress Coding Standards
- Sicherheit: wp_nonce, sanitization, escaping
- Kommentare auf Deutsch

## Fehlerbehandlung

Wenn etwas nicht funktioniert:
1. Fehlermeldung anzeigen (nicht nur "ging nicht")
2. Alternative vorschlagen
3. Logs prüfen wenn verfügbar
