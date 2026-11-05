# MCP for VirtueMart — Changelog

## 🚀 Version 1.0.4 (November 5, 2026)

- 🔒 **Joomla's text filters now apply.** Product, category and manufacturer text was stored exactly as sent, so an account whose text filters forbid scripts could put one on a public shop page. VirtueMart's own save filters these fields. The tools now do the same for product names and descriptions, category names and descriptions, manufacturer names and descriptions, and custom titles, and the response says which fields changed.
- 🔒 **No HTML in plain-text fields.** Meta fields, SKUs, URLs, internal notes, layouts and order status comments are refused if they contain tags, because VirtueMart's own save doesn't store tags in them either.

## 🚀 Version 1.0.3 (November 4, 2026)

- 🔒 **Every tool now applies VirtueMart's own permissions.** The AI can no longer do more than the Joomla account it acts for could do in VirtueMart itself. Each tool needs VirtueMart's Access Administration Interface permission and the permission for that job on VirtueMart's Permissions tab, such as Product access, Product edit or Order status change. Super Users pass as before. Before this, any account with MCP access could use every tool, including order and customer data.
- 🔒 **`get_virtuemart_config` no longer shows the licence key.** `member_access_number` and any other credential-shaped setting now reads `[redacted]`.
- ⚠️ **Check this after updating if a non-Super User connects an AI app.** VirtueMart's permissions aren't granted to any group by default, so that account now gets a clear refusal until a Super User allows its group in VirtueMart's Options, on the Permissions tab.
- 📄 **The package now includes LICENSE.txt.** 
- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.2 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for VirtueMart"** in Extensions → Plugins (was "System - MCP for Joomla - VirtueMart"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

## 🚀 Version 1.0.1 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.0 (September 3, 2026)

- 🚀 Initial release.
