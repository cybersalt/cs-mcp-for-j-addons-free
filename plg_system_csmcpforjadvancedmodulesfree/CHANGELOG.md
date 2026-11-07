# MCP for Advanced Module Manager — Changelog

## 🚀 Version 1.0.0 (November 7, 2026)

First release. 22 tools for **Advanced Module Manager** by Regular Labs, studied against the 12.1.0 and 12.1.0PRO packages and driven on both editions on Joomla 6.1.4. Free, and works with both editions.

With Advanced Module Manager's plugin on, Joomla's own module access, menu assignment, language and publishing dates stop deciding where a module shows. These tools work with what does decide it.

- 🔍 **See what decides each module.** One module or a list, with its Condition Set in plain English, or a clear statement that it has none and shows everywhere, plus the Joomla settings being ignored.
- 🧩 **Condition Sets.** List, read, create, update, duplicate, publish, unpublish, trash and delete, with rules added, changed and removed one at a time, for every rule type the Free edition runs.
- 🤝 **Group modules on shared sets.** Put many modules on one set in a single call, make modules show wherever another one does, and find modules with identical rules so a later change is made once.
- 🔄 **Convert Joomla's own assignments.** Work out and create the sets that keep modules showing as their menu assignment, access level, language and publishing dates say, before or after the plugin goes on.
- 🩺 **Health check.** The plugin being off, versions older than the 12.1.0 security fix, modules whose Joomla settings are being ignored, modules on unpublished or empty sets, sets running PHP, rules the Free edition skips, rules pointing at deleted items, loops of nested sets, and unused sets.
- 🛡️ **Safe by design.** PHP rules are never written, and sets that run them, or that one depends on, are never attached, published, copied, nested or changed. Changing a shared set names every module it reaches. Anything that would put modules on every page needs confirm. Rule ids that have gone stale are refused rather than guessed at. URL regular expressions have to compile, and patterns that could tie up a visitor's request are refused. Advanced Module Manager's and Conditions' own permissions decide, with no fallback.
