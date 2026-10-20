# MCP for Akeeba Backup Core — Changelog

## 📝 Unreleased (ships with this add-on's next release)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.4 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for Akeeba Backup Core"** in Extensions → Plugins (was "System - MCP for Joomla: Akeeba Backup add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- No tool behaves differently.

## 🚀 Version 1.0.3 (October 19, 2026)

- 🐛 **`start_akeeba_backup` uses the profile's own settings.** On a site whose backup profile is encrypted (Akeeba's default once its Control Panel has been opened), the add-on could not read the encryption key, so the backup started on Akeeba's *default* settings (default output folder, default filters) and the first `step_akeeba_backup` then failed with "Akeeba Engine detected a problem while saving temporary data". The key is now loaded the way Akeeba's own admin screens load it, the profile's configuration is loaded before the backup starts, and an encrypted profile that cannot be decrypted is refused instead of silently backed up on defaults.
- 🐛 **Start and step results are unambiguous.** Each now returns `state` (`running`, `complete` or `failed`), `done` (true means stop calling `step_akeeba_backup`, whether the backup finished or failed), `succeeded`, the backup `record` (id, archive path, size) and a `next_action`. A failed backup is returned as an error result. Previously a failure came back as `done: false` with `errored: true`.
- ✨ `start_akeeba_backup` reports the profile's output folder, and refuses a profile id that does not exist.
- 📝 `list_akeeba_backups` and `get_akeeba_backup_info` say that backup times are UTC.

## 🚀 Version 1.0.2 (October 9, 2026)

- Akeeba Backup: drop a tool from the docblock that does not exist.
- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.1 (June 18, 2026)

- Post-install confirmation message.

## 🚀 Version 1.0.0 (June 18, 2026)

- 🚀 Initial release (split out of MCP for Joomla).
