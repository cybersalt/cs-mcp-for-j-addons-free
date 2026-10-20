# MCP for Cookies Policy Notification Bar — Changelog

## 📝 Unreleased (ships with this add-on's next release)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.1 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for Cookies Policy Notification Bar"** in Extensions → Plugins (was "System - MCP for Joomla: Cookies Policy Notification Bar add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

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
