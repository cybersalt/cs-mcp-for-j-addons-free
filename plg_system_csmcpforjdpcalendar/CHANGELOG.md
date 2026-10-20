# MCP for DPCalendar — Changelog

## 🚀 Version 1.0.3 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for DPCalendar"** in Extensions → Plugins (was "System - MCP for Joomla: DPCalendar add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- No tool behaves differently.

## 🚀 Version 1.0.2 (October 19, 2026)

- 🐛 **`list_dpcalendar_coupons` works on MySQL and MariaDB.** It selected the coupon's `limit` column without quoting it, and `limit` is a reserved word, so every call failed with an SQL syntax error. All plain column lists in the add-on are now quoted.

## 🚀 Version 1.0.1 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.0 (July 21, 2026)

- 🚀 Initial release.
