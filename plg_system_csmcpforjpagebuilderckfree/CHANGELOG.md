# MCP for Page Builder CK — Changelog

## 🚀 Version 1.0.4 (November 20, 2026)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.
- 🔧 **Joomla's Changelog button now works for this add-on.** On System → Manage → Extensions it shows this add-on's release notes.
- 🔒 **Tighter permission checks.** Each tool now also needs the matching permission in Page Builder CK itself, as its own screens do: "Access Administration Interface" to list things, "Edit" to read a page's, block's, style's or element's content or to change a page, "Create" to add or copy one, "Edit State" to publish or trash one (also when create or update sets the state), and "Delete" to delete one for good. A refusal says which permission is missing.
- 🔒 **Checked-out pages are left alone.** Every tool that changes a page's content refuses while another user has the page checked out, as Page Builder CK's own screens do, so their next Save can't silently undo the change. Pass `force_checked_out: true` to write anyway.
- 🔒 **Safer file and error handling.** restore_pagebuilderck_backup checks the snapshot path before it looks on disk and only restores a page's own snapshots (not those of saved elements), and error messages no longer include database details or server paths.
- 🔒 **Titles and heading tags are cleaned as Page Builder CK cleans them.** HTML tags are removed from page titles, a page's title tag option must be h1–h6, div, p or span, and a page state must be published, unpublished, trashed or archived.
- 🔧 **Clearer hints for the AI app.** restore_pagebuilderck_backup, set_pagebuilderck_row_columns and update_pagebuilderck_page are marked as tools that overwrite content.
- 🐛 **The snapshot list works.** list_pagebuilderck_backups stopped with a type error as soon as a page had a snapshot. It now lists them.

## 🚀 Version 1.0.3 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for Page Builder CK"** in Extensions → Plugins (was "System - MCP for Joomla - Page Builder CK"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

## 🚀 Version 1.0.2 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.1 (September 3, 2026)

- 📦 Declares **PHP 8.2** as the minimum, since the add-on uses trait constants.

## 🚀 Version 1.0.0 (August 27, 2026)

- 🚀 Initial release.
