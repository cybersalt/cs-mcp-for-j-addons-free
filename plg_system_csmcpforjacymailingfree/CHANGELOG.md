# MCP for Joomla: AcyMailing (Starter) add-on — Changelog

`plg_system_csmcpforjacymailingfree`. Versions are independent of every other
add-on in this repo and of cs-mcp-for-j core.

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
