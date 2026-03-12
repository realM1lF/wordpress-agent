# Coding-Regeln

## Standards
- PSR-4 Autoloading, WordPress Coding Standards, Kommentare auf Deutsch
- Sicherheit: wp_nonce, sanitization, escaping
- Kein `<code>`/`<pre>` in Frontend-HTML-Output — rohes HTML verwenden
- Nie Falschaussagen — nur Tool-verifizierte Informationen weitergeben

## Externe URLs
- `http_fetch` funktioniert NUR für die eigene WP-Seite
- Externe URLs nur mit aktivierter Web-Suche (Globe-Button) lesbar
- Ohne Zugriff: Ehrlich sagen — VERBOTEN: Behaupten, eine URL besucht zu haben

## Verifikation nach Änderungen
- Write/Patch → sofort `read_plugin_file` → korrekt? → PHP Fatal Errors beheben
- Bei mehreren Dateien: Jede prüfen — geschrieben, eingebunden, funktional?
- Bei Frontend-Output: `http_fetch` auf Zielseite → wird korrekt gerendert?
- Bei CSS-Änderungen: `http_fetch` mit `extract: 'styles'` → echte Klassen/Variablen nutzen
- Bei Settings: Read → Change → Verify
- Nach Plugin-Erstellung/-Bearbeitung: `read_error_log` → keine neuen PHP-Fehler?
- Tool-Erfolgsmeldungen bestätigen nur die Operation, NICHT die Korrektheit
- Erst nach Verifikation "Fertig!" melden

## Client-seitig gerenderte Bereiche
Block-basierte UI rendert client-seitig. PHP-Output wird dort escaped. Für dynamisch geladene Bereiche: JavaScript/DOM-Manipulation statt PHP-Template-Output.

## Plugin-Erstellung
- `create_plugin` erstellt nur Scaffold → danach `write_plugin_file` mit echtem Code
- Slug-Kollision still lösen, Schreibreihenfolge: Unter-Dateien zuerst, Hauptdatei zuletzt
- Dateien >300 Zeilen aufteilen
- "Erstelle Plugin" = IMMER neues Plugin. Nie "ähnlich klingende" Plugins zusammenfassen. Bearbeiten nur bei explizitem Bezug. Im Zweifel: Nachfragen.
- Vor Erstellung: `get_plugins` → Kollisionsprüfung

## patch_plugin_file vs. write_plugin_file
- **patch**: Kleine Änderungen (1-5 Zeilen). Schneller, Rollback bei Syntaxfehler.
- **write**: Neue Dateien oder Rewrite >50%. Immer gesamte Datei vorher lesen.

## Wiederaufnahme nach Crash
`list_plugin_files` → jede Datei lesen → nur fehlende/kaputte Dateien schreiben. Korrekte nicht anfassen.

## Debugging
`read_error_log` → alle beteiligten Dateien lesen → Ursache aus Code ableiten (NIE raten) → minimaler Fix, keine Duplikate.

## Konsistenz
Nonce-Namen, Action-Namen, CSS-Klassen über alle Dateien identisch. Bei Datei-Änderung: Abhängige Dateien prüfen.

## Styling
- `http_fetch` + `extract: 'styles'` → CSS-Variablen der Zielseite nutzen
- `filemtime()` als Versionsparameter, eigene `.css`-Dateien per `wp_enqueue_style`
- Drittanbieter-Plugins nie direkt ändern

## Versionskompatibilität
WP/WC-Version aus Environment lesen. Bei neueren Features: `function_exists()` / `version_compare()` als Guards.

## Effizienz
Aufgabe funktional fertig → KEINE Extra-Tool-Calls nur für Kommentare oder Kosmetik.
