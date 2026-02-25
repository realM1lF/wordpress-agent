# Levi Chat Enhancement Konzept

> State-of-the-Art KI-Chat-Interface für WordPress

---

## 🎯 Vision

Ein moderner, Full-Featured KI-Chat, der mit ChatGPT/Claude mithält:
- **Full-Screen Mode** mit professionellem Layout
- **Datei-Upload** (.md, .txt, Bilder) mit Session-Context
- **Erweiterte Features** (Voice, Code-Execution, Tool-Visualization)
- **WordPress-Native** Integration (Gutenberg, Media Library)

---

## 📱 1. Full-Screen Chat Interface

### Layout-Optionen

#### Option A: "ChatGPT-Style" (Empfohlen)
```
┌─────────────────────────────────────────────────────────────┐
│  ☰  Levi AI Agent                              [⚙] [👤]   │  ← Header
├──────────┬──────────────────────────────────────────────────┤
│          │                                                  │
│ 📁 NEU   │     👤 Wie erstelle ich einen Custom Post Type? │
│          │                                                  │
│ 🗂️ Heute │     🤖 Ich zeige dir das Schritt für Schritt:   │
│          │                                                  │
│ 📅 Gest. │     [Tool: get_posts] Lade aktuelle Posts...     │
│          │                                                  │
│ ──────── │     ```php                                      │
│          │     add_action('init', function() {              │
│ 📂 PROJEKTE│       register_post_type('portfolio', [        │
│          │         'labels' => [...]                       │
│   Marketing│       ]);                                       │
│   Dev      │     });                                        │
│   Support  │     ```                                         │
│          │                                                  │
│ ──────── │     Soll ich das direkt in deine               │
│          │     functions.php einfügen?                     │
│ ⚙️ Settings│                                                  │
│          │                                                  │
└──────────┴──────────────────────────────────────────────────┘
     ↑ Sidebar (kollabierbar)        ↑ Main Chat Area (70-80%)
```

**Features:**
- **Kollabierbare Sidebar** (ähnlich VS Code)
- **Conversation History** mit Ordnern/Tags
- **Suchfunktion** über alle Chats
- **Neue Chat** Button prominent
- **Einstellungen** direkt erreichbar

#### Option B: "Overlay Mode" (Floating → Fullscreen)
```
// Kleiner Chat-Button (bestehend)
[💬] 

// Klick → expandiert zu Fullscreen Overlay
┌────────────────────────────────────────────┐
│  ┌──────────────────────────────────────┐  │
│  │                                     X │  │ ← Close
│  │  🗨️ Levi Assistant                  │  │
│  │                                     │  │
│  │  [Chat Content]                     │  │
│  │                                     │  │
│  └──────────────────────────────────────┘  │
│           ↑ Centered Modal (90vw/90vh)     │
└────────────────────────────────────────────┘
```

### Responsive Breakpoints

| Breakpoint | Layout |
|------------|--------|
| Desktop (≥1024px) | Sidebar (250px) + Chat (flex) |
| Tablet (768-1023px) | Sidebar kollabiert (Icons only), Drawer on click |
| Mobile (<768px) | Full-Screen Overlay, Bottom Sheet für Input |

### Komponenten-Struktur

```
FullscreenChat/
├── Layout/
│   ├── Header.tsx              # Logo, Model-Selector, Settings
│   ├── Sidebar.tsx             # Chat-History, Folders, New Chat
│   ├── MainChat.tsx            # Message List + Input
│   └── ResizablePanel.tsx      # Sidebar width adjustable
├── Chat/
│   ├── MessageList.tsx         # Virtualized scrolling
│   ├── MessageBubble.tsx       # User/AI messages
│   ├── ThinkingIndicator.tsx   # "Levi denkt..."
│   └── ToolCallCard.tsx        # Tool execution visualization
├── Input/
│   ├── ChatInput.tsx           # Textarea + Buttons
│   ├── FileUploadButton.tsx    # Upload .md/.txt
│   ├── VoiceButton.tsx         # Speech-to-text
│   └── ModelSelector.tsx       # GPT-4, Claude, etc.
└── Code/
    ├── CodeBlock.tsx           # Syntax highlighting
    └── CodeRunner.tsx          # Execute PHP/JS (optional)
```

---

## 📄 2. Datei-Upload & Session-Context

### Unterstützte Formate

| Format | Verarbeitung | Max Size |
|--------|-------------|----------|
| `.md` | Full text → Session Context | 5MB |
| `.txt` | Full text → Session Context | 2MB |
| `.php`, `.js`, `.css` | Code → Context + Syntax Highlight | 1MB |
| `.json`, `.yaml` | Structured data → Context | 500KB |
| `.csv` | Parsed → Table Preview + Context | 2MB |
| Bilder (`.png`, `.jpg`) | Vision API → Description | 5MB |

### Upload-Flow

```
1. User drag-droppt file in Chat
         ↓
2. Client zeigt Upload-Progress
         ↓
3. Server empfängt → speichert temporär
         ↓
4. Chunking (falls > 4000 tokens)
         ↓
5. Speicherung in Session-Cache (Redis/SQLite)
         ↓
6. Context-Injection in nächsten Prompt
```

### Session-Cache Architektur

**Redis (Empfohlen für Production):**
```php
// Redis Key-Struktur
levi:session:{session_id}:files       # Liste der hochgeladenen Files
levi:session:{session_id}:file:{id}   # Einzelnes File (Base64/Text)
levi:session:{session_id}:chunks      # Chunked Inhalte
TTL: 3600 (1 Stunde)
```

**SQLite (Fallback für Shared Hosting):**
```sql
CREATE TABLE session_files (
    id INTEGER PRIMARY KEY,
    session_id VARCHAR(64),
    filename VARCHAR(255),
    content_type VARCHAR(50),
    content TEXT,              -- Text content oder JSON für chunks
    chunk_count INTEGER DEFAULT 1,
    created_at DATETIME,
    INDEX idx_session (session_id)
);
```

### Chunking-Strategie

```php
class FileChunker {
    
    // Für Text-Dateien
    public function chunkText(string $content, int $chunkSize = 4000): array {
        $chunks = [];
        $lines = explode("\n", $content);
        $currentChunk = "";
        
        foreach ($lines as $line) {
            if (strlen($currentChunk) + strlen($line) > $chunkSize) {
                $chunks[] = $currentChunk;
                $currentChunk = $line;
            } else {
                $currentChunk .= "\n" . $line;
            }
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }
    
    // Markdown: Header-basiertes Chunking
    public function chunkMarkdown(string $content): array {
        // Split at ## Header level
        $sections = preg_split('/^(#{1,3}\s+)/m', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        $chunks = [];
        for ($i = 1; $i < count($sections); $i += 2) {
            $header = $sections[$i];
            $body = $sections[$i + 1] ?? '';
            $chunks[] = [
                'type' => 'section',
                'header' => trim($header),
                'content' => $header . $body,
            ];
        }
        
        return $chunks;
    }
}
```

### UI für Dateien

```jsx
// In der Message List
<MessageWithFiles>
  <UserMessage>"Schau dir diese Doku an:"</UserMessage>
  
  <FileAttachments>
    <FileCard 
      name="plugin-readme.md"
      size="12.4 KB"
      type="markdown"
      preview="# My Plugin\n\nThis plugin does..."
      chunks={5}
    />
  </FileAttachments>
</MessageWithFiles>

// File Card Komponente
<FileCard>
  ┌─────────────────────────────────────┐
  │  📄 plugin-readme.md          12KB │
  │  ─────────────────────────────────  │
  │  # My Plugin                        │
  │  This plugin does...                │
  │  [5 Abschnitte geladen]             │
  │                          [× Löschen]│
  └─────────────────────────────────────┘
</FileCard>
```

---

## ✨ 3. Zusätzliche Feature-Ideen

### A. Voice Mode 🎤

```
[🎤] Button im Input
    ↓
Halten → Aufnahme
    ↓
OpenAI Whisper API → Text
    ↓
Automatisch senden

+ TTS (Text-to-Speech) für Antworten
```

**Implementation:**
- Web Speech API (kostenlos, Browser-native)
- OpenAI Whisper (höhere Qualität)

### B. Code Execution 💻

```php
// Levi generiert Code
"Hier ist dein Shortcode:"

[CodeBlock 
  language="php"
  runnable={true}  // ← Execute Button
]

// Bei Klick: Sandbox-Ausführung
$result = eval_in_sandbox($code);
```

**Safety:** Containerized execution (nur bei VPS möglich)

### C. Image Generation 🎨

```
User: "Erstelle ein Hero-Bild für meinen Blog"
Levi: "Ich generiere das Bild..."

[DALL-E 3 / Stable Diffusion]
    ↓
Vorschau im Chat
    ↓
[Zur Media Library hinzufügen] [Nochmal generieren]
```

### D. Branching / Edit Mode 🌳

```
Message 1
  ├── Antwort A [regenerate]
  │     └── Weiterführung...
  ├── Antwort B [regenerate]
  │     └── Weiterführung...
  └── Antwort C (aktuell)

User kann zwischen Versionen wechseln
```

### E. Collaborative Chat 👥

```
Mehrere WordPress-User im selben Chat:
- Admin gibt Anweisungen
- Editor bearbeitet
- Levi assistiert

[admin]: "Wir brauchen einen neuen Post"
[editor]: "Ich schreibe den Content"
[Levi]: "Soll ich den Post strukturieren?"
```

### F. Scheduled Tasks ⏰

```
User: "Erstelle jeden Montag um 9 Uhr einen Zusammenfassungs-Post"
Levi: "Ich richte das als geplanten Task ein."

// WP-Cron Job
wp_schedule_event(strtotime('monday 9am'), 'weekly', 'levi_generate_summary');
```

### G. Smart Suggestions 💡

```
Während der Eingabe:
"Schreibe einen Post über..."
           ↓
[WordPress SEO]  [Gutenberg Blocks]  [Plugin Dev]

Context-basierte Vorschläge basierend auf:
- Aktueller WP-Admin Seite
- Letzten Aktionen
- Häufige Tasks
```

---

## 🔧 4. Technische Architektur

### Tech Stack (State-of-the-Art)

```
Frontend:
├── React 18 (TypeScript)
├── Zustand (State Management)
├── TanStack Virtual (Scrolling)
├── react-markdown + react-syntax-highlighter
├── Tailwind CSS + shadcn/ui
└── Vite (Build Tool)

Backend (WordPress):
├── PHP 8.1+
├── WordPress REST API
├── Redis (Session Cache)
├── SQLite (Fallback)
└── WP-Cron (Scheduled Tasks)

AI Integration:
├── OpenAI API
├── Anthropic API
├── OpenRouter (Aggregator)
└── Streaming (SSE)
```

### Komponenten-Details

#### Virtual Scrolling (TanStack Virtual)
```tsx
import { useVirtualizer } from '@tanstack/react-virtual';

function ChatMessageList({ messages }) {
  const parentRef = useRef<HTMLDivElement>(null);
  
  const virtualizer = useVirtualizer({
    count: messages.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 100,
    overscan: 5,
    // Wichtig für Chat:
    getItemKey: (index) => messages[index].id,
    measureElement: (el) => el.getBoundingClientRect().height,
  });

  return (
    <div ref={parentRef} className="h-full overflow-auto">
      <div style={{ height: virtualizer.getTotalSize() }}>
        {virtualizer.getVirtualItems().map((item) => (
          <MessageBubble
            key={item.key}
            message={messages[item.index]}
            style={{
              position: 'absolute',
              top: 0,
              transform: `translateY(${item.start}px)`,
            }}
          />
        ))}
      </div>
    </div>
  );
}
```

#### Streaming Implementation (SSE)
```php
// WordPress REST API Endpoint
add_action('rest_api_init', function() {
  register_rest_route('levi/v1', '/chat/stream', [
    'methods' => 'POST',
    'callback' => 'levi_stream_chat',
    'permission_callback' => '__return_true',
  ]);
});

function levi_stream_chat(WP_REST_Request $request) {
  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  header('Connection: keep-alive');
  
  // Disable output buffering
  ob_end_flush();
  set_time_limit(0);
  
  $client = new AIClient(); // Dein AI Client
  
  foreach ($client->stream($request->get_param('message')) as $chunk) {
    echo "data: " . json_encode([
      'content' => $chunk,
      'done' => false,
    ]) . "\n\n";
    ob_flush();
    flush();
  }
  
  echo "data: " . json_encode(['done' => true]) . "\n\n";
  exit;
}
```

```tsx
// React Hook für SSE
function useStreamingChat() {
  const [messages, setMessages] = useState([]);
  const [isStreaming, setIsStreaming] = useState(false);

  const sendMessage = async (text: string) => {
    setIsStreaming(true);
    
    const eventSource = new EventSource(
      `/wp-json/levi/v1/chat/stream?message=${encodeURIComponent(text)}`
    );
    
    let currentResponse = '';
    
    eventSource.onmessage = (event) => {
      const data = JSON.parse(event.data);
      
      if (data.done) {
        eventSource.close();
        setIsStreaming(false);
      } else {
        currentResponse += data.content;
        setMessages(prev => [
          ...prev,
          { role: 'assistant', content: currentResponse, streaming: true }
        ]);
      }
    };
  };

  return { messages, sendMessage, isStreaming };
}
```

#### File Upload Handler
```php
// REST API für File Upload
add_action('rest_api_init', function() {
  register_rest_route('levi/v1', '/upload', [
    'methods' => 'POST',
    'callback' => 'levi_handle_file_upload',
    'permission_callback' => function() {
      return current_user_can('edit_posts');
    },
  ]);
});

function levi_handle_file_upload(WP_REST_Request $request) {
  $session_id = sanitize_text_field($request->get_param('session_id'));
  $files = $request->get_file_params();
  
  $uploaded = [];
  
  foreach ($files as $file) {
    // Validate
    $allowed_types = ['text/plain', 'text/markdown', 'text/x-markdown'];
    if (!in_array($file['type'], $allowed_types)) {
      continue;
    }
    
    // Read content
    $content = file_get_contents($file['tmp_name']);
    
    // Chunk if necessary
    $chunks = [];
    if (strlen($content) > 4000) {
      $chunks = chunk_text($content);
    }
    
    // Store in Redis/SQLite
    store_in_session_cache($session_id, [
      'filename' => $file['name'],
      'content' => $content,
      'chunks' => $chunks,
      'size' => $file['size'],
    ]);
    
    $uploaded[] = [
      'id' => uniqid(),
      'name' => $file['name'],
      'chunks' => count($chunks),
    ];
  }
  
  return new WP_REST_Response(['files' => $uploaded], 200);
}
```

---

## 📊 5. Roadmap

### Phase 1: Core UI (Woche 1-2)
- [ ] Fullscreen Layout implementieren
- [ ] Sidebar mit Chat-History
- [ ] Virtual Scrolling für Messages
- [ ] Responsive Breakpoints

### Phase 2: File Upload (Woche 3)
- [ ] Drag & Drop UI
- [ ] Backend Upload Handler
- [ ] Session-Cache (Redis/SQLite)
- [ ] Chunking-Logik
- [ ] File Preview Cards

### Phase 3: Enhanced Features (Woche 4-5)
- [ ] Voice Input (Web Speech API)
- [ ] Code Syntax Highlighting
- [ ] Tool Visualization
- [ ] Edit/Regenerate/Branching

### Phase 4: Advanced (Woche 6+)
- [ ] Image Generation
- [ ] Collaborative Chat
- [ ] Scheduled Tasks
- [ ] Plugin/Theme Preview

---

## 💰 Kosten-Schätzung (AI APIs)

| Feature | Kosten/Usage | Monatlich (1000 requests) |
|---------|-------------|---------------------------|
| GPT-4o Chat | $0.005/1K tokens | ~$5-15 |
| File Processing | $0.005/1K tokens | ~$2-5 |
| Voice (Whisper) | $0.006/minute | ~$3-10 |
| TTS | $0.015/1K chars | ~$1-3 |
| Image Gen (DALL-E 3) | $0.04/image | ~$40 (optional) |

**Gesamt:** ~$10-75/Monat je nach Nutzung

---

**Soll ich mit der Implementation beginnen?**