# Levi Setup Wizard + Free/Pro Monetization Plan (WordPress-konform)

## Ziel
Nach Installation soll Levi fuer nicht-technische Nutzer in 3-5 Minuten startklar sein.  
Der Wizard fuehrt in klaren Schritten durch:
- Erstes Verstaendnis
- Plan-Auswahl (Free oder Pro)
- Sichere Einrichtung
- Abschluss inkl. Initial-Indexierung

Wichtig: Free bleibt immer nutzbar. Pro wird erst nach erfolgreicher Zahlung freigeschaltet.

---

## 1) UX-Grundprinzip

- Kein klassisches Frontend-Popup, sondern **eigene Admin-Wizard-Seite**.
- Einmaliger Auto-Redirect nach Aktivierung.
- Schrittbalken oben (1/5 ... 5/5), klare CTA pro Schritt.
- Sprache in einfacher Du-Form.
- Wizard-Styling uebernimmt die visuelle Linie der aktuellen Settings-Seite (Farben, Karten, Buttons, Icons).

---

## 2) Setup-Flow (finales Konzept)

## Step 1: Willkommen
**Ziel:** Vertrauen und Orientierung

Inhalt:
- Danke fuer die Installation.
- Kurze Erklaerung: "Levi ist dein Assistent in WordPress."
- Link zur Hilfe-Website (Platzhalter).
- Hinweis: "Wir fuehren dich sicher durch alle Schritte."

CTA: `Weiter`

---

## Step 2: Plan waehlen (Free vs. Pro)
**Ziel:** fruehe, klare Entscheidung

Zwei Karten:
- **Levi Free**
  - "Sofort starten, kein eigenes API-Konto noetig"
  - sichere Standardnutzung
  - begrenzte Limits / reduzierte Automationen
- **Levi Pro**
  - mehr Leistung, mehr Features, mehr Modelle
  - priorisierte Nutzung und erweiterte Einstellungen

CTA:
- `Free nutzen`
- `Pro einrichten`

Hinweistext:
- "Du kannst spaeter jederzeit wechseln."

---

## Step 3A (Free): KI wird automatisch verbunden
**Ziel:** Null technische Huerde

### Konkreter Modell-Default fuer Free (OpenRouter)
Empfohlener Standard:
- **Primary:** `meta-llama/llama-3.1-70b-instruct:free`

Warum:
- 0 direkte Modellkosten (free tier route),
- ausreichend brauchbar fuer viele Read/Analyze-Aufgaben,
- kein API-Key vom Nutzer erforderlich.

Empfohlene Runtime-Regeln im Free Plan:
- Read-first Modus standardmaessig
- restriktive Tool-Limits
- konservative Iterationen
- klare Tages-/Monatslimits auf Levi-Seite

Optionaler fallback (wenn free endpoint nicht verfuegbar):
- `google/gemini-2.0-flash-001` mit hartem Kosten-Cap pro Account/Tag

CTA: `Free aktivieren`

---

## Step 3B (Pro): Zahlung und Freischaltung
**Ziel:** Pro erst nach Zahlung aktivieren

### Verbindlicher Workflow
1. Nutzer waehlt in Step 2 "Pro einrichten".
2. Step 3B zeigt Plan + Preis + "Jetzt kaufen".
3. Checkout startet (in Admin eingebettet oder Overlay/Hosted Checkout).
4. Nach erfolgreicher Zahlung:
   - Lizenzstatus = `active`
   - Planstatus = `pro`
5. Erst dann wird `Weiter zu Step 4` freigeschaltet.

### Kritische Regel
**Ohne aktiven Zahlungs-/Lizenzstatus darf der Wizard nicht in den Pro-Flow weitergehen.**

### WordPress-konforme Umsetzung
- Free bleibt voll funktionsfaehig als Free (kein Trialware-Verhalten).
- Pro-Features sind sauber als Premium markiert und gesperrt bis Kauf.
- Keine irrefuehrende UI ("aktiv", obwohl unbezahlt).

### Technische Integrationsoptionen
Priorisierung fuer schnelle Umsetzung:
1. **Freemius SDK** (schnellster Standard fuer WP-Freemium)
2. Eigener Checkout + Lizenzserver (mehr Aufwand, maximale Kontrolle)

Empfehlung fuer MVP:
- Start mit Freemius (Lizenz, Checkout, Upgrade-Flow),
- spaeter bei Bedarf Migration zu eigener Billing-Logik.

---

## Step 4: Agent konfigurieren (vereinfacht)
**Ziel:** Feintuning ohne Technikjargon

Felder in Alltagssprache:
- "Wie gruendlich soll Levi lesen?"
- "Wie vorsichtig soll Levi bei Aenderungen sein?"
- "Wie viele Arbeitsschritte darf Levi pro Aufgabe machen?"

Je Feld:
- 1-Satz-Erklaerung
- "Niedrig / Empfohlen / Hoch"
- sichtbare Empfehlung fuer Standardnutzer

Fuer Pro:
- API-Key-Feld (optional BYOK-Modus)
- Modell-Auswahl in einfacher Sprache:
  - **Kimi 2.5**: bestes Preis/Leistungs-Verhaeltnis
  - **Codex 5.3**: sehr stark fuer Code, teurer
  - **Claude Opus 4.6**: sehr stark generell, am teuersten

---

## Step 5: Abschluss + Initialisierung
**Ziel:** Startklar machen

Inhalt:
- kurze Zusammenfassung der Auswahl
- Hilfelink (Website, Platzhalter)
- Button: `Levi jetzt starten`

Aktion nach Klick:
- Snapshot/Import von WordPress + Plugins in Levis Wissenskontext starten
- Fortschritt anzeigen
- Erfolgsnachricht ausgeben: "Levi ist eingerichtet."

---

## 3) Monetization-Architektur (final)

## Produktmodell
- **Ein Plugin, zwei Plaene** (Free/Pro) in einer Codebasis.
- Feature-Gating ueber Planstatus/Lizenz.
- Optional spaeter "Agency" als dritter Plan.

## Warum nicht zwei separate Plugins als Standard?
- Hoeherer Pflegeaufwand,
- schlechtere Upgrade-UX,
- mehr Support-Komplexitaet.

---

## 4) Umsatzplan (konservativer Start)

## Planvorschlag
- **Free**: 0 EUR
- **Pro Starter**: 9-19 EUR/Monat
- **Pro Plus**: 29-49 EUR/Monat (mit Premium-Modellen/hoeheren Limits)

## Kostenkontrolle
- Free nutzt Primary-Freemodell via OpenRouter.
- Harte Request-Limits pro Tag/Monat.
- Optional fallback nur mit Budget-Cap.
- Pro-Modelle ueber Whitelist je Plan.

## KPI-Fokus
- Install -> Setup-Completion
- Setup-Completion -> erste erfolgreiche Aufgabe
- Free -> Pro Conversion
- KI-Kostenquote pro aktivem Nutzer
- Churn nach 30/90 Tagen

---

## 5) WordPress.org Konformitaet (wichtig)

- Free muss echten Nutzen haben (nicht nur "Kauf-Container").
- Premium-Upsell ist erlaubt, aber transparent und nicht aggressiv.
- Keine Trialware-artige Komplettsperre des Free-Produkts.
- Externe Services/Checkout klar dokumentieren.
- Keine irrefuehrenden Behauptungen zu Verfuegbarkeit/Funktionen.

---

## 6) Technische Akzeptanzkriterien (Definition of Done)

1. Nach Aktivierung erscheint Wizard einmalig.
2. Free-Flow funktioniert komplett ohne Nutzer-API-Key.
3. Free-Default-Modell ist gesetzt: `meta-llama/llama-3.1-70b-instruct:free`.
4. Pro-Flow blockt Weiter-CTA bis Lizenzstatus `active`.
5. Planstatus wird im Plugin robust gespeichert und geprueft.
6. Setup-Abschluss triggert Initial-Snapshot.
7. Wizard kann spaeter aus den Einstellungen erneut gestartet werden.

---

## 7) Risken + Gegenmassnahmen

- **Risk:** Free endpoint nicht verfuegbar  
  **Mitigation:** klarer fallback + Budget-Cap + transparente Meldung.

- **Risk:** Nutzer verstehen Pro-Wert nicht  
  **Mitigation:** klare Vergleichstabelle in Step 2 und konkrete Nutzenbeispiele.

- **Risk:** Hohe KI-Kosten im Free-Plan  
  **Mitigation:** strenge Limits, caching, kostenguenstige Modellrouting-Regeln.

---

## 8) Quellen / Orientierung

- WordPress Plugin Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Guideline 8 Kontext (Install/Upsell): https://make.wordpress.org/plugins/2017/03/16/clarification-of-guideline-8-executable-code-and-installs/
- Freemius Checkout Docs: https://freemius.com/help/documentation/checkout/
- Freemius In-Dashboard Upgrade: https://freemius.com/help/documentation/getting-started/making-your-first-sale/
- WooCommerce Setup Wizard Referenz: https://woocommerce.com/document/woocommerce-setup-wizard/

