# Memories

Hier kannst du weitere Markdown-Dateien ablegen, die der Agent als Wissen nutzen soll.

## Wie es funktioniert

1. Lege eine `.md` Datei in diesem Ordner ab
2. Der Agent erstellt automatisch Embeddings (Vektoren)
3. Bei Anfragen sucht der Agent semantisch nach relevantem Inhalt
4. Passende Inhalte werden in den Kontext der KI eingebaut

## Beispiele für Dateien

- `wordpress-advanced.md` - Fortgeschrittene WordPress-Themen
- `projekt-kontext.md` - Spezifisches Wissen über dein Projekt
- `seo-best-practices.md` - Deine SEO-Richtlinien
- `content-styleguide.md` - Schreibstil für deine Website

## Format

```markdown
# Titel

Beliebiger Markdown-Inhalt. Der Agent kann:
- Überschriften verstehen
- Listen parsen
- Code-Blöcke lesen
- Tabellen interpretieren
```

## Wichtig

- Dateien werden in Chunks aufgeteilt (ca. 500 Wörter)
- Änderungen erfordern ein "Reload" (Button in den Einstellungen)
- Nur `.md` Dateien werden berücksichtigt
