# cs-mcp-for-j-addons-free

Free MCP add-on plugins for [cs-mcp-for-j](https://github.com/cybersalt/cs-mcp-for-j) — the Joomla extension that turns a Joomla site into its own MCP server.

Each plugin in this repo is a standalone Joomla system plugin that subscribes to cs-mcp-for-j's `onCsMcpRegisterTools` event and registers a set of MCP tools wrapping a specific third-party Joomla extension.

## What ships here

| Folder | Extension wrapped | Tools |
|---|---|---|
| `plg_system_csmcpforjakeebabackup` | [Akeeba Backup Core](https://www.akeeba.com/products/akeeba-backup-core.html) | Backup orchestration: list profiles, list / start / step / inspect / delete backups, manage archives. |
| `plg_system_csmcpforjreleasemanager` | [Cybersalt Release Manager](https://www.cybersalt.com/extensions/cs-release-manager) | Manage your own extension releases: list / get / create / update / delete Packages and PackageVersions, plus read-only views of installations and the activity log. |
| `plg_system_csmcpforjstageit` | [StageIt](https://www.php-web-design.com/products/stageit) | Staging environment orchestration: get status / prechecks / list backups, plus chunked start+continue tools for deploy / sync / remove / restore-backup. Each long-runner returns a resume_token so operations bigger than the PHP execution budget can be driven to completion across multiple MCP calls. |

## Requirements

- A Joomla 5 or 6 site
- [cs-mcp-for-j](https://github.com/cybersalt/cs-mcp-for-j) installed and enabled (provides the MCP component + tool framework these plugins register against)
- The third-party extension each plugin wraps must also be installed; the wrapper plugins refuse cleanly on sites without the host extension

## Installing

The easiest path is through the in-admin catalog provided by cs-mcp-for-j: **Components → MCP for Joomla → Browse MCP Add-ons** → click Install on any of these. Catalog metadata, version checks, and download URLs all flow through cs-release-manager on cybersalt.com.

For manual install or development testing, build a standalone zip from this repo (see Building below) and install via **System → Install → Extensions → Upload Package File**.

## Building

`build.ps1` at the repo root produces a standalone Joomla-installable zip for every add-on in the repo. Each add-on carries its own manifest version, so changing one add-on's source and rebuilding only emits a new zip for that one (the others are skipped via the "source unchanged" check).

```powershell
.\build.ps1            # produces dated test builds: <addon>_v<version>_<yyyymmdd>_<hhmm>.zip
.\build.ps1 -Release   # produces stable-named release builds: <addon>_v<version>.zip
```

Requires 7-Zip at `C:\Program Files\7-Zip\7z.exe`.

## License

GPL-2.0-or-later — see [LICENSE.txt](LICENSE.txt). The same license as Joomla itself and cs-mcp-for-j.

## Related repos

- **[cs-mcp-for-j](https://github.com/cybersalt/cs-mcp-for-j)** — the core MCP server component these plugins extend
- **cs-mcp-for-j-addons-pro** (private) — paid wrappers for commercial Joomla extensions (4SEO, RSTicketsPro, etc.), available via a [Cybersalt Pro membership](https://www.cybersalt.com/extensions/pro-membership-activation)
