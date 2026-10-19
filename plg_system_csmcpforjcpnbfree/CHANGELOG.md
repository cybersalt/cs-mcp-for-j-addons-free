# MCP for Joomla: Cookies Policy Notification Bar add-on — Changelog

## 🚀 Version 1.0.0 (October 19, 2026)

First release. 14 tools for **Cookies Policy Notification Bar 5.x** (CPNB) by Web357, studied against
the 5.1.2 package and driven end-to-end on Joomla 6.2.0 / PHP 8.3. Free, and works with both the free
and PRO editions of CPNB.

### ✨ New

- **Diagnostics** — `get_cpnb_component_info`, `check_cpnb_health`, `check_cpnb_frontend`.
- **Configuration** — `get_cpnb_config`, `set_cpnb_config` (position, timers, overlay, colours,
  button order, consent-cookie names and lifetimes, Cookies Manager, menu visibility).
- **Cookie categories** — `list_cpnb_categories`, `create_cpnb_category`, `update_cpnb_category`,
  `delete_cpnb_category`.
- **Cookie declarations** — `list_cpnb_cookie_declarations`, `set_cpnb_cookie_declaration`,
  `delete_cpnb_cookie_declaration`.
- **Texts** (site default language) — `get_cpnb_texts`, `set_cpnb_texts`.

### 🏗 Architecture

- **Writes go through CPNB's own settings model**, so its validation rules and its cache purge
  (Joomla page cache, every cache group, JCH Optimize) apply, and every write is re-read from the
  database before it is reported.
- **The model is never allowed to read its own starting params.** It reads them through
  `PluginHelper::getPlugin()`, which returns nothing for a disabled plugin — so with the bar switched
  off, CPNB's own save of one key wipes every other setting. The tools inject the stored params
  themselves.
- **Unknown keys are refused**, because CPNB's model silently drops keys it has no rule for and reports
  success.
- **Lists are edited one row at a time.** CPNB replaces a subform (categories, declarations, button
  order) whole, so the tools read the full list, change one row, and write the full list back.
- **Pro capabilities are not unlocked here.** On the free build of CPNB, settings behind cookie
  blocking, consent logs and GeoIP targeting are refused, and texts can only be written for the site's
  default language. Dedicated tools for CPNB PRO capabilities ship in the Cybersalt Pro add-on.

### 📝 CPNB behaviour worth knowing

- Deleting every cookie category does not remove them from the site: CPNB falls back to four
  hard-coded sample categories. `delete_cpnb_category` refuses to remove the last one.
- Texts are stored per language tag (`header_message_en_GB`); the bar shows the visitor's language and
  falls back to the language-file default, not to another language's text.
- Only the first non-empty GeoIP list is evaluated, and none of them apply unless GeoIP is switched on.
- Uninstalling CPNB drops its consent-log table.
