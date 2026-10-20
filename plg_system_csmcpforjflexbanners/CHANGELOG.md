# MCP for FlexBanners — Changelog

## 📝 Unreleased (ships with this add-on's next release)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.1 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for FlexBanners"** in Extensions → Plugins (was "System - MCP for Joomla: FlexBanners add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

## 🚀 Version 1.0.0 (October 19, 2026)

First release. 26 tools for **FlexBanners** (`com_flexbanners` + `mod_flexbanners`) by Inch Hosting.
Studied against **FlexBanners 5.1.10** and checked against **6.0.05** (same schema, different module
behaviour). Driven end-to-end on Joomla 6.2.0 / PHP 8.3, including banners rendering, rotating and
counting clicks on the front end.

### ✨ New

- **Diagnostics** — `check_flexbanners_health`, `query_flexbanners_table`.
- **Locations (ad groups)** — `list_flexbanners_locations`, `create_flexbanners_location`,
  `update_flexbanners_location`, `delete_flexbanners_location`.
- **Modules** — `create_flexbanners_location_module` (a new location and a cloned `mod_flexbanners`
  module in ONE call: "add another row of ads"), `list_flexbanners_modules`, `update_flexbanners_module`.
- **Banners** — `list_flexbanners`, `get_flexbanner`, `create_flexbanner`, `update_flexbanner`,
  `set_flexbanner_state`, `reset_flexbanner_counters`, `delete_flexbanner`.
- **Click-through links** — `list_flexbanners_links`, `create_flexbanners_link`, `update_flexbanners_link`.
- **Clients** — `list_flexbanners_clients`, `create_flexbanners_client`, `update_flexbanners_client`.
- **Size presets** — `list_flexbanners_sizes`, `create_flexbanners_size`, `update_flexbanners_size`.
- **Reports** — `get_flexbanners_stats` (impressions, clicks and CTR per banner, location and client).

### 🏗 Architecture

- **Every banner tool explains whether the banner will actually show**, using the same rules as
  `mod_flexbanners`: published and not finished, an existing size and category (both INNER JOINed),
  a category visitors can see, language `*` or the site default, and a published, positioned module
  showing its location. When it will not show, it says exactly why.
- **Writes are targeted SQL of the named columns.** FlexBanners' own banner `save()` cannot be driven
  from outside its admin form: it reads the request, mis-keys category/article restrictions, always
  clears "finished", and turns a category id it does not recognise into a new category.
- **Module rows go through Joomla's own module model** (asset, menu assignment, cache), and module
  settings are changed by rewriting only the named params, so position and menu assignment are never
  touched.
- **Free**, because FlexBanners is free.

### 📝 FlexBanners behaviour worth knowing

- **On Joomla 6, FlexBanners (5.1.10 and 6.0.05) needs the "Behaviour - Backward Compatibility 6"
  plugin.** Without it the component dies silently on load and the module fails with
  `Class "JRoute" not found`; any page showing a banner breaks. `check_flexbanners_health` detects
  both. These MCP tools are unaffected because they never boot the component.
- One module shows one location, so several rows of ads means several locations and several modules.
- 5.1.x never enforces end dates or impression/click limits. 6.x enforces them on every page view, and
  also re-publishes any unfinished banner whose start date has passed.
- The language test uses the site's default language, not the language of the page.
- Clicks are counted for every request, bots included; only impressions are bot-filtered.
- There is no per-day statistics table: the counters are lifetime totals.
- A size preset's "max file size" is stored but never enforced.
- Article restrictions saved in the FlexBanners admin are stored as article id 0 and never work;
  `update_flexbanner` writes them correctly.

### 📦 Build

- Requires PHP 8.2+ and MCP for Joomla. Installs disabled, with an explanation, when MCP for Joomla
  is not present, and clears Joomla's caches after install.
