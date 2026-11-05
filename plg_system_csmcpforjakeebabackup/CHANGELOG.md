# MCP for Akeeba Backup Core — Changelog

## 🚀 Version 1.0.5 (November 5, 2026)

Thanks to Leandro Santana, whose report ("it did not recognise the latest backups") led to this. Tested on Joomla 6.1.4 with Akeeba Backup Core 10.5.0 and Pro 10.5.0: real backups run through MCP, and every tool tried with Super User, Administrator and Manager accounts, with the Manager granted each Akeeba permission in turn.

- 🔒 **Security: the tools now check Akeeba Backup's own permissions.** Before this, any account allowed to use MCP for J's tools could list, start and delete backups, and read a profile's raw settings, even with no access to Akeeba Backup at all. Those settings can hold remote storage credentials and the archive password. Now every tool needs Akeeba Backup's "Access Administration Interface" permission, the same rule as its own screens: starting or continuing a backup needs "Backup", deleting one needs "Download", and a profile's configuration and filters are returned only with "Configure". A profile at an access level the account doesn't have is left out of the list and can't be read or backed up. If you use this add-on with an account that isn't a Super User, check it has these permissions.
- 🐛 **Backups kept in remote storage are reported as good backups.** When a backup profile uploads to remote storage (Google Drive, S3, Dropbox…) and removes the local copy, Akeeba marks every good backup "file not on this server". The tools passed that flag on as it was, so an AI read the newest backups as missing, and the only record with a file could be a failed one. `list_akeeba_backups` and `get_akeeba_backup_info` now say where each archive is (`stored`: on this server, on this server and in remote storage, remote storage only, no copy found, running, failed), whether it can be restored, and why, in plain words.
- ✨ **`list_akeeba_backups` answers "when was my last good backup?"** Its summary names the newest backup that can be restored, wherever it's kept. A new `only_restorable` filter lists just those. `only_existing` still means "archive on this server", and its description now says it drops backups kept only in remote storage.
- 🔍 A backup still marked as running after six hours is called interrupted, and a failed backup's leftover file is called a partial archive.
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
