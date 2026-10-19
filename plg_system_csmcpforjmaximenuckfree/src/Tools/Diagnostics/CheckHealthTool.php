<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Diagnostics;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckCssTrait;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckLayoutTrait;
use Joomla\CMS\User\User;

/**
 * Site-wide audit of the things that make a Maximenu change "not show up".
 */
final class CheckHealthTool extends AbstractTool
{
	use MaximenuckBootTrait;
	use MaximenuckCssTrait;
	use MaximenuckLayoutTrait;

	private const TEXT_LIMIT = 65535;

	public function getName(): string { return 'check_maximenuck_health'; }

	public function getDescription(): string
	{
		return 'Audits every Maximenu CK module and style for the problems that make a menu change silently '
			. 'not appear. Checks: modules still in v8 legacy mode (isv9=0); modules pointing at a missing or '
			. 'trashed style (a trashed style emits NO css — Style::getCss() only loads state 0/1); a module '
			. '`customcss` param with content (dead since v9 — mod_maximenuck.php never emits it; the CSS '
			. 'belongs on the style row); compiled-CSS mode "1" with an existing file (served stale for ever '
			. 'until deleted); styles whose raw custom CSS uses bare |ID| while the baked copy at the end of '
			. 'layoutcss is out of step (those rules match nothing); style columns near the 65,535-byte `text` '
			. 'limit; modules whose menutype has no items; mega-menu column mistakes in each module\'s menu '
			. '(first child not a column start, columns on a top-level item, rows inflating the panel); and '
			. 'per-item Maximenu settings present while the Pro system plugin is off (per-item styles will '
			. 'not render). Read-only.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$findings = [];
		$add = static function (string $severity, string $code, string $message, array $ctx = []) use (&$findings) {
			$findings[] = ['severity' => $severity, 'code' => $code, 'message' => $message] + $ctx;
		};

		$plugin = $this->mxSystemPluginState();

		if (!$this->mxTableExists('styles')) {
			$add('error', 'styles_table_missing', '#__maximenuck_styles does not exist; no module can load a style.');
		}

		$modules     = $this->mxModules();
		$styleCache  = [];
		$itemsCache  = [];

		foreach ($modules as $m) {
			$s   = $this->mxModuleSummary($m);
			$ctx = ['module_id' => $m['id'], 'module_title' => $m['title']];

			if (!$s['v9_mode']) {
				$add('error', 'module_v8_legacy', 'Module runs in Maximenu v8 legacy mode (isv9=0): it shows a red '
					. 'notice on the page and ignores styles. Convert it in the module\'s Advanced options.', $ctx);
			}

			if ($s['dead_module_customcss_bytes'] > 0) {
				$add('warning', 'dead_module_customcss', sprintf(
					'The module\'s own `customcss` param holds %d bytes that are NEVER output (v9+ only emits the '
						. 'style row\'s CSS). Move it to the style\'s custom CSS with write_maximenuck_style_custom_css.',
					$s['dead_module_customcss_bytes']
				), $ctx);
			}

			if ($s['compiled_css']['mode'] === '1' && $s['compiled_css']['file_exists']) {
				$add('warning', 'compiled_css_stale_risk', 'Compiled CSS mode is "1" (compile once) and '
					. $s['compiled_css']['file'] . ' exists, so style and menu-item changes stay invisible until '
					. 'that file is deleted (clear_maximenuck_compiled_css).', $ctx);
			}

			if ($s['compiled_css']['mode'] !== '0' && $m['published'] === 1) {
				$add('info', 'compiled_css_no_cache_buster', 'Compiled CSS (mode "' . $s['compiled_css']['mode']
					. '") is linked as ' . ($s['compiled_css']['url'] ?? $s['compiled_css']['file']) . ' with no version '
					. 'query, so a CDN or browser cache keeps serving old CSS after a change. Purge it after edits, or '
					. 'use loadcompiledcss = 0 (inline) on sites behind a CDN.', $ctx);
			}

			if ($s['style_id'] > 0 && $this->mxTableExists('styles')) {
				$style = $styleCache[$s['style_id']] ??= $this->mxLoadStyle($s['style_id']);

				if ($style === null) {
					$add('error', 'style_missing', 'Module points at style ' . $s['style_id'] . ', which does not exist: '
						. 'the menu renders with theme CSS only.', $ctx);
				} elseif (!\in_array($style['state'], [0, 1], true)) {
					$add('error', 'style_trashed', 'Module uses style ' . $s['style_id'] . ' whose state is '
						. $style['state'] . '. Style::getCss() only loads state 0 or 1, so NONE of its CSS is emitted.', $ctx);
				}
			}

			if ($s['source'] === 'menu' && $s['menutype'] !== '') {
				$items = $itemsCache[$s['menutype']] ??= $this->mxMenuItems($s['menutype']);

				if ($items === []) {
					$add('warning', 'menutype_empty', 'Module\'s menutype "' . $s['menutype'] . '" has no items; '
						. 'Maximenu renders nothing.', $ctx);
					continue;
				}

				foreach ($this->mxRootColumnIssues($items, $s['start_level']) as $i) {
					$add($i['severity'], $i['code'], $i['message'], $ctx);
				}

				foreach ($this->mxComputePanels($items, $s['start_level'], $s['end_level']) as $panel) {
					foreach ($this->mxPanelIssues($panel, null, 0.0) as $i) {
						$add($i['severity'], $i['code'], $i['message'], $ctx + ['parent_item_id' => $panel['parent_id']]);
					}
				}

				if (!$plugin['enabled']) {
					$styled = 0;

					foreach ($items as $it) {
						foreach (array_keys($it['params']) as $k) {
							if (preg_match('/^(itemnormalstyles|itemhoverstyles|itemactivestyles|submenustyles)/', (string) $k)
								&& (string) $it['params'][$k] !== '') {
								$styled++;
								break;
							}
						}
					}

					if ($styled > 0) {
						$add('warning', 'item_styles_not_rendered', sprintf(
							'%d item(s) in menu "%s" carry per-item style settings, but the Pro system plugin '
								. '(plg_system_maximenuck) is not enabled, so those styles are not rendered.',
							$styled,
							$s['menutype']
						), $ctx);
					}
				}
			}
		}

		if ($this->mxTableExists('styles')) {
			$rows = $this->db->setQuery(
				'SELECT id, name, state, layoutcss, customcss FROM ' . $this->db->quoteName('#__maximenuck_styles')
			)->loadAssocList() ?: [];

			foreach ($rows as $r) {
				$ctx = ['style_id' => (int) $r['id'], 'style_name' => (string) $r['name']];

				foreach (['layoutcss', 'customcss'] as $col) {
					$len = \strlen((string) $r[$col]);

					if ($len > self::TEXT_LIMIT * 0.9) {
						$add('warning', 'text_column_near_limit', sprintf(
							'%s is %d bytes, near the 65,535-byte limit of its `text` column. MySQL truncates '
								. 'silently in non-strict mode.',
							$col,
							$len
						), $ctx);
					}
				}

				$custom = (string) $r['customcss'];

				if ($custom === '') {
					continue;
				}

				$plan  = $this->mxPlanCustomCss((string) $r['layoutcss'], $custom, $custom, false);
				$baked = $plan['sync_state'] === 'in_sync';
				$eff   = $this->mxEffectiveness($custom, $baked);

				if (!$baked && $eff['id_tokens']['bare'] > 0) {
					$add('error', 'custom_css_dead_rules', sprintf(
						'The style\'s custom CSS uses bare |ID| %d time(s), but the baked copy at the end of '
							. 'layoutcss does not match it (state: %s), so those rules match nothing on the page. '
							. 'Re-write it with write_maximenuck_style_custom_css (rewrite_bare_id: true), or '
							. 'open and save the style in Maximenu\'s editor.',
						$eff['id_tokens']['bare'],
						$plan['sync_state']
					), $ctx);
				} elseif (!$baked && $plan['sync_state'] === 'out_of_sync') {
					$add('info', 'custom_css_out_of_sync', 'The style\'s custom CSS was changed outside the '
						. 'editor: layoutcss still ends with an older baked copy, whose rules also still apply '
						. 'until the style is next saved in the editor.', $ctx);
				}
			}
		}

		$counts = ['error' => 0, 'warning' => 0, 'info' => 0];

		foreach ($findings as $f) {
			$counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
		}

		return ToolResult::json([
			'ok'        => true,
			'component' => $this->mxEditionNotice(),
			'modules_checked' => \count($modules),
			'summary'   => $counts,
			'healthy'   => $counts['error'] === 0 && $counts['warning'] === 0,
			'findings'  => $findings,
		]);
	}
}
