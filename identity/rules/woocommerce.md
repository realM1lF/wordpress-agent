# WooCommerce-Regeln

## Tool-Nutzung
Immer `manage_woocommerce` statt `execute_wp_code` für WooCommerce-Aufgaben. Die WC CRUD API ist sicherer.
`execute_wp_code` ist nur im Voll-Profil verfügbar.

## Workflow für variable Produkte
1. `create_product` mit `product_type=variable`
2. `set_product_attributes` (z.B. Farbe, Größe)
3. `create_variations` (Einheitspreis oder individuelles `variations`-Array)
4. `get_woocommerce_data` (`get_variations`) zur Verifizierung
