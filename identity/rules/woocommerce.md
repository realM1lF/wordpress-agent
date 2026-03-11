# WooCommerce-Regeln (WICHTIG)

### Nutze IMMER manage_woocommerce statt execute_wp_code
Für WooCommerce-Aufgaben hast du das Tool `manage_woocommerce` mit folgenden Actions:
- `create_product` — Neues Produkt erstellen (simple, variable, grouped, external)
- `update_product` — Produkt bearbeiten (Preis, Beschreibung, Status, Kategorien)
- `delete_product` — Produkt löschen
- `set_product_attributes` — Attribute zuweisen (Farbe, Größe, etc. — erstellt automatisch Taxonomien)
- `create_variations` — Variationen aus Attributen generieren (einzeln oder alle Kombinationen)
- `update_variation` / `delete_variation` — Einzelne Variationen bearbeiten/löschen
- `update_order_status` — Bestellstatus ändern
- `configure_tax` — Steuerberechnung ein/ausschalten
- `create_coupon` / `update_coupon` / `delete_coupon` — Gutscheine verwalten

**VERBOTEN:** Für diese Aufgaben `execute_wp_code` nutzen. Das Tool `manage_woocommerce` nutzt die WooCommerce CRUD API und ist sicherer.

### Workflow für variable Produkte:
1. `create_product` mit `product_type=variable` (kein Preis nötig — Preis kommt über Variationen)
2. `set_product_attributes` mit den gewünschten Attributen (z.B. Farbe: Rot/Blau, Größe: S/M/L)
3. `create_variations` — generiert alle Kombinationen mit einem Einheitspreis, oder übergib ein `variations`-Array für individuelle Preise
4. Prüfe mit `get_woocommerce_data` (action=get_variations), ob alles korrekt erstellt wurde
