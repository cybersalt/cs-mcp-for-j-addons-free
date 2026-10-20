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

final class GetItemSettingsTool extends AbstractTool
{
	use MaximenuckBootTrait;
	use MaximenuckLayoutTrait;

	public function getName(): string { return 'get_maximenuck_item_settings'; }

	public function getDescription(): string
	{
		return 'The Maximenu CK settings of one Joomla menu item: every maximenu_* / per-item style key in its '
			. 'params (column start, column width, new row, submenu width/height/margins, full width and tab '
			. 'classes in maximenu_liclass, icon, description, inserted module, desktop/mobile hiding, item '
			. 'styles), any legacy title markers ([col=N], [newrow], [subwidth=N], [modid=N], "Title||desc"), '
			. 'the item\'s place in its parent\'s panel (which column), its own panel if it has children, and '
			. 'which Maximenu modules render its menu. Also says whether those settings can be EDITED on this '
			. 'site: per-item mega-menu settings are a Maximenu CK Pro feature, edited with '
			. 'set_maximenuck_item_settings from the Pro add-on. Read-only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'item_id' => ['type' => 'integer', 'description' => 'The #__menu id.'],
			],
			'required'             => ['item_id'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->mxAdminBase() === null) {
			return $this->mxNotInstalledError();
		}

		$id   = $this->requirePositiveInt($arguments, 'item_id');
		$item = $this->mxMenuItem($id);

		if ($item === null || $item['client_id'] !== 0) {
			return ToolResult::json(['ok' => false, 'error' => 'No site menu item with id ' . $id . '.'], true);
		}

		$modules = array_values(array_filter(
			$this->mxModules(),
			fn ($m) => $this->mxModParam($m, 'source', 'menu') === 'menu'
				&& $this->mxModParam($m, 'menutype', '') === (string) $item['menutype']
		));

		$items  = $this->mxMenuItems((string) $item['menutype']);
		$panels = $this->mxComputePanels($items);

		$inPanel = null;
		$ownPanel = null;

		foreach ($panels as $p) {
			if ($p['parent_id'] === $id) {
				$ownPanel = $p;
				unset($ownPanel['children']);
				$ownPanel['issues'] = $this->mxPanelIssues($p, null, 0.0);
			}

			foreach ($p['columns'] as $c) {
				foreach ($c['items'] as $ci) {
					if ($ci['id'] === $id) {
						$inPanel = [
							'parent_item_id'  => $p['parent_id'],
							'column'          => $c['index'],
							'column_count'    => \count($p['columns']),
							'starts_column'   => $c['start_item_id'] === $id && $c['width_kind'] !== 'unset',
							'column_width'    => $c['width'],
							'panel_width'     => $p['submenuswidth'],
						];
					}
				}
			}
		}

		$isPro  = $this->mxVendorIsPro();
		$plugin = $this->mxSystemPluginState();

		return ToolResult::json([
			'ok'   => true,
			'item' => [
				'id'        => $item['id'],
				'title'     => $item['title'],
				'menutype'  => $item['menutype'],
				'level'     => $item['level'],
				'parent_id' => $item['parent_id'],
				'published' => $item['published'],
				'menu_show' => (string) ($item['params']['menu_show'] ?? '1'),
			],
			'maximenu_params' => $this->mxItemMaximenuParams($item['params']),
			'title_markers'   => $this->mxTitleMarkers((string) $item['title']) ?: null,
			'in_parent_panel' => $inPanel,
			'own_panel'       => $ownPanel,
			'rendered_by_modules' => array_map(fn ($m) => [
				'module_id'    => $m['id'],
				'title'        => $m['title'],
				'menu_html_id' => $this->mxMenuHtmlId($m),
			], $modules),
			'editing' => [
				'edition'                 => $isPro ? 'pro' : 'light',
				'megamenu_settings_editable' => $isPro
					? 'yes — set_maximenuck_item_settings / set_maximenuck_columns (Pro add-on)'
					: 'no — Maximenu CK Pro feature; Light\'s menu manager does not save these.',
				'item_styles_render'      => $plugin['enabled'],
			],
		]);
	}
}
