1. Episodic Memory endlich aktivieren (Roadmap Phase 6)
Das ist der größte Hebel. Levi hat die DB-Tabelle episodic_memory schon, die searchEpisodicMemories()-Methode existiert, und im ChatController wird sie sogar schon abgefragt – aber es wird nie etwas reingeschrieben. Es fehlt die Logik, die nach einem Chat automatisch Fakten/Präferenzen extrahiert und speichert.
Konkret: Nach jeder abgeschlossenen Konversation (oder bei Session-Cleanup) könnte ein kurzer LLM-Call die Konversation zusammenfassen und 0-3 "gelernte Fakten" extrahieren:
"Kunde nutzt Elementor Pro"
"Kunde bevorzugt kurze Antworten"
"Shop verkauft Hundezubehör, Hauptkategorie: Leinen"
Das würde Levi über Zeit persönlicher und effizienter machen – er müsste nicht jedes Mal fragen, welches Theme/Builder genutzt wird.
2. Chunking-Strategie für Memories verbessern
Der aktuelle Chunker in MemoryLoader.php macht preg_replace('/\s+/', ' ', $text) – das zerstört alle Zeilenumbrüche und Markdown-Struktur bevor überhaupt gechunkt wird (Zeile 163). Dadurch gehen Code-Blöcke, Tabellen und Überschriften-Hierarchien verloren.
Besser:
Chunking an Markdown-Headings (## ...) statt an Absätzen orientieren
Heading als Kontext-Prefix jedem Chunk mitgeben (z.B. "[Quelle: WooCommerce > Cart > Hooks] ...")
Code-Blöcke als zusammenhängende Einheiten behandeln, nie mitten im Block splitten
Das \s+ durch etwas Sanfteres ersetzen, das Zeilenumbrüche in Code-Blöcken erhält
3. Vector Search Performance: Brute-Force-Scan ablösen
Aktuell lädt searchSimilar() alle Vektoren eines memory_type in PHP, deserialisiert jedes JSON-Embedding und berechnet Cosine Similarity in einer Schleife. Bei ~3.3 MB Docs sind das hunderte bis tausende Chunks.
Optionen:
Kurzfristig: Embeddings vorberechnet als Binary-Blob speichern statt JSON (spart json_decode pro Row)
Mittelfristig: SQLite sqlite-vec Extension nutzen (ihr hattet das schon als Option im Roadmap) – das macht den Similarity-Search nativ in SQLite, deutlich schneller
Quick Win: Einen Embedding-Cache für wiederkehrende Queries einführen (Transient mit TTL), damit nicht jede User-Nachricht einen API-Call für das Query-Embedding braucht
4. Dynamischer System-Prompt mit Plugin/Theme-Kontext
Identity.php fügt dynamischen Context hinzu (User, Site, Time, WP Version) – aber nicht welche Plugins/Themes aktiv sind. Das wäre extrem nützlich, denn Levi muss dann nicht erst get_plugins callen um zu wissen, dass z.B. Elementor, WooCommerce und ein bestimmtes Theme aktiv sind.
Vorschlag:
$activePlugins = get_option('active_plugins');$theme = wp_get_theme()->get('Name');
Das in getDynamicContext() ergänzen. Kostet quasi nichts und gibt Levi sofort Kontext über die Umgebung.
5. Conversation Summary / Sliding Window
Aktuell wird die gesamte Chat-History mitgeschickt (bis zu history_context_limit = 50 Messages). Bei langen Konversationen wird das teuer und langsam. Der trimMessagesToBudget() schneidet zwar alte Messages ab, aber verliert dabei den Kontext komplett.
Besser: Wenn die History zu lang wird, die ältesten Messages durch eine LLM-generierte Zusammenfassung ersetzen. So bleibt der Kontext erhalten, aber der Token-Verbrauch bleibt konstant.
6. Tool-Result-Kompression intelligenter machen
compactToolResultForModel() schneidet bei 12.000 Zeichen ab und begrenzt Listen auf 20 Items. Das ist okay, aber nicht kontextsensitiv. Wenn der User fragt "Zeig mir alle Produkte", sind 20 eventuell zu wenig. Wenn er fragt "Ändere den Titel von Post 42", sind die anderen 19 Posts Noise.
Vorschlag: Die User-Intent-Erkennung (die ihr schon habt mit inferTaskIntent) nutzen, um die Kompression anzupassen – bei Analyse-Fragen mehr Items durchlassen, bei gezielten Aktionen aggressiver kürzen.
7. Multi-Provider-Support für Embeddings
Der VectorStore nutzt immer text-embedding-3-small via OpenAI/OpenRouter. Wenn jemand nur einen Anthropic-Key hat, funktionieren Embeddings gar nicht (es gibt sogar eine explizite Fehlermeldung dafür).
Option: Einen lokalen/kostenlosen Embedding-Fallback einbauen, z.B. über einen kostenlosen Hugging Face Inference Endpoint, oder ein lokales PHP-basiertes Embedding (TF-IDF als Fallback). Dann ist das Memory-System nicht an einen bestimmten Provider gekoppelt.
8. Frontend/UX-Verbesserungen (Phase 7)
Laut Roadmap steht das noch aus. Die wichtigsten Quick Wins:
Typing Indicator während SSE-Status-Events (ihr habt die Events schon, nur das Frontend-Rendering)
Tool-Ausführung visuell anzeigen – die SSE progress-Events werden gesendet, aber im Frontend könnten kleine Badges/Icons die laufenden Tools zeigen
Markdown-Rendering im Chat (Marked.js ist schon geladen laut Plugin.php)
Keyboard Shortcut Cmd/Ctrl+Enter zum Senden
9. Robusteres Error-Log-Monitoring
Die injectPostWriteValidation() prüft nach Write-Tools automatisch das Error-Log – sehr gut! Aber nur mit filter: 'Fatal'. PHP-Warnings und Notices, die auf Probleme hindeuten (z.B. "undefined index", "deprecated"), werden ignoriert.
Vorschlag: Den Filter erweitern auf Fatal|Warning|Parse error und die letzten Einträge auf den Zeitstempel der letzten Write-Operation filtern (nicht nur die letzten 30 Zeilen generell).
10. Setup-Wizard für Erstinstallation
Ihr habt ein SetupWizardPage.php. Falls das noch nicht vollständig implementiert ist: Ein guter Wizard, der beim ersten Start den API-Key abfragt, das Tool-Profil erklärt und automatisch die Memories lädt, würde die Onboarding-Experience deutlich verbessern. Viele WordPress-User sind nicht technisch und brauchen Führung.
Bonus: Kleinere Quick-Wins
X-Title Header in OpenRouterClient steht noch auf 'Mohami WordPress Agent' (Zeile 165, 239) – sollte Levi AI Agent sein
StateSnapshotService wird im System-Prompt eingebunden – prüfen ob das performant genug ist und keinen zusätzlichen DB-Roundtrip pro Request macht, wenn kein Snapshot da ist
Rate Limiting nutzt set_transient – bei jedem Request wird der Counter überschrieben. Bei gleichzeitigen Requests kann das Race Conditions verursachen. Ein UPDATE ... SET count = count + 1 wäre robuster