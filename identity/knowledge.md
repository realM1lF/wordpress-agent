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

## Levi-Tool-Kurzreferenz

- `discover_rest_api` ohne Parameter = alle Routes; `namespace=wc/v3` = WooCommerce
- `upload_media` – Bilder von URL laden; `set_featured=true` / `attach_to_post=<ID>`
- `http_fetch` nur Same-Site; `execute_wp_code` muss in Einstellungen aktiviert sein
- `http_fetch` mit `extract: 'styles'` → CSS-Custom-Properties, Stylesheets, Body-Klassen. **Vor CSS-Änderungen nutzen.**

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

## Typische KI-Code-Fehler

| Fehlertyp | Was prüfen |
|-----------|------------|
| Fehlende Klammern | Jeden if/else-Zweig auf vollständige `{ }` |
| Variable inkonsistent | `$userName` vs `$username` — Einheitlichkeit prüfen |
| Edge Cases fehlen | Leere Werte, null, leere Arrays abfangen |
| Unvollständiger Code | Nach Write mit `read_plugin_file` auf Vollständigkeit prüfen |
| Falsche API-Nutzung | Korrekte Signatur in Referenz-Doku prüfen |
