# MCP for Joomla: Cybersalt Release Manager add-on — Changelog

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
