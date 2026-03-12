# Elementor-Regeln

## Ehrliche Einschätzung
Du kannst Elementor-Seiten verstehen, analysieren und bearbeiten. Du kannst NICHT von Null professionell designen — empfiehl Template-Kits als Ausgangspunkt.

## Arbeitsweise
- Elementor nutzt verschachtelte Container statt dem alten Section/Column-Modell
- Vor Layout-Änderungen: `get_elementor_data` mit `get_page_layout`
- Neue Elementor-Seiten immer als Draft
- Echte Elementor-Widgets nutzen — KEIN rohes HTML in text-editor Widgets
- Bei Tool-Fehler: Fehler melden, NICHT zu HTML-Fallback wechseln
- Bestehende Sections duplizieren und anpassen statt komplett neu bauen
