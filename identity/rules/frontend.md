# Frontend-Qualität für Plugin-Output

## Grundprinzip
Plugin-Frontend muss sich nahtlos ins aktive Theme einfügen und in jedem Container korrekt funktionieren — Full-Width, Sidebar, Modal, Widget-Area. Nie Viewport-Breite annehmen.

## Theme-Integration (PFLICHT)
- VOR dem CSS-Schreiben: `http_fetch` mit `extract: 'styles'` auf Zielseite → CSS-Custom-Properties des Themes übernehmen
- Block-Themes: `var(--wp--preset--color--primary)`, `var(--wp--preset--font-size--medium)`, `var(--wp--preset--spacing--40)`
- Elementor: `var(--e-global-color-primary)`, `var(--e-global-typography-primary-font-family)`
- **VERBOTEN:** Eigene Farben, Font-Families oder Font-Sizes hardcoden. Immer Theme-Variablen nutzen, Fallback-Wert im `var()` setzen.
- **VERBOTEN:** Inline-CSS via `<style>`-Tags. Immer eigene `.css`-Datei per `wp_enqueue_style` mit `filemtime()` als Version.

## Container-Aware Layouts
Plugins wissen nicht, wo ihr Output landet. Deshalb Container Queries statt Media Queries:

```css
.mein-plugin-wrapper {
    container-type: inline-size;
}

.mein-plugin-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
    gap: var(--wp--preset--spacing--40, 1.5rem);
}

@container (max-width: 500px) {
    .mein-plugin-card-grid {
        grid-template-columns: 1fr;
    }
}
```

### Entscheidungsbaum
- Mehrere gleichartige Items (Karten, Events, Produkte) → **CSS Grid** mit `auto-fit` + `minmax()`
- Einzelnes Element mit flexibler Anordnung (Bild + Text, Icon + Label) → **Flexbox** mit `flex-wrap: wrap`
- Nur ein Element, zentriert oder begrenzt → `max-width` + `margin-inline: auto`

## Content-Overflow-Schutz
Jede Komponente muss mit extremen Inhalten umgehen — leere Felder, lange Titel, fehlende Bilder:

- Titel: `overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;`
- Bilder: `object-fit: cover; aspect-ratio: 16/9;` + Fallback-Hintergrundfarbe wenn kein Bild
- Flex/Grid-Kinder: `min-width: 0;` setzen (verhindert Overflow durch lange Wörter)
- Text: `overflow-wrap: break-word;` auf allen Text-Containern
- Leere Zustände: Immer prüfen ob Daten vorhanden, sonst freundliche Meldung statt leeres `<div>`

## Responsives Spacing & Typografie
- Keine festen `px`-Werte für Spacing — `clamp()` oder Theme-Spacing-Variablen nutzen
- Font-Sizes: `clamp(0.875rem, 2vw + 0.5rem, 1.125rem)` oder Theme-Presets
- Padding/Margin: Relativ zum Container, nicht zum Viewport

## Accessibility Baseline
- Kontrast: Mindestens 4.5:1 für Text, 3:1 für UI-Elemente (Theme-Variablen respektieren das normalerweise)
- Interaktive Elemente: Fokus-States (`outline` oder `box-shadow`), Touch-Targets mindestens 44×44px
- Semantisches HTML: `<nav>`, `<main>`, `<section>`, `<article>`, `<button>` statt generischer `<div>`-Suppe
- Animationen: `@media (prefers-reduced-motion: reduce)` respektieren — Animationen reduzieren oder abschalten

## Typische Frontend-Fallen

| Falle | Folge | Lösung |
|-------|-------|--------|
| Feste `width`/`height` auf Karten | Bricht in schmalen Containern | `min-height` + `auto`, oder `aspect-ratio` |
| `@media` statt `@container` | Plugin-Output ignoriert Container-Breite | `container-type: inline-size` auf Wrapper |
| Eigene Farben/Fonts hardcoden | Passt nicht zum Theme, wirkt fremd | Theme CSS-Variablen mit Fallback |
| Kein `min-width: 0` auf Flex-Kindern | Lange Wörter sprengen das Layout | Immer `min-width: 0` auf Grid/Flex-Children |
| Leere Zustände ignoriert | Leeres `<div>` oder JS-Fehler bei 0 Items | Prüfung + Fallback-Meldung |
| Bilder ohne `object-fit` | Verzerrte oder übergroße Bilder | `object-fit: cover` + `aspect-ratio` |
| Kein Fokus-State auf Buttons/Links | Tastatur-Navigation unbenutzbar | `:focus-visible` mit sichtbarem Outline |

## Verifikation nach Fertigstellung
Nach jedem Frontend-Plugin gedanklich prüfen:
1. Funktioniert der Output in einem 300px-Container? (Sidebar-Test)
2. Was passiert bei 0 Items? Bei 1 Item? Bei 20 Items?
3. Was passiert bei einem Titel mit 200 Zeichen?
4. Werden Theme-Farben genutzt oder eigene?
5. `http_fetch` auf Zielseite → ist der Output sichtbar und korrekt?
