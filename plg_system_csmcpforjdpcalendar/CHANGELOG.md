# MCP for DPCalendar — Changelog

## 🚀 Version 1.0.4 (November 20, 2026)

- 📝 **Uses the core extension's current name.** The core installs as "CS MCP for J" (its Joomla Extensions Directory listing name) and shows as "MCP for J" in the Components menu; it was "MCP for Joomla". The install and update messages, and the plugin description where it names the core, now say so. No tool behaves differently.
- 🔧 **Joomla's Changelog button now works for this add-on.** On System → Manage → Extensions it shows this add-on's release notes.
- 🔒 **Tighter permission checks.** Each tool now also needs the matching permission in DPCalendar itself, as its own screens do: access to the component to read; Create, Edit (or Edit Own), Edit State or Delete to change things, checked on the event's own calendar for events; and Admin Booking for bookings, tickets and booking reports.
- 🔒 **Passwords and keys stay hidden.** `get_dpcalendar_extcalendar` masks the credentials in an external calendar's settings unless a Super User asks to see them, and `update_dpcalendar_extcalendar` keeps the stored value when it is sent a masked one.
- 🔧 **Check-in tools are marked as changes.** `check_in_dpcalendar_ticket` and `check_out_dpcalendar_ticket` change a ticket, and now tell the AI app so instead of looking like read-only tools.
- 🐛 **Calendar and event lists work on more databases.** `list_dpcalendar_calendars`, `list_dpcalendar_events` and `get_dpcalendar_event` failed on sites whose tables mix two text collations. They now match calendars to events in a way every collation accepts.
- 🔒 **Text is cleaned as DPCalendar's own forms clean it.** Titles and descriptions of events, locations and external calendars go through Joomla's text filter for the account the AI app acts for, so script that account could not save in DPCalendar is removed. Other text fields lose any HTML tags, colours must be hex values, links must start with http:// or https://, and email addresses are checked.
- 🔒 **Publishing needs Edit State.** Setting a state or publish dates on an event, location, tax rate or external calendar needs DPCalendar's "Edit State" permission, as its own forms do. Without it, a new item is saved unpublished, as DPCalendar would save it.
- 🔒 **Event lists and reads follow view access levels.** Events, calendars and external calendars outside the account's view levels are left out, and an event whose content level the account lacks shows "Busy" instead of its title, as in DPCalendar's own list. Meeting passwords that online-meeting plugins keep in an event's settings are masked, and a copied event doesn't take the original's meeting with it. Copying an event also needs Edit (or Edit Own) on it.
- 🔒 **Checked-out items are left alone.** Updating an event, location, coupon or tax rate that another user has open in DPCalendar is refused, so their Save can't silently undo the change. Pass `force_checked_out: true` to save anyway.
- 🔒 **External calendar settings are merged, not replaced.** `update_dpcalendar_extcalendar` keeps every setting you don't send, works with the real password rather than its stored form, and puts back any masked value (also inside nested settings and link addresses) instead of saving the mask. More credential fields are masked, including keys and the user name, password or secret part of feed addresses.
- 🐛 **Ticket check-in uses DPCalendar's "Checked in" state.** `check_in_dpcalendar_ticket` now sets state 9, which keeps the seat counted as taken; the old state 3 ("Confirmation") freed the seat. Booking and ticket states are labelled with DPCalendar's own names, and `update_dpcalendar_booking` describes 6 as "cancelled", so a cancel goes through DPCalendar's cancellation rule.
- 🐛 **Updating a booking keeps its coupon.** `update_dpcalendar_booking` used to detach a booking's coupon on every call. It now also needs DPCalendar's Edit permission, as the admin form does, and no longer writes the payment transaction id, which DPCalendar keeps read-only.
- 🔒 **Less private data in replies.** `get_dpcalendar_booking` no longer returns the booking's public link token or the payment gateway's raw data, and error messages from DPCalendar no longer include database details or server paths.
- 🔧 **Deleting follows DPCalendar's trash rule.** `delete_dpcalendar_event` and `delete_dpcalendar_location` delete only items already in the trash, as DPCalendar's admin does.
- 🐛 **Searches match literally.** A search containing % or _ now matches those characters instead of acting as a wildcard.
- 🐛 **Booking and ticket changes work again.** `update_dpcalendar_booking`, `check_in_dpcalendar_ticket`, `check_out_dpcalendar_ticket` and `delete_dpcalendar_booking` stopped with "Call to undefined method ApiRouter::build()" on any site with bookings, because DPCalendar builds the links for its emails while it saves. They now save, and DPCalendar's emails carry the right site links.
- 🐛 **The country list works.** `list_dpcalendar_countries` failed every time: it looked for a name column that DPCalendar's country table doesn't have. Country names now come from DPCalendar's own language file, and the search matches them.

## 🚀 Version 1.0.3 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for DPCalendar"** in Extensions → Plugins (was "System - MCP for Joomla: DPCalendar add-on"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- No tool behaves differently.

## 🚀 Version 1.0.2 (October 19, 2026)

- 🐛 **`list_dpcalendar_coupons` works on MySQL and MariaDB.** It selected the coupon's `limit` column without quoting it, and `limit` is a reserved word, so every call failed with an SQL syntax error. All plain column lists in the add-on are now quoted.

## 🚀 Version 1.0.1 (October 9, 2026)

- 🔧 **Clears Joomla's caches after install** (the PSR-4 autoload map, opcache and Joomla's cache groups). A plugin installed after the autoload map was written is not in it, which could produce an HTTP 500 shortly after installing.
- 🛡️ **Never takes the site down when MCP for Joomla is missing.** Installed on a site without MCP for Joomla, the add-on no longer enables itself, and says plainly that nothing is broken and MCP for Joomla is needed. If it is enabled anyway, it stays inert instead of fataling every request, admin included.

## 🚀 Version 1.0.0 (July 21, 2026)

- 🚀 Initial release.
