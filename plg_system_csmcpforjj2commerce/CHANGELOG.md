# MCP for Joomla: J2Commerce add-on — Changelog

## 🚀 Version 1.0.0 (October 16, 2026)

First release. 43 tools for **J2Commerce 6** (`com_j2commerce`) by J2Commerce, LLC. Studied
against **J2Commerce 6.6.5** and driven end-to-end on Joomla 6.2.0 / PHP 8.3, including a real
storefront checkout. This is J2Commerce *six*; J2Store 4 (`com_j2store`) is a different product
with a different database and is not covered.

### ✨ New

- **Diagnostics** — `get_j2commerce_component_info`, `check_j2commerce_health`,
  `check_j2commerce_tax_setup`, `get_j2commerce_config`, `set_j2commerce_config`,
  `list_j2commerce_tables`, `query_j2commerce_table`.
- **Products** — `list_j2commerce_products`, `get_j2commerce_product`,
  `create_j2commerce_product`, `update_j2commerce_product`, `update_j2commerce_variant`,
  `delete_j2commerce_product`.
- **Advanced prices** — `list_j2commerce_product_prices`, `set_j2commerce_product_price`,
  `delete_j2commerce_product_price`.
- **Stock** — `list_j2commerce_stock`, `set_j2commerce_stock`.
- **Orders** — `list_j2commerce_orders`, `get_j2commerce_order`,
  `list_j2commerce_order_statuses`, `set_j2commerce_order_status`, `add_j2commerce_order_note`,
  `set_j2commerce_order_tracking`.
- **Customers** — `list_j2commerce_customers`, `get_j2commerce_customer`.
- **Coupons** — `list_j2commerce_coupons`, `get_j2commerce_coupon`, `create_j2commerce_coupon`,
  `update_j2commerce_coupon`, `delete_j2commerce_coupon`.
- **Gift vouchers** — `list_j2commerce_vouchers`, `get_j2commerce_voucher`,
  `adjust_j2commerce_voucher_balance`.
- **Store** — `list_j2commerce_currencies`, `list_j2commerce_countries`,
  `list_j2commerce_geozones`, `list_j2commerce_manufacturers`, `list_j2commerce_options`,
  `list_j2commerce_shipping_methods`, `list_j2commerce_payment_methods`.
- **Reports** — `get_j2commerce_sales_summary`, `get_j2commerce_top_products`.

### 🏗 Architecture

- **Reads go to the tables; writes go where J2Commerce puts them.** Order status changes,
  notes and tracking use J2Commerce's own `OrderModel` methods — the same ones its REST API
  calls — so stock moves, history is written, downloads are granted and plugins hear about it.
  Stock goes through its atomic `InventoryHelper`, voucher balances through its locked
  `VoucherModel::adjustBalance()`, product deletion through its own `ProductService` cascade.
- **Everything else is a targeted update of the named fields.** J2Commerce's product save treats
  a save as a complete form and sets every field it was not given to zero — price, weight,
  dimensions, tax profile, manufacturer, even child-variant stock. No tool here ever hands it a
  partial product.
- **A product is an article.** `create_j2commerce_product` turns an existing article into a
  product exactly as J2Commerce's own "create from article" action does.
- **Tax and geozones are read and audited, never written.** J2Commerce saves those by deleting
  every rule and re-inserting, with no transaction; a partial save silently zeroes a shop's tax.
- **Free**, because J2Commerce 6 has no Pro edition gate: `isPro()` gates no capability.

### 🔒 Never returned

The guest order-view token, raw payment-gateway data, stored payment profiles, customer IP
addresses, the cron / payment-hash secret (`queue_key`) and the download ID. Voucher codes are
masked unless explicitly revealed. Payment and shipping plugin credentials are withheld by name
pattern, including on gateways this add-on has never seen. Secret columns are also refused as
filter and sort targets, so they cannot be recovered a character at a time.

### 📝 J2Commerce behaviour worth knowing

- Guest checkout saves **no address row**, so J2Commerce's own Customers screen never lists guest
  buyers. These tools build customers from orders as well.
- A status change with "notify" records the order as notified **even when no email template
  matches**; on a stock install Delivered has no template. `set_j2commerce_order_status` predicts
  which templates can fire and reports what was actually sent.
- Stock is released only by status ids 3, 5 and 6, so a custom "Refunded" status keeps stock held.
- The store-wide "Enable inventory" switch does not control stock in 6.6.5; each variant's
  "Manage stock" does.
- An advanced price is used only if it is **lower** than the base price, and a customer group of
  0 matches nobody.
- A coupon's "User Group" and "Limit to X items" fields are silently discarded: 6.6.5 has no
  columns for them.
- Deleting an order never restocks it.

### 📦 Build

- Requires PHP 8.2+ and MCP for Joomla. Installs disabled, with an explanation, when MCP for
  Joomla is not present, and clears Joomla's caches after install.
