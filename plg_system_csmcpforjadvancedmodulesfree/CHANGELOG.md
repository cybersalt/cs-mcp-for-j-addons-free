# MCP for Advanced Module Manager — Changelog

## 🚀 Version 1.0.1 (November 19, 2026)

- 💬 **Clearer permission messages.** When an account lacks a permission, the message now names it in plain words and says where a Super User can grant it.
- 🔒 **Leaves alone what someone has open.** A Condition Set or module that another user has open in the Joomla administrator isn't changed, because their Save would overwrite the change. The message says how to check it in.
- 🧱 **Needs Advanced Module Manager 12.1.0 or newer to make changes.** On an older version the read tools still work, and every change is refused with a note to update first.
- 🔄 **All or nothing.** Converting Joomla's assignments, and putting several modules on a set, now either finish completely or change nothing.
- ✅ **Tested with Advanced Module Manager 12.1.0, Free and Pro, on Joomla 5.4 and 6.1.**

## 🚀 Version 1.0.0 (November 7, 2026)

First release. 22 tools for **Advanced Module Manager** by Regular Labs, studied against the 12.1.0 and 12.1.0PRO packages and driven on both editions on Joomla 6.1.4. Free, and works with both editions.

With Advanced Module Manager's plugin on, Joomla's own module access, menu assignment, language and publishing dates stop deciding where a module shows. These tools work with what does decide it.

- 🔍 **See what decides each module.** One module or a list, with its Condition Set in plain English, or a clear statement that it has none and shows everywhere, plus the Joomla settings being ignored.
- 🧩 **Condition Sets.** List, read, create, update, duplicate, publish, unpublish, trash and delete, with rules added, changed and removed one at a time, for every rule type the Free edition runs.
- 🤝 **Group modules on shared sets.** Put many modules on one set in a single call, make modules show wherever another one does, and find modules with identical rules so a later change is made once.
- 🔄 **Convert Joomla's own assignments.** Work out and create the sets that keep modules showing as their menu assignment, access level, language and publishing dates say, before or after the plugin goes on.
- 🩺 **Health check.** The plugin being off, versions older than the 12.1.0 security fix, modules whose Joomla settings are being ignored, modules on unpublished or empty sets, sets running PHP, rules the Free edition skips, rules pointing at deleted items, loops of nested sets, and unused sets.
- 🛡️ **Limits that look after your pages.** A set can hold up to 100 rules and 50 regular expressions, counting the sets it includes, nesting is capped, and a regular expression that would take too long to check is refused. Anything that would put modules on every page needs `confirm: true`.
- 🛡️ **Safe by design.** PHP rules are never written, and sets that run them, or that one depends on, are never attached, published, copied, nested or changed. Changing a shared set names every module it reaches. Anything that would put modules on every page needs confirm. Rule ids that have gone stale are refused rather than guessed at. URL regular expressions have to compile, and patterns that could tie up a visitor's request are refused. Advanced Module Manager's and Conditions' own permissions decide, with no fallback.
