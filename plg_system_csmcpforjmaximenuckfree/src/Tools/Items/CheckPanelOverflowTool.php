<?php
/**
 * @package     MCP for Maximenu CK
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\Items;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckBootTrait;
use Cybersalt\Plugin\System\Csmcpforjmaximenuckfree\Tools\MaximenuckLayoutTrait;
use Joomla\CMS\User\User;

final class CheckPanelOverflowTool extends AbstractTool
{
	use MaximenuckBootTrait;
	use MaximenuckLayoutTrait;

	public function getName(): string { return 'check_maximenuck_panel_overflow'; }

	public function getDescription(): string
	{
		return 'Checks every mega-menu dropdown panel of a Maximenu CK module for column overflow, from the '
			. 'same arithmetic the renderer uses. Flags: columns whose combined width exceeds the panel (an '
			. 'explicit submenu width narrower than a row of columns — the last column wraps or spills); the '
			. 'first child not being a column start (column 1 is unsized and not counted, so the panel is '
			. 'narrower than its columns); rows of columns inflating the panel (Maximenu sums every column '
			. 'across all rows); mixed px / % / auto widths; columns on a top-level item; and, when you give '
			. 'available_width_px (e.g. the template container or the narrowest desktop viewport), any panel '
			. 'whose width plus panel_padding_px is wider than that. The panel\'s .floatck wrapper adds the '
			. 'style\'s padding OUTSIDE the computed width — measure .floatck in the browser and pass it as '
			. 'panel_padding_px (e.g. 40 for 20px each side). Where a panel opens on screen also depends on its '
			. 'parent\'s position, which this static check cannot know; the module\'s `testoverflow` option '
			. 'only flips level-3+ panels to open leftwards. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'module_id'          => ['type' => 'integer', 'description' => 'A menu-sourced mod_maximenuck module.'],
				'available_width_px' => ['type' => 'number', 'description' => 'Width a panel must fit in (optional).'],
				'panel_padding_px'   => ['type' => 'number', 'description' => 'Horizontal padding+border of .floatck added outside the panel width (default 0).'],
				'parent_item_id'     => ['type' => 'integer', 'description' => 'Only check this item\'s panel.'],
			],
			'required'             => ['module_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$mods = $this->mxModules($this->requirePositiveInt($arguments, 'module_id'));

		if ($mods === []) {
			return ToolResult::json(['ok' => false, 'error' => 'No site mod_maximenuck module with id ' . (int) $arguments['module_id'] . '.'], true);
		}

		$module = $this->mxModuleSummary($mods[0]);

		if ($module['source'] !== 'menu') {
			return ToolResult::json(['ok' => false, 'error' => 'Module ' . $module['id'] . ' is not menu-sourced (source "' . $module['source'] . '").'], true);
		}

		$available = isset($arguments['available_width_px']) ? (float) $arguments['available_width_px'] : null;
		$padding   = (float) ($arguments['panel_padding_px'] ?? 0);
		$items     = $this->mxMenuItems($module['menutype']);
		$panels    = $this->mxComputePanels($items, $module['start_level'], $module['end_level']);

		if (!empty($arguments['parent_item_id'])) {
			$pid    = (int) $arguments['parent_item_id'];
			$panels = array_values(array_filter($panels, static fn ($p) => $p['parent_id'] === $pid));
		}

		$report = [];
		$counts = ['error' => 0, 'warning' => 0];

		foreach ($panels as $p) {
			$issues = $this->mxPanelIssues($p, $available, $padding);

			foreach ($issues as $i) {
				$counts[$i['severity']] = ($counts[$i['severity']] ?? 0) + 1;
			}

			$width = is_numeric($p['submenuswidth']) ? (float) $p['submenuswidth'] : null;

			$report[] = [
				'parent_item_id'    => $p['parent_id'],
				'parent_title'      => $p['parent_title'],
				'columns'           => array_map(static fn ($c) => [
					'index' => $c['index'], 'start_item_id' => $c['start_item_id'], 'width' => $c['width'],
					'new_row' => $c['new_row'], 'items' => \count($c['items']),
				], $p['columns']),
				'panel_width'       => $p['submenuswidth'],
				'panel_width_source' => $p['explicit_width'] !== '' ? 'explicit submenu width' : ($p['computed_sum'] !== null ? 'sum of column widths' : 'theme default (no columns sized)'),
				'on_screen_width_px' => $width !== null ? $width + $padding : null,
				'fits'              => $issues === [],
				'issues'            => $issues,
			];
		}

		foreach ($this->mxRootColumnIssues($items, $module['start_level']) as $i) {
			$counts[$i['severity']] = ($counts[$i['severity']] ?? 0) + 1;
			$report[] = ['root_issue' => $i];
		}

		return ToolResult::json([
			'ok'      => true,
			'module'  => ['id' => $module['id'], 'title' => $module['title'], 'menutype' => $module['menutype'], 'menu_html_id' => $module['menu_html_id'], 'testoverflow' => $module['testoverflow']],
			'inputs'  => ['available_width_px' => $available, 'panel_padding_px' => $padding],
			'summary' => $counts + ['panels' => \count($panels)],
			'panels'  => $report,
		]);
	}
}
