# MCP for Joomla: Convert Forms add-on — Changelog

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
