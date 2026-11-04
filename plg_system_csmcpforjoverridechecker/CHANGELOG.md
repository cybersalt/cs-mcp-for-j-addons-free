# MCP for Override Checker — Changelog

## 🚀 Version 1.0.0 (unreleased)

First release. 12 tools for **Cybersalt Override Checker** (`com_csoverridechecker`), so any MCP client can review
and fix the template overrides Joomla flags after an update. Browser chats such as claude.ai, ChatGPT and Gemini
can't run Override Checker's copy-paste prompt, because it needs an AI that calls the site's REST API with an
`X-Joomla-Token` header. Through MCP for J they can. Built against **Override Checker 2.6.1** and needs **2.6.1 or
later**.

### ✨ New

- **Status**: `get_override_checker_status`: versions, flagged overrides per template (checked and not), backup
  and session counts, this user's permissions, and the review workflow.
- **Flagged overrides**: `list_override_checker_overrides` (Override Checker's REST filters: template, site/admin,
  checked or not, extension), `get_override_checker_override_file`, `get_override_checker_core_file`,
  `apply_override_checker_fix`, `dismiss_override_checker_override`.
- **Backups**: `list_override_checker_backups` (shows whether the live file still matches each backup),
  `get_override_checker_backup`, `restore_override_checker_backup`.
- **Review sessions**: `list_override_checker_sessions`, `get_override_checker_session` (the report, plus the
  fixes, backups and restores filed under it), `save_override_checker_session`.

### 🏗 Architecture

- **Override Checker's own code does every write.** A fix goes through its `applyFix()`, which saves the current
  file as a backup before writing and only writes inside `templates/<template>/html/`. A restore backs up the
  current file first, so it can be undone too.
- **Files are read whole.** Override Checker's in-admin chat cuts file reads at 16 KB. A fix built from a cut-off
  read would delete the end of the override when written back, so these tools return the whole file (up to the
  REST API's 1 MB cap).
- **A fix must name the file it was built from.** `apply_override_checker_fix` needs the sha256 of the file it read
  and refuses to write if the file on disk differs. A `.php` fix that doesn't parse is refused, and writing the
  bytes already there does nothing.
- **One row at a time.** No dismiss-all: Override Checker removed it from its own chat in 2.3.3 after a misread
  instruction cleared 70 rows in testing.
- **Alternative layouts are expected.** When an override has no core file, the core-file tool says so as a normal
  result and lists the core folder, so a renamed layout can be spotted.
- **Override Checker's own permissions:** `csoverridechecker.view` for reads, `csoverridechecker.write` for fixes,
  dismissals, restores and saving sessions (core.manage and Super Users pass both, as in Override Checker).
- **Free**, because Override Checker is free.

### 📦 Build

- Requires PHP 8.2+ and CS MCP for J. Installs disabled, with an explanation, when CS MCP for J is not present,
  and clears Joomla's caches after install.
