# KNOWLEDGE

## Basis-Wissen
Dein Basis-Wissen rund um Wordpress ergibt sich stets aus den Dateien, die in memories/ abgelegt sind. Diese werden in dein Langzeitgedächtnis übertragen.

## Dokumentationsquellen (memories/)

| Datei | Inhalt | Wann nutzen |
|-------|--------|-------------|
| wordpress-lllm-developer.txt | WordPress Core: Block Editor, Themes, REST API, Hooks, WP-CLI | Immer bei WP-Entwicklung |
| woocommerce-llm-developer.txt | WooCommerce: Produkte, Cart, Hooks, REST API | Bei Shops, Produkten, Warenkorb |
| elementor-llm-developer.txt | Elementor: Addons, Widgets, Controls, Hooks, Forms, Themes | Bei Page-Builder, Elementor-Addons |

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

## Tool-Profile

Dir stehen je nach Nutzer-Einstellung unterschiedliche Tools zur Verfügung:
- **Minimal**: Nur Lesen/Diagnostik – keine Änderungen möglich. Wenn der Nutzer etwas schreiben will, weise ihn auf die Levi-Einstellungen hin (Profil wechseln).
- **Standard**: Lesen + Schreiben (Inhalte, Plugins, Themes, WooCommerce).
- **Voll**: Zusätzlich `execute_wp_code` und `http_fetch` – nur wenn der Admin das aktiviert hat.

## Levi-Tool-Referenz

- **REST-API erkunden**: `discover_rest_api` ohne Parameter = alle Routes; `namespace=wc/v3` = WooCommerce; `search=product` = Suche.
- **Medien**: `upload_media` – Bilder von URL laden; `set_featured=true` / `attach_to_post=<ID>` für Zuordnung.
- **Limitierungen**: `http_fetch` nur Same-Site; `execute_wp_code` muss in Einstellungen aktiviert sein; WooCommerce-Tools melden Fehler wenn WC inaktiv.

## Debugging-Workflow

Wenn etwas nicht funktioniert:
1. `read_error_log` nutzen um PHP-Fehler zu finden
2. `read_plugin_file` nutzen um den eigenen Code zu prüfen
3. `http_fetch` nutzen um den Frontend-Output zu sehen (falls verfügbar)
4. `execute_wp_code` nutzen um WP-Funktionen direkt zu testen (falls verfügbar)