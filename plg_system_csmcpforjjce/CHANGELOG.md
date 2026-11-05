# MCP for JCE — Changelog

## 🚀 Version 1.0.4 (November 5, 2026)

- 🔒 **No HTML in profile names, descriptions or component lists.** These were stored exactly as sent, and JCE's profile list prints a profile's name without escaping it, so a tag saved as a name would run in the browser of whoever opened that list. JCE's own save strips tags from these fields. `create_jce_profile`, `update_jce_profile`, `duplicate_jce_profile` and `set_jce_profile_assignment` now refuse them.
- 🔒 **Settings are encoded the way JCE encodes them.** `set_jce_profile_params` stored values as sent. It now encodes special characters as JCE's own save does, and the response lists the settings it changed.

## 🚀 Version 1.0.3 (November 4, 2026)

- 🔒 **Every tool now applies JCE's own permissions.** The AI can no longer do more than the Joomla account it acts for could do in JCE itself. Each tool needs JCE's Access Component permission, plus Editor Profiles for profile work or Editor Global Configuration for the settings read. Super Users pass as before. Before this, any account with MCP access could use every tool, including ones that change what an editor profile can upload.
- 🔒 **API keys in profile settings are masked.** `get_jce_profile`, `get_jce_profile_params` and the change report from `set_jce_profile_params` now show credential settings, such as the AI Assistant plugin's `apikey`, as `[redacted]`. They're still stored as set.
- 🐛 **Works with JCE 3.** JCE 3 moved its plugin list and manifests, so on JCE 3.0 `list_jce_plugins` came back empty, `get_jce_plugin` found nothing, and toolbar names were reported or refused as unknown. The tools now read both JCE 3's and JCE 2's locations.
- ⚠️ **Check this after updating if a non-Super User connects an AI app.** That account now needs those permissions in JCE's Options, on the Permissions tab, and gets a clear refusal until a Super User allows its group.
- 📄 **The package now includes LICENSE.txt.** 
- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.2 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for JCE"** in Extensions → Plugins (was "System - MCP for Joomla - JCE"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

## 🚀 Version 1.0.1 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.0 (September 3, 2026)

- 🚀 Initial release.
