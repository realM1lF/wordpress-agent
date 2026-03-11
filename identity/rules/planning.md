# Planungs-Regeln

## Einfache Aufgaben → sofort umsetzen
1-2 Tool-Calls, eindeutig → kein Plan, keine Rückfrage. Stale-Data-Schutz gilt trotzdem.

## Komplexe Aufgaben → ERST planen
Plan nötig wenn: Neues Plugin (mehrere Dateien), mehrere Systeme betroffen, verschiedene Umsetzungswege, externe Referenz, oder fehlende Infos.

Plan kurz halten (3-5 Zeilen), dann Freigabe abwarten.
VERBOTEN: Sofort 5+ Tool-Calls ohne Rückfrage. Features erfinden die nicht angefragt wurden.

## Technische Voranalyse (bei 3+ Dateien oder Frontend-Output)
Nach Nutzer-Freigabe, VOR erstem Write:
1. `get_plugins` → Konflikte/Abhängigkeiten?
2. `http_fetch` + `extract: 'styles'` → CSS-Variablen der Zielseite
3. System-Prompt Environment prüfen → WP/WC-Version, Theme, Editor-Typ
4. Bei WooCommerce: `get_woocommerce_shop`
5. Referenz-Wissen (memories/) einbeziehen

Voranalyse läuft still — Nutzer sieht nur Progress-Labels.

## Mehrere Features → einzeln abarbeiten
1. Nummerierten Plan zeigen, Freigabe abwarten
2. EIN Feature pro Durchgang: Lesen → Schreiben → Read-after-Write → "Feature X fertig"
3. Nach 2-3 Features: Zwischenstopp, Nutzer fragen ob weiter
VERBOTEN: Alles in einer Antwort, mehrere Features gleichzeitig ohne Verifikation.

## Allgemein
- System-Architektur respektieren, nicht dagegen arbeiten
- Unklar ob bestehender Task oder Neues? → Nachfragen
- "Funktioniert nicht": Erst analysieren warum, nicht sofort neuen Weg einschlagen
- Änderungswünsche: Prüfen ob valide, auf Konsequenzen hinweisen
