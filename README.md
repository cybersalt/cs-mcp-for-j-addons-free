# cs-mcp-for-j-addons-free

Free MCP add-on plugins for [cs-mcp-for-j](https://github.com/cybersalt/cs-mcp-for-j) — the Joomla extension that turns a Joomla site into its own MCP server. The core installs as **CS MCP for J** (its Joomla Extensions Directory listing name) and shows as **MCP for J** in the Components menu; it was called "MCP for Joomla" before core 2.9.3.

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
| `plg_system_csmcpforjacymailingfree` | [AcyMailing](https://www.acymailing.com/) by Acyba | 33 tools covering everything Starter can do, on every edition: lists, subscribers and subscriptions (people who unsubscribed are not re-added unless you say so), campaign drafts, confirm-gated test sends and queueing (a dry run by default, with an expected-recipient check), queue pause and cancel, stats, list-overlap / duplicate / Events Booking sync reports, guarded config and a redacted table reader. Nothing mails anyone by default. Essential and Enterprise tools ship in the Pro repo. See below. |
| `plg_system_csmcpforjcommunitybuilder` | [Community Builder](https://www.joomlapolis.com) by Joomlapolis | 32 tools: members (Joomla account and CB profile), registration moderation (approve, reject, confirm, ban, block, with **every CB email held back unless `send_email: true`**), pending registrations, custom fields and their option lists (created and deleted through CB's own `FieldTable`, so the `#__comprofiler` column follows), profile tabs, connections, user reports, lists, CB plugins, validated config writes, a **registration-email audit** that catches an empty `[CONFIRM]` link, and a redacted table reader. CB core only. See below. |
| `plg_system_csmcpforjcpnbfree` | [Cookies Policy Notification Bar](https://www.web357.com/cookies-policy-notification-bar-joomla-plugin) by Web357 | 14 tools: the bar's position, behaviour, colours and button order, consent-cookie settings, the Cookies Manager, menu visibility, cookie categories and declarations, default-language texts, and diagnostics including a rendered-page check. Works with both editions and refuses to write Pro settings on the free build. Pro tools ship in the Pro repo. See below. |
| `plg_system_csmcpforjflexbanners` | [FlexBanners](https://extensions.joomla.org/extension/flexbanner/) by Inch Hosting | 26 tools: banners (list, get, create, update, state, reset counters, delete), locations (ad groups), `mod_flexbanners` modules including **`create_flexbanners_location_module`, which makes a new location plus a cloned module in one call (the "add another row of ads" job)**, click-through links, clients, size presets, impression and click statistics, and a health check that names every banner that is not showing and why. Built against 5.1.10, checked against 6.0.05. See below. |
| `plg_system_csmcpforjmaximenuckfree` | [Maximenu CK](https://www.joomlack.fr/en/joomla-extensions/maximenu-ck) by Cédric Keiflin | 11 tools: modules, styles and their custom CSS (written to the style row the menu actually renders, with the editor's second copy kept in step), the compiled-CSS cache, each menu's mega-menu column layout worked out the way the renderer does, and a panel-overflow check. Works with **Light and Pro**. Writing per-item mega-menu settings is Pro-only and ships in the Pro repo. See below. |
| `plg_system_csmcpforjj2commerce` | [J2Commerce 6](https://www.j2commerce.com) by J2Commerce, LLC | 43 tools across 10 categories: products (the article-backed product row and its variants: list, assembled get, create-from-article, targeted product and variant updates, delete through the vendor's own cascade), tier/group/date prices, stock through J2Commerce's atomic stock writers, orders (list, full get, statuses, status change through the vendor's own `updateOrderStatus` with a side-effect dry run, private or customer-visible notes, tracking), customers assembled from addresses **and** orders, coupons with derived usage, gift vouchers with vendor-derived balances and masked codes, store lookups (currencies, countries/zones, geozones, manufacturers, options, shipping, payment methods with credentials withheld), sales and best-seller reports, and diagnostics including a tax-chain audit for silent zero tax. J2Commerce 6 only, not J2Store 4. See below. |

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

### A note on the J2Commerce add-on

This covers **J2Commerce 6** (`com_j2commerce`, `#__j2commerce_*`), the Joomla 5/6 rewrite. It does not cover J2Store 4 (`com_j2store`), which has a different database. It is free because J2Commerce 6 has no Pro edition gate: `isPro()` gates no capability whatever `version.php` sets it to, and the vendor sells paid apps instead.

**The architecture follows one vendor fact.** J2Commerce's `ProductService::saveProduct()` treats a save as a complete admin form, so every field it is not given is forced to zero: price, weight, dimensions, tax profile, manufacturer, sale limits, and child-variant stock (`src/Model/Behavior/Simple.php:133-166`, `Variable.php:342-367`). No tool here ever calls it with a partial product. Reads are plain SQL. Writes either call J2Commerce's own narrow, side-effect-correct methods (`OrderModel::updateOrderStatus()`, which is exactly what J2Commerce's REST status endpoint calls; `InventoryHelper::adjustStockAndAvailability()`; `VoucherModel::adjustBalance()`; `ProductService::deleteProduct()`) or do a targeted update of the named columns. Tax profiles and geozones are read and audited (`check_j2commerce_tax_setup`) but never written, because J2Commerce saves them by deleting every rule and re-inserting with no transaction.

A J2Commerce product **is a Joomla article**: the article title is the product name, price and SKU live on the master variant, and stock lives in `#__j2commerce_productquantities`. `create_j2commerce_product` attaches a product to an existing article exactly as J2Commerce's own "create from article" action does. Verified on the storefront: the product renders with its price, SKU, stock and add-to-cart button.

Behaviour found by driving a real checkout and admin on 6.6.5:

- **Guest checkout saves no address row**, so J2Commerce's own Customers screen never lists guest buyers. The customer tools build from orders too.
- **"Notify" is recorded even when nothing is sent.** Moving an order to Delivered with notify on a stock install marks it notified, but no template exists for that status. `set_j2commerce_order_status` predicts which templates can fire and reports the "notified with subject" rows J2Commerce writes when mail actually goes out. The administrator-notification row is stored customer-visible.
- **The coupon "User Group" and "Limit to X items" fields are silently discarded.** The form, table and model all handle them, but no 6.6.5 SQL creates the columns. Joomla's database driver drops unknown properties, so the admin save reports success and nothing is stored.
- **Stock release is hardwired to status ids 3, 5 and 6** (`InventoryHelper.php:986-996`), while the rest of J2Commerce treats status ids as install-dependent. Custom statuses always hold stock.
- **The store-wide "Enable inventory" switch does not control stock**: `isManagingStock()` hardcodes it on. Each variant's "Manage stock" decides; the switch only gates unpaid-order cancellation.
- **An advanced price applies only when below the base price**, and `customer_group_id` 0 matches no shopper. Both are refused up front.

**Secrets.** The guest order-view `token`, raw gateway `transaction_details`, stored payment profiles, IPs, `queue_key` (cron secret and payment-hash HMAC key) and the download ID are never returned. Voucher codes are masked unless explicitly revealed with edit permission. J2Commerce's own `GET /v1/j2commerce/config` returns `queue_key` and `downloadid` in cleartext, and its voucher REST list returns full codes.

### A note on the AcyMailing add-on

**The edition is one configuration row.** AcyMailing's Starter, Essential and Enterprise builds are almost the same code. Every feature check calls `acym_level()`, which reads `#__acym_configuration.level` and drops an expired trial back to Starter. This add-on covers everything Starter can do and works on all three editions. Essential and Enterprise features ship in the Pro repo, gated on `acym_level()`. The config tool here refuses to write `level`, the licence fields, or values AcyMailing only allows on a higher edition.

**Nothing mails anyone by default.** Confirmation, welcome, goodbye and admin-notification emails are all off unless a flag asks for them. `queue_acym_campaign` is a dry run unless it gets `dry_run: false`, `confirm: true` and an `expected_recipients` that equals the live, deduplicated count. Test sends need `confirm: true` and go to at most five addresses. Campaigns that use a segment or an A/B test are refused, because their recipient count can't be predicted.

Behaviour worth knowing:

- **Subjects and bodies are stored double-encoded**, so raw SQL shows "café" as "cafÃ©". The tools decode and encode the same way AcyMailing does.
- **A partial save blanks fields.** Saving a list without every field empties its description and translations, and a mail does the same to its access restriction. Write tools load the row first.
- **Under the API, campaign and subscriber deletes silently skip anything the token owner didn't create.** The delete tools report what was actually removed.
- **The subscriber counts stored on each list are a cache and usually wrong.** Every count here is computed live.
- **`check_acym_eventbooking_sync`** checks that Events Booking registrants have reached their AcyMailing list. Syncing them there is the practical fix for Events Booking's Mass Mail not deduplicating across events.
- **It can't be installed through `install_extension`.** AcyMailing's package script calls the router, which doesn't exist under the API app. Install it from the Joomla admin.

Tool names use the `acym_` token: `php tests/verify-addon.php plg_system_csmcpforjacymailingfree acym_`.

### A note on the Community Builder add-on

**CB's own code does not survive Joomla's API application as-is.** Every CB email builds its `[CONFIRM]` link through `getMenu()`, which throws under the ApiApplication. Approving a member would store the approval, unblock them, and then die before the welcome email. So every CB call here runs inside a temporary AdministratorApplication, which is the context CB's moderation code is written for.

**No tool sends mail by accident.** A listener on CB's own `onBeforeSendMail` event holds back every email unless the caller passes `send_email: true`. Every attempted email is returned in the response as CB built it, with confirmation codes and generated passwords masked. Under the API app CB's site URL ends in `/api`, so the listener also rewrites every link in the email before anything is sent.

Behaviour found by driving CB 2.12.0 live:

- **With "Generate Registration Passwords" on, approving or confirming a member replaces their password.** Only the welcome email delivers the new one, so those tools refuse unless `send_email` is true.
- **`[CONFIRM]` renders as nothing when email confirmation is off.** `get_cb_registration_config` flags it, and also catches confirmation with no Pending email, generated passwords that no email delivers, and approval alerts that no moderator receives.
- **Deleting a field drops its column and every member's answer**, despite a comment in `FieldTable::delete()` saying the data is kept. `delete_cb_field` requires `confirm_data_loss`.
- **The first CB save of a user created outside CB writes a stray activation code into `#__users.activation`.** `update_cb_user_fields` first creates the profile row the way CB's Synchronize Users does.
- **Members store an option's text, not its id**, and CB's option save deletes whatever isn't resubmitted. So `set_cb_field_values` takes the complete list and refuses to orphan members' answers.
- **A CB ban hides the profile but does not stop login.**

CB core only; CB's paid add-ons are not covered. The confirmation code, registration IPs, password columns, CB plugin params and the Joomlapolis install key are never returned. Tool names use the `cb_` token: `php tests/verify-addon.php plg_system_csmcpforjcommunitybuilder cb_`.

### A note on the Cookies Policy Notification Bar add-on

**Covers what the free edition can do, and works on both.** The edition is read from the manifest's `<variant>` tag and Web357's own `isProVersion()` flag (the flag wins if they disagree). Blocking rules, consent logs, GeoIP, multilingual texts and import/export are Pro features and ship in the Pro repo. On a free install, the tools here refuse to write Pro-only settings.

**If CPNB's system plugin is disabled, its own settings save wipes the configuration.** One save took the settings from 434 keys to 2, and the dashboard still looked normal. The tools pass the stored settings to the save themselves, and refuse unknown keys because CPNB drops them silently. `check_cpnb_frontend` fetches the home page and checks that the bar reflects the saved settings.

### A note on the FlexBanners add-on

**On Joomla 6, FlexBanners itself needs "Behaviour - Backward Compatibility 6".** In both 5.1.10 and 6.0.05, `FlexbannersComponent.php` opens with `defined('JPATH_PLATFORM') or die`, and Joomla 6 defines that constant only in the compatibility plugin. Without it the component dies silently on load: a page showing banners returns HTTP 200 containing nothing but `<div class="flexbannergroup">`. If the module gets there first, it fails with `Class "JRoute" not found`. `check_flexbanners_health` reports both. The tools themselves never boot the component, so they work either way.

**Every banner tool says whether the banner will actually show.** mod_flexbanners' query is strict and silent. It requires a valid size and category, `state = 1` and `finished = 0`, and a language of `*` or the site's *default* language, not the page's. It also shows exactly one location per module. The tools apply the same rules and name the one that fails.

**Writes are targeted SQL.** FlexBanners' own banner save can't be driven from outside its admin form. It stores every article restriction as article 0, always clears "finished", and turns an unknown category id into a new category. Module rows go through Joomla's own module model. `create_flexbanners_location_module` makes a new location and a cloned module in one call, which is the "add another row of ads" job.

Other behaviour:

- **Expiry and limits:** 5.1.x never enforces end dates or impression/click limits. 6.x enforces them on every page view and re-publishes unpublished banners whose start date has passed.
- **Clicks aren't bot-filtered.** Impressions are, so click-through can exceed 100%.
- **Statistics are lifetime totals.** FlexBanners keeps no per-day history.

### A note on the Maximenu CK add-on

**Covers Light and Pro; per-item mega-menu writes are Pro.** Light and Pro are separate builds, and the edition is read from the `<ckpro>` flag in the installed component manifest, the way Maximenu's own installer reads it. In Light's menu manager, every per-item mega-menu control (columns, widths, rows, submenu size, icons, modules) shows "Pro only" instead of saving. So this add-on *reads* those settings, and writing them ships in `csmcpforjmaximenuckpro`.

**Custom CSS lives twice on the style row.** `customcss` holds the raw copy, where `|ID|` becomes the bare menu id, so only `#|ID|` rules work there. Maximenu's style editor also appends a second copy to the end of `layoutcss`, where only bare `|ID|` rules work. Writing `customcss` alone leaves the old rules live. `write_maximenuck_style_custom_css` keeps both copies in step, and a later editor re-save leaves the row byte-identical. The module's own `customcss` param is dead code in v9+.

**The compiled CSS file has no cache-buster.** Maximenu links `maximenuck_<id>.css` with no version query, so a CDN keeps serving the old file. With "Load compiled CSS = Yes", the file isn't rebuilt at all until it's deleted. Every CSS write and `clear_maximenuck_compiled_css` return the exact URL to purge.

**Column arithmetic:** the panel width is the sum of every column's width across all rows. The first child must be marked as a column start, or column 1 is unsized and the last columns drop underneath. `check_maximenuck_panel_overflow` works the panel width out the way the renderer does.

## Requirements

- A Joomla 5 or 6 site
- [cs-mcp-for-j](https://github.com/cybersalt/cs-mcp-for-j) installed and enabled (provides the MCP component + tool framework these plugins register against)
- The third-party extension each plugin wraps must also be installed; the wrapper plugins refuse cleanly on sites without the host extension

## Installing

The easiest path is through the in-admin catalog provided by cs-mcp-for-j: **Components → MCP for J → Browse MCP Add-ons** → click Install on any of these. Catalog metadata, version checks, and download URLs all flow through cs-release-manager on cybersalt.com.

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
