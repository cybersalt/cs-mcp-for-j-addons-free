<?php
/**
 * @package     MCP for FlexBanners
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjflexbanners\Tools\FlexbannersBootTrait;
use Joomla\CMS\User\User;

final class ListModulesTool extends AbstractTool
{
	use FlexbannersBootTrait;
	use ModuleSupportTrait;

	public function getName(): string { return 'list_flexbanners_modules'; }

	public function getDescription(): string
	{
		return 'List every site mod_flexbanners module with what it shows: its location, how many banners per page, '
			. 'their order (down / up / random — a legacy value like "1" behaves as down), content-specific mode, '
			. 'caching, position, menu assignment, anything stopping it from rendering, and how many banners in its '
			. 'location are currently displayable. Modules in one position stack in ordering order, one row per '
			. 'module.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$this->fbInstalled()) {
			return $this->fbNotInstalledError();
		}

		$this->fbRequireAccess($actor);

		$modules    = $this->fbModules();
		$sizes      = $this->fbLookup('size', 'sizeid');
		$links      = $this->fbLookup('link', 'linkid');
		$rows       = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName($this->fbTable('')) . ' ORDER BY id')->loadAssocList() ?: [];
		$categories = $this->fbCategories(array_column($rows, 'catid'));
		$enforces   = $this->fbModuleEnforcesLimits();

		$displayable = [];

		foreach ($rows as $r) {
			$audit = $this->fbDisplayAudit($r, $sizes, $categories, $links, $modules, $enforces);

			if ($audit['problems'] !== []) {
				continue;
			}

			foreach ($audit['modules'] as $mid) {
				$displayable[$mid][] = (int) $r['id'];
			}
		}

		$out = [];

		foreach ($modules as $m) {
			$shaped                        = $this->moduleShape($m);
			$shaped['displayable_banners'] = $displayable[$m['id']] ?? [];

			if (\count($shaped['displayable_banners']) > $m['numberbanner']) {
				$shaped['note'] = \count($shaped['displayable_banners']) . ' banners qualify but only ' . $m['numberbanner']
					. ' show per page' . ($m['banner_order'] === 'random' ? ' (a random selection each view).' : ' (always the same first ones; set banner_order random to rotate).');
			}

			if ($m['cache'] === 1) {
				$shaped['cache_warning'] = 'Module caching is on: banners do not rotate and impressions are not counted while a cached copy is served.';
			}

			$out[] = $shaped;
		}

		return ToolResult::json(['ok' => true, 'count' => \count($out), 'modules' => $out]);
	}
}
