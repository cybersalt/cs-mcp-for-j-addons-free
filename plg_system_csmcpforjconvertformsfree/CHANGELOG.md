# MCP for Convert Forms — Changelog

## 📝 Unreleased (ships with this add-on's next release)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.4 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for Convert Forms"** in Extensions → Plugins (was "System - MCP for Joomla: Convert Forms Free add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

## 🚀 Version 1.0.3 (October 19, 2026)

- 🐛 **`list_convertforms_tasks` works on MariaDB and MySQL 8.** It selected the `trigger` column without quoting it, and `trigger` is a reserved word, so every call failed with an SQL syntax error. All column lists in the add-on are now quoted. (GitHub issue #4)
- 🐛 **`update_convertforms_task` no longer reports a change it did not make.** After saving, it re-reads the task from the database and checks every requested change against the stored row; if any did not land, the call fails and names it. The response now shows the stored values rather than echoing the request back.
- 🐛 **`update_convertforms_task` refuses argument names it does not know** instead of silently ignoring them. Passing `state` (the column name `get_convertforms_task` returns) used to be dropped without a word while the call reported success; `state` is now accepted as an alias for `enabled`.

## 🚀 Version 1.0.2 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.1 (September 3, 2026)

- 📦 Declares **PHP 8.2** as the minimum, since the add-on uses trait constants.

## 🚀 Version 1.0.0 (August 19, 2026)

- 🚀 Initial release.
