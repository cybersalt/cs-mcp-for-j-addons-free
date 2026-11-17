# MCP for Convert Forms — Changelog

## 🚀 Version 1.0.5 (November 17, 2026)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.
- 🔧 **Joomla's Changelog button now works for this add-on.** On System → Manage → Extensions it shows this add-on's release notes.
- 🐛 **Forms made with `create_convertforms_form` and `add_convertforms_form_field` now look the same as forms made in Convert Forms' own editor.** Each field starts from the editor's settings for its type, so labels show, the submit button has its size and colours, headings get their heading tag, and the text of an HTML field is kept. The form also gets the editor's design settings, so it no longer has a black border. (GitHub issue #14)
- 🐛 **New fields are optional unless you ask for required.** Before, a field sent without `required` looked optional but was checked as required when the form was submitted.
- ✨ **Easier field settings.** An HTML field takes its content as `text`, `html` or `value`; a submit button takes its words as `text` or `label`; a heading takes its tag as `heading_type` (h1 to h6) or `level`.
- 🔧 **`update_convertforms_form_field` repairs older fields.** It adds any of the editor's settings that a field is missing, so a field made by an earlier version renders properly once it is edited.
- ✨ **`list_convertforms_field_types` with `type` now shows the editor's settings for that type.**
- 🔒 **Tighter permission checks.** Each tool now also needs the matching permission in Convert Forms itself, as its own screens do.
- 🔒 **Only a Super User can add or change PHP code through these tools** (a form's PHP Scripts, and the PHP task in Convert Forms Pro). Removing code is still allowed.
- 🔒 **Task settings that look like passwords, tokens or keys are hidden** in task results, unless someone with Convert Forms' Options permission asks to see them.
- 🐛 **Deleting a disabled email task no longer asks for confirmation.** The warning about a form losing its last email notification now counts only tasks that are switched on.
- ✅ **Tested with Convert Forms 5.2.7**, Free and Pro, on Joomla 5.4 and 6.1.
- 📦 The package now includes `LICENSE.txt`.

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
