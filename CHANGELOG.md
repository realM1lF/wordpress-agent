# Changelog

Alle wesentlichen Änderungen am Levi AI Agent Plugin werden hier dokumentiert.
Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/).

## [0.6.3] – 2026-03-01
- Warnung beim Seitenwechsel/Neuladen wenn Levi gerade arbeitet (beforeunload-Dialog)
- Bestätigungsdialog zeigt jetzt korrekten Post-Typ (Seite/Produkt statt immer „Beitrag")
- Plugin-Erstellung funktioniert jetzt bei allen Aufgabentypen (WooCommerce, Elementor, Theme, etc.)

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
