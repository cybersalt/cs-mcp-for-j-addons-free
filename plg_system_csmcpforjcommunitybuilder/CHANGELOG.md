# MCP for Community Builder — Changelog

## 🚀 Version 1.0.1 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for Community Builder"** in Extensions → Plugins (was "System - MCP for Joomla: Community Builder add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

## 🚀 Version 1.0.0 (October 19, 2026)

First release. 32 tools for **Community Builder** (`com_comprofiler`) by Joomlapolis. Studied
against **Community Builder 2.12.0** (2026-08-28) and driven end-to-end on Joomla 6.2.0 / PHP 8.3.
Free, because Community Builder is free; CB's paid add-ons (CB Paid Subscriptions, GroupJive,
CB Gallery and the rest) are not covered.

### ✨ New

- **Diagnostics** — `get_cb_component_info`, `check_cb_health`, `list_cb_tables`, `query_cb_table`.
- **Configuration** — `get_cb_registration_config` (the sign-up audit), `get_cb_config`,
  `set_cb_config`.
- **Members** — `list_cb_users`, `get_cb_user`, `update_cb_user_fields`,
  `list_cb_pending_registrations`.
- **Moderation** — `approve_cb_user` (approve or reject), `confirm_cb_user`, `set_cb_user_ban`,
  `set_cb_user_block`, `list_cb_user_reports`.
- **Fields** — `list_cb_fields`, `get_cb_field`, `list_cb_field_types`, `create_cb_field`,
  `update_cb_field`, `delete_cb_field`, `set_cb_field_values`.
- **Tabs** — `list_cb_tabs`, `get_cb_tab`, `create_cb_tab`, `update_cb_tab`, `delete_cb_tab`.
- **Community** — `list_cb_connections`, `list_cb_lists`, `list_cb_plugins`, `set_cb_plugin_state`.

### 🏗 Architecture

- **CB code runs inside a temporary AdministratorApplication.** Every CB email builds the
  `[CONFIRM]` link through `viewUrl()` → `CBframework::itemid()` → `getMenu()`, which throws under
  Joomla's API application (there is no `ApiMenu`). Left alone, approving a member would store the
  approval and unblock them, then die before the welcome email. Booting CB as its own back end
  (`getUi() == 2`) is also the context its moderation code is written for.
- **Every CB email is held back unless `send_email: true`.** A listener on CB's own
  `onBeforeSendMail` event (registered under CB Core, which cannot be unpublished) vetoes the send
  through the `$error` argument CB checks, and every attempted email is returned in the response
  as CB built it. Under the API application CB's `live_site` ends in `/api`, so `[SITEURL]` and the
  confirmation link would point into the REST API; the listener rewrites them to the site URL. A
  second listener on `onAfterSendEmailMSG` reports what the mailer actually returned.
- **Writes go through CB's table classes** (`UserTable`, `FieldTable`, `FieldValueTable`,
  `TabTable`, `PluginTable`) so CB's events fire and `#__users` / `#__comprofiler` stay in step;
  every write re-reads the rows and diffs them. Profile values are written with CB's narrow
  `UserTable::storeDatabaseValues()`, never the form save, which treats absent checkboxes as unchecked.
- **Configuration writes merge**: CB's Configuration save replaces the whole JSON blob, so
  `set_cb_config` re-reads it, validates each key against CB's own configuration form XML, merges,
  and stores through `PluginTable` so the name-style side effect still happens.

### 🐛 Vendor behaviour found and handled (CB 2.12.0, verified live)

- **Approving or confirming resets the password** when "Generate Registration Passwords" is on; only
  the welcome email carries the new one. The tools refuse unless `send_email` is true.
- **`[CONFIRM]` renders empty** whenever email confirmation is off (`cbNotification::_replaceVariables()`).
  `get_cb_registration_config` flags a Pending email that relies on it.
- **Deleting a field drops its column and every member's answer**, despite a comment in
  `FieldTable::delete()` saying the data is preserved. `delete_cb_field` demands `confirm_data_loss`.
- **The first CB save of a user created outside CB writes a stray activation code into Joomla's
  `#__users.activation`** (CB sees `confirmed` as NULL). `update_cb_user_fields` first creates the
  profile row the way CB's Synchronize Users does, so no code is minted.
- **A CB ban hides the profile but does not stop login**; `can_log_in` reflects CB's real login rules.
- Option lists are a replace-set and members store option *text*, so `set_cb_field_values` refuses
  to orphan members' answers unless told to.

### 🔒 Secrets

Never returned: `#__users` password / OTP / activation, `#__comprofiler.cbactivation` (masked in
returned email text too), registration IPs, CB password-field columns, CB plugin params, the
Joomlapolis install-from-web key and token, and cleartext passwords CB generates.
`#__comprofiler_sessions` and the private-message tables are refused outright.
