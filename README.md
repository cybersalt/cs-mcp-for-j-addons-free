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
| `plg_system_csmcpforjatsfree` | [Akeeba Ticket System](https://www.akeeba.com/products/ats.html) by Akeeba Ltd | 30 tools over the Core (free) edition's surface: tickets (list/get/create/update, status, public/private, assignment, delete), posts — the replies, including each ticket's opening message — invited collaborators, ticket managers and assignees, categories with their ATS-specific params, statuses, triage reporting (summary, stale tickets, per-agent workload), config and health diagnostics, plus a scoped table reader. Writes go through ATS' own `AdminModel`s so the status matrix, permission checks and notification emails all fire. Works with **both the Core and Professional editions**, detecting which via the vendor's own `ATS_PRO` constant. Pro-only tooling ships separately in the Pro add-on repo. See below. |

### A note on the Akeeba Ticket System add-on

**The most important thing about this add-on is a table it refuses to read.**

`#__ats_managernotes` holds private, staff-only commentary on tickets. It is a Pro feature, and on a Core install the natural assumption — the one that holds for every other Pro-only ATS table — is that it is empty. It isn't necessarily. ATS creates every table unconditionally at install time with no edition branching at all, and a site that ran Professional and later dropped to Core **keeps every note it ever wrote**, because a downgrade removes code, not data.

ATS itself fails closed there. `TicketTable::managerNotes()` returns `[]` on the edition check *before* it builds a query, so on Core those rows are invisible in the UI no matter who is looking. A generic `SELECT` would not fail closed. So this add-on has no manager-notes tool, the table is absent from `query_ats_table`'s allowlist and is refused by name rather than by a generic "unknown table", and `check_ats_health` reports only a **count** of such rows — never their content — so an operator learns their site is holding data the UI will never show them, without the tool being the thing that discloses it.

**The edition gate is a constant nobody defines for us.** Roughly thirty-five places in ATS branch on `defined('ATS_PRO') && ATS_PRO`. That constant is set by `Dispatcher::loadVersion()`, which runs only when Joomla dispatches `com_ats` through its own entry point — which an MCP tool call never does. Left alone the constant is simply *absent*, and absent reads as Core everywhere: on a genuine Pro site we would report attachments and manager notes as unavailable on an install that has them, and `ControlpanelModel.php:67` reads the constant bare, which on PHP 8 is a fatal rather than a notice. The boot trait therefore replicates `loadVersion()` exactly, `version.php` include and `is_dir(src/CliCommand)` fallback both. It never defines `ATS_PRO` to `'1'` on its own initiative — re-implementing the vendor's gate is the point, and the Pro-only surface belongs in the Pro add-on, not in a workaround here.

**Booting the component is not optional.** ATS ships its own `vendor/` tree and the only place it is ever required is `services/provider.php:37`. HTMLPurifier lives in there and `Helper\Filter::filterText()` is on the save path for every ticket and post. Touch an ATS class without `bootComponent('com_ats')` first and the write fatals on a missing class with nothing in the log to explain it.

**Three columns that don't mean what they say.** A ticket's `modified` / `modified_by` are "last reply", not "last edited" — the usual assignment is commented out in both `TicketTable::onBeforeStore()` and `TicketModel::prepareTable()`, with a comment saying so, which is why the reports compute real activity from the newest enabled post instead. `#__ats_tickets.timespent` is derived, recomputed by `PostTable::onAfterStore()` as a `SUM` over *enabled* posts, so writing it directly is pointless and unpublishing a post only reduces the ticket total on the next reply. And `#__ats_posts.attachment_id` is a comma-separated list in a singular-named `VARCHAR(512)`, not a foreign key — it can disagree with `#__ats_attachments.post_id`, which is an `INT(11)` pointing at a `bigint`.

**Posting to a closed ticket is very nearly a no-op**, and silently so: `onAfterStore()` skips the whole status / `modified` / `timespent` block when the status is already `C`. The row lands, nothing else moves, and nobody is notified. `add_ats_post` surfaces that rather than letting a reply disappear into a closed ticket.

**Tickets have no `access` and no `language` column** — both are inherited from the category — so no write tool offers to set them. And because `catid` is a bare `bigint` with no foreign key, every category join carries `extension = 'com_ats'`; without it, a ticket pointing at a `com_content` category joins happily and reports nonsense.

**Visibility is never hand-rolled.** Every read tool runs each candidate row through `Permissions::getTicketPrivileges()` even when the list query already filtered it. ATS 5.6.0 shipped specifically to fix a disagreement between the list query and the single-resource check, where a ticket the collection endpoint correctly omitted was served happily by the single-resource endpoint through the JSON:API — private tickets leaked. Re-deriving that predicate by hand is how you re-create that bug.

**Deliberate omissions:** everything Pro-gated in the vendor's own code — attachments, canned replies, auto-replies, manager notes, user tags (distinct from ticket tags, which are Joomla-native and Core), the Timecard, the Log, scheduling/CRON, the email-template manager UI and the mail gateway. Also the `onAts*` plugin events, which only fire on Pro, and the usage-statistics collector, which is anonymous phone-home to Akeeba rather than a reporting feature.

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
