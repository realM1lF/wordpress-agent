# Levi AI Agent - Sicherheits- und Datenschutzbericht

> Umfassende Dokumentation der Sicherheitsarchitektur und Datenschutzmaßnahmen des Levi AI Agent für WordPress

---

## 📋 Executive Summary

Der **Levi AI Agent** implementiert ein mehrschichtiges Sicherheitskonzept, das auf WordPress-Standards aufbaut und durch zusätzliche Schutzmaßnahmen für den KI-Betrieb erweitert wird. Die Architektur priorisiert **Defense in Depth** mit klar definierten Sicherheitsstufen, granularen Berechtigungen und transparentem Datenhandling.

### Sicherheits-Highlights

| Bereich | Maßnahme | Status |
|---------|----------|--------|
| **Zugriffskontrolle** | WordPress Capabilities + Tool-Profile | ✅ Implementiert |
| **Code-Ausführung** | Sandboxed mit Funktions-Blockliste | ✅ Implementiert |
| **Datei-Operationen** | Path Traversal-Schutz + Rollback | ✅ Implementiert |
| **API-Sicherheit** | Nonce-Validierung + Rate-Limiting | ✅ Implementiert |
| **Datenschutz** | PII-Redaktion + Konfigurierbare Speicherung | ✅ Implementiert |
| **Verschlüsselung** | HTTPS für alle externen Verbindungen | ✅ Implementiert |

---

## 🛡️ Sicherheitsarchitektur

### 1. Drei-Stufen Tool-Profil-System

Das Herzstück der Sicherheitsarchitektur ist das **Tool-Profil-System**, das den Zugriff auf Basis der Benutzerrolle und des Vertrauensniveaus steuert:

```
┌─────────────────────────────────────────────────────────────┐
│                    TOOL-PROFILE                              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  🔵 MINIMAL (14 Tools)                                      │
│  ├── Für: Anfänger, Content-Manager                         │
│  ├── Rechte: Nur lesen, keine Änderungen                    │
│  └── Tools: get_posts, get_pages, get_users, etc.           │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  🟡 STANDARD (32 Tools) ⭐ EMPFOHLEN                        │
│  ├── Für: Standard-Nutzer, Redakteure                       │
│  ├── Rechte: Lesen + Schreiben (Content, Einstellungen)     │
│  └── + create_post, update_post, install_plugin, etc.       │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  🔴 FULL (41 Tools)                                         │
│  ├── Für: Entwickler, Administratoren                       │
│  ├── Rechte: Alle Tools inkl. Code-Ausführung               │
│  └── + execute_wp_code, http_fetch                          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Tool-Zuweisung pro Profil:**

| Profil | Anzahl Tools | Beschreibung |
|--------|--------------|--------------|
| **minimal** | 14 | Lese-Tools für Posts, Pages, Users, Plugins, Options, Media, Error-Logs |
| **standard** | 32 | Lesen + Schreiben (Content, Plugins, Themes, WooCommerce) |
| **full** | 41 | Alle Tools inkl. PHP-Code-Ausführung und HTTP-Fetch |

### 2. WordPress Capability-Prüfungen

Jedes Tool erfordert spezifische WordPress-Berechtigungen:

| Tool-Kategorie | Erforderliche Capability | Standard-Rollen |
|----------------|-------------------------|-----------------|
| **Chat-Widget anzeigen** | `edit_posts` | Editor, Admin |
| **Content erstellen/bearbeiten** | `edit_posts` | Editor, Admin |
| **Plugins installieren** | `install_plugins` | Admin |
| **Themes wechseln** | `switch_themes` | Admin |
| **Einstellungen ändern** | `manage_options` | Admin |
| **Code ausführen (full)** | `manage_options` | Admin |
| **HTTP-Fetch (full)** | `manage_options` | Admin |

**Implementierung in jedem Tool:**
```php
public function execute(array $parameters): array
{
    // Capability-Check
    if (!current_user_can('edit_posts')) {
        return ['error' => 'Insufficient permissions'];
    }
    
    // Tool-Logik...
}
```

### 3. Code-Ausführungssicherheit (ExecuteWPCodeTool)

Das `execute_wp_code` Tool (nur im **Full** Profil verfügbar) implementiert umfassende Sicherheitsmaßnahmen:

#### Blockierte Funktionen (Schwarze Liste)

| Kategorie | Blockierte Funktionen |
|-----------|----------------------|
| **Shell-Ausführung** | `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `popen()`, `pcntl_exec()` |
| **Code-Evaluierung** | `eval()` (verschachtelt), `create_function()`, `assert()` |
| **Datei-Inklusion** | `include`, `require` mit externen URLs |
| **Prozess-Kontrolle** | `proc_*` Funktionen |
| **Socket-Operationen** | Unsichere Socket-Funktionen |

#### Sicherheitslimits

| Limit | Wert | Zweck |
|-------|------|-------|
| **Timeout** | 30 Sekunden | Verhindert Endlosschleifen |
| **Max. Output** | 50 KB | Schutz vor Speicherüberlastung |
| **Memory Limit** | System-Standard | WordPress-Kontext |
| **Recursion** | Verhindert | Kein verschachteltes eval() |

#### Ausführungs-Kontext
```php
// Code läuft im WordPress-Kontext
// - Zugriff auf $wpdb
// - Zugriff auf WordPress-Funktionen
// - KEIN Zugriff auf Shell
// - KEIN Zugriff auf Dateisystem außerhalb WP
```

### 4. Datei-Operationen-Sicherheit

Alle Datei-Operationen (Plugin/Theme-Dateien lesen/schreiben) sind durch mehrere Sicherheitsschichten geschützt:

#### Path Traversal-Schutz

```php
// Beispiel: WritePluginFileTool
private function validatePath(string $plugin, string $file): bool
{
    // 1. Plugin-Name validieren (keine .. erlaubt)
    if (strpos($plugin, '..') !== false || strpos($plugin, '/') !== false) {
        return false;
    }
    
    // 2. Dateipfad validieren
    if (strpos($file, '..') !== false) {
        return false;
    }
    
    // 3. Realpath-Überprüfung
    $basePath = WP_PLUGIN_DIR . '/' . $plugin . '/';
    $targetPath = realpath($basePath . $file);
    $realBasePath = realpath($basePath);
    
    if ($targetPath === false || strpos($targetPath, $realBasePath) !== 0) {
        return false; // Außerhalb des erlaubten Verzeichnisses
    }
    
    return true;
}
```

#### Automatisches Backup und Rollback

| Aktion | Implementierung |
|--------|----------------|
| **Backup vor Änderung** | Bestehender Inhalt wird gespeichert |
| **PHP-Lint-Check** | Syntax-Validierung vor Speicherung |
| **Automatisches Rollback** | Bei Syntax-Fehler → Wiederherstellung |
| **Benachrichtigung** | Fehlermeldung bei fehlgeschlagener Validierung |

#### Verzeichnis-Einschränkungen

| Tool | Erlaubte Verzeichnisse |
|------|----------------------|
| `write_plugin_file` | `wp-content/plugins/{plugin}/` |
| `write_theme_file` | `wp-content/themes/{theme}/` |
| `read_plugin_file` | `wp-content/plugins/{plugin}/` |
| `read_theme_file` | `wp-content/themes/{theme}/` |

### 5. REST API Sicherheit

Alle REST-Endpoints implementieren WordPress-Sicherheitsstandards:

#### Authentifizierung und Autorisierung

```php
// Beispiel: ChatController Registrierung
register_rest_route('levi-agent/v1', '/chat', [
    'methods' => 'POST',
    'callback' => [$this, 'handleChat'],
    'permission_callback' => function() {
        return current_user_can('edit_posts'); // Capability-Check
    },
]);
```

#### Nonce-Verifizierung

| Endpoint | Nonce-Prüfung | Zweck |
|----------|--------------|-------|
| `POST /chat` | ✅ `wp_rest` | CSRF-Schutz |
| `POST /chat/stream` | ✅ `wp_rest` | CSRF-Schutz |
| `DELETE /chat/{session}` | ✅ `wp_rest` | CSRF-Schutz |
| `POST /chat/upload` | ✅ `wp_rest` | CSRF-Schutz |

#### Request-Validierung

```php
// Parameter-Sanitization
$message = sanitize_text_field($request['message']);
$sessionId = sanitize_text_field($request['session_id']);

// Typ-Validierung
if (!is_string($message) || strlen($message) > 10000) {
    return new WP_Error('invalid_message', 'Invalid message format', 400);
}
```

### 6. Rate-Limiting

Schutz gegen Missbrauch durch Anfrage-Begrenzung:

#### Konfiguration

| Einstellung | Standard | Bereich |
|-------------|----------|---------|
| **Rate Limit** | 50 Anfragen/Stunde | 1-1000 konfigurierbar |
| **Zeitfenster** | 1 Stunde | Fest |
| **Speicherung** | WordPress Transients | Temporär |

#### Implementierung
```php
public function checkRateLimit(int $userId): bool
{
    $transientKey = 'levi_rate_limit_' . $userId;
    $current = get_transient($transientKey);
    
    if ($current === false) {
        set_transient($transientKey, 1, HOUR_IN_SECONDS);
        return true;
    }
    
    if ($current >= $this->rateLimit) {
        return false; // Limit erreicht
    }
    
    set_transient($transientKey, $current + 1, HOUR_IN_SECONDS);
    return true;
}
```

### 7. PII-Redaktion (Personenbezogene Daten)

Automatischer Schutz sensibler Daten:

#### Blockierte Post-Types (Schutz sensibler Formulardaten)

```php
$blockedPostTypes = [
    'wpforms',           // WPForms Einträge
    'flamingo_contact',  // Flamingo Nachrichten
    'nf_sub',           // Ninja Forms
    'edd_payment',      // Easy Digital Downloads
    'shop_order',       // WooCommerce Bestellungen (optional)
    'wc_booking',       // WooCommerce Bookings
];
```

#### Blockierte Meta-Keys (Zahlungs-/Kundendaten)

```php
$blockedMetaKeys = [
    '_billing_*',        // Rechnungsadressen
    '_shipping_*',       // Lieferadressen
    '_stripe_*',         // Stripe-Zahlungsdaten
    '_paypal_*',         // PayPal-Daten
    '_payment_*',        // Allgemeine Zahlungsdaten
    '_credit_card*',     // Kreditkarteninformationen
    '_iban*',           // IBANs
];
```

#### Automatische Maskierung

| Datentyp | Beispiel | Maskiert |
|----------|----------|----------|
| **E-Mail** | `max@example.com` | `***@***.com` |
| **Telefon** | `+49 123 456789` | `+** *** ******` |
| **IBAN** | `DE12 3456 7890...` | `**** **** ****` |
| **Kreditkarte** | `1234 5678 9012...` | `**** **** ****` |

### 8. Session-Isolation

- **Benutzer-Sessions** sind voneinander isoliert
- **Session-Ownership**: Benutzer können nur eigene Sessions sehen/löschen
- **Admin-Override**: Administratoren (`manage_options`) haben Vollzugriff
- **Session-ID**: Kryptographisch sichere UUIDs

---

## 🔐 Datenschutz und Datenhandling

### 1. Gespeicherte Daten

#### 1.1 MySQL-Datenbank (WordPress)

**Tabelle: `wp_levi_conversations`**

| Feld | Typ | Inhalt | Verschlüsselt |
|------|-----|--------|---------------|
| `id` | bigint(20) | Primärschlüssel | - |
| `session_id` | varchar(64) | Session-Identifikator | ❌ |
| `user_id` | bigint(20) | WordPress-Benutzer-ID | - |
| `role` | varchar(20) | `user` / `assistant` / `system` | - |
| `content` | longtext | Nachrichteninhalt | ❌ |
| `context_hash` | varchar(32) | Kontext-Hash | - |
| `created_at` | datetime | Zeitstempel | - |

**Tabelle: `wp_levi_actions`**

| Feld | Typ | Inhalt | Verschlüsselt |
|------|-----|--------|---------------|
| `id` | bigint(20) | Primärschlüssel | - |
| `conversation_id` | bigint(20) | Fremdschlüssel | - |
| `action_type` | varchar(50) | Ausgeführte Aktion | - |
| `object_type` | varchar(50) | Objekt-Typ | - |
| `object_id` | bigint(20) | Objekt-ID | - |
| `parameters` | longtext | Aktion-Parameter (JSON) | ❌ |
| `result` | longtext | Ergebnis | ❌ |
| `status` | varchar(20) | Status | - |
| `executed_at` | datetime | Zeitstempel | - |

#### 1.2 SQLite-Datenbank (lokal)

**Datei:** `wp-content/plugins/levi-agent/data/vector-memory.sqlite`

| Tabelle | Inhalt | Verschlüsselt |
|---------|--------|---------------|
| `memory_vectors` | Vektor-Embeddings (1536 Dimensionen) | ❌ |
| `episodic_memory` | Gelernte Fakten/Benutzerpräferenzen | ❌ |
| `loaded_files` | Datei-Hashes für Change-Detection | - |
| `wp_levi_state_snapshots` | WordPress-Status-Snapshots | ❌ |

### 2. Speicherdauer und Löschung

#### Automatische Bereinigung

| Daten | Dauer | Methode |
|-------|-------|---------|
| **Chat-Verläufe** | 30 Tage (Standard) | `cleanupOldSessions(30)` |
| **State Snapshots** | Max. 60 Snapshots | Automatisch bei Überschreitung |
| **Embeddings** | Persistenz | Manuelle Löschung möglich |

#### Manuelle Löschung

```php
// Einzelne Session löschen
DELETE /wp-json/levi-agent/v1/chat/{session_id}

// Alle Sessions eines Benutzers
ConversationRepository::deleteAllUserSessions($userId)

// Alte Sessions bereinigen
ConversationRepository::cleanupOldSessions($days = 30)
```

### 3. Datenübertragung an KI-Provider

#### 3.1 Was wird übertragen?

**An alle Provider:**
- Benutzer-Nachrichten
- System-Prompt (inkl. Identity, Rules, Knowledge)
- Tool-Definitionen
- Chat-Kontext (begrenzte Historie)

**Zusätzlich bei OpenRouter:**
```http
HTTP-Referer: https://deine-domain.de/
X-Title: Mohami WordPress Agent
```

#### 3.2 Provider-spezifische Übertragung

| Provider | Endpoint | Authentifizierung | Timeout |
|----------|----------|-------------------|---------|
| **OpenRouter** | `openrouter.ai/api/v1/chat/completions` | `Authorization: Bearer {key}` | 120s |
| **OpenAI** | `api.openai.com/v1/chat/completions` | `Authorization: Bearer {key}` | 120s |
| **Anthropic** | `api.anthropic.com/v1/messages` | `x-api-key: {key}` | 120s |

#### 3.3 Embeddings (für Memory-System)

| Provider | Model | Verwendung |
|----------|-------|------------|
| OpenRouter | `openai/text-embedding-3-small` | Semantic Search |
| OpenAI | `text-embedding-3-small` | Semantic Search |
| Anthropic | ❌ Nicht unterstützt | - |

### 4. API-Key-Speicherung

#### Speicherorte (Priorität)

```
1. .env-Datei (EMPFOHLEN)
   ├── dirname(ABSPATH) . '/.env'
   ├── dirname(dirname(ABSPATH)) . '/.env'
   └── ABSPATH . '../.env'

2. WordPress Datenbank (FALLBACK)
   └── wp_options → levi_agent_settings
```

#### Sicherheitsmaßnahmen

| Aspekt | Implementierung |
|--------|----------------|
| **Priorität** | `.env` vor Datenbank |
| **Maskierung** | Keys werden in UI maskiert (`••••••••`) |
| **Zugriff** | Nur `manage_options` kann Keys sehen |
| **Dateiberechtigungen** | `.env` sollte außerhalb Document Root liegen |

**Beispiel .env-Datei:**
```bash
# Außerhalb Document Root (/var/www/ oder höher)
OPEN_ROUTER_API_KEY=sk-or-v1-...
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
```

### 5. DSGVO-Konformität

#### 5.1 Rechtsgrundlagen

| Verarbeitung | DSGVO-Artikel | Begründung |
|--------------|---------------|------------|
| **Chat-Verarbeitung** | Art. 6(1)(b) | Vertragserfüllung (Auf Anfrage) |
| **State Snapshots** | Art. 6(1)(f) | Berechtigtes Interesse (Administration) |
| **KI-Provider** | Art. 6(1)(b) + AVV | Auftragsverarbeitung |

#### 5.2 Auftragsverarbeitung (AVV)

**Erforderlich:** Ja - für alle drei Provider

| Provider | DPA verfügbar | URL |
|----------|---------------|-----|
| **OpenAI** | ✅ Ja | openai.com/business/terms |
| **Anthropic** | ✅ Ja | anthropic.com/legal/commercial-terms |
| **OpenRouter** | ⚠️ Prüfen | Nutzt Sub-Provider |

#### 5.3 Datenübertragung in Drittstaaten (USA)

| Aspekt | Status |
|--------|--------|
| **Transfer-Mechanismus** | Standard Contract Clauses (SCC) |
| **Adequacy Decision** | ❌ Nein (Schrems II) |
| **Zusätzliche Maßnahmen** | DPA + Transparenz in Privacy Policy |

#### 5.4 Betroffenenrechte

| Recht | Umsetzung | Einschränkung |
|-------|-----------|---------------|
| **Auskunft** | ✅ Export aus DB | Nur lokale Daten |
| **Berichtigung** | ✅ Direkt möglich | Nur lokale Daten |
| **Löschung** | ⚠️ Teilweise | Nicht bei KI-Providern möglich |
| **Datenportabilität** | ⚠️ Eingeschränkt | Kein Export-Feature |
| **Widerspruch** | ✅ Plugin deaktivieren | Vollständig möglich |

**Wichtiger Hinweis:**
> Daten bei OpenRouter/OpenAI/Anthropic können **nicht nachträglich gelöscht** werden. Die Provider speichern Anfragen für 30-90 Tage (je nach Anbieter und Vertrag).

### 6. Cookies und LocalStorage

| Speicher | Inhalt | Dauer | Zweck |
|----------|--------|-------|-------|
| **WordPress Auth Cookie** | Session-ID | WordPress-Standard | Authentifizierung |
| **localStorage** | `levi_session_id` | Persistenz | Session-Tracking |
| **localStorage** | `levi_chat_open` | Persistenz | UI-Zustand |

**Keine Third-Party-Cookies**

### 7. Verschlüsselung

#### In-Transit (Übertragung)

| Verbindung | Protokoll | Status |
|------------|-----------|--------|
| **WordPress → KI-Provider** | HTTPS | ✅ TLS 1.2+ |
| **Admin → WordPress** | HTTPS | ✅ Empfohlen |
| **Streaming (SSE)** | HTTPS | ✅ TLS 1.2+ |

#### At-Rest (Speicherung)

| Daten | Verschlüsselung | Empfehlung |
|-------|-----------------|------------|
| **MySQL-Tabellen** | ❌ Nein | WordPress-Standard-DB nutzen |
| **SQLite-DB** | ❌ Nein | Dateiberechtigungen setzen (600) |
| **API-Keys in DB** | ❌ Nein | .env-Datei bevorzugen |
| **Chat-Inhalte** | ❌ Nein | Datenbank absichern |

---

## ⚠️ Risikoanalyse und Empfehlungen

### Identifizierte Risiken

| Risiko | Schwere | Wahrscheinlichkeit | Mitigation |
|--------|---------|-------------------|------------|
| **Unverschlüsselte API-Keys in DB** | 🔶 Mittel | 🔴 Hoch | .env-Datei nutzen |
| **Keine Löschung bei KI-Providern** | 🔴 Hoch | 🟡 Mittel | Privacy Policy aktualisieren |
| **Unverschlüsselte Chat-Historie** | 🔶 Mittel | 🟡 Mittel | DB-Zugriff beschränken |
| **Drittstaatentransfer USA** | 🔶 Mittel | 🔴 Sicher | DPA abschließen |
| **Path Traversal** | 🔴 Hoch | 🟢 Niedrig | Validierung implementiert |
| **Code-Injection** | 🔴 Hoch | 🟢 Niedrig | Sandbox + Blockliste |

### Sicherheitsempfehlungen

#### Sofortmaßnahmen (Vor Produktivbetrieb)

1. **API-Keys in .env-Datei speichern**
   ```bash
   # .env im Verzeichnis über Document Root
   OPEN_ROUTER_API_KEY=sk-or-v1-...
   ```

2. **PII-Redaktion aktivieren**
   ```php
   // Einstellungen > Safety
   'pii_redaction' => 1
   ```

3. **Rate-Limiting konfigurieren**
   ```php
   // Standard: 50/Stunde
   'rate_limit' => 50
   ```

4. **Tool-Profil auf Standard setzen**
   ```php
   // Für alle nicht-Admin-Benutzer
   'tool_profile' => 'standard'
   ```

#### Datenschutz-Empfehlungen

1. **Privacy Policy aktualisieren** mit:
   - Verwendung von KI/LLM-Technologie
   - Datenweitergabe an OpenRouter/OpenAI/Anthropic
   - Speicherdauer (30 Tage lokal, 30-90 Tage bei Providern)
   - Hinweis: Keine vollständige Löschung bei Providern möglich
   - PII-Redaction-Feature (falls aktiviert)

2. **Auftragsverarbeitungsvertrag (AVV)** mit Providern abschließen

3. **Datenschutz-Folgenabschätzung (DSFA)** durchführen für:
   - Verarbeitung personenbezogener Daten durch KI
   - Datenübertragung in die USA
   - Fehlende Löschungsmöglichkeit bei Providern

4. **Einwilligung** bei Verarbeitung besonderer Kategorien (Art. 9 DSGVO)

#### Technische Empfehlungen

1. **HTTPS erzwingen** für WordPress-Admin
2. **Datenbank-Zugriff beschränken** auf localhost
3. **SQLite-Datei schützen** mit Berechtigungen `600`
4. **Backup-Strategie** für SQLite-DB implementieren
5. **Monitoring** für ungewöhnliche API-Nutzung

---

## 🔧 Konfigurations-Beispiele

### Sichere Standard-Konfiguration

```php
// wp-config.php oder Einstellungen
$leviSettings = [
    // Sicherheit
    'tool_profile' => 'standard',           // Nicht 'full' für Standard-Nutzer
    'rate_limit' => 50,                     // 50 Anfragen/Stunde
    'pii_redaction' => 1,                   // PII-Redaktion aktivieren
    'require_confirmation' => 1,            // Bestätigung für destruktive Aktionen
    
    // Datenschutz
    'blocked_post_types' => 'wpforms,flamingo_contact,nf_sub', // Sensitive CPTs
    'history_context_limit' => 50,          // Kontext begrenzen
    'max_context_tokens' => 100000,         // Token-Limit
    
    // Performance
    'ai_timeout' => 120,                    // Timeout
    'max_tool_iterations' => 12,            // Tool-Runden begrenzen
];
```

### Maximale Sicherheitskonfiguration (Enterprise)

```php
$leviSettings = [
    // Nur Lese-Zugriff für die meisten Benutzer
    'tool_profile' => 'minimal',
    
    // Striktes Rate-Limiting
    'rate_limit' => 20,
    
    // Alle Schutzmechanismen aktivieren
    'pii_redaction' => 1,
    'require_confirmation' => 1,
    
    // Keine Code-Ausführung erlauben
    'allow_code_execution' => 0,
    
    // Kurze Speicherdauer
    'conversation_retention_days' => 7,
    
    // Embeddings nur für Identity (nicht für Chats)
    'episodic_memory_enabled' => 0,
];
```

---

## 📊 Zusammenfassung

### Sicherheits-Score

| Bereich | Bewertung | Anmerkung |
|---------|-----------|-----------|
| **Zugriffskontrolle** | ⭐⭐⭐⭐⭐ | Drei-Stufen-System + WP Capabilities |
| **Code-Sicherheit** | ⭐⭐⭐⭐⭐ | Sandboxed + Blockliste |
| **Datei-Sicherheit** | ⭐⭐⭐⭐⭐ | Path Traversal-Schutz + Rollback |
| **API-Sicherheit** | ⭐⭐⭐⭐⭐ | Nonce + Rate-Limiting |
| **Datenschutz** | ⭐⭐⭐⭐☆ | PII-Redaktion, aber keine Verschlüsselung |
| **Transparenz** | ⭐⭐⭐⭐⭐ | Klare Dokumentation |

### Gesamtbewertung

Der **Levi AI Agent** implementiert ein durchdachtes Sicherheitskonzept mit mehreren Verteidigungslinien. Die Architektur folgt dem **Principle of Least Privilege** und bietet granular konfigurierbare Sicherheitsstufen.

**Stärken:**
- ✅ Drei-Stufen Tool-Profil-System
- ✅ Umfassende WordPress-Capability-Prüfungen
- ✅ Sandboxed Code-Ausführung
- ✅ PII-Redaktion
- ✅ Path Traversal-Schutz
- ✅ Rate-Limiting

**Verbesserungspotenzial:**
- ⚠️ Keine Verschlüsselung gespeicherter Daten
- ⚠️ API-Keys werden in Datenbank gespeichert (wenn keine .env)
- ⚠️ Keine vollständige Löschung bei KI-Providern möglich

**Empfehlung:** Für den Produktivbetrieb sollten die Sicherheitsempfehlungen implementiert und eine Datenschutz-Folgenabschätzung durchgeführt werden.

---

**Dokumentation erstellt am:** 01.03.2026  
**Plugin-Version:** 0.1.0  
**Sicherheits-Version:** 1.0  
**Nächste Überprüfung:** Bei Plugin-Updates oder bei Änderungen der KI-Provider-Terms
