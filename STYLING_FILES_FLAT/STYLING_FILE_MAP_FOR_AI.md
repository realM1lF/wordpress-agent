# WordPress Agent - Styling Dateimappe fuer externe KI

Diese Datei beschreibt die relevante Struktur fuer **Settings-Seiten**, **Setup-Wizard** und **Chat-UI**.
Du kannst die unten gelisteten Dateien einzeln hochladen.

## Ziel

- UI/Styling modernisieren (Layout, Farben, Spacing, Typografie, States, Micro-Animations)
- Bestehende Funktionalitaet beibehalten
- Fokus auf:
  - Admin Settings
  - Setup Wizard
  - Chat Widget

## Ordnerstruktur (relevant)

```text
wordpress-agent/
├─ wp-levi-agent.php
├─ src/
│  ├─ Core/
│  │  └─ Plugin.php
│  ├─ API/
│  │  └─ ChatController.php
│  └─ Admin/
│     ├─ SettingsPage.php
│     ├─ SetupWizardPage.php
│     └─ ChatWidget.php
├─ templates/
│  └─ admin/
│     └─ chat-widget.php
└─ assets/
   ├─ css/
   │  ├─ settings-page.css
   │  ├─ setup-wizard.css
   │  └─ chat-widget.css
   └─ js/
      ├─ settings-page.js
      └─ chat-widget.js
```

## Datei-fuer-Datei Erklaerung

### `wp-levi-agent.php`
- Plugin-Bootstrap (Entry-Point), Hook-Registrierung.
- Wichtig fuer Styling nur indirekt (Initialisierung / Lebenszyklus).

### `src/Core/Plugin.php`
- Zentrale Plugin-Initialisierung.
- Laedt Assets (`chat-widget.css`, `chat-widget.js`) und setzt Localized JS-Daten.
- Relevanz fuer Styling: Asset-Einbindung und Script-Abhaengigkeiten.

### `src/Admin/SettingsPage.php`
- Rendert die gesamte Einstellungsoberflaeche (Tabs, Cards, Formularstruktur).
- Hauptdatei fuer Markup/Struktur der Settings.
- Wenn Layout geaendert wird, passiert es hier.

### `src/Admin/SetupWizardPage.php`
- Rendert Setup-Wizard Steps und verarbeitet Wizard-Formulare.
- Strukturelle Quelle fuer Wizard-UI.

### `src/Admin/ChatWidget.php`
- Haengt Chat-Widget im Admin ein.
- Bindet Template `templates/admin/chat-widget.php` ein.

### `src/API/ChatController.php`
- Backend fuer Chat/Streaming/Tool-Execution.
- **Nicht primar Styling**, aber relevant fuer:
  - UI-Zustaende (Events/Responses)
  - Fehlermeldungen/Status-Texte, die in der UI angezeigt werden

### `templates/admin/chat-widget.php`
- HTML-Struktur fuer Chat-Widget inkl. Modals/Container.
- Direktes Ziel fuer visuelle Strukturverbesserungen.

### `assets/css/settings-page.css`
- Styles fuer Settings-Tab, Cards, Inputs, Buttons, Status-Elemente.
- Haupt-CSS fuer Admin Settings UI.

### `assets/css/setup-wizard.css`
- Styles fuer Wizard-spezifische Sections/Steps.
- Nutzt teils Basisklassen aus Settings-Design.

### `assets/css/chat-widget.css`
- Styles fuer Chat-Widget (Bubble, Panel, Header, Messages, Loader, Modals).
- Hauptdatei fuer Chat-Look & Feel + Animationen.

### `assets/js/settings-page.js`
- Interaktionen der Settings-Seite (Buttons, AJAX-Aktionen, Feedback-Zustaende).
- Relevanz fuer UX-Animationen/States.

### `assets/js/chat-widget.js`
- Client-Logik fuer Chat (SSE/Streaming, Rendering, Modal-Flow, Uploads).
- Wichtig fuer animierte Zustandswechsel und UI-Reaktion.

## Hinweise fuer die externe KI (wichtig)

1. **Funktion nicht brechen**  
   - Keine API-Route-Namen, Nonces, Action-Keys oder DOM-IDs fuer JS-Selektoren unbedacht aendern.

2. **Styling-first Ansatz**  
   - Bevorzugt CSS/Markup-Anpassungen.
   - JS nur fuer UX-Verbesserungen (Transitions, Loading-States, Mikrointeraktionen), nicht fuer Core-Logik-Umbauten.

3. **Kompatibilitaet**  
   - Admin-Dark/Light-Kontraste beachten.
   - Mobile/kleine Viewports im Chat mitdenken.

4. **Sichere Aenderungen**  
   - Bestehende Klassen eher erweitern als komplett ersetzen.
   - Rueckwaertskompatibel mit existierenden Event-Handlern arbeiten.

## Empfohlene Upload-Reihenfolge an die externe KI

1. `templates/admin/chat-widget.php`
2. `assets/css/chat-widget.css`
3. `assets/js/chat-widget.js`
4. `src/Admin/SettingsPage.php`
5. `assets/css/settings-page.css`
6. `assets/js/settings-page.js`
7. `src/Admin/SetupWizardPage.php`
8. `assets/css/setup-wizard.css`
9. `src/Admin/ChatWidget.php`
10. `src/Core/Plugin.php`
11. `src/API/ChatController.php`
12. `wp-levi-agent.php`

