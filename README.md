# cs-mcp-for-j-addons-free

Free MCP add-on plugins for [cs-mcp-for-j](https://github.com/cybersalt/cs-mcp-for-j) — the Joomla extension that turns a Joomla site into its own MCP server.

Each plugin in this repo is a standalone Joomla system plugin that subscribes to cs-mcp-for-j's `onCsMcpRegisterTools` event and registers a set of MCP tools wrapping a specific third-party Joomla extension.

## What ships here

| Folder | Extension wrapped | Tools |
|---|---|---|
| `plg_system_csmcpforjakeebabackup` | [Akeeba Backup Core](https://www.akeeba.com/products/akeeba-backup-core.html) | Backup orchestration: list profiles, list / start / step / inspect / delete backups, manage archives. |
| `plg_system_csmcpforjreleasemanager` | [Cybersalt Release Manager](https://www.cybersalt.com/extensions/cs-release-manager) | Manage your own extension releases: list / get / create / update / delete Packages and PackageVersions, plus read-only views of installations and the activity log. |
| `plg_system_csmcpforjstageit` | [StageIt](https://www.php-web-design.com/products/stageit) | Staging environment orchestration: get status / prechecks / list backups, plus chunked start+continue tools for deploy / sync / remove / restore-backup. Each long-runner returns a resume_token so operations bigger than the PHP execution budget can be driven to completion across multiple MCP calls. |
| `plg_system_csmcpforjdpcalendar` | [DPCalendar Core](https://joomla.digital-peak.com/products/dpcalendar) by Digital Peak | ~43 tools covering the full DPCalendar Core surface: calendars, events (with recurrence), bookings, tickets (with check-in/out), locations, coupons, external calendar feeds (Google/iCal/CalDAV), tax rates, country lookup, plus dashboard + per-event booking + revenue reports. Writes go through DPCalendar's own AdminModels so notifications, capacity bookkeeping, fields, and tags fire correctly. |
| `plg_system_csmcpforjconvertformsfree` | [Convert Forms](https://www.tassos.gr/joomla-extensions/convert-forms) by Tassos Marinos | 30 tools: forms (list/get/create/update/duplicate/publish/delete), individual form fields (add/update/delete/reorder, with the field-type registry discovered from the install), submissions (list/get/correct/set state/delete, with answers resolved to their field labels), Tasks — the on-submission engine behind email notifications and app integrations — plus connections, task history and reporting. Works with **both the free and Pro editions**, and detects which one it is: Pro-only field types and apps light up automatically where present and are reported as locked where they are not. Pro-specific tooling ships separately in the Pro add-on repo. |
| `plg_system_csmcpforjpagebuilderckfree` | [Page Builder CK](https://www.joomlack.fr/en/joomla-extensions/page-builder-ck) by Cédric Keiflin | 30 tools across 7 categories over the five `#__pagebuilderck_*` tables: pages (CRUD, state, duplicate), the block tree parsed out of the `htmlcode` column (outline, per-block read, whole-content write, and surgical add / update / move / delete), rows and columns, styles and the page-to-style link, categories, saved elements, fonts (read-only), the `.pbck` backup history with restore, and a site-wide health audit. Works with **both the Light and Pro editions** and reports which it found. Pro-only tooling ships separately in the Pro add-on repo — and additionally refuses unless the vendor's own Pro build is installed. See below. |

### A note on the Page Builder CK add-on

Light and Pro are the **same codebase** — same `com_pagebuilderck` element, same version number, same five tables. The manifest carries `<ckpro>0</ckpro><variant>free</variant>`, and the entire Pro licence system is one line in `administrator/helpers/pagebuilderck.php`:

```php
if (file_exists(PAGEBUILDERCK_PATH . '/pro')) { … }
```

No key, no download id, no phone-home. Unlike SP Page Builder — whose `isProVersion()` is hardcoded `true` in the Free build and therefore lies — this probe is honest, so one add-on serves both editions cleanly.

Three findings shaped the design.

**Content is raw HTML, not JSON.** A page is markup in a `htmlcode` column, and every block option is an HTML *attribute* on an empty sibling `<div class="ckprops">`, indexed by a `fieldslist` attribute. There is no encoder to normalise what gets written, so the parser contract is load-bearing. The tools parse with Page Builder CK's **own bundled `simple_html_dom`** rather than DOMDocument or a vendored copy — it is GPL, already on disk, and is literally the parser the renderer uses. Two of that library's *default* arguments corrupt real stored pages: `$lowercase` rewrites the `viewBox` in the Tabler SVG icons that ship inside real content (SVG attribute names are case-sensitive), and `$stripRN` eats the whitespace between blocks. Even at the correct flags the parser drops trailing whitespace, so the edges are captured and re-applied by hand. That combination round-trips all four of the vendor's own shipped `.pbck` sample pages byte-for-byte, and every write additionally re-checks losslessness first and refuses rather than silently altering markup nobody asked to change.

**A missing addon fails OPEN, not blank.** This is the opposite of SP Page Builder. When no enabled plugin provides a block's `data-type`, `renderElement()` returns `''` (`site/models/page.php:645-646`) and `replaceElement()` falls through to `return $e->innertext` (`:575`) — so the block renders its inner markup as static HTML with its CSS still applied, with no error and no log line. Light ships 11 of the 29 addons, and `heading` and `button` are Pro-only. A page using them looks almost right while being inert. The rendered output therefore cannot tell you an addon is missing, so the tools cross-check every `data-type` against the enabled plugins and **warn rather than refuse** — the content is not broken, only inactive.

**Style options written by a tool are inert until a human re-applies.** `administrator/helpers/stylescss.php`, which turns `.ckprops` attributes into a block's `#id`-scoped CSS, is included only by admin views and the editor's AJAX endpoints — never by the site renderer. So changing a padding, background or effect attribute changes nothing visible until someone opens the page in the builder and saves. *Functional* options are different and apply immediately, because the addon's render handler reads them at render time. Every tool that writes options says which kind it just wrote.

Three quirks the tools absorb rather than hide:

- **There is no `styles` column.** The vendor's admin model reads and writes one, but it does not exist and the write is silently dropped. The real page-to-style association is a comma-separated id list in `div.pagebuilderckparams[data-styles]` *inside* `htmlcode`.
- **The editor destroys out-of-band column writes.** `controllers/page.php:59-67` hardcodes `alias`, `ordering`, `state`, `catid`, `created_by` and `access` on every save, so anything written there survives only until the next human Save. Every tool touching those columns says so in its response.
- **The `.pbck` snapshot is taken *before* its own save**, two lines above the store, so the newest backup is the *previous* version — "restore the latest backup" is an off-by-one that silently discards a save. A direct column write makes no snapshot at all.

`#__pagebuilderck_styles` uses `text` (64 KB), not `longtext`, so oversized style content is refused up front rather than silently truncated by MySQL; and all five tables are `utf8mb3`, so astral characters are rejected before they can truncate a value mid-write.

**Security note.** Page Builder CK below **3.6.5** carries known, actively exploited remote-code-execution flaws. CVE-2026-56290 (CVSS 10.0) is on the CISA Known Exploited Vulnerabilities list with public mass-exploitation tooling, and 3.6.0 only partially closed it — 3.6.3 and 3.6.5 fixed two further RCE/SQLi issues. The installer warns on any version below 3.6.5. Font handling is the attack surface, which is why `list_pagebuilderck_fonts` is read-only and there is deliberately no font write tool.

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
