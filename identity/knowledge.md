# KNOWLEDGE

## Dokumentationsquellen (memories/)

| Datei | Inhalt | Wann nutzen |
|-------|--------|-------------|
| wordpress-lllm-developer.txt | WordPress Core: Block Editor, Themes, REST API, Hooks, WP-CLI | Immer bei WP-Entwicklung |
| woocommerce-llm-developer.txt | WooCommerce: Produkte, Cart, Hooks, REST API | Bei Shops, Produkten, Warenkorb |
| elementor-llm-developer.txt | Elementor: Addons, Widgets, Controls, Hooks, Forms, Themes, Layouting via _elementor_data | Bei Page-Builder, Elementor-Layouts |

Nutze diese als erste Referenz, bevor du ratest.

## Tool-Profile

- **Minimal**: Nur Lesen/Diagnostik. Bei Schreibwünschen auf Levi-Einstellungen verweisen.
- **Standard**: Lesen + Schreiben (Inhalte, Plugins, Themes, WooCommerce).
- **Voll**: Zusätzlich `execute_wp_code` und `http_fetch`.

## Deferred Tool Loading

Nicht alle Tools werden in jedem API-Call mitgesendet. **Core-Tools** (~18 Stück: Lesen, Plugin-Entwicklung, Content, search_tools) sind immer verfügbar. Spezialisierte Tools (WooCommerce, Elementor, Theme-Editing, Cron, User-Management, Taxonomien, Options, Media-Upload, Code-Ausführung) werden über `search_tools` entdeckt und ab dem nächsten Schritt automatisch geladen. Bei <= 20 registrierten Tools wird alles direkt gesendet (kein Deferred Loading).

## Levi-Tool-Kurzreferenz

- `discover_rest_api` ohne Parameter = alle Routes; `namespace=wc/v3` = WooCommerce
- `upload_media` – Bilder von URL laden; `set_featured=true` / `attach_to_post=<ID>`
- `http_fetch` nur Same-Site; `execute_wp_code` muss in Einstellungen aktiviert sein
- `http_fetch` mit `extract: 'styles'` → CSS-Custom-Properties, Stylesheets, Body-Klassen. **Vor CSS-Änderungen nutzen.**

## create_plugin — Scaffold-Parameter

| Parameter | Werte | Effekt |
|-----------|-------|--------|
| `plugin_type` | `plain` (Default), `woocommerce`, `elementor` | Typ-spezifisches Scaffold mit Dependency-Checks |
| `features` | `admin-settings`, `frontend-css`, `frontend-js`, `rest-api` | Generiert entsprechende Dateien und Hooks automatisch |
| `depends_on` | Array von Plugin-Slugs | Setzt `Requires Plugins` Header (ab WP 6.5) |

- `plugin_type=woocommerce` → WC-Dependency-Check, HPOS-Kompatibilität, WC-Settings-Section
- `plugin_type=elementor` → Elementor-Dependency-Check, Mindestversion-Prüfung
- `features` erzeugt fertige Dateien (`includes/settings.php`, `assets/frontend.css`, etc.) die in der Hauptdatei bereits eingebunden werden

## write_plugin_file — Header-Schutz

`write_plugin_file` bewahrt automatisch den bestehenden Plugin-Header wenn die Hauptdatei (`slug.php`) geschrieben wird. Das verhindert versehentliches Überschreiben von Plugin Name, Version, Description etc. Deaktivierbar mit `preserve_header=false`.

Gleiches gilt für `write_theme_file` bei `style.css` — der Theme-Header wird automatisch geschützt.

## CSS-Variablen: Gängige Patterns

- **Block-Themes**: `var(--wp--preset--color--primary)`, `var(--wp--preset--font-size--medium)`, `var(--wp--preset--spacing--40)`
- **Elementor**: `var(--e-global-color-primary)`, `var(--e-global-typography-primary-font-family)`
- **WooCommerce**: `var(--wc--body-text-color)`
- **Fallback**: WordPress System-Font-Stack, `#1d2327` (Text), `#2271b1` (Links)

## DB-Tabellen in Plugins

Nie nur `register_activation_hook` für Tabellenerstellung nutzen — `create_plugin` aktiviert das Plugin bevor der Code geschrieben ist. Stattdessen `admin_init` + Versionscheck:

```php
add_action('admin_init', function () {
    if (get_option('myplugin_db_version', '0') === '1.0') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$wpdb->prefix}myplugin_items (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        PRIMARY KEY  (id)
    ) {$wpdb->get_charset_collate()};");
    update_option('myplugin_db_version', '1.0');
}, 5);
```

`dbDelta`-Regeln: `require_once` vor Aufruf, zwei Leerzeichen nach `PRIMARY KEY`, kein Trailing-Comma, jede Spalte eigene Zeile.

Immer `uninstall.php` mitliefern: `DROP TABLE IF EXISTS` + `delete_option`.

## Dateien lesen vor dem Patchen

- `read_plugin_file` OHNE `max_bytes` aufrufen (Default 250 KB reicht). Nie kleine Werte wie 500 oder 1000 setzen.
- Such-String für `patch_plugin_file` exakt aus dem Read-Output kopieren, nie aus dem Gedächtnis.
- Bei "No replacements" → Stelle nochmal lesen, exakten Text kopieren, erneut patchen.

## WooCommerce Block Cart vs. Classic Cart — Fallstricke

Block-Themes (Twenty Twenty-Four, etc.) verwenden den **WooCommerce Cart Block** (`<!-- wp:woocommerce/cart -->`), nicht den klassischen `[woocommerce_cart]` Shortcode. Das hat weitreichende Konsequenzen:

### 1. `is_cart()` funktioniert nicht zuverlässig im `wp_enqueue_scripts` Hook

Bei Block-basierten Cart-Seiten kann `is_cart()` beim Enqueue-Zeitpunkt `false` zurückgeben, weil der WooCommerce-Conditional-Tag die Seite noch nicht korrekt identifiziert hat. **Lösung: Zusätzliche Prüfung auf die WooCommerce-Cart-Page-ID und den Block:**

```php
add_action('wp_enqueue_scripts', function () {
    if (!$this->should_load_on_cart()) return;
    // enqueue...
});

private function should_load_on_cart(): bool {
    if (is_cart()) return true;
    // Fallback for Block Cart
    global $post;
    if (!$post) return false;
    $cart_page_id = wc_get_page_id('cart');
    return (int) $post->ID === $cart_page_id;
}
```

### 2. Klassische PHP-Hooks feuern NICHT bei Block Cart

Alle Hooks wie `woocommerce_before_cart`, `woocommerce_after_cart_table`, `woocommerce_cart_contents` etc. funktionieren **nur** mit dem klassischen Shortcode-Cart. Für Block Cart gibt es diese Alternativen:
- **Slot/Fill API**: `ExperimentalOrderMeta`, `ExperimentalDiscountsMeta` (JavaScript-basiert)
- **`render_block_{$name}` Filter**: PHP-basiert, rendered vor/nach einem Block
- **JavaScript DOM-Injection**: Eigenes Script das nach `.wp-block-woocommerce-cart` sucht und Inhalte einfügt
- **Custom Inner Block**: Eigenen WooCommerce-Block erstellen und manuell im Cart-Template platzieren

### 3. WooCommerce Store API liefert KEINE Produktkategorien

Die Store API (`/wc/store/v1/cart/items`) enthält **keine** `categories`-Eigenschaft in den Cart-Items. Wenn Kategorie-Informationen benötigt werden, muss ein eigener REST-Endpoint registriert oder `woocommerce_store_api_register_endpoint_data` genutzt werden.

### 4. `WC()->cart` ist in eigenen REST-Endpoints NICHT verfügbar

WooCommerce initialisiert den Cart (`WC()->cart`) nur im Frontend-Kontext, **nicht** bei REST API Requests. Ein eigener `register_rest_route`-Endpoint, der `WC()->cart->get_cart()` aufruft, bekommt immer `null` zurück.

**Falsch** (funktioniert nicht):
```php
register_rest_route('myplugin/v1', '/check-cart', [
    'callback' => function() {
        // WC()->cart ist NULL im REST-Kontext!
        foreach (WC()->cart->get_cart() as $item) { ... }
    }
]);
```

**Richtige Alternativen:**

1. **Server-seitig beim Seiten-Rendering prüfen** und Ergebnis direkt per `wp_localize_script` an JS übergeben:
```php
add_action('wp_enqueue_scripts', function() {
    $has_trigger = false;
    if (WC()->cart) {
        foreach (WC()->cart->get_cart() as $item) {
            if (has_term('kuche', 'product_cat', $item['product_id'])) {
                $has_trigger = true;
                break;
            }
        }
    }
    wp_localize_script('my-script', 'myData', [
        'hasTriggerProduct' => $has_trigger,
    ]);
});
```

2. **WooCommerce Store API Extension** nutzen (offizielle Methode für Block Cart):
```php
woocommerce_store_api_register_endpoint_data([
    'endpoint' => CartItemSchema::IDENTIFIER,
    'namespace' => 'my-plugin',
    'data_callback' => function($cart_item) {
        $cats = wp_get_post_terms($cart_item['product_id'], 'product_cat', ['fields' => 'slugs']);
        return ['categories' => $cats];
    },
    'schema_callback' => function() { ... },
]);
```

### 5. WooCommerce Settings API

Für Plugin-Einstellungen unter WooCommerce → Einstellungen **niemals** die WordPress-Standard-API (`add_settings_section`/`add_settings_field`) nutzen. Stattdessen die WooCommerce-eigene API verwenden:
- **Section-Ansatz**: `woocommerce_get_sections_{tab}` + `woocommerce_get_settings_{tab}` Filter (für standardisierte Felder)
- **Tab-Ansatz**: `woocommerce_settings_tabs_{tab}` + `woocommerce_settings_{tab}` + `woocommerce_update_options_{tab}` (für eigene HTML-Formulare)
- **Eigene Klasse**: `WC_Settings_Page` erweitern (sauberster Ansatz für komplexe Settings)

## Typische KI-Code-Fehler

| Fehlertyp | Was prüfen |
|-----------|------------|
| Fehlende Klammern | Jeden if/else-Zweig auf vollständige `{ }` |
| Variable inkonsistent | `$userName` vs `$username` — Einheitlichkeit prüfen |
| Edge Cases fehlen | Leere Werte, null, leere Arrays abfangen |
| Unvollständiger Code | Nach Write mit `read_plugin_file` auf Vollständigkeit prüfen |
| Falsche API-Nutzung | Korrekte Signatur in Referenz-Doku prüfen |
