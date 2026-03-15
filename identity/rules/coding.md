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

## Automatische Tool-Validierung
Die Write-Tools (`write_plugin_file`, `patch_plugin_file`, `write_theme_file`, `patch_theme_file`) liefern automatisch:
- **Read-back**: Zeilenanzahl + Preview der ersten 15 Zeilen nach jedem Write
- **Syntax-Check**: PHP/JS/CSS-Validierung mit Rollback bei Fehlern
- **Size-Warning**: Warnung wenn Datei >300 Zeilen (Hinweis zum Aufteilen)
- **Constant-Warning**: Warnung wenn Sub-Dateien undefinierte Konstanten referenzieren
- **Block-Detection**: `http_fetch` erkennt automatisch ob WC-Seiten Blocks oder Shortcodes nutzen und warnt bei inkompatiblen Hooks
- **Fuzzy-Match**: Bei fehlgeschlagenem Patch wird die aehnlichste Zeile vorgeschlagen
- **Dry-Run**: `patch_plugin_file`/`patch_theme_file` mit `dry_run=true` zeigt Aenderungen ohne zu schreiben
- **Undo-Stack**: Alle Write/Patch-Operationen speichern die vorherige Version. Bei Problemen: `revert_file` nutzen
- **Dependency-Scan**: Nach Write/Patch-Operationen wird automatisch geprueft, welche anderen Dateien die geaenderten Symbole (Funktionen, Klassen, Hooks, Konstanten) referenzieren. Bei Treffern erscheint eine `[DEPENDENCY-WARNUNG]` — diese Stellen MUESSEN geprueft und ggf. angepasst werden.
- **Session-Kontext**: Das System trackt automatisch welche Dateien du gelesen/geschrieben hast und zeigt dir ab Iteration 2 eine `[SESSION-KONTEXT]`-Zusammenfassung. Nutze diesen Kontext um den Ueberblick zu behalten.
- **Referenz-Check**: Nach Write/Patch-Operationen werden geschriebene PHP-Dateien auf undefinierte Funktionsaufrufe geprueft — Funktionen die weder im Plugin definiert noch als WordPress-Core/PHP-Builtin bekannt sind. Bei Treffern erscheint eine `[CODE-WARNUNG]`.
- **WordPress-Pattern-Check**: Automatische Pruefung auf haeufige WordPress-Antipatterns: echo in Filter-Callbacks, $_POST ohne Nonce-Pruefung, unsichere DB-Queries ohne prepare(), deprecated Funktionen und fehlende Text-Domains in Uebersetzungsfunktionen.
- **Kontextfenster-Komprimierung**: Aeltere Tool-Ergebnisse (>3 Iterationen) werden automatisch zu einzeiligen Zusammenfassungen komprimiert, um Platz im Kontextfenster zu sparen. Die wesentlichen Informationen (Dateipfad, Zeilenzahl, Symbole) bleiben erhalten.

Auf diese Daten in der Tool-Response achten und entsprechend reagieren.

## Multi-File-Inventur vor "Fertig!"
Für jede betroffene Datei prüfen: Geschrieben? Eingebunden (`require_once`, `wp_enqueue_*`)? Funktionalität genutzt?

## Plugin-Erstellung
- `create_plugin` erzeugt ein fertiges Scaffold mit korrektem Header, ABSPATH-Check, Konstanten (`_FILE`, `_VERSION`, `_DIR`, `_URL`)
  - `plugin_type=woocommerce` → WC-Dependency-Check, HPOS-Kompatibilität, Settings-Section
  - `plugin_type=elementor` → Elementor-Dependency-Check
  - `features` → automatisch generierte Admin-Settings, Frontend-CSS/JS, REST-API Dateien
- `write_plugin_file` danach für die eigentliche Geschäftslogik — der Plugin-Header wird automatisch bewahrt
- Slug-Kollision still lösen (anderen Slug wählen, Fehler nicht zeigen)
- Schreibreihenfolge: Unter-Dateien zuerst, Hauptdatei zuletzt, aktivieren wenn alles existiert
- Nach Fertigstellung: `http_fetch` auf Zielseite → prüfen ob Output sichtbar (PFLICHT bei Frontend-Plugins)
- Nach Plugin-Erstellung/-Bearbeitung: `read_error_log` → keine neuen PHP-Fehler?
- Nach umfangreichen Aenderungen: `check_plugin_health` → alle Dateien syntaktisch korrekt? Include-Targets vorhanden?

## Wiederaufnahme nach Crash
`list_plugin_files` → jede Datei lesen → nur fehlende/kaputte Dateien schreiben. Korrekte nicht anfassen.

## patch vs. write (Plugins UND Themes)
- **patch_plugin_file / patch_theme_file**: IMMER fuer Aenderungen an bestehenden Dateien. Egal ob 1 Zeile oder 30 Zeilen. Bis zu 50 Replacements pro Aufruf. Rollback bei Syntaxfehler.
- **write_plugin_file / write_theme_file**: NUR fuer **neue** Dateien. Wird bei bestehenden Dateien **abgelehnt** (Fehler). Ausnahme: `overwrite=true` bei komplettem Rewrite (>50% Aenderung) — dabei MUSS vorher die gesamte Datei gelesen werden.
- PFLICHT: Gesamte Datei lesen vor dem Bearbeiten. VERBOTEN: Teilweise lesen, dann ganz ueberschreiben.
- PFLICHT: Vor Aenderungen `grep_plugin_files` nutzen um alle betroffenen Stellen zu finden.

## Überschreib-Schutz
- "Erstelle ein Plugin/Widget/Feature" = IMMER neues Plugin (`create_plugin` + neuer Slug)
- Vor Erstellung: `get_plugins` → prüfen was existiert
- Nie "ähnlich klingende" Plugins zusammenfassen
- Bearbeiten nur wenn Nutzer explizit auf bestehendes Plugin verweist
- Im Zweifel: NACHFRAGEN

## Debugging
1. `read_error_log` → PHP-Fehler?
2. `read_plugin_file` auf ALLE beteiligten Dateien → Konsistenz?
3. `check_plugin_health` → Syntax-Fehler, undefinierte Referenzen und WordPress-Antipatterns in allen Plugin-Dateien auf einmal pruefen
4. Ursache aus gelesenem Code ableiten — NIE raten oder vermuten
5. Minimaler Fix — NICHT komplett neu schreiben
- Keine Duplikate: Bestehende Funktion fixen statt zweite daneben schreiben
- Diagnose MUSS auf Tool-Ergebnissen basieren. Wenn kein Tool aufgerufen: kein Urteil.
- Bei verpatztem Edit: `revert_file` nutzen um die letzte funktionierende Version wiederherzustellen, statt blind weiterzupatchen.

## Konsistenz
- Nonce-Namen, Action-Namen, CSS-Klassen über alle Dateien identisch
- Bei Datei-Änderung: Abhängige Dateien prüfen — das System zeigt dir automatisch `[DEPENDENCY-WARNUNG]` mit Dateien die betroffene Symbole referenzieren. Diese Warnungen NICHT ignorieren.
- Meta-Keys: IMMER aus dem bestehenden `save_post`/`update_post_meta`-Handler kopieren. Nie Meta-Key-Namen auswendig tippen. Bei neuer Funktion die Meta-Keys liest: Erst die Datei mit dem save-Handler lesen, Keys von dort kopieren.

## Custom Post Types (CPT)
- `register_post_type()` erzeugt neue URL-Regeln, die erst nach `flush_rewrite_rules()` funktionieren
- PFLICHT bei Plugin mit CPT: `register_activation_hook` mit `flush_rewrite_rules()` einbauen
- PFLICHT bei Plugin mit CPT: `register_deactivation_hook` mit `flush_rewrite_rules()` einbauen
- Nach dem Schreiben von CPT-Code: Plugin per `install_plugin` mit `action=deactivate` und dann `action=activate` neu aktivieren, damit der Activation-Hook feuert
- Ohne flush: Archiv- und Einzelseiten liefern 404, obwohl der Code korrekt ist
- Das System flusht Rewrite-Regeln automatisch im Smoke-Test wenn `register_post_type` erkannt wird — trotzdem immer die Activation-Hooks einbauen

## Typische PHP-Fallen

| Falle | Folge | Lösung |
|-------|-------|--------|
| `the_content()` in `the_content`-Filter aufrufen | Endlosschleife → HTTP 500 | `$content`-Parameter nutzen, nie `the_content()` im Filter-Callback |
| `is_cart()` / `is_checkout()` auf Block-Seiten | Gibt `false` zurück → Code läuft nie | `wc_get_page_id('cart')` + aktuelle Post-ID vergleichen |
| Meta-Keys auswendig tippen | Stille Fehler, leere Daten | IMMER aus bestehendem `save_post`-Handler kopieren |
| `wp_enqueue_*` nur mit `is_page()` | Verpasst Block-Seiten | Seitentyp-unabhängig laden, dann spezifisch prüfen |
| `wp_redirect()` nach Header-Output | "headers already sent" | Redirect vor jeder Ausgabe, mit `exit` danach |

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
- Detaillierte Frontend-Qualitätsregeln (Layouts, Overflow, Accessibility) → siehe `frontend.md`

## Kritische Settings
Read → Change → Verify. Nur "erledigt" melden wenn Verifikation stimmt. Keine direkte DB-Manipulation.

## Effizienz
Wenn eine Aufgabe funktional fertig ist: KEINE Extra-Tool-Calls nur für Kommentare oder Kosmetik. Sauberen Code direkt beim Schreiben produzieren.
