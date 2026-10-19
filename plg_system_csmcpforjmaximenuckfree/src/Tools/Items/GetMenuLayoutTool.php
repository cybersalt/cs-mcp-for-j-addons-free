<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Items;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckLayoutTrait;
use Joomla\CMS\User\User;

final class GetMenuLayoutTool extends AbstractTool
{
	use MaximenuckBootTrait;
	use MaximenuckLayoutTrait;

	public function getName(): string { return 'get_maximenuck_menu_layout'; }

	public function getDescription(): string
	{
		return 'The mega-menu layout of a Maximenu CK menu, computed the way the renderer computes it: every '
			. 'dropdown panel with its columns (which child starts each column, its width, the items in it, new '
			. 'rows), the panel width Maximenu will set on .maxidrop-main (the sum of column widths, or the '
			. 'parent\'s explicit submenu width), and per-item Maximenu settings (columns, widths, submenu '
			. 'sizes, full width, tab, icon, description, inserted module, desktop/mobile hiding). Settings come '
			. 'from menu-item params (maximenu_*) and from the legacy title markers [col=N], [newrow], '
			. '[subwidth=N], [modid=N] — both are honoured by Light and Pro. Only items the renderer shows '
			. '(published, not hidden) are counted. Pass module_id (uses its menutype and start/end levels) or '
			. 'menutype. Column mistakes are flagged: e.g. the first child not being a column start, which '
			. 'leaves column 1 unsized and drops the last column underneath. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_id'      => ['type' => 'integer', 'description' => 'A mod_maximenuck module; its menutype and start/end levels are used.'],
				'menutype'       => ['type' => 'string', 'description' => 'A Joomla menutype, when not using module_id.'],
				'parent_item_id' => ['type' => 'integer', 'description' => 'Only the panel of this item.'],
				'include_items'  => ['type' => 'boolean', 'description' => 'Include the flat item list with settings (default true).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$start = 0;
		$end   = 0;
		$module = null;

		if (!empty($arguments['module_id'])) {
			$mods = $this->mxModules((int) $arguments['module_id']);

			if ($mods === []) {
				return ToolResult::json(['ok' => false, 'error' => 'No site mod_maximenuck module with id ' . (int) $arguments['module_id'] . '.'], true);
			}

			$module   = $this->mxModuleSummary($mods[0]);
			$menutype = $module['menutype'];
			$start    = $module['start_level'];
			$end      = $module['end_level'];

			if ($module['source'] !== 'menu') {
				return ToolResult::json([
					'ok'    => false,
					'error' => 'Module ' . $module['id'] . ' takes its items from source "' . $module['source']
						. '", not a Joomla menu. Only menu-sourced modules have per-item column settings in #__menu.',
				], true);
			}
		} else {
			$menutype = trim((string) ($arguments['menutype'] ?? ''));
		}

		if ($menutype === '') {
			return ToolResult::json(['ok' => false, 'error' => 'Pass module_id or menutype.'], true);
		}

		$items = $this->mxMenuItems($menutype);

		if ($items === []) {
			return ToolResult::json(['ok' => false, 'error' => 'Menutype "' . $menutype . '" has no (non-trashed) site items.'], true);
		}

		$panels = $this->mxComputePanels($items, $start, $end);

		if (!empty($arguments['parent_item_id'])) {
			$pid    = (int) $arguments['parent_item_id'];
			$panels = array_values(array_filter($panels, static fn ($p) => $p['parent_id'] === $pid));
		}

		foreach ($panels as &$p) {
			$p['issues'] = $this->mxPanelIssues($p, null, 0.0);
			unset($p['children']);
		}

		unset($p);

		$response = [
			'ok'        => true,
			'menutype'  => $menutype,
			'module'    => $module,
			'component' => $this->mxEditionNotice(),
			'panels'    => $panels,
			'root_issues' => $this->mxRootColumnIssues($items, $start),
		];

		if (!isset($arguments['include_items']) || $arguments['include_items']) {
			$visible = array_map(static fn ($i) => (int) $i['id'], $this->mxVisibleItems($items, $start, $end));

			$response['items'] = array_map(fn ($i) => [
				'id'        => $i['id'],
				'title'     => $i['title'],
				'level'     => $i['level'],
				'parent_id' => $i['parent_id'],
				'published' => $i['published'],
				'rendered'  => \in_array($i['id'], $visible, true),
				'maximenu'  => $this->mxItemMaximenuParams($i['params']),
				'title_markers' => $this->mxTitleMarkers((string) $i['title']) ?: null,
			], $items);
		}

		return ToolResult::json($response);
	}
}
