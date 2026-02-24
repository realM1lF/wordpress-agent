# Recherche: Sicherer WordPress-Zugriff für KI-Agenten

## Übersicht

Diese Dokumentation beschreibt, wie ein KI-Agent sicher auf WordPress zugreifen kann, welche APIs verfügbar sind und welche Sicherheitsmaßnahmen implementiert werden müssen.

---

## 1. WP REST API für Content (Posts, Pages, Custom Post Types)

### Grundlegende Endpunkte

| Ressource | Endpoint | Methoden |
|-----------|----------|----------|
| Posts | `/wp/v2/posts` | GET, POST, PUT, DELETE |
| Pages | `/wp/v2/pages` | GET, POST, PUT, DELETE |
| Custom Post Types | `/wp/v2/{post_type}` | GET, POST, PUT, DELETE |

### Code-Beispiele

**Post erstellen:**
```bash
curl -X POST https://example.com/wp-json/wp/v2/posts \
  -u "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Neuer Post",
    "content": "Inhalt des Posts",
    "status": "draft",
    "categories": [1, 2]
  }'
```

**Python-Beispiel:**
```python
import requests
from requests.auth import HTTPBasicAuth

auth = HTTPBasicAuth('username', 'application_password')
base_url = 'https://example.com/wp-json/wp/v2'

# Post erstellen
new_post = requests.post(
    f'{base_url}/posts',
    auth=auth,
    json={
        'title': 'KI-generierter Post',
        'content': '<p>Content hier...</p>',
        'status': 'draft',
        'meta': {
            'ki_agent_generated': True,
            'ki_agent_id': 'agent-001'
        }
    }
)
```

**Custom Post Type registrieren (Plugin-Code):**
```php
add_action('init', 'register_ki_content_cpt');
function register_ki_content_cpt() {
    register_post_type('ki_content', array(
        'labels' => array(
            'name' => 'KI Content',
            'singular_name' => 'KI Content'
        ),
        'public' => true,
        'show_in_rest' => true,  // WICHTIG: REST API aktivieren
        'rest_base' => 'ki-content',
        'supports' => array('title', 'editor', 'custom-fields'),
        'capabilities' => array(
            'create_posts' => 'create_ki_content',
            'edit_posts' => 'edit_ki_content',
            'delete_posts' => 'delete_ki_content',
        ),
        'map_meta_cap' => true,
    ));
}
```

---

## 2. WP REST API für Einstellungen (Options, Theme-Mods)

### Standard-Endpoints

WordPress bietet keine direkten REST-Endpunkte für Settings. Lösungen:

**Option 1: Custom Endpoint für Options**
```php
add_action('rest_api_init', 'register_ki_options_endpoint');
function register_ki_options_endpoint() {
    register_rest_route('ki-agent/v1', '/options/(?P<option_name>[a-zA-Z0-9_-]+)', array(
        array(
            'methods' => 'GET',
            'callback' => 'get_ki_option',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ),
        array(
            'methods' => 'POST',
            'callback' => 'update_ki_option',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => array(
                'value' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return !empty($param);
                    }
                )
            )
        )
    ));
}

function get_ki_option($request) {
    $option_name = sanitize_key($request['option_name']);
    $value = get_option($option_name);
    return rest_ensure_response(array(
        'option_name' => $option_name,
        'value' => $value
    ));
}

function update_ki_option($request) {
    $option_name = sanitize_key($request['option_name']);
    $value = sanitize_text_field($request['value']);
    
    // Audit-Log vor Änderung
    $old_value = get_option($option_name);
    
    update_option($option_name, $value);
    
    // Änderung loggen
    do_action('ki_agent_option_changed', $option_name, $old_value, $value);
    
    return rest_ensure_response(array(
        'success' => true,
        'option_name' => $option_name
    ));
}
```

**Option 2: WordPress Settings API Wrapper**
```php
// Sichere Wrapper-Klasse für Settings
class KI_Agent_Settings_API {
    
    private $allowed_options = array(
        'blogname',
        'blogdescription',
        'posts_per_page',
        'default_category',
        // Weitere erlaubte Optionen
    );
    
    public function get_option($name) {
        if (!in_array($name, $this->allowed_options)) {
            return new WP_Error('not_allowed', 'Option nicht erlaubt', array('status' => 403));
        }
        return get_option($name);
    }
    
    public function update_option($name, $value) {
        if (!in_array($name, $this->allowed_options)) {
            return new WP_Error('not_allowed', 'Option nicht erlaubt', array('status' => 403));
        }
        
        // Vor Änderung Snapshot erstellen
        $this->create_snapshot('option', $name, get_option($name));
        
        return update_option($name, $value);
    }
}
```

---

## 3. WP REST API für Medien (Upload, Library)

### Media Endpoint

```bash
# Bild uploaden
curl -X POST https://example.com/wp-json/wp/v2/media \
  -u "username:application_password" \
  -H "Content-Disposition: attachment; filename=image.jpg" \
  -H "Content-Type: image/jpeg" \
  --data-binary @image.jpg
```

**Python mit Metadaten:**
```python
import requests

def upload_media(file_path, auth, title='', alt_text=''):
    """
    Lädt eine Mediendatei zu WordPress hoch
    """
    filename = os.path.basename(file_path)
    mime_type = mime_types.guess_type(file_path)[0]
    
    headers = {
        'Content-Disposition': f'attachment; filename={filename}',
        'Content-Type': mime_type
    }
    
    with open(file_path, 'rb') as f:
        response = requests.post(
            'https://example.com/wp-json/wp/v2/media',
            auth=auth,
            headers=headers,
            data=f.read()
        )
    
    if response.status_code == 201:
        media_id = response.json()['id']
        
        # Metadaten aktualisieren
        requests.post(
            f'https://example.com/wp-json/wp/v2/media/{media_id}',
            auth=auth,
            json={
                'title': title,
                'alt_text': alt_text,
                'meta': {
                    'ki_agent_uploaded': True,
                    'ki_agent_timestamp': datetime.now().isoformat()
                }
            }
        )
        return media_id
    
    return None
```

**Eingeschränkter Media-Upload für KI-Agent:**
```php
add_filter('wp_handle_upload_prefilter', 'ki_agent_restrict_upload');
function ki_agent_restrict_upload($file) {
    // Prüfen ob Request von KI-Agent kommt
    if (ki_agent_is_authenticated_request()) {
        // Dateigröße limitieren
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_size) {
            $file['error'] = 'Datei zu groß (max 5MB für KI-Agent)';
            return $file;
        }
        
        // Erlaubte MIME-Typen
        $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'application/pdf');
        if (!in_array($file['type'], $allowed_types)) {
            $file['error'] = 'Dateityp nicht erlaubt';
            return $file;
        }
    }
    
    return $file;
}
```

---

## 4. WP REST API für Users und Permissions

### User-Rollen für KI-Agent

**Eigene Rolle erstellen:**
```php
add_action('init', 'create_ki_agent_role');
function create_ki_agent_role() {
    add_role('ki_agent', 'KI-Agent', array(
        'read' => true,
        'edit_posts' => true,
        'edit_published_posts' => true,
        'publish_posts' => true,
        'delete_posts' => true,
        'upload_files' => true,
        'manage_categories' => true,
        'edit_pages' => true,
        'publish_pages' => true,
        // KEINE Admin-Rechte:
        // 'manage_options' => false,
        // 'activate_plugins' => false,
        // 'edit_users' => false,
    ));
}
```

**Benutzer über REST erstellen:**
```bash
curl -X POST https://example.com/wp-json/wp/v2/users \
  -u "admin:application_password" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "ki-agent-001",
    "email": "ki-agent@example.com",
    "password": "secure_random_password",
    "roles": ["ki_agent"]
  }'
```

**Eigene Permission Callbacks:**
```php
// Strikte Berechtigungsprüfung für KI-Agent
function ki_agent_permission_check($request) {
    if (!is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'Authentifizierung erforderlich', array('status' => 401));
    }
    
    // Nur KI-Agent Rolle oder Admin
    if (!current_user_can('ki_agent_access') && !current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'Keine Berechtigung für KI-Agent Actions', array('status' => 403));
    }
    
    // Rate-Limiting prüfen
    if (ki_agent_rate_limit_exceeded(get_current_user_id())) {
        return new WP_Error('rate_limited', 'Zu viele Requests', array('status' => 429));
    }
    
    return true;
}
```

---

## 5. Datenbank-Zugriff (WPDB vs. direktes SQL)

### Best Practices

| Methode | Verwendung | Sicherheit |
|---------|------------|------------|
| `WP_Query` | Posts, Pages, CPTs | ⭐⭐⭐⭐⭐ |
| `$wpdb->get_results()` | Komplexe Queries | ⭐⭐⭐⭐ (mit prepare) |
| `$wpdb->query()` | Raw SQL | ⭐⭐ (vermeiden) |
| `get_posts()` | Einfache Abfragen | ⭐⭐⭐⭐⭐ |

### Sicherer Datenbankzugriff

```php
// ✅ KORREKT: Mit prepare()
global $wpdb;
$user_id = get_current_user_id();
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} WHERE post_author = %d AND post_status = %s",
        $user_id,
        'publish'
    )
);

// ✅ KORREKT: WP_Query verwenden
$query = new WP_Query(array(
    'author' => $user_id,
    'post_status' => 'publish',
    'post_type' => 'post'
));

// ❌ FALSCH: Direkte SQL ohne Escaping
$results = $wpdb->get_results("SELECT * FROM wp_posts WHERE post_author = $user_id");
```

**KI-Agent DB-Wrapper:**
```php
class KI_Agent_DB {
    private $allowed_tables = array();
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->allowed_tables = array(
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->terms,
            $wpdb->term_taxonomy,
        );
    }
    
    public function query($sql, $params = array()) {
        // Nur SELECT erlauben für KI-Agent
        if (!preg_match('/^\s*SELECT/i', $sql)) {
            return new WP_Error('not_allowed', 'Nur SELECT-Queries erlaubt');
        }
        
        // Table Whitelist prüfen
        foreach ($this->allowed_tables as $table) {
            if (strpos($sql, $table) === false) {
                return new WP_Error('table_not_allowed', 'Tabelle nicht erlaubt');
            }
        }
        
        // Prepared Statement
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        
        return $this->wpdb->get_results($sql, ARRAY_A);
    }
}
```

---

## 6. File-System-Zugriff (Plugin/Theme-Editor)

### WordPress Filesystem API

```php
// Filesystem initialisieren
function ki_agent_write_file($file_path, $content) {
    global $wp_filesystem;
    
    if (empty($wp_filesystem)) {
        require_once(ABSPATH . '/wp-admin/includes/file.php');
        WP_Filesystem();
    }
    
    // Prüfen ob Datei im erlaubten Verzeichnis
    $allowed_dirs = array(
        WP_CONTENT_DIR . '/uploads/ki-agent/',
        get_template_directory() . '/ki-generated/',
    );
    
    $in_allowed_dir = false;
    foreach ($allowed_dirs as $dir) {
        if (strpos($file_path, $dir) === 0) {
            $in_allowed_dir = true;
            break;
        }
    }
    
    if (!$in_allowed_dir) {
        return new WP_Error('path_not_allowed', 'Pfad nicht erlaubt');
    }
    
    // Verzeichnis erstellen falls nicht existiert
    $dir = dirname($file_path);
    if (!$wp_filesystem->is_dir($dir)) {
        $wp_filesystem->mkdir($dir, FS_CHMOD_DIR);
    }
    
    // Datei schreiben
    return $wp_filesystem->put_contents($file_path, $content, FS_CHMOD_FILE);
}
```

**Alternative: Nur Uploads-Verzeichnis**
```php
function ki_agent_safe_file_write($filename, $content) {
    $upload_dir = wp_upload_dir();
    $ki_dir = $upload_dir['basedir'] . '/ki-agent/';
    
    // Sanitizen des Dateinamens
    $filename = sanitize_file_name($filename);
    $filepath = $ki_dir . $filename;
    
    // Keine PHP-Dateien erlauben
    if (pathinfo($filename, PATHINFO_EXTENSION) === 'php') {
        return false;
    }
    
    // WordPress Filesystem API verwenden
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
    global $wp_filesystem;
    
    return $wp_filesystem->put_contents($filepath, $content);
}
```

---

## 7. Custom Endpoints für KI-Actions

### Strukturierter Endpoint

```php
class KI_Agent_API_Controller extends WP_REST_Controller {
    
    protected $namespace = 'ki-agent/v1';
    protected $rest_base = 'actions';
    
    public function register_routes() {
        // Bulk-Content-Generierung
        register_rest_route($this->namespace, '/' . $this->rest_base . '/generate-content', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'generate_content'),
                'permission_callback' => array($this, 'check_ki_agent_permission'),
                'args' => $this->get_generate_content_args()
            )
        ));
        
        // Content-Review vor Publishing
        register_rest_route($this->namespace, '/' . $this->rest_base . '/review', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_pending_reviews'),
                'permission_callback' => array($this, 'check_ki_agent_permission'),
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_review_status'),
                'permission_callback' => array($this, 'check_admin_permission'),
            )
        ));
        
        // Rollback-Endpunkt
        register_rest_route($this->namespace, '/' . $this->rest_base . '/rollback/(?P<action_id>[a-zA-Z0-9_-]+)', array(
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'rollback_action'),
                'permission_callback' => array($this, 'check_admin_permission'),
            )
        ));
    }
    
    public function generate_content($request) {
        $params = $request->get_json_params();
        
        // Content generieren
        $content = $this->generate_ai_content($params);
        
        // Als Pending speichern
        $post_id = wp_insert_post(array(
            'post_title' => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($content),
            'post_status' => 'pending',  // Nicht direkt publish!
            'post_type' => $params['post_type'] ?? 'post',
            'meta_input' => array(
                '_ki_agent_generated' => true,
                '_ki_agent_timestamp' => current_time('mysql'),
                '_ki_agent_params' => $params,
                '_ki_agent_review_status' => 'pending'
            )
        ));
        
        // Action loggen für Rollback
        $this->log_action('content_generated', $post_id, $params);
        
        return rest_ensure_response(array(
            'post_id' => $post_id,
            'status' => 'pending_review',
            'preview_url' => get_preview_post_link($post_id)
        ));
    }
    
    public function check_ki_agent_permission() {
        return current_user_can('ki_agent_access') || current_user_can('manage_options');
    }
    
    public function check_admin_permission() {
        return current_user_can('publish_posts') || current_user_can('manage_options');
    }
}

// Registrieren
add_action('rest_api_init', function() {
    $controller = new KI_Agent_API_Controller();
    $controller->register_routes();
});
```

---

## 8. Berechtigungs-System (User-Rollen)

### Empfohlene Rollen-Hierarchie

| Rolle | Berechtigungen | Verwendung |
|-------|---------------|------------|
| `ki_agent_restricted` | Nur Lesen, eigene Posts bearbeiten | Minimaler Agent |
| `ki_agent` | Posts/Pages erstellen, Media upload, eigene Inhalte bearbeiten | Standard-Agent |
| `ki_agent_advanced` | Alles von `ki_agent` + Settings lesen, Kategorien verwalten | Erweiterter Agent |
| `administrator` | Vollzugriff | Admin-Review |

### Capability-Mapping

```php
add_action('init', 'add_ki_capabilities');
function add_ki_capabilities() {
    $roles = array('administrator', 'ki_agent', 'ki_agent_advanced');
    
    foreach ($roles as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            $role->add_cap('ki_agent_access');
            $role->add_cap('ki_agent_generate_content');
            $role->add_cap('ki_agent_upload_media');
            
            if ($role_name === 'ki_agent_advanced' || $role_name === 'administrator') {
                $role->add_cap('ki_agent_read_settings');
                $role->add_cap('ki_agent_approve_content');
            }
            
            if ($role_name === 'administrator') {
                $role->add_cap('ki_agent_manage_agents');
                $role->add_cap('ki_agent_rollback');
                $role->add_cap('ki_agent_view_logs');
            }
        }
    }
}
```

---

## 9. Audit-Logging

### Custom Audit-Log Implementation

```php
class KI_Agent_Audit_Log {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ki_agent_audit_log';
    }
    
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action varchar(50) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) unsigned,
            user_id bigint(20) unsigned NOT NULL,
            old_value longtext,
            new_value longtext,
            ip_address varchar(45) NOT NULL,
            user_agent text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            rollback_data longtext,
            PRIMARY KEY (id),
            KEY action (action),
            KEY object_id (object_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function log($action, $object_type, $object_id, $old_value = null, $new_value = null, $rollback_data = null) {
        global $wpdb;
        
        return $wpdb->insert($this->table_name, array(
            'action' => sanitize_text_field($action),
            'object_type' => sanitize_text_field($object_type),
            'object_id' => absint($object_id),
            'user_id' => get_current_user_id(),
            'old_value' => $old_value ? wp_json_encode($old_value) : null,
            'new_value' => $new_value ? wp_json_encode($new_value) : null,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'rollback_data' => $rollback_data ? wp_json_encode($rollback_data) : null,
        ));
    }
    
    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        return sanitize_text_field($ip);
    }
}

// Hooks für automatisches Logging
add_action('wp_insert_post', 'log_ki_agent_post_creation', 10, 3);
function log_ki_agent_post_creation($post_id, $post, $update) {
    if (defined('KI_AGENT_REQUEST') && KI_AGENT_REQUEST) {
        $logger = new KI_Agent_Audit_Log();
        $logger->log(
            $update ? 'post_updated' : 'post_created',
            'post',
            $post_id,
            null,
            array('title' => $post->post_title, 'status' => $post->post_status)
        );
    }
}
```

### Bestehende Audit-Log Plugins

| Plugin | Features | Preis |
|--------|----------|-------|
| **WP Activity Log** | Umfassend, DSGVO-konform, Exports | Freemium |
| **Activity Log** | Leichtgewichtig, einfach | Kostenlos |
| **Simple History** | Minimalistisch | Kostenlos |

---

## 10. Rollback-Funktion

### Implementierung

```php
class KI_Agent_Rollback {
    
    private $logger;
    
    public function __construct() {
        $this->logger = new KI_Agent_Audit_Log();
    }
    
    /**
     * Rollback einer einzelnen Action
     */
    public function rollback_action($action_id) {
        global $wpdb;
        
        $action = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ki_agent_audit_log WHERE id = %d",
            $action_id
        ));
        
        if (!$action) {
            return new WP_Error('action_not_found', 'Action nicht gefunden');
        }
        
        $rollback_data = json_decode($action->rollback_data, true);
        
        switch ($action->action) {
            case 'post_created':
                return $this->rollback_post_creation($action->object_id);
                
            case 'post_updated':
                $old_value = json_decode($action->old_value, true);
                return $this->rollback_post_update($action->object_id, $old_value);
                
            case 'option_updated':
                $old_value = json_decode($action->old_value, true);
                return $this->rollback_option_update($rollback_data['option_name'], $old_value);
                
            case 'media_uploaded':
                return $this->rollback_media_upload($action->object_id);
                
            default:
                return new WP_Error('rollback_not_supported', 'Rollback nicht unterstützt');
        }
    }
    
    private function rollback_post_creation($post_id) {
        // Post auf Trash setzen (nicht direkt löschen!)
        $result = wp_trash_post($post_id);
        
        if ($result) {
            $this->logger->log('rollback_executed', 'post', $post_id, null, array('action' => 'trashed'));
        }
        
        return $result;
    }
    
    private function rollback_post_update($post_id, $old_data) {
        // Post auf vorherigen Zustand zurücksetzen
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $old_data['title'],
            'post_content' => $old_data['content'],
            'post_status' => $old_data['status']
        ), true);
        
        // Auch Meta-Daten zurücksetzen
        if (!is_wp_error($result) && !empty($old_data['meta'])) {
            foreach ($old_data['meta'] as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
        }
        
        return $result;
    }
    
    /**
     * Batch-Rollback mehrerer Actions
     */
    public function rollback_batch($action_ids) {
        $results = array();
        
        foreach ($action_ids as $id) {
            $results[$id] = $this->rollback_action($id);
        }
        
        return $results;
    }
}

// REST Endpoint für Rollback
add_action('rest_api_init', function() {
    register_rest_route('ki-agent/v1', '/rollback/(?P<action_id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => function($request) {
            $rollback = new KI_Agent_Rollback();
            $result = $rollback->rollback_action($request['action_id']);
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            return rest_ensure_response(array('success' => true));
        },
        'permission_callback' => function() {
            return current_user_can('ki_agent_rollback');
        }
    ));
});
```

---

## Sicherheitsaspekte

### 1. Sandbox-Konzept

```php
class KI_Agent_Sandbox {
    
    public function init() {
        // Sandbox-Modus prüfen
        if (defined('KI_AGENT_SANDBOX') && KI_AGENT_SANDBOX) {
            add_filter('wp_insert_post_data', array($this, 'sandbox_post_data'), 10, 2);
            add_action('save_post', array($this, 'sandbox_post_saved'), 10, 3);
        }
    }
    
    /**
     * Im Sandbox-Modus: Posts als Pending markieren
     */
    public function sandbox_post_data($data, $postarr) {
        if (defined('KI_AGENT_REQUEST') && KI_AGENT_REQUEST) {
            // Immer als Pending speichern
            $data['post_status'] = 'pending';
            
            // Sandbox-Flag setzen
            $data['post_content'] .= "\n\n<!-- KI-Agent Sandbox: Nicht veröffentlicht -->";
        }
        return $data;
    }
}
```

### 2. Bestätigungen für kritische Actions

```php
// Kritische Actions erfordern zusätzlichen Header
function ki_agent_require_confirmation($request) {
    $critical_actions = array('delete_post', 'update_option', 'rollback');
    $action = $request->get_route();
    
    foreach ($critical_actions as $critical) {
        if (strpos($action, $critical) !== false) {
            $confirmation = $request->get_header('X-KI-Agent-Confirmation');
            
            if ($confirmation !== 'confirmed') {
                return new WP_Error(
                    'confirmation_required',
                    'Kritische Action erfordert Bestätigung',
                    array('status' => 403, 'requires_confirmation' => true)
                );
            }
            
            // Action zusätzlich loggen
            do_action('ki_agent_critical_action', $action, $request);
        }
    }
    
    return true;
}
```

### 3. Rate-Limiting

```php
class KI_Agent_Rate_Limiter {
    
    private $max_requests = 100;  // pro Minute
    private $time_window = 60;
    
    public function check_limit($user_id) {
        $key = 'ki_agent_rate_' . $user_id;
        $requests = get_transient($key);
        
        if ($requests === false) {
            set_transient($key, 1, $this->time_window);
            return true;
        }
        
        if ($requests >= $this->max_requests) {
            return new WP_Error('rate_limited', 'Rate-Limit überschritten');
        }
        
        set_transient($key, $requests + 1, $this->time_window);
        return true;
    }
}
```

### 4. Content-Validierung

```php
function ki_agent_validate_content($content, $post_type = 'post') {
    $errors = array();
    
    // Mindestlänge prüfen
    if (strlen(strip_tags($content)) < 50) {
        $errors[] = 'Content zu kurz (mindestens 50 Zeichen)';
    }
    
    // Verbotene Wörter prüfen
    $forbidden = array('spam', 'betrug', 'scam');
    foreach ($forbidden as $word) {
        if (stripos($content, $word) !== false) {
            $errors[] = 'Verbotenes Wort gefunden: ' . $word;
        }
    }
    
    // Links zählen (max. 10)
    $link_count = substr_count($content, '<a ');
    if ($link_count > 10) {
        $errors[] = 'Zu viele Links (max. 10)';
    }
    
    // Für KI-generierten Content: Disclaimer hinzufügen
    if (empty($errors)) {
        $content .= "\n\n<p><small>Dieser Content wurde mit KI-Unterstützung erstellt.</small></p>";
    }
    
    return array(
        'valid' => empty($errors),
        'errors' => $errors,
        'content' => $content
    );
}
```

---

## Authentifizierungs-Methoden im Vergleich

| Methode | Sicherheit | Komplexität | Ablauf | Best for |
|---------|-----------|-------------|--------|----------|
| **Application Passwords** | ⭐⭐⭐⭐ | Niedrig | Nie | Server-zu-Server |
| **JWT** | ⭐⭐⭐⭐⭐ | Mittel | Konfigurierbar | SPAs, Mobile Apps |
| **OAuth 2.0** | ⭐⭐⭐⭐⭐ | Hoch | Kurz (mit Refresh) | Third-party Apps |
| **API Keys** | ⭐⭐⭐ | Niedrig | Nie | Einfache Integrationen |
| **Basic Auth** | ⭐⭐ | Niedrig | Nie | Nur für Dev! |

### Empfohlene Konfiguration für KI-Agent

```php
// wp-config.php

// Application Passwords erzwingen
define('WP_APPLICATION_PASSWORDS_ENABLED', true);

// JWT Secret (falls JWT-Plugin verwendet)
define('JWT_AUTH_SECRET_KEY', 'your-256-bit-secret-here');
define('JWT_AUTH_CORS_ENABLE', true);

// Sandbox-Modus für KI-Agent
define('KI_AGENT_SANDBOX', true);

// Rate-Limiting
define('KI_AGENT_RATE_LIMIT', 100);  // Requests pro Minute
define('KI_AGENT_RATE_WINDOW', 60);

// Audit-Logging
define('KI_AGENT_AUDIT_LOG', true);
define('KI_AGENT_LOG_RETENTION_DAYS', 90);
```

---

## Zusammenfassung: Best Practices

1. **Immer Application Passwords oder JWT verwenden** - Nie Basic Auth in Produktion
2. **Eigene KI-Agent Rolle erstellen** - Mit minimalen nötigen Berechtigungen
3. **Sandbox-Modus für neue Agenten** - Immer Pending-Status vor Publishing
4. **Umfassendes Audit-Logging** - Jede Action loggen mit Rollback-Daten
5. **Rate-Limiting implementieren** - Schutz vor Überlastung
6. **Content-Validierung** - Automatische Prüfung vor Speichern
7. **Bestätigungen für kritische Actions** - Extra Header für Deletes/Updates
8. **Regelmäßige Backups** - Unabhängig vom Audit-Log
9. **WordPress Filesystem API verwenden** - Nie direkte PHP File-Operationen
10. **Prepared Statements für DB** - SQL-Injection verhindern

---

## Ressourcen

- [WordPress REST API Handbook](https://developer.wordpress.org/rest-api/)
- [Application Passwords Guide](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
- [WP REST API Authentication Plugin](https://wordpress.org/plugins/wp-rest-api-authentication/)
- [WP Activity Log Plugin](https://wordpress.org/plugins/wp-security-audit-log/)
- [WordPress Filesystem API](https://developer.wordpress.org/apis/filesystem/)
