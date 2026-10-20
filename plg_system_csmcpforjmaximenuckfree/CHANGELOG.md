# MCP for Maximenu CK — Changelog

## 🚀 Version 1.0.1 (October 20, 2026)

- 🏷️ **Renamed to "System - MCP for Maximenu CK"** in Extensions → Plugins (was "System - MCP for Joomla - Maximenu CK"). The new name follows the Joomla Extensions Directory's naming rules. Only the displayed name changes: the plugin's element, folder and update channel are the same, so updates keep working.
- 📄 **Every PHP file now carries a GPL licence notice** (GNU General Public License version 2 or later).
- 🧹 **Removed the non-standard `<php_minimum>` element from the manifest.** Joomla never read it from there. The minimum PHP version is still published by the update server, which is where Joomla checks it.
- No tool behaves differently.

## 🚀 Version 1.0.0 (October 19, 2026)

- 🚀 Initial release: 11 tools for Maximenu CK (Light and Pro), covering modules, styles, custom CSS, the compiled-CSS cache, each menu's mega-menu column layout and a panel-overflow check.
- ✏️ **`write_maximenuck_style_custom_css` writes to the place that renders.** Maximenu v9+ takes its CSS from the style row (`#__maximenuck_styles`); a module's own `customcss` param is a hidden v8 field that is never output. The style editor also bakes a second copy of the custom CSS onto the end of `layoutcss`, with `|ID|` expanded differently from the raw copy, so writing `customcss` alone leaves the old rules live and makes the editor's own bare-`|ID|` convention match nothing. The tool replaces that baked copy exactly when it can identify it, and refuses to write rules it can prove will not apply.
- 🧹 **Clears the compiled CSS that hides changes.** With "Load compiled CSS" set to Yes, Maximenu writes `maximenuck_<id>.css` once and serves it for ever. Writes delete it; `clear_maximenuck_compiled_css` does it on its own.
- 🌐 **Warns that the compiled file has no cache-buster.** Maximenu links `maximenuck_<id>.css` with no version query, so a CDN or browser that cached it keeps serving the old CSS. On the test site Cloudflare served the pre-change file with a 30-day max-age. Every write and clear returns the exact URL to purge, or suggests inlining the CSS (`loadcompiledcss = 0`).
- 📐 **Mega-menu layout and overflow, computed as the renderer computes it.** Shows each dropdown's columns and the panel width Maximenu will set, and flags the classic mistakes: the first child not being a column start (column 1 is unsized and the last column drops underneath), columns wider than an explicit submenu width, rows inflating the panel, mixed units, and panels wider than a width you give.
- 🔒 Writing per-item mega-menu settings is a Maximenu CK Pro feature (Light's own menu manager refuses it), so it is not offered here. It ships in the separate Pro add-on, which also refuses on Light.
