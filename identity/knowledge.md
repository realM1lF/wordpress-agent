# KNOWLEDGE

## Basis-Wissen
Dein Basis-Wissen rund um Wordpress ergibt sich stets aus den Dateien, die in memories/ abgelegt sind. Diese werden in dein Langzeitgedächtnis übertragen.

## Dokumentationsquellen (memories/)

| Datei | Inhalt | Wann nutzen |
|-------|--------|-------------|
| wordpress-lllm-developer.txt | WordPress Core: Block Editor, Themes, REST API, Hooks, WP-CLI | Immer bei WP-Entwicklung |
| woocommerce-llm-developer.txt | WooCommerce: Produkte, Cart, Hooks, REST API | Bei Shops, Produkten, Warenkorb |
| elementor-llm-developer.txt | Elementor: Addons, Widgets, Controls, Hooks, Forms, Themes, Layouting via _elementor_data | Bei Page-Builder, Elementor-Layouts, Elementor-Addons |

Diese Dateien werden taeglich aktualisiert. Nutze sie als erste Referenz, bevor du ratest.

## WooCommerce-Architektur

### Produkttypen
- **Simple Product** (`product`): Einfaches Produkt mit einem Preis
- **Variable Product** (`product`): Hat untergeordnete Variationen (z.B. Größe, Farbe)
- **Product Variation** (`product_variation`): Child-Post eines variablen Produkts, eigener Preis/Lager/SKU
- **Grouped Product**: Sammlung von einfachen Produkten
- **External/Affiliate**: Link auf externes Produkt

### Preisspeicherung
- `_regular_price`: Normalpreis
- `_sale_price`: Angebotspreis (wenn gesetzt, aktiver Preis)
- `_price`: Effektiver Preis (wird automatisch von WC berechnet: sale > regular)
- Bei Variationen: Jede Variation hat eigene `_regular_price`, `_sale_price`, `_price`

### Variable Produkte & Variationen
- Ein variables Produkt hat `product_type = variable` in der Taxonomie `product_type`
- Variationen sind eigene Posts mit `post_type = product_variation` und `post_parent = <parent_product_id>`
- Attribute werden als Taxonomie-Terms (`pa_farbe`, `pa_groesse`) oder als Custom-Attribute gespeichert
- Jede Variation hat Meta-Keys wie `attribute_pa_farbe = schwarz`
- `wc_get_product($id)` gibt ein `WC_Product_Variable` Objekt zurück, `$product->get_available_variations()` listet alle Variationen

### Warenkorb (Cart)
- Einfache Produkte: `?add-to-cart=<product_id>` oder AJAX POST an `/?wc-ajax=add_to_cart` mit `product_id`
- Variable Produkte: Man MUSS zusätzlich `variation_id=<variation_id>` und die Attribute (z.B. `attribute_pa_farbe=schwarz`) mitschicken
- Ohne `variation_id` bei variablen Produkten → WooCommerce-Fehler: "Bitte wähle Produktoptionen aus"
- Cart-Item-Data kann über `woocommerce_add_cart_item_data` Filter erweitert werden (z.B. Bundle-Preise)

### Wichtige WooCommerce-Hooks
- `woocommerce_before_cart`: Vor dem gesamten Warenkorb
- `woocommerce_cart_contents`: Innerhalb der Cart-Tabelle
- `woocommerce_after_cart_table`: Nach der Cart-Tabelle
- `woocommerce_before_cart_totals`: Vor der Summen-Box
- `woocommerce_cart_calculate_fees`: Gebühren/Rabatte hinzufügen
- `woocommerce_add_to_cart`: Nach Hinzufügen zum Warenkorb
- `woocommerce_cart_item_price`: Preis pro Artikel im Warenkorb filtern

### Versand
- Versandzonen: `WC_Shipping_Zones::get_zones()` listet alle Zonen
- Versandmethoden pro Zone: Flat Rate, Free Shipping, Local Pickup
- Free Shipping hat oft Bedingung: Mindestbestellwert (`min_amount`)
- `WC()->shipping()->get_packages()` gibt aktive Versandpakete zurück

### WooCommerce PHP-API
- `wc_get_product($id)`: Produkt-Objekt laden
- `wc_get_products($args)`: Mehrere Produkte abfragen
- `$product->get_type()`: simple, variable, variation, grouped, external
- `$product->get_price()`, `$product->get_regular_price()`, `$product->get_sale_price()`
- `$product->is_in_stock()`, `$product->get_stock_quantity()`
- `$product->get_available_variations()`: Alle Variationen (nur bei variable)
- `$product->get_attributes()`: Produkt-Attribute
- `wc_get_product_terms($id, 'product_cat')`: Produkt-Kategorien

### WooCommerce-Tool-Referenz (manage_woocommerce)

Das Tool `manage_woocommerce` deckt alle schreibenden WooCommerce-Operationen ab:
- Produkte: create_product, update_product, delete_product
- Attribute: set_product_attributes (erstellt automatisch globale Taxonomien pa_*)
- Variationen: create_variations (alle Kombinationen oder individuell), update_variation, delete_variation
- Bestellungen: update_order_status
- Steuern: configure_tax
- Coupons: create_coupon, update_coupon, delete_coupon

Fuer variable Produkte ist die korrekte Reihenfolge: create_product → set_product_attributes → create_variations

## Tool-Profile

Dir stehen je nach Nutzer-Einstellung unterschiedliche Tools zur Verfügung:
- **Minimal**: Nur Lesen/Diagnostik – keine Änderungen möglich. Wenn der Nutzer etwas schreiben will, weise ihn auf die Levi-Einstellungen hin (Profil wechseln).
- **Standard**: Lesen + Schreiben (Inhalte, Plugins, Themes, WooCommerce).
- **Voll**: Zusätzlich `execute_wp_code` und `http_fetch` – nur wenn der Admin das aktiviert hat.

## Levi-Tool-Referenz

- **REST-API erkunden**: `discover_rest_api` ohne Parameter = alle Routes; `namespace=wc/v3` = WooCommerce; `search=product` = Suche.
- **Medien**: `upload_media` – Bilder von URL laden; `set_featured=true` / `attach_to_post=<ID>` für Zuordnung.
- **Limitierungen**: `http_fetch` nur Same-Site; `execute_wp_code` muss in Einstellungen aktiviert sein; WooCommerce-Tools melden Fehler wenn WC inaktiv.
- **Design-Kontext lesen**: `http_fetch` mit `extract: 'styles'` liefert CSS-Custom-Properties, Stylesheets und Body-Klassen einer Seite. Nutze das **vor** dem Schreiben von CSS, um dich ans bestehende Design anzupassen.

## CSS-Custom-Properties — gängige Patterns

Wenn du `http_fetch` mit `extract: 'styles'` nutzt, findest du typischerweise:

| Quelle | Variable-Pattern | Beispiel |
|--------|-----------------|----------|
| **WordPress (Block-Themes)** | `--wp--preset--color--*` | `var(--wp--preset--color--primary)` |
| | `--wp--preset--font-size--*` | `var(--wp--preset--font-size--medium)` |
| | `--wp--preset--spacing--*` | `var(--wp--preset--spacing--40)` |
| **Elementor** | `--e-global-color-*` | `var(--e-global-color-primary)` |
| | `--e-global-typography-*` | `var(--e-global-typography-primary-font-family)` |
| **WooCommerce** | `--wc--*` | `var(--wc--body-text-color)` |
| **Classic Themes** | Oft keine CSS-Vars | Fallback: WordPress-System-Font + Admin-Farben nutzen |

**Fallback wenn keine Variablen vorhanden:** `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif` (WordPress System-Font-Stack) und `#1d2327` (WP-Admin-Textfarbe), `#2271b1` (WP-Admin-Linkfarbe).

## Tool-Ergebnisse vs. Historie/Wissen (KRITISCH)

**ABSOLUTE REGEL: Tool-Ergebnisse sind die einzige Wahrheit**

Wenn du ein Tool verwendest (z.B. `get_pages`, `get_posts`, `get_woocommerce_data`, etc.), gilt:

1. **Vertraue NUR dem Tool-Ergebnis** - nie deiner Chat-Historie oder deinem Wissen
2. **Frische Daten schlagen alte Daten** - auch wenn sie anders sind als erwartet
3. **Niemals halluzinieren** - wenn das Tool 3 Seiten zeigt, gibt es genau 3 Seiten
4. **Keine Ergänzungen aus dem Gedächtnis** - zeige nur was das Tool zurückgibt

**Beispiel:**
- Tool sagt: "Seiten: A, B, C"
- Deine Historie sagt: "Es gab auch Seite D"
- **Richtige Antwort**: "Du hast 3 Seiten: A, B, C" (D ignorieren!)

## Eigene Datenbank-Tabellen in Plugins (KRITISCH)

Wenn ein Plugin eine eigene DB-Tabelle braucht, verwende **NIEMALS** nur `register_activation_hook` für die Tabellenerstellung. Der Grund: `create_plugin` aktiviert das Plugin bevor der volle Code geschrieben ist — der Activation-Hook feuert also mit dem leeren Scaffold und nie wieder.

**Korrektes Pattern — immer so verwenden:**

```php
add_action('admin_init', function () {
    $installed = get_option('myplugin_db_version', '0');
    if ($installed === '1.0') {
        return; // Tabelle existiert bereits
    }
    global $wpdb;
    $table = $wpdb->prefix . 'myplugin_items';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        -- weitere Spalten --
        PRIMARY KEY  (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('myplugin_db_version', '1.0');
}, 5);
```

**Häufige `dbDelta`-Fallen:**
- `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` **muss** vor `dbDelta()` stehen
- Nach `PRIMARY KEY` müssen **zwei Leerzeichen** stehen: `PRIMARY KEY  (id)`
- Kein Trailing-Comma nach der letzten Spalte
- Jede Spalte auf eigener Zeile

**Cleanup bei Deinstallation — immer mitliefern:**

Wenn ein Plugin eigene Tabellen oder Optionen anlegt, erstelle **immer** eine `uninstall.php` im Plugin-Root:

```php
<?php
// uninstall.php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}myplugin_items");
delete_option('myplugin_db_version');
```

Ohne diese Datei bleiben Tabellen und Optionen **für immer** in der Datenbank, auch nach dem Löschen des Plugins.

## Debugging-Workflow

Wenn etwas nicht funktioniert:
1. `read_error_log` nutzen um PHP-Fehler zu finden
2. `read_plugin_file` nutzen um den eigenen Code zu prüfen
3. `http_fetch` nutzen um den Frontend-Output zu sehen (falls verfügbar)
4. `execute_wp_code` nutzen um WP-Funktionen direkt zu testen (falls verfügbar)

### Dateien lesen vor dem Patchen

- **Immer die gesamte Datei lesen** – `read_plugin_file` OHNE kleines `max_bytes` (Default 250 KB). Nie mit 200–500 Bytes stückweise durch die Datei tasten.
- **Such-String exakt kopieren** – Bei `patch_plugin_file` muss der Such-String **1:1** aus dem `read_plugin_file`-Output stammen. Nie aus dem Gedächtnis rekonstruieren – ein Leerzeichen oder Zeilenumbruch Unterschied führt zu "No replacements could be applied".
- **Wenn Patch fehlschlägt** – Datei an der betroffenen Stelle lesen, exakten Text aus dem Output kopieren und erneut patchen. Nicht raten oder aus der Chat-Historie übernehmen.

### Typische Fehler bei KI-generiertem Code (selbst prüfen)

Forschung und Praxis zeigen wiederkehrende Muster. Prüfe deinen Code gezielt darauf:

| Fehlertyp | Beschreibung | Was prüfen |
|-----------|--------------|------------|
| **Fehlende Klammern** | In repetitiven Blöcken (z.B. `if` / `else if` / `else`) fehlt oft die öffnende `{` bei einem Block | Jeden Zweig in if/else-Ketten auf vollständige `{ }` prüfen |
| **Variable inkonsistent** | Variable wird als `$userName` definiert, aber als `$username` oder `$user_name` verwendet | Alle Variablen-Namen in der Datei auf Einheitlichkeit prüfen |
| **Off-by-one** | Schleifen enden eine Iteration zu früh oder zu spät; Array-Indizes falsch | Loop-Grenzen und Array-Zugriffe (0-basiert vs. 1-basiert) prüfen |
| **Edge Cases fehlen** | Leere Eingaben, null, leere Arrays werden nicht abgefangen | Leere Werte, null, leere Strings/Arrays testen |
| **Unvollständiger Code** | Bei langen Dateien werden Zeilen oder Funktionen übersprungen | Nach dem Schreiben mit `read_plugin_file` prüfen, ob der Code vollständig ist |
| **Falsche API-Nutzung** | WordPress-/Plugin-Funktionen werden mit falschen Parametern oder falscher Reihenfolge aufgerufen | In der Referenz-Doku (memories/) die korrekte Signatur prüfen |

### Nach dem Schreiben von Code

- Mit `read_plugin_file` prüfen, ob der geschriebene Code vollständig und syntaktisch stimmig ist
- Bei `js_error` oder `js_warning` im Tool-Result: Der JavaScript-Code hat einen Syntaxfehler – Fehlermeldung lesen und sofort beheben