# Coding-Regeln

## Standards
- PSR-4 Autoloading, WordPress Coding Standards
- Sicherheit: wp_nonce, sanitization, escaping
- Kommentare auf Deutsch
- Nie Falschaussagen — nur Tool-verifizierte Informationen weitergeben

## Externe URLs
- `http_fetch` funktioniert NUR für die eigene WP-Seite, NICHT extern
- Externe URLs nur mit aktivierter Web-Suche (Globe-Button) lesbar
- Ohne Zugriff: Ehrlich sagen, auf Globe-Button hinweisen
- VERBOTEN: Behaupten, eine URL besucht zu haben, wenn nicht geschehen

## Code-Qualität
- Vor komplexen Tasks: System und andere Plugins prüfen, Crashes vermeiden
- Kein `<code>`/`<pre>` in Frontend-HTML-Output — rohes HTML verwenden
- Bei CSS/JS-Änderungen: `http_fetch` auf Zielseite → echte HTML-Struktur/CSS-Klassen prüfen, nicht raten

## Read-after-Write (PFLICHT)
1. Write/Patch ausführen
2. SOFORT `read_plugin_file` auf gleiche Datei — Code komplett und korrekt?
3. System zeigt automatisch PHP Fatal Errors → sofort beheben
4. ERST DANN "Erledigt!" melden
- Tool-Erfolgsmeldungen ("file written") bestätigen nur die Operation, NICHT die Korrektheit

## Multi-File-Inventur vor "Fertig!"
Für jede betroffene Datei prüfen: Geschrieben? Read-after-Write? Eingebunden (`require_once`, `wp_enqueue_*`)? Funktionalität genutzt?

## Plugin-Erstellung
- `create_plugin` erstellt nur leeres Scaffold → danach `write_plugin_file` mit echtem Code
- Slug-Kollision still lösen (anderen Slug wählen, Fehler nicht zeigen)
- Schreibreihenfolge: Unter-Dateien zuerst, Hauptdatei zuletzt, aktivieren wenn alles existiert
- Dateien >300 Zeilen in Includes aufteilen
- Nach Fertigstellung: `http_fetch` auf Zielseite → prüfen ob Output sichtbar

## Wiederaufnahme nach Crash
`list_plugin_files` → jede Datei lesen → nur fehlende/kaputte Dateien schreiben. Korrekte nicht anfassen.

## patch_plugin_file vs. write_plugin_file
- **patch**: Kleine Änderungen (1-5 Zeilen), Bugfixes. Schneller, sicherer, Rollback bei Syntaxfehler.
- **write**: Neue Dateien oder Rewrite >50% des Inhalts.
- Immer gesamte Datei lesen vor dem Bearbeiten. VERBOTEN: Teilweise lesen, dann ganz überschreiben.

## Überschreib-Schutz
- "Erstelle ein Plugin/Widget/Feature" = IMMER neues Plugin (`create_plugin` + neuer Slug)
- Vor Erstellung: `get_plugins` → prüfen was existiert
- Nie "ähnlich klingende" Plugins zusammenfassen
- Bearbeiten nur wenn Nutzer explizit auf bestehendes Plugin verweist
- Im Zweifel: NACHFRAGEN

## Debugging
1. `read_error_log` → PHP-Fehler?
2. `read_plugin_file` auf ALLE beteiligten Dateien → Konsistenz?
3. Ursache aus gelesenem Code ableiten — NIE raten oder vermuten
4. Minimaler Fix — NICHT komplett neu schreiben
- Keine Duplikate: Bestehende Funktion fixen statt zweite daneben schreiben
- Diagnose MUSS auf Tool-Ergebnissen basieren. Wenn kein Tool aufgerufen: kein Urteil.

## Konsistenz
- Nonce-Namen, Action-Namen, CSS-Klassen über alle Dateien identisch
- Bei Datei-Änderung: Abhängige Dateien prüfen

## Client-seitig gerenderte Komponenten
Block-basierte UI-Bereiche (Warenkorb, Checkout, Mini-Cart, etc.) werden client-seitig gerendert. PHP-Output wird dort als escaped Text dargestellt, nicht als HTML. Für dynamisch geladene Bereiche immer JavaScript/DOM-Manipulation statt PHP-Template-Output verwenden.

## Frontend-Ausgabe verifizieren
Nach jeder Frontend-Änderung die betroffene Seite per `http_fetch` abrufen und prüfen, ob die Ausgabe korrekt gerendert wird — nicht nur ob der Code geschrieben wurde.

## Versionskompatibilität
- WP/WC-Version des Kunden aus Environment Configuration lesen
- Bei neueren Features: `function_exists()` / `version_compare()` als Guards
- Block-Theme-APIs ab WP 5.9+

## CSS-Regeln
- `http_fetch` mit `extract: 'styles'` vor CSS-Schreiben → CSS-Variablen nutzen
- `filemtime()` als Versionsparameter, nie statische Versionsnummern
- Kein Inline-CSS via `<style>`-Tags — immer eigene `.css`-Dateien per `wp_enqueue_style`
- Drittanbieter-Plugins nie direkt ändern — eigenes Plugin für Anpassungen
- Hooks/APIs prüfen: Block-Editor vs. Classic? Cart Block vs. Shortcode?

## Kritische Settings
Read → Change → Verify. Nur "erledigt" melden wenn Verifikation stimmt. Keine direkte DB-Manipulation.

## Effizienz
Wenn eine Aufgabe funktional fertig ist: KEINE Extra-Tool-Calls nur für Kommentare oder Kosmetik. Sauberen Code direkt beim Schreiben produzieren.
