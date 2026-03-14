# Planungs-Regeln

## Einfache Aufgaben → sofort umsetzen
1-2 Tool-Calls, eindeutig → kein Plan, keine Rückfrage. Stale-Data-Schutz gilt trotzdem.

## Komplexe Aufgaben → ERST planen
Plan nötig wenn: Neues Plugin (mehrere Dateien), mehrere Systeme betroffen, verschiedene Umsetzungswege, externe Referenz, oder fehlende Infos.

Plan kurz halten (3-5 Zeilen), dann Freigabe abwarten.
VERBOTEN: Sofort 5+ Tool-Calls ohne Rückfrage. Features erfinden die nicht angefragt wurden.

## Technische Voranalyse — PFLICHT bei Plugin-Erstellung oder Frontend-Output
Nach Nutzer-Freigabe, VOR erstem Write:
1. `get_plugins` → Konflikte/Abhängigkeiten, vorhandene Custom Post Types?
2. Environment im System-Prompt prüfen:
   - Theme: Block-Theme (FSE) oder Classic? → bestimmt ob get_header/get_footer/get_sidebar funktionieren
   - Editor: Gutenberg oder Classic Editor?
   - PHP-Version: bestimmt welche Sprachfeatures verfügbar sind (z.B. Enums ab 8.1, match ab 8.0)
   - WP/WC-Version
3. Bei Frontend-Output: `http_fetch` auf die Zielseite → Block vs. Shortcode wird automatisch erkannt (Tool liefert `wc_rendering` und `wc_note`). Bei Block-basierten WC-Seiten: Klassische PHP-Hooks feuern NICHT → Custom Block, WC Block Extensibility API oder JS/DOM nutzen. Layout-Entscheidung: Container Queries + CSS Grid für Karten-Layouts, Theme-Variablen statt eigene Farben (→ `frontend.md`)
4. Bei WooCommerce: `get_woocommerce_shop` → Shop-Config
5. Bei Interaktion mit bestehenden Plugins/CPTs: `discover_content_types` → Custom Post Types und Taxonomien ermitteln
6. Bei CSS/Styling: `http_fetch` + `extract: 'styles'` → CSS-Variablen der Zielseite
7. Referenz-Wissen (memories/) einbeziehen

Voranalyse läuft still — Nutzer sieht nur Progress-Labels. Ergebnisse fließen in die Architektur-Entscheidung ein.

WICHTIG: Die Post-Processing-Validierung prüft deinen Code automatisch gegen die Environment-Konfiguration. Falls ein Konflikt erkannt wird (z.B. klassische WC-Hooks auf Block-Seiten), wirst du aufgefordert den Code sofort zu korrigieren. Die Voranalyse verhindert solche Konflikte bereits im Vorfeld.

## Mehrere Features → einzeln abarbeiten
1. Nummerierten Plan zeigen, Freigabe abwarten
2. EIN Feature pro Durchgang: Lesen → Schreiben → Tool-Response prüfen → "Feature X fertig"
3. Nach 2-3 Features: Zwischenstopp, Nutzer fragen ob weiter
VERBOTEN: Alles in einer Antwort, mehrere Features gleichzeitig ohne Verifikation.

## Allgemein
- System-Architektur respektieren, nicht dagegen arbeiten
- Unklar ob bestehender Task oder Neues? → Nachfragen
- "Funktioniert nicht": Erst analysieren warum, nicht sofort neuen Weg einschlagen
- Änderungswünsche: Prüfen ob valide, auf Konsequenzen hinweisen
