# MCP for AcyMailing — Changelog

`plg_system_csmcpforjacymailingfree`. Versions are independent of every other
add-on in this repo and of cs-mcp-for-j core.

## 📝 Unreleased (ships with this add-on's next release)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.

## 🚀 Version 1.0.2 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for AcyMailing"** in Extensions → Plugins (was "System - MCP for Joomla: AcyMailing (Starter) add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- 🧹 `update_acym_list` reports a failed save's return value with `json_encode()` instead of `var_export()`, which the JED Checker flags as debug output. The message reads the same.
- No tool behaves differently.

## 🚀 Version 1.0.1 (October 20, 2026)

- 🐛 **`check_acym_eventbooking_sync` now checks the right plugin.** Its `bridge_plugin` line looked at `plg_acymailing_eventbooking`, which is an old AcyMailing 5 tag plugin that inserts event listings into newsletters and adds nobody to any list. The plugin that actually adds Events Booking registrants to AcyMailing lists is Joomdonation's **Events Booking - AcyMailing** (`plg_eventbooking_acym`), so a site could be told "enabled" while the real sync was off, or the other way round. The line now reports `plg_eventbooking_acym`, and a new `bridge` block shows its default lists, whether the checked list is one of them, and whether registrants must opt in to the newsletter first. If the old tag plugin is also installed, the report says what it is.
- ✨ **Reports events that feed no list.** The sync plugin takes its lists from each event's own setting and only falls back to the plugin's Default Lists when the event has none. With no default set, a new event that nobody configured adds its registrants to no list, and nothing says so. The new `event_list_settings` block counts the published events in scope that feed the checked list, feed only other lists, or feed **no list**, with upcoming events listed first, and `findings` calls out the gaps. Still read-only.

## 🚀 Version 1.0.0 (October 19, 2026)

First release. 33 tools for **AcyMailing** (`com_acym`) by Acyba, covering
everything AcyMailing **Starter** can do, on every edition. Built from the
10.11.1 Starter / Essential / Enterprise and 11.1.0 Enterprise packages and
driven live on Joomla 6.2.0 / PHP 8.3 against Starter 10.11.1, Essential
10.11.1 and Enterprise 11.1.0. Capabilities AcyMailing reserves for Essential
or Enterprise are in the separate Pro add-on, which shares no tool name.

### ✨ New

- **Diagnostics** — `get_acym_component_info`, `check_acym_health`,
  `get_acym_config`, `set_acym_config`, `list_acym_tables`, `query_acym_table`.
- **Lists** — `list_acym_lists`, `get_acym_list`, `create_acym_list`,
  `update_acym_list`, `delete_acym_list`.
- **Subscribers** — `list_acym_subscribers`, `get_acym_subscriber`,
  `create_acym_subscriber`, `update_acym_subscriber`, `delete_acym_subscriber`,
  `subscribe_acym_subscribers`, `unsubscribe_acym_subscribers`.
- **Reports** — `get_acym_list_membership_report`,
  `check_acym_subscriber_duplicates`, `check_acym_eventbooking_sync`,
  `get_acym_campaign_stats`.
- **Campaigns** — `list_acym_campaigns`, `get_acym_campaign`,
  `create_acym_campaign`, `update_acym_campaign`, `delete_acym_campaign`,
  `list_acym_templates`.
- **Sending** — `send_acym_test_email`, `queue_acym_campaign`,
  `get_acym_queue_status`, `cancel_acym_campaign_sending`,
  `set_acym_campaign_paused`.

### 🛡 Nothing mails anyone by accident

AcyMailing's own classes send mail as a side effect in more places than the
admin UI suggests, and under Joomla's API application the "admin saves never
send" exemption (`acym_isAdmin()`) does not apply:

- `UserClass::save()` ends with `sendConfirmation()` — creating an unconfirmed
  subscriber mails the double-opt-in email.
- `subscribe()` mails each list's welcome email; `unsubscribe()` mails the
  goodbye email **and** the admin "unsubscribed" notification.

Every one of those is off by default and has an explicit opt-in flag. The only
tools that can reach an inbox are `send_acym_test_email` (max 5 explicit
addresses, `confirm: true`) and `queue_acym_campaign`, which is a dry run by
default and needs `dry_run: false`, `confirm: true` **and**
`expected_recipients` equal to the live deduplicated count, so an approval for
12 people cannot silently become 1,200 because a list changed.
`cancel_acym_campaign_sending` reproduces the Queue screen's Cancel exactly.

### 🧨 Vendor behaviour the tools absorb (all confirmed in source and live)

- **Mail text is stored double-encoded.** `MailClass` runs
  `mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1')` on name, subject, body,
  preheader before saving, so a raw SQL read of "café" returns "cafÃ©". Every
  read decodes; every write goes through `MailClass`. `query_acym_table`
  decodes mail tables unless `raw: true`.
- **Partial saves delete data.** `ListClass::save()` forces `description = ''`
  and `translation = ''` when absent, and `MailClass::save()` forces
  `access = ''` — a rename wipes the description, translations or the mail's
  access restriction. `update_acym_list` and `update_acym_campaign` hydrate the
  whole row first and return a before/after diff.
- **Off the admin client, deletes are silently narrowed.**
  `CampaignClass::delete()` keeps only campaigns whose mail `creator_id` is the
  current Joomla user, and `UserClass::delete()` only subscribers of the
  current user's front-end lists. Under MCP that turns "delete" into a silent
  0-row no-op for anything the token owner did not create. The tools use the
  admin-client behaviour and verify the rows are gone.
- **The list subscriber counters are a cache** refreshed only by an admin AJAX
  call. Every count here is computed live.
- **Re-subscribing an opt-out is one call away.** `subscribe()` flips an
  unsubscribe record back to subscribed. `subscribe_acym_subscribers` skips
  those unless `allow_resubscribe: true`.
- **Deleting a subscriber erases their opt-out.** The unsubscribe record goes
  with the row, so a later import re-adds them. The delete tool says so and
  suggests deactivating.
- **Subscribers created through the API get the token owner's language** in
  the vendor's non-admin branch. The create tool sets AcyMailing's default.
- **`mail.name LIKE '%confirm%'` bypasses `require_confirmation`** in
  `QueueClass::getReady()`. `check_acym_health` flags campaign/follow-up mails
  named like that.
- **Confirming a subscriber over MCP stamps the MCP client's IP** as their
  `confirmation_ip` — not evidence of consent.

### 🔒 Paywall and secrets

- `set_acym_config` writes through the vendor's `saveConfig()` (a per-key
  `REPLACE INTO` — it does not truncate), refuses edition and licence state
  (`level` is the whole edition gate; writing it would unlock paid features),
  credentials, cron activation keys (saving them calls acymailing.com), and
  values AcyMailing itself only allows on a higher edition (non-manual
  `queue_type`, `multilingual`, `captcha`, hiding the built-by footer). It
  re-reads the table and proves nothing else changed.
- `query_acym_table` never returns, filters or sorts on the subscriber `key`
  (it authorises unsubscribe/profile links), mailbox passwords, add-on
  settings, or the value of a credential-shaped configuration row.
- `send_acym_test_email` only returns PHPMailer's transcript on failure, with
  headers dropped: on success it contains the subscriber's `userKey` in the
  List-Unsubscribe header (caught live during testing).

### 🏗 Architecture

- Boots AcyMailing with one include of `Core/init.php`, inside a guard that
  strips a trailing `/api` from `Uri::root()` so the URL constants AcyMailing
  defines once (`ACYM_LIVE` …) point at the site. On Joomla 6.2 the root was
  already correct; the guard is a no-op there.
- One category, `acymailing`, for every tool.
- PHP 8.2 minimum (constants in a trait).
