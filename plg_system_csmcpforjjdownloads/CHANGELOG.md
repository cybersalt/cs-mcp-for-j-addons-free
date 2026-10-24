# MCP for jDownloads — Changelog

## 🚀 Version 1.0.0 (October 24, 2026)

First release. 17 tools for **jDownloads** (`com_jdownloads`) by Arno Betz. Studied against
**jDownloads 4.1.7 Stable**, the current release for Joomla 4, 5 and 6. Driven end-to-end on Joomla 6.2.0 /
PHP 8.3, including files downloaded from the front end and the download counter checked afterwards.

### ✨ New

- **Diagnostics** — `check_jdownloads_health` (database against disk), `query_jdownloads_table`.
- **Categories** — `list_jdownloads_categories`, `get_jdownloads_category`, `create_jdownloads_category`,
  `update_jdownloads_category` (rename and move included, folder and all).
- **Downloads** — `list_jdownloads_downloads`, `get_jdownloads_download`, `create_jdownloads_download`,
  `update_jdownloads_download`, `set_jdownloads_download_state`, `delete_jdownloads_download`.
- **Files** — `attach_jdownloads_file`, `list_jdownloads_unassigned_files`.
- **Read-only lookups** — `list_jdownloads_licenses`, `list_jdownloads_groups` (user-group download and upload
  limits), `list_jdownloads_permissions` (effective permissions per user group).

### 🏗 Architecture

- **Every category is also a folder on disk.** Creating, renaming and moving a category checks the disk before
  anything is written and verifies the database against the disk afterwards. A move is one rename of the folder,
  so everything below it moves intact.
- **New categories go through jDownloads' own category model.** Edits use its category table for the nested set
  and permissions, because its save routine works out the old folder from hidden form fields.
- **Downloads are written through jDownloads' own table, field by field.** Its admin save cannot be used from
  outside its form: there it blanks the author and URL fields, deletes the screenshots and resets permissions.
- **Partial updates keep everything you did not send:** tags, custom field values, permission rules, the
  download counter, checksums and screenshots. Tags, custom field values, permission rules and the counter were
  checked in the database after live edits.
- **Files are attached only from inside the jDownloads upload folder.** No other path, no `..`, no symbolic links,
  no file type jDownloads' upload settings block, and never a server-executable type.
- **Nothing sends email.** jDownloads' own notifications fire on front-end downloads and uploads, never on these
  tools.
- **Free**, because jDownloads is free.

### 📝 jDownloads behaviour worth knowing

- **jDownloads has no trash.** Its delete is final and removes the file too. `delete_jdownloads_download` wants the
  download unpublished first and keeps the file unless you ask.
- **Publishing or unpublishing a category applies to the whole branch below it**, and jDownloads will not publish a
  category whose folder is missing or whose parent is unpublished.
- **A new subcategory always gets its parent's access level**, and a new download with Public access gets its
  category's access level, as in the jDownloads admin.
- **The default upload folder is inside the web root with no protection.** Anyone can fetch a file by its URL,
  past access levels and the counter, until "anti-leech" is switched on. `check_jdownloads_health` warns about it.
- **The counter goes up even when the file is missing.** The visitor is sent back to the home page and the
  download is still counted.
- From reading jDownloads' code: its admin "move category to another parent" does not copy folders two levels down
  before deleting the old folder, and then unpublishes the moved category. These tools do not use that routine.

### 📦 Build

- Requires PHP 8.2+ and MCP for J. Installs disabled, with an explanation, when MCP for J is not present, and
  clears Joomla's caches after install.
