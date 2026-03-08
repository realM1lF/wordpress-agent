# Changelog

Alle wesentlichen Änderungen am Levi AI Agent Plugin werden hier dokumentiert.
Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/).

## [0.6.6] – 2026-03-08
- Plugin-Smoke-Test: Nach dem Schreiben von PHP-Dateien wird das Plugin automatisch aktiviert und eine Frontend-Seite geladen, um Runtime-Fehler (Fatal, ArgumentCountError, TypeError) zu erkennen
- Bei Fehler wird das Plugin sofort deaktiviert und der Fehler wird Levi als Pflicht-Fix injiziert
- Auch Error-Log wird nach dem Seitenaufruf geprüft (plugin-spezifisch gefiltert)

## [0.6.5] – 2026-03-08
- QueryExpander: Deutsche Nutzeranfragen werden automatisch in englische Such-Queries übersetzt für bessere Treffer in der Referenz-Doku (Vector-DB)
- Multi-Query-Retrieval: Vektor-Suche läuft parallel über Original- und expandierte Queries, Ergebnisse werden nach Similarity gemergt
- QueryClassifier: Fehlende deutsche Action-Verben (baue, programmier, implementier) ergänzt, ACTION-Queries erhalten jetzt Referenz-Dokumente
- http_fetch ins Standard-Profil verschoben (vorher nur im Entwickler-Profil)
- Automatische CSS-Verifikation: Nach CSS/JS-Writes wird die Shop-Seite automatisch per http_fetch geholt und dem LLM als DOM-Kontext injiziert
- Versionskompatibilität: WordPress- und WooCommerce-Version des Kunden werden im System-Prompt angezeigt, neue Regel zur Versionsprüfung bei Hooks/Filtern
- Frontend-Verifikationsregel: Levi muss nach CSS-Änderungen die echte HTML-Struktur prüfen statt zu raten
- DocsFetcher (PHP): Ersetzt die alten Python-Scripts, holt Referenz-Docs direkt im Plugin und speichert sie in wp-content/uploads/
- Memory-System: Inkrementelle Updates (nur geänderte Dateien), täglicher Docs-Fetch-Cron um 04:00 Uhr
- VectorStore: Bucket-Hash-Optimierung nur noch bei >3000 Vektoren, Brute-Force bei kleineren Datensätzen für bessere Trefferquote
- PatchPluginFileTool: Neues Tool für gezielte Search-and-Replace-Patches in Plugin-Dateien (schneller als write_plugin_file bei kleinen Änderungen)
- SessionSummarizer: Eigener Service für LLM-basierte Session-Zusammenfassungen (extrahiert aus ChatController)
- Settings: Neues Feld für manuell erlaubte Plugin-Slugs, Summary-Modell-Konfiguration, Docs-Fetch-Button im Admin
- Build-Script: Secret-Leak-Erkennung vor ZIP-Erstellung, zusätzliche Exclude-Patterns

## [0.6.4] – 2026-03-08
- Plugin-Erstellung funktioniert jetzt bei allen Aufgabentypen (WooCommerce, Elementor, Theme, etc.)
- Fake-Confirmation-Schutz: Backend erkennt wenn Levi eine Bestätigung nur als Text schreibt und erzwingt den echten Tool-Call

## [0.6.3] – 2026-03-01
- Warnung beim Seitenwechsel/Neuladen wenn Levi gerade arbeitet (beforeunload-Dialog)
- Bestätigungsdialog zeigt jetzt korrekten Post-Typ (Seite/Produkt statt immer „Beitrag")

## [0.6.2] – 2026-03-01
- Session-Zusammenfassung via LLM statt Messages droppen (Summary-Injection bei Token-Budget-Überschreitung)

## [0.6.1] – 2026-02-28
- WooCommerce Tools erweitern, Confirmation-Flow robuster, filemtime-Versionierung
- Chat: Session-Gruß, Bestätigungs-Feedback, Safety-Texte, Vorname
- Settings-Persistenz, Plugin-Scaffold-Nudge, Overwrite-Schutz, Bestätigungs-Flow
- Fallback-Text verbessern, PHP-Zeitlimit Default auf 180s
- Chat: Datum + Uhrzeit bei Nachrichten anzeigen
- Confirmation Flow: Rules/Soul anpassen, Sofort-Abbruch, Safety Gates
- Defaults: rate_limit 100, max_tool_iterations 18
- Settings UX Overhaul: ehrliche Labels, Zahlenfelder, neue Wert-Mappings
- Smart Memory Routing: Identity-Cache, Adaptive Query Router, Event-basierte Snapshots
- Auto-Retry bei Bestätigung destruktiver Aktionen + GetOptionsTool Whitelist
- Alternatives Modell (Turbo) für einfache Queries
- Embedding Cache, Partial-Import & Resume-Logik
- Performance-Optimierungen, Tool-Fehler-Handling und UI-Verbesserungen
