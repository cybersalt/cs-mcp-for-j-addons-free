# MCP for Gantry 5 — Changelog

## 🚀 Version 1.0.0 (October 24, 2026)

First release. 21 tools for the **Gantry 5** template framework (`pkg_gantry5`) by Tiger12. Studied against
**Gantry 5.6.4** with the **Helium 5.6.4** theme and driven end-to-end on Joomla 6.2.0 / PHP 8.3, including
particle, module position, style and particle-module changes showing up on a rendered page.

### ✨ New

- **Diagnostics** — `check_gantry5_health`, `clear_gantry5_cache`.
- **Themes and outlines** — `list_gantry5_themes`, `list_gantry5_outlines`, `get_gantry5_outline` (with the full
  inheritance chain), `list_gantry5_assignments` (which pages use which outline), `set_gantry5_outline_assignments`,
  `create_gantry5_outline` (duplicate an outline as a copy or inheriting from its source).
- **Layouts** — `get_gantry5_layout`, `list_gantry5_outline_modules` (module positions and module instances per
  outline, the Joomla modules each renders, and positions the layout never renders).
- **Particles** — `list_gantry5_particle_types`, `get_gantry5_particle`, `update_gantry5_particle`,
  `get_gantry5_particle_defaults`, `update_gantry5_particle_defaults`.
- **Styles** — `get_gantry5_styles`, `update_gantry5_styles`.
- **Gantry 5 Particle modules** — `list_gantry5_particle_modules`, `update_gantry5_particle_module`.
- **Backups** — `list_gantry5_backups`, `restore_gantry5_backup`.

### 🏗 Architecture

- **Every value says where it comes from**: this outline, an instance inherited from another outline, the Base
  Outline, the theme's shipped config, or the blueprint default, resolved in the same order Gantry uses.
- **Reads never write.** Gantry's own layout reader saves files when its index is out of date, so these tools read
  Gantry's YAML themselves instead of booting Gantry.
- **Writes change one file, key by key**, only under `templates/<theme>/custom/config/`, after a backup
  (`restore_gantry5_backup` undoes them). Keys and values are checked against the particle or style blueprint, the
  YAML is written the way Gantry writes it and read back before it replaces the original, and Gantry's cache is
  cleared the way its own Clear Cache button does it. A styles change also deletes the outline's compiled CSS so
  the next page view rebuilds it.
- **Inherited items are refused, not silently copied**: the error names the outline to edit instead, and every
  write lists the other outlines it also reaches.
- **Free**, because Gantry 5 is free.

### 📝 Gantry behaviour worth knowing

- **Joomla 6 needs Gantry 5.6.2 or later**; 5.6.0 and 5.6.1 are Joomla 5 only. Gantry 5.6.4 with Helium 5.6.4
  runs on Joomla 6.2 without the Backward Compatibility plugin. `check_gantry5_health` checks the combination.
- **Gantry's admin can overwrite an outline.** For a template style without a `configuration` param, Gantry's
  outline list copies the preset's folder over the outline's own; Gantry's installer creates every style that way.
  The write tools set the param first, and the health check warns about any style still missing it.
- **Deleting a template style that other outlines inherit from** makes them fall back to a preset, and Gantry
  creates a folder for the deleted outline on the next page view. The health check reports both.
- A Custom HTML particle with "Process Twig" on runs its content as server-side Twig; only a Super User can turn it
  on or change such content through these tools.
- Gantry's configuration YAML is readable over the web; keep secrets out of particle settings.

### 📦 Build

- Requires PHP 8.2+ and CS MCP for J. Installs disabled, with an explanation, when CS MCP for J is not present,
  and clears Joomla's caches after install.
