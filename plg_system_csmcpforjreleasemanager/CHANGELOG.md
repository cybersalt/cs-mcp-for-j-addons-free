# MCP for Cybersalt Release Manager — Changelog

## 🚀 Version 1.12.0 (November 5, 2026)

- 🔒 **Security: the tools now check Release Manager's own permissions.** Before this, any account allowed to use MCP for J's tools could read customer emails and installations, and any account with its write permission could change or delete packages, versions and installations, even with no access to Release Manager at all. Now every tool needs Release Manager's "Access Administration Interface" permission, the same rule as its own screens, and creating needs "Create", changing needs "Edit", changing a published state needs "Edit State", and deleting needs "Delete". If you use this add-on with an account that isn't a Super User, check it has these permissions.
- ✨ **The package tools can set every package field Release Manager has.** `create_release_manager_package` and `update_release_manager_package` now take the 13 fields added in Release Manager 1.9 to 1.13: the account link location (`relink_hint`), feed key, successor package, renewable groups and their message, site limits by group, licence family, the over-the-limit message, the update XML, download and sign-up URLs, the catalog slug and the catalog metadata. Before, `get_release_manager_package` showed them but the write tools ignored them and still answered "ok". They're checked the way Release Manager's own edit screen checks them: a feed key must be unique, a successor must be another existing package, user groups must exist, URLs must be http(s), the catalog metadata must be a JSON object, and the messages are cleaned the same way. On a site whose Release Manager is older than a field, the field is skipped and listed instead of failing the call.
- 🐛 **Changing only `install_limit` no longer deletes the per-group site limits.** Release Manager's save treats a form without the site-limits table as "every row removed", so an update that only set `install_limit` emptied the limits for every group. The stored limits are now sent back with it.
- 🔍 **Every field a package tool sets is read back from the database**, and anything stored differently from what was sent is reported. Clearing the catalog slug works too: Release Manager's own save leaves the old slug in place when it's emptied, so the tool clears it.
- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.11.3 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for Cybersalt Release Manager"** in Extensions → Plugins (was "System - MCP for Joomla - Cybersalt Release Manager add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- No tool behaves differently.

## 🚀 Version 1.11.2 (October 19, 2026)

- 🐛 **`create_release_manager_package_version` now really computes `sha256` when you leave it out**, as its description always said. It used to store an empty checksum, because Release Manager only hashes files on its own upload screen, so the update feed served that version without a `<sha256>`. The tool now hashes the zip in the package's downloads folder, and refuses (creating nothing) if the file is not there yet.
- 🐛 **`update_release_manager_package_version` re-computes `sha256` when you change `filename`** without supplying a new checksum, instead of leaving the old file's checksum in place, which would make Joomla reject the download.
- 🛡️ Both tools reject a malformed `sha256` or a `filename` with folders in it, and re-read the stored checksum after saving.

## 🚀 Version 1.11.1 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.11.0 (September 13, 2026)

- 📝 Version number realigned with the version already published (1.1.0 had been released as 1.11.0). No code change.

## 🚀 Version 1.1.0 (September 13, 2026)

- Installation get/update/delete tools.

## 🚀 Version 1.0.1 (June 18, 2026)

- Post-install confirmation message.

## 🚀 Version 1.0.0 (June 18, 2026)

- 🚀 Initial release (split out of MCP for Joomla).
