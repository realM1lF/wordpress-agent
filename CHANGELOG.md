# Changelog

Alle wesentlichen Änderungen am Levi AI Agent Plugin werden hier dokumentiert.
Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/).

## [0.7.2] – 2026-03-11
- **Session-Learnings deutlich verbessert:** Levi merkt sich jetzt nur noch zeitlose Regeln und Präferenzen – keine Systemzustände („DDEV aktiv“, „Theme XY“), kein allgemeines WordPress-Wissen und keine Prompt-Beispiele mehr. Nutzt dafür Kimi 2.5 statt eines Billig-Modells. Erfasst sowohl explizite Wünsche als auch implizite Präferenzen aus Korrekturen.
- **Kein „database is locked“ mehr:** Die SQLite-Vector-DB nutzt jetzt WAL-Mode und einen 5-Sekunden-Busy-Timeout. Learnings-Extraktion und Chat können parallel laufen, ohne sich gegenseitig zu blockieren.
- **Prompt-Caching nur für Anthropic:** Kimi 2.5 auf OpenRouter nutzt eigenes implizites Caching – der vorherige Anthropic-spezifische Ansatz wurde entfernt, damit Kimi korrekt cachen kann.
- **Stream-Text bleibt sichtbar:** Wenn Levi mit Tools arbeitet, verschwindet seine vorherige Antwort nicht mehr – sie bleibt lesbar, darunter erscheint „Levi arbeitet…“.
- **Continuation nach Tools: robuster und ehrlich:** Wenn die Zusammenfassung nach einer Tool-Ausführung wegen Timeout fehlschlägt, versucht Levi es erneut mit kleinerem Payload (ohne Tool-Definitionen). Ein Ehrlichkeits-Guard verhindert, dass Levi Ergebnisse erfindet, wenn Tools gerade nicht verfügbar sind – er sagt dann ehrlich, was erledigt wurde und was noch offen ist.
- **„Levi antwortet…“ bleibt sichtbar:** Der Status-Text verschwindet nicht mehr vorzeitig bei Timeouts; „Levi versucht es erneut…“ erscheint während des Retrys.

## [0.7.1] – 2026-03-11
- **Levi antwortet deutlich schneller:** Drei Performance-Optimierungen sorgen dafür, dass Levi spürbar weniger Zeit pro Anfrage braucht:
  - **Prompt Caching:** Der stabile Teil von Levis Identität (soul, rules, knowledge) wird jetzt als eigener System-Prompt-Block gesendet, der von Anbietern wie Anthropic und OpenAI gecacht werden kann. Bei Folgenachrichten werden diese ~18K Tokens nicht erneut verarbeitet.
  - **Modulare Regeln:** Die Regeldatei (vorher ~14K Tokens in einem Stück) ist jetzt in 7 thematische Module aufgeteilt (core, tools, coding, planning, woocommerce, elementor, cron). Bei einfachen Anfragen (z.B. "Hallo" oder "Welche Plugins habe ich?") lädt Levi nur die relevanten Module — das spart bis zu 10K Tokens pro Anfrage.
  - **History-Komprimierung in Tool-Loops:** Ab der 2. Iteration in einer Tool-Kette wird die Chat-Historie auf die letzten Nachrichten gekürzt. System-Prompts und Tool-Ergebnisse bleiben vollständig erhalten, aber ältere Konversationsnachrichten werden weggelassen.
- **`<code>`- und `<pre>`-Tags werden automatisch entfernt:** Beim Schreiben und Patchen von Plugin- und Theme-Dateien entfernt Levi diese Tags jetzt automatisch aus dem HTML-Output, bevor die Datei gespeichert wird. So greifen CSS-Styles zuverlässig und Frontend-Inhalte werden nicht mehr als Monospace-Text angezeigt.
- **Tab-Benachrichtigungen bei Hintergrund-Tab:** Wenn du in einem anderen Tab arbeitest, zeigt der Browser-Tab-Titel den Levi-Status an: „Levi arbeitet…“ (mit animierten Punkten) während Levi tüftelt, „Levi ist fertig!“ wenn er fertig ist, und „Levi braucht Hilfe“ bei Fehlern. Sobald du zurück zum Tab wechselst, wird der normale Titel wiederhergestellt.
- **Beiträge vs. Seiten – keine Verwechslung mehr:** Levi hat manchmal Beiträge und Seiten verwechselt (z.B. `get_pages` statt `get_posts` aufgerufen). Das System erkennt das jetzt automatisch und korrigiert Levi, sodass er das richtige Tool nutzt. Zusätzlich sind die Tool-Beschreibungen und Regeln klarer formuliert.
- **Setup-Wizard überarbeitet:** Der Einrichtungsassistent ist freundlicher, weniger technisch und leichter verständlich. Klare Schritte, bessere Erklärungen und eine realistische Zeitangabe (5–10 Minuten).
- **Einstellungen neu sortiert:** Die Rubrik „Allgemein" heißt jetzt „Dashboard", der WordPress-Snapshot liegt unter „Memory", die Web-Suche unter „Erweitert". Sinnvollere Standardwerte für PHP-Zeitlimit, Chat-Verlauf und Arbeitsschritte.

## [0.7.0] – 2026-03-09
- **Levi erkennt vergessene Dateien automatisch:** Wenn Levi eine Datei schreibt (z.B. eine Settings-Seite), prüft das System jetzt automatisch, ob sie auch in der Hauptdatei eingebunden wird. Falls nicht, bekommt Levi sofort eine Warnung und muss die Einbindung nachholen — bevor er "fertig" meldet. Das verhindert "tote Dateien", die zwar existieren, aber nie geladen werden.
- **Completion Gate verhindert voreilige "Fertig!"-Meldungen:** Bevor Levi seine Antwort an dich sendet, prüft das System ein letztes Mal, ob alle geschriebenen Dateien korrekt verknüpft sind. Wenn noch Probleme bestehen, muss Levi sie erst beheben. Das passiert automatisch im Hintergrund.
- **Inventur-Pflicht bei Multi-File-Aufgaben:** Levi muss jetzt bei Aufgaben mit mehreren Dateien vor dem "Fertig!" alle betroffenen Dateien auflisten und für jede bestätigen: geschrieben, verifiziert und eingebunden. Vergessene Schritte werden so früh erkannt.
- **Keine `<code>`-Tags mehr im HTML-Output:** Levi darf HTML-Elemente nicht mehr in `<code>`-Tags packen — das verhindert, dass CSS-Styles greifen. Eine neue Regel und ein Backend-Check erkennen das sofort beim Schreiben und Patchen: Levi bekommt eine Warnung und muss die Tags entfernen, bevor er "fertig" meldet.

## [0.6.9] – 2026-03-09
- **Levi passt sich an dein Design an:** Wenn Levi ein Plugin mit Frontend-Ausgabe baut, analysiert er vorher automatisch die Farben, Schriften und Abstände deiner Seite. So sieht neuer Code nicht wie ein Fremdkörper aus, sondern passt optisch zum bestehenden Design.
- **Plugins mit vielen Dateien laufen stabiler:** Levi schreibt jetzt eine Datei nach der anderen (statt alles auf einmal) und erstellt zuerst die Hilfsdateien, bevor die Hauptdatei kommt. Das verhindert Abstürze und "weiße Seiten" bei größeren Plugins.
- **Levi prüft die Umgebung vor dem Coden:** Bei größeren Plugins analysiert Levi vor dem Schreiben automatisch die aktiven Plugins, das Theme, die WordPress-Version und seine Dokumentation — damit der Code auf Anhieb passt.
- **Bessere Wiederaufnahme nach Abbrüchen:** Wenn ein Plugin-Bau unterbrochen wird (Timeout, Fehler), erkennt Levi beim Weitermachen was schon da ist und schreibt nur die fehlenden Dateien — statt alles von vorne zu beginnen.
- **Plugins werden nach Fertigstellung getestet:** Nach dem Bau eines neuen Plugins ruft Levi die Zielseite auf und prüft, ob das Plugin dort wirklich sichtbar ist — bevor er "fertig" meldet.
- **Eigene Datenbank-Tabellen funktionieren sofort:** Wenn ein Plugin eine eigene Tabelle braucht (z.B. für TODOs), wird sie jetzt beim ersten Aufruf automatisch erstellt — nicht nur bei der Aktivierung.
- **Saubere Deinstallation:** Plugins, die eigene Datenbank-Tabellen anlegen, räumen diese beim Löschen jetzt automatisch auf.
- **Slug-Konflikte löst Levi selbst:** Wenn ein Plugin-Name schon auf wordpress.org existiert, wählt Levi automatisch einen anderen Namen — statt den Fehler anzuzeigen.

## [0.6.8] – 2026-03-09
- **Levi arbeitet große Aufgaben schrittweise ab:** Wenn du mehrere Features oder Ideen auf einmal anforderst (z. B. „Setze Idee 2–8 um"), teilt Levi sie jetzt in einzelne Schritte auf. Er setzt ein Feature nach dem anderen um, meldet den Fortschritt und fragt nach 2–3 Features, ob er weitermachen soll. Das verhindert Timeouts und sorgt dafür, dass nichts halb fertig bleibt.
- **Einrichtung erst fertig, wenn Levi wirklich bereit ist:** Der Einrichtungsassistent wartet jetzt, bis die Wissensdatenbank vollständig heruntergeladen und aufgebaut ist. Du siehst den Fortschritt (Dokumentation laden → Wissensdatenbank aufbauen → Snapshot erstellen) und Levi ist erst „fertig", wenn alles durch ist. Das kann 4-8 Minuten dauern.
- **Sync setzt sich von selbst fort:** Wenn der Aufbau der Wissensdatenbank wegen eines Timeouts oder Verbindungsabbruchs nicht abgeschlossen wird, setzt Levi ihn beim nächsten Admin-Besuch automatisch fort – ohne dass du etwas tun musst.
- **Keine falschen „Dateien geändert"-Meldungen mehr:** Das Problem, dass nach einem Sync weiterhin angezeigt wurde, es gäbe noch offene Änderungen, ist behoben.
- **Levi kann jetzt laengere Aufgaben durchfuehren:** Die Standardwerte fuer Arbeitsschritte und PHP-Zeitlimit wurden angehoben (25 Schritte / 300 Sekunden). Aufgaben wie "Pruefe alle Seiten auf Rechtschreibfehler" laufen jetzt zuverlaessig durch, ohne vorzeitig abzubrechen.
- **Weniger Serverlast im Admin:** Die Pruefung auf geaenderte Memory-Dateien lief vorher bei jedem Admin-Seitenaufruf. Jetzt wird nur noch alle 15 Minuten geprueft — der Rest bleibt gleich schnell.
- **Levi arbeitet praeziser mit Daten:** Wenn Levi Aenderungen an bestehenden Inhalten vornimmt (z. B. Seiten veroeffentlichen, Beitraege loeschen), prueft er jetzt immer zuerst den aktuellen Stand und handelt nur mit den echten Daten. Revisionen, Anhaenge oder Beitraege werden nicht mehr faelschlich als Seiten behandelt.
- **Regelkonflikte behoben:** Mehrere interne Regeln, die sich gegenseitig widersprochen haben, wurden bereinigt. Levi unterscheidet jetzt klar zwischen schnellen Einzelaktionen, komplexen Coding-Aufgaben und einfachen Fragen.

## [0.6.7] – 2026-03-09
- **Levi findet JavaScript-Fehler sofort:** Wenn Levi Code schreibt, der im Browser ausgeführt wird (z. B. bei Elementor-Widgets), prüft das System jetzt automatisch die Syntax. Fehlende Klammern oder Tippfehler werden erkannt – bevor du sie im Frontend siehst. Levi bekommt die Meldung direkt und kann den Fehler direkt beheben.
- **Levi versteht Dokumentation besser:** Die Referenz-Dokumente (z. B. Elementor-Anleitung) werden jetzt sinnvoller in Abschnitte zerlegt. Levi findet dadurch die passenden Stellen schneller und erzeugt bessere Lösungen – besonders bei komplexen Aufgaben wie Widget-Entwicklung.
- **Memory-Sync funktioniert wieder:** Der Button „Memories neu laden" im Chat-Widget hat vorher manchmal einen Fehler angezeigt. Das ist behoben.
- **Plugin-Installation und Theme-Wechsel:** Levi kann wieder Plugins installieren, Themes wechseln und Menüs bearbeiten, ohne dass der Domain-Guard fälschlich blockiert.

## [0.6.6] – 2026-03-08
- **Levi testet Plugins automatisch:** Nach dem Erstellen oder Ändern eines Plugins wird es kurz aktiviert und eine Seite aufgerufen. Wenn dabei ein Fehler auftritt (z. B. weiße Seite, Absturz), bekommt Levi die Meldung sofort und muss den Fehler beheben – bevor du ihn siehst.

## [0.6.5] – 2026-03-08
- **Levi versteht deutsche Anfragen besser:** Wenn du z. B. „Baue mir ein Widget" sagst, sucht Levi jetzt auch in englischer Dokumentation nach der passenden Anleitung und findet sie zuverlässiger.
- **Levi prüft Änderungen am Shop:** Nach Anpassungen an CSS oder JavaScript holt Levi die echte Shop-Seite und prüft, ob alles richtig aussieht – statt zu raten.
- **Levi kennt deine WordPress- und WooCommerce-Version:** Er berücksichtigt sie beim Schreiben von Code und vermeidet damit Kompatibilitätsprobleme.
- **Kleine Änderungen sind schneller:** Levi kann gezielte Textersetzungen in Dateien vornehmen (z. B. Versionsnummer ändern) – das geht schneller als die ganze Datei neu zu schreiben.
- **Referenz-Dokumente werden automatisch aktualisiert:** Die Anleitungen (Elementor, WooCommerce etc.) werden täglich neu geladen. Im Admin gibt es einen Button, um sie manuell zu aktualisieren.
- **Einstellungen:** Neues Feld für manuell erlaubte Plugins, Konfiguration für Zusammenfassungen, Button zum Neuladen der Dokumentation.

## [0.6.4] – 2026-03-08
- **Plugin-Erstellung funktioniert überall:** Levi kann jetzt Plugins für WooCommerce, Elementor, Themes und andere Bereiche erstellen – nicht mehr nur für bestimmte Aufgabentypen.
- **Bestätigungen müssen echt sein:** Levi kann keine gefährlichen Aktionen mehr „überspringen", indem er nur „ja" schreibt. Der Bestätigungs-Button muss wirklich geklickt werden.

## [0.6.3] – 2026-03-01
- **Warnung beim Verlassen:** Wenn Levi gerade arbeitet und du die Seite wechselst oder neu lädst, wirst du gefragt, ob du wirklich weg willst – damit keine Arbeit verloren geht.
- **Bestätigungsdialog zeigt den richtigen Typ:** Beim Löschen oder Ändern steht jetzt korrekt „Seite" oder „Produkt" – nicht mehr immer „Beitrag".

## [0.6.2] – 2026-03-01
- **Lange Chats bleiben verständlich:** Bei sehr langen Gesprächen fasst Levi ältere Nachrichten automatisch zusammen, statt sie einfach wegzulassen. So behält er den Überblick.

## [0.6.1] – 2026-02-28
- **WooCommerce:** Levi kann mehr mit Produkten, Bestellungen und dem Shop anfangen.
- **Bestätigungen:** Der Ablauf bei riskanten Aktionen (Löschen, Ändern) ist robuster. Levi bittet dich zuverlässig um Bestätigung.
- **Chat:** Gruß beim Start, Feedback nach Bestätigungen, dein Vorname wird verwendet, Datum und Uhrzeit bei Nachrichten.
- **Einstellungen:** Überarbeitete Oberfläche mit klaren Beschriftungen, Zahlenfelder wo nötig, Einstellungen werden zuverlässig gespeichert.
- **Geschwindigkeit:** Levi nutzt für einfache Fragen ein schnelleres Modell, speichert Zwischenergebnisse und arbeitet insgesamt effizienter.
