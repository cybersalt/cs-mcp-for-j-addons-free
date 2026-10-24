# MCP for Page Protector — Changelog

## 🚀 Version 1.0.0 (October 24, 2026)

First release. 15 tools for **Cybersalt Page Protector** (`com_cspageprotector` + `plg_system_cspageprotector`).
Built against **Page Protector 0.2.1** and needs **0.2.0 or later**. Driven end-to-end on Joomla 6.2.0 / PHP 8.3,
including the challenge page appearing and disappearing on the front end, an allow-listed IP getting through, a
solved proof-of-work pass being accepted and then rejected after a pass reset, and protected modules showing
their placeholder.

### ✨ New

- **Status** — `get_page_protector_status`: the dashboard's own health checks (gatekeeper plugin, whether the
  captcha can run and what that means for visitors right now, what is protected, Cloudflare, logging, page cache),
  plus versions, protection mode, exemptions and logging settings.
- **Settings** — `get_page_protector_settings` (every option, with menu items, modules, positions and user groups
  resolved to titles, and the IP lists parsed the way Page Protector reads them) and `set_page_protector_settings`
  (validated partial update; `null` puts an option back to its default; `dry_run`).
- **Protected pages and modules** — `add_page_protector_menu_items`, `remove_page_protector_menu_items`,
  `add_page_protector_modules`, `remove_page_protector_modules` (by id and/or by position).
- **IP allow-list** — `add_page_protector_exempt_ips`, `remove_page_protector_exempt_ips` (IPv4/IPv6, CIDR-aware).
- **Passes** — `reset_page_protector_passes` (the dashboard's "Reset all passes").
- **Event log** — `get_page_protector_stats`, `list_page_protector_events` (the Event Log's filters),
  `get_page_protector_top_ips` (with a "likely scraper" flag), `delete_page_protector_events`,
  `clear_page_protector_log`.

### 🏗 Architecture

- **Page Protector's own code does the work.** The tools call its helpers and its Dashboard and Logs models, so
  what MCP reports is what the dashboard and the gatekeeper plugin decide.
- **Settings are saved the way Options → Save saves them**, through Joomla's com_config model with the full set
  of stored options. That runs Page Protector's after-save work: shortening the IPs already in the log when IP
  shortening is on, and cleaning the page and module caches. A save never drops a stored option.
- **Validated before saving:** menu items, modules, positions and user groups must exist, IP lists must parse,
  values must fit the Options form, unknown options are refused. Switching to "every page except" needs an
  explicit confirmation.
- **Page Protector's own permissions:** view for reads, write for log deletes, Options for settings, admin for
  resetting passes.
- **Logged IPs are shown as the admin shows them**, with a note that they are network addresses while IP
  shortening is on, and the range to block at a firewall.
- **Free**, because Page Protector is free.

### 📝 Page Protector behaviour worth knowing

- An allow-list entry Page Protector can't parse (`203.0.113.300`, `/33`) is ignored without a word. The tools
  refuse to write one and flag any already stored.
- The allow-list is split on commas before `#` comments are removed, so commenting out a line such as
  `# 198.51.100.7, 198.51.100.0/24` leaves the second entry active. The tools flag it and refuse a comment that
  contains a comma.

### 📦 Build

- Requires PHP 8.2+ and CS MCP for J. Installs disabled, with an explanation, when CS MCP for J is not present,
  and clears Joomla's caches after install.
