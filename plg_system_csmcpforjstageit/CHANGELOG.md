# MCP for StageIt — Changelog

## 🚀 Version 1.1.0 (unreleased, in test)

Brings the add-on up to date with **StageIt 6.4.0 and later on Joomla 6** (and 5.8.0+ on Joomla 5), using
the same in-process way of running StageIt that StageIt's own command line uses. Tested on Joomla 6.1.4
with StageIt 6.6.2: build, push, restore and remove, ending in the same state as StageIt's own buttons. If you
use StageIt with this add-on, update.

- 🔒 **Security: the tools now check StageIt's own permissions.** Before this, any account allowed to use
  MCP for J's write tools could build, push, restore or remove, even with no access to StageIt at all. A
  restore or push replaces the whole site, including its user accounts. Now every tool needs StageIt's
  "Access Administration Interface" permission, and build, push, restore and remove also need "Configure
  ACL & Options", which Super Users have.
- 🐛 **Building a staging copy works again.** 1.0.2 ran StageIt from the wrong folder, so a build made a
  stray `administrator/components/stageit/` folder and then went round in circles without ever building a
  staging copy. If someone tried a build through MCP before, that folder can be deleted.
- 🐛 **Pushes and removes run the way StageIt runs them.** Each operation now runs from the folder and with
  the settings StageIt's own admin uses (a push from inside the staging copy, with the staging copy's
  StageIt options). Errors StageIt reports are now passed on instead of being lost, and StageIt's
  progress tracking during a push is no longer skipped.
- 🐛 **Restoring a backup can work at all.** StageIt only restores from inside the staging copy, and 1.0.2
  never ran it from there.
- 🛡️ **The same safety checks as StageIt's command line.** A push is refused when the staging copy is
  incomplete, has the wrong database prefix or has no data, when the site isn't writable, when an earlier
  StageIt run didn't finish, or when backups are switched off. A remove is refused while the staging copy
  hasn't been pushed to live since it was built, because that would throw its work away. New `force` and
  `no_backup` options override these, as `--force` and `--no-backup` do on the command line.
- 🛡️ **No rebuilding over unpushed work.** `deploy_stageit` refuses to build over a staging copy that hasn't
  been pushed since it was built, unless `force` is given.
- 🛡️ **One operation at a time.** A new build, push, remove or restore is refused while another one
  started through MCP is unfinished.
- ⏱️ **The last part of a push or restore runs in one call.** While StageIt swaps live's files and
  database, the site's own API (which MCP uses) is briefly unreachable. The add-on no longer pauses there,
  so a push can't be left half-done; that last call can take a while.
- 📊 **`get_stageit_status` reports what `php cli/joomla.php stageit:status --json` reports:** whether a
  staging copy exists and when it was built, its database prefix and tables, the last backup and push,
  leftovers of an unfinished run, and whether a push or remove would be allowed right now and why not.
  `list_stageit_backups` also says whether each backup includes the database, and when the last push was.
- 📝 Every build, push, remove and restore started through MCP is written to StageIt's audit log, with
  the user who started it.
- Needs StageIt 6.4.0+ (Joomla 6) or 5.8.0+ (Joomla 5) for building, pushing, removing and restoring.
  With an older StageIt those tools say so; status, pre-checks and the backup list still work.
- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.2 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for StageIt"** in Extensions → Plugins (was "System - MCP for Joomla: StageIt add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- No tool behaves differently.

## 🚀 Version 1.0.1 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.0 (July 7, 2026)

- 🚀 Initial release.
