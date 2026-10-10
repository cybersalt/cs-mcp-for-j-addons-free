# MCP for Joomla: Page Builder CK add-on — Changelog

## 🚀 Version 1.0.2 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.1 (September 3, 2026)

- 📦 Declares **PHP 8.2** as the minimum, since the add-on uses trait constants.

## 🚀 Version 1.0.0 (August 27, 2026)

- 🚀 Initial release.
