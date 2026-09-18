# Changelog — MCP add-on for Akeeba Ticket System

## 🚀 Version 1.0.0 (September 17, 2026)

First release. 30 tools wrapping Akeeba Ticket System (`com_ats`) by Akeeba Ltd,
covering the Core (free) edition's surface. Studied against **ATS 5.6.0** read from a
live Core install.

### ✨ New

- **Tickets** — `list_ats_tickets`, `get_ats_ticket`, `create_ats_ticket`,
  `update_ats_ticket`, `set_ats_ticket_status`, `set_ats_ticket_public`,
  `assign_ats_ticket`, `delete_ats_ticket`.
- **Posts** — `list_ats_posts`, `get_ats_post`, `add_ats_post`, `update_ats_post`,
  `set_ats_post_state`, `delete_ats_post`. A ticket's opening message is a post row,
  not a ticket column.
- **Collaborators** — `list_ats_ticket_users`, `invite_ats_user`, `remove_ats_invite`.
  Invitations look like a premium feature and are not: `#__ats_tickets_users` is in the
  base install SQL and the whole path is ungated.
- **People** — `list_ats_managers`, `list_ats_assignees`.
- **Lookups** — `list_ats_categories`, `get_ats_category`, `list_ats_statuses`.
- **Reports** — `get_ats_ticket_summary`, `list_ats_stale_tickets`,
  `get_ats_agent_workload`.
- **Config + diagnostics** — `get_ats_component_info`, `get_ats_config`,
  `check_ats_health`.
- **Scoped table access** — `list_ats_tables`, `query_ats_table`.

### 🏗 Architecture

- **The edition gate is replicated, not assumed.** Around thirty-five places in ATS
  branch on `defined('ATS_PRO') && ATS_PRO`. The constant is defined by
  `Dispatcher::loadVersion()`, which runs only when Joomla dispatches `com_ats`
  through its own entry point — something an MCP tool call never does. Left alone the
  constant is *absent*, and absent reads as Core at every one of those call sites. On a
  real Pro install that would mean reporting attachments and manager notes as
  unavailable on a site that has them, and `ControlpanelModel.php:67` reads the
  constant bare, which is a PHP 8 fatal rather than a notice. `ATSBootTrait`
  reproduces `loadVersion()` exactly — the `version.php` include *and* the
  `is_dir(src/CliCommand)` dev-build fallback — and never defines `ATS_PRO` to `'1'`
  on its own initiative.

- **`#__ats_managernotes` is excluded from the add-on entirely, on purpose.** Every
  other Pro-only ATS table is created unconditionally by the installer and is simply
  empty on Core, so reading it is harmless. Notes are the exception: a site downgraded
  from Professional keeps every private note it ever wrote, because the downgrade
  removes code and not data. ATS fails closed — `TicketTable::managerNotes()` returns
  `[]` on the edition check before it builds a query — but a generic `SELECT` would
  not. The table is off `query_ats_table`'s allowlist and refused by name;
  `check_ats_health` reports a **count** of orphaned notes and never their content.

- **`bootComponent('com_ats')` before touching anything.** ATS ships its own `vendor/`
  tree, required in exactly one place — `services/provider.php:37`. HTMLPurifier lives
  there and `Helper\Filter::filterText()` is on the save path for every ticket and
  post, so skipping the boot fatals the write on a missing class with nothing logged.

- **Writes go through `TicketModel` / `PostModel`.** Both are genuine Joomla
  `AdminModel`s reachable through the component's MVCFactory, and unusually for a
  wrapped extension ATS is already API-application-aware — it carries explicit
  `isClient('api')` branches and an API-specific `getForm()` fix, because Akeeba ship
  a JSON:API plugin in Pro. `save()` loads-then-binds, so a partial payload is
  non-destructive.

- **Mail-sending writes are wrapped in a `SiteApplication` context.** Adding a post,
  assigning a ticket and inviting a collaborator all build ticket URLs for the mail
  body, and URL building under the API application fails with
  "Error loading menu: api". Same remedy as the RSTicketsPro add-on — with one
  addition that add-on did not need: **the swapped application is passed the calling
  actor's identity.** A `SiteApplication` built out of the DI container has no identity
  loaded and reads as a guest, and `Permissions::getUser()` with no argument resolves
  to `Factory::getApplication()->getIdentity()` (`Permissions.php:1003-1013`), which is
  what nearly every ACL decision in ATS runs through. Without `loadIdentity()` the
  authenticated caller silently becomes anonymous inside the closure: invitations 403,
  and — worse, because it is not an error — a manager's reply is recorded as a
  stranger's and the new-post status matrix lands the ticket in the wrong state.

- **Notifications are the controller's job, not the model's, so this add-on fires them
  itself.** Nothing in `PostModel::save()` or `PostTable::onAfterStore()` sends mail;
  `PostController::postSaveHook()` calls `PostNotification::notify()`.
  `PostNotification.php:23-28` says so explicitly — before 5.6.0 this lived on a
  controller trait, so "anything that creates a post outside a controller sent no
  notifications at all". **A model-only save is a silent reply**, which is precisely
  the failure an MCP tool would ship by default. `add_ats_post` and `invite_ats_user`
  replicate the controller's notify step, guarded by `class_exists` for pre-5.6.0
  installs.

- **The primary key is re-injected after form validation on a post edit.**
  `forms/post.xml` declares no `id` field, and `AdminModel::validate()` returns only
  keys the form knows about — so the id does not survive filtering, `save()` calls
  `$table->load(null)` (which Joomla returns `true` from *without loading*), the bind
  lands on an empty row and `store()` **INSERTs**. The symptom is the worst available:
  the edit reports success, the original post keeps its text, and the ticket silently
  gains a duplicate reply. This was caught by driving the tool against a live install,
  not by reading — two "edits" produced posts 7 and 8 while post 3 stood unchanged.
  Static checks cannot see it and a unit test against the model would have reproduced
  the same wrong behaviour happily.

  ATS already knows about this hazard and works around it one field at a time:
  `PostModel::validate()` reads `$data['id']` to set `post.isNew` *before* delegating
  to the parent, then re-injects `created_by` afterwards for exactly the same reason
  (`post_new.xml` has no `created_by` field either). `update_ats_post` does the same
  for the key itself, **and** refuses outright if the saved row comes back under a
  different id — a tool that cannot edit must never report that it did.
  `forms/ticket.xml` *does* declare `id`, which is why `update_ats_ticket` was never
  affected; that asymmetry is the reason this needed testing rather than reasoning.

- **Visibility is delegated, never re-derived.** Every read tool gates each candidate
  row through `Permissions::getTicketPrivileges()` even when the list query already
  filtered. ATS 5.6.0 shipped to fix a disagreement between the list query and the
  single-resource check that leaked private tickets through the JSON:API; hand-rolling
  the predicate is how that bug gets re-created.

### 📝 Vendor behaviour worth knowing

- **`modified` / `modified_by` on a ticket mean "last reply", not "last edited".** The
  usual assignment is commented out in both `TicketTable::onBeforeStore()` and
  `TicketModel::prepareTable()`, with a comment explaining why. Reports compute real
  activity from the newest enabled post instead.
- **`#__ats_tickets.timespent` is derived**, recomputed by `PostTable::onAfterStore()`
  as `SUM(timespent) WHERE ticket_id = :id AND enabled = '1'`. Writing it directly is
  pointless, and because of the `enabled` filter, unpublishing a post reduces the
  ticket's total only when the *next* reply triggers the rollup.
- **Posting to a closed ticket is nearly a no-op — but it still mails.**
  `PostTable.php:291` guards the whole block with
  `if ($this->isNewPost && $ticket->status != 'C')`, so a reply to a closed ticket gets
  no status assignment, no `modified` / `modified_by`, no `timespent` rollup and no
  auto-assign. Notifications are outside that block and go out regardless. `add_ats_post`
  requires an explicit `acknowledge_closed_ticket` rather than refusing outright,
  because `Permissions.php:713-727` already collapses every privilege but `view` to
  false for a non-manager on a closed ticket — so the only caller who can reach the
  path is a manager, for whom ATS' own UI permits it.
- **The `siteurl` param is not what our notification mail uses.**
  `EmailSending.php:703` resolves the `{siteurl}` token as
  `$app->isClient('site') ? Uri::base() : $params->get('siteurl')`, and every
  mail-sending tool here runs inside the site-application wrapper — so the param is
  bypassed and links come from `Uri::base()`. An empty `siteurl` is still a real latent
  misconfiguration, affecting ATS' own non-site contexts (Pro CLI tasks, scheduled
  auto-close and auto-reply, the mail gateway), and `check_ats_health` reports it as
  exactly that rather than claiming the mail we just sent is broken.
- **The post edit grace window is honoured, and ATS computes it twice, differently.**
  `Permissions.php:851-878` measures `editeableforxminutes` (default 15) from `created`
  and grants it only to the post's own author; `editGraceTime()` at `:155-172` measures
  from `modified` when you were the last editor and is used only to decide whether to
  draw a button. `update_ats_post` gates on the privilege array rather than binding a
  table directly to route around it, and its refusal message says which window closed.
- **`set_ats_post_state` leaves the ticket's `timespent` stale on purpose.**
  `Table::publish()` is a direct UPDATE — no store, no `onAfterStore`, no rollup — and
  an edit would not help either, since the rollup is gated on `isNewPost`. The tool
  returns `timespent_stored` alongside `timespent_if_recomputed` rather than pretending
  the two agree.
- **Two vendor typos that are currently harmless but will mislead a reader.**
  `PostModel::canDelete()` / `canEditState()` both contain
  `isset($record->ticket_id) && empty($record->ticket_id)` (`PostModel.php:199`, `:218`)
  — inverted, so `setTicket()` never fires; harmless only because
  `PostTable::getTicket()` lazy-loads. And `PostModel.php:90` reads
  `$timeSpentMandatory = !$timeSpentHidden && $cParams->get('timespent_mandatory', 0) != 1`,
  a double negative that makes turning "Time Spent Mandatory" **on** the thing that
  stops the field being required.
- **The new-post status matrix is deliberate and easy to get wrong.** A manager's reply
  on someone else's ticket sets `P` ("waiting for the customer"); the owner's, an
  invited collaborator's, or a manager's reply on their own ticket sets `O`; an
  automated notice (`created_by <= 0`) leaves it alone. The vendor's comment warns
  against rewriting it as a test for "anyone other than the ticket owner", which was
  the bug it replaced.
- **Tickets have no `access` and no `language` column** — both inherited from the
  category — and no `checked_out` either.
- **`priority` is `TINYINT NOT NULL` with no default**, so an `INSERT` omitting it
  errors under `STRICT_TRANS_TABLES`. Priorities are also hidden by default: the
  `ticketPriorities` param defaults to `0` and `TicketModel::getForm()` removes the
  field when it is off.
- **`catid` is a bare `bigint` with no foreign key**, so every category join must carry
  `extension = 'com_ats'` or a ticket pointing at a `com_content` category joins
  happily and reports nonsense.
- **`#__ats_tickets_users` has no unique index and no index at all.** Duplicate
  invitations are prevented only in PHP, and `TicketTable::onAfterDelete()` does not
  clean the table, so deleting a ticket orphans its rows. Both are health checks.
- **`#__ats_posts.attachment_id` is a comma-separated list** in a singular-named
  `VARCHAR(512)`, not a foreign key, and can disagree with
  `#__ats_attachments.post_id` — which is an `INT(11)` pointing at a `bigint`.
- **`created_by <= 0` on a post is the synthetic "system" author** and does not join to
  `#__users`. `Permissions::getUser(-1)` and `EmailSending::getPostEmailData()`
  fabricate *two different* system users with two different email addresses.
- **The component's manifest version can lie.** On the build studied, `com_ats/ats.xml`
  says `5.5.2` while `version.php` and `pkg_ats` both say `5.6.0`, and the code is
  demonstrably 5.6.0. Never key a capability check off `#__extensions.manifest_cache`
  for `com_ats`; read `ATS_VERSION` or `pkg_ats` instead.
- **`#__ats_cannedreplies.access` defaults to `0`**, which is not a valid Joomla view
  level. Only upgraded sites got the `UPDATE … SET access = 1` fixup; fresh installs
  keep the `0`.
- **`TicketTable::load()` is not read-only.** `onAfterLoad()` calls
  `ensureUcmRecord()`, which INSERTs a `#__ucm_content` row when one is missing
  (`TicketTable.php:710-719, 1008-1039`). It self-disables above Joomla 5.4, so it is
  inert on Joomla 6 — but on any Joomla 5.x host a listing tool would write a row per
  ticket simply by reading. Both read tools therefore `SELECT` the row and hand it to
  the vendor's own `bindAsLoadEquivalent()` (added in 5.3.10 for exactly this) rather
  than calling `load()`. Worth knowing that testing on a Joomla 6 site alone would
  never have caught it.
- **A numeric ticket status must be compared as a string.** `status = 7` makes MySQL
  treat the value as an ENUM *ordinal* and match the wrong status; `status = '7'` is
  correct. The ENUM permits `'1'`–`'99'` unconditionally — the whitelist is the
  `customStatuses` component param, not the schema — so deleting a custom status
  leaves tickets holding the code with no label. `list_ats_statuses` and
  `get_ats_category` flag those as orphaned.
- **`Permissions::getStatuses()` returns a flat `code => label` map**, not a
  `value`/`text` option list, and the custom entries carry *integer* keys (cast at
  `Permissions.php:567`) while the ENUM stores them as strings. It also appends `'C'`
  last deliberately — "The Closed status must always be AFTER any custom status".
- **`getTicketPrivileges()`'s docblock under-reports its own return value**: beyond the
  documented keys it also returns `private`, `ticket.assign`, `ticket.assignee` and
  `ticket.invite`. `get_ats_ticket` returns the whole array rather than a curated subset.
- **ATS contradicts itself about whether managers are bound by category access.**
  `getPostableCategories()` / `getManagerCategories()` skip the view-level filter for
  anyone with `core.admin` / `core.manage` on `com_ats` (`Permissions.php:319-325,
  502-509`), while `getTicketPrivileges()` since 5.6.0 applies it to everyone. A Super
  User can therefore be entitled to create a ticket in a category whose tickets they
  cannot read. The category tools expose `can_access` / `can_create` / `is_manager`
  separately so that is visible rather than baffling.
- **A genuine vendor bug, routed around rather than inherited.**
  `TicketsModel::getListQuery()` lines 405-411 swap an inverted date range with
  `$temp = $to; $to = $since; $since = $to;` — the last assignment should read
  `$since = $temp`. Both bounds collapse to the original `since`, so an inverted range
  matches almost nothing instead of being corrected. Our tools use independent
  `created_after` / `created_before` clauses and do not go through it.
- **`migrateLegacyCustomFieldsData()` is worse than "getForm() writes".** It is a
  hand-rolled reimplementation of Joomla's `FieldModel::setFieldValue()` written
  specifically to bypass the ACL check — the vendor's comment says "I need to migrate
  the data regardless of the current user… If a public ticket is accessed by a guest I
  still need to migrate its data" (`TicketModel.php:1522-1596`). So calling `getForm()`
  on a ticket carrying ATS 1.x–4.x field data mutates `#__fields_values` as a side
  effect of a read. `get_ats_ticket` reads `#__fields` / `#__fields_values` directly
  instead, honours Joomla's own category-assignment rule, and filters by the actor's
  view levels.

### 📦 Build

- Requires PHP 8.2+ (constants declared inside a trait).
- No admin UI by design — the tools appear in connected MCP clients automatically.
